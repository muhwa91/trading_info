<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketSessionService;
use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TossChangeCalculator 단위 테스트.
 *
 * 검증 대상:
 *   - calculate(): lastPrice · prevClose 로 등락 계산 정확도
 *   - getPrevClose(): 캐시 hit → API 호출 없음
 *   - getPrevClose(): 캐시 miss → /candles 호출 → 캐시 저장
 *   - /candles 응답이 1봉 이하면 null 반환
 *   - 빈 응답 graceful → change=0
 *   - 봉 정렬 (시간 역순 응답도 정상 처리)
 */
class TossChangeCalculatorTest extends TestCase
{
    private $clientMock;
    private $sessionMock;
    private TossChangeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock  = $this->createMock(TossApiClient::class);
        $this->sessionMock = $this->createMock(MarketSessionService::class);
        $this->calculator  = new TossChangeCalculator($this->clientMock, new TossSymbolMapper(), $this->sessionMock);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();  // 시간 고정 해제 (날짜 경계 테스트 격리)
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────
    // calculate() — 등락 계산
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testCalculate_PositiveChange(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // 실측 응답 구조: result.candles 배열, 최신(index 0) → 오래된(index 1)
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '72000', 'lowPrice' => '70000', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'openPrice' => '70000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                    'nextBefore' => '2026-06-22T00:00:00.000+09:00',
                ],
            ]);

        $result = $this->calculator->calculate('005930', 71500.0);

        // prevClose = 70500 (index 1 = 오래된 봉), change = 71500 - 70500 = 1000
        $this->assertSame(1000.0, $result['change_amount']);
        $this->assertGreaterThan(0, $result['change_percent']);
        $this->assertSame(70500.0, $result['prev_close']);
    }

    /** @test */
    public function testCalculate_NegativeChange(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '70500', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'openPrice' => '71000', 'highPrice' => '71000', 'lowPrice' => '70000', 'closePrice' => '71000', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $result = $this->calculator->calculate('005930', 70000.0);

        // prevClose = 71000 (index 1), change = 70000 - 71000 = -1000
        $this->assertSame(-1000.0, $result['change_amount']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    /** @test */
    public function testCalculate_NoPrevClose_ReturnsZero(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]);  // 빈 응답

        $result = $this->calculator->calculate('005930', 71000.0);

        $this->assertSame(0.0, $result['change_amount']);
        $this->assertSame(0.0, $result['change_percent']);
        $this->assertNull($result['prev_close']);
    }

    // ──────────────────────────────────────────────────────────────────
    // getPrevClose() — 캐시 hit/miss
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testGetPrevClose_CacheHit_NoApiCall(): void
    {
        Cache::put('toss_prev_close_005930', 70500.0, 3600);

        $this->clientMock->expects($this->never())->method('get');

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertSame(70500.0, $prevClose);
    }

    /** @test */
    public function testGetPrevClose_CacheMiss_CallsApiAndCaches(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정 (KR 오늘봉 존재 = 분기 1)
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // KR 분기 1 은 candles + price-limits 두 경로를 모두 호출한다(경로별 응답 분기).
        $this->clientMock
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) {
                if ($path === self::PRICE_LIMITS) {
                    // (상한가 92,300 + 하한가 49,700)/2 = 71,000 (당일 거래소 기준가)
                    return ['result' => ['upperLimitPrice' => '92300', 'lowerLimitPrice' => '49700']];
                }
                // candles: 국내 등락 기준가는 이 종가가 아니라 price-limits 로 대체된다
                return ['result' => ['candles' => [
                    ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '70800', 'volume' => '200', 'currency' => 'KRW'],
                    ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                ]]];
            });

        $prevClose = $this->calculator->getPrevClose('005930');

        // KR 오늘봉 존재(분기 1) → 기준가 = price-limits (92,300+49,700)/2 = 71,000 (candles[1]=70,500 아님)
        $this->assertSame(71000.0, $prevClose);

        // 두 번째 호출은 캐시 히트 → API 미호출
        $prevClose2 = $this->calculator->getPrevClose('005930');
        $this->assertSame(71000.0, $prevClose2);
    }

    // ──────────────────────────────────────────────────────────────────
    // 국내 기준가 = /api/v1/price-limits (상한가+하한가)/2 (토스 앱 등락 정합)
    //   토스 앱 등락은 candles 종가가 아니라 '당일 거래소 기준가'에 대해 계산되며,
    //   한국 가격제한폭이 기준가에 대칭이라 (upper+lower)/2 로만 얻는다.
    //   분기 1·2(오늘봉/라이브)만 교체 · 분기 3(개장 전·장마감)은 candles 유지 · US 무영향.
    // ──────────────────────────────────────────────────────────────────

    private const PRICE_LIMITS = '/api/v1/price-limits';

    /**
     * @test
     * KR 라이브(정규장, 오늘봉 미생성=분기 2): 기준가 = price-limits (상한+하한)/2.
     * 실증 000660: (2,486,000 + 1,340,000)/2 = 1,913,000 (candles[0] 종가 1,941,000 아님).
     */
    public function testGetPrevClose_KrUsesPriceLimitsReference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->clientMock->method('get')->willReturnCallback(function (string $path) {
            if ($path === self::PRICE_LIMITS) {
                return ['result' => ['upperLimitPrice' => '2486000', 'lowerLimitPrice' => '1340000']];
            }
            // 최신 봉 = 7/14(어제) → 오늘(7/15) 봉 없음 + 정규장 = 라이브 분기 2
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '1941000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '1900000', 'currency' => 'KRW'],
            ]]];
        });

        // 기준가 = (2,486,000+1,340,000)/2 = 1,913,000 (candles 종가 대체 검증)
        $this->assertSame(1913000.0, $this->calculator->getPrevClose('000660'));
    }

    /**
     * @test
     * 등락률까지 — 005930 기준가 263,000, 현재가 279,500 → 약 +6.27% (토스 앱 일치).
     */
    public function testCalculate_KrPriceLimitsReference_Percent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->clientMock->method('get')->willReturnCallback(function (string $path) {
            if ($path === self::PRICE_LIMITS) {
                return ['result' => ['upperLimitPrice' => '341500', 'lowerLimitPrice' => '184500']];
            }
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '270000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '265000', 'currency' => 'KRW'],
            ]]];
        });

        // 기준가 (341,500+184,500)/2 = 263,000, 현재가 279,500 → +6.27%
        $result = $this->calculator->calculate('005930', 279500.0);
        $this->assertSame(263000.0, $result['prev_close']);
        $this->assertEqualsWithDelta(6.27, $result['change_percent'], 0.05);
    }

    /**
     * @test
     * price-limits 빈 응답 → candles 기반 기준가로 graceful 폴백(캐시 기아·NULL 방지).
     */
    public function testGetPrevClose_KrPriceLimitsEmpty_FallsBackToCandle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->clientMock->method('get')->willReturnCallback(function (string $path) {
            if ($path === self::PRICE_LIMITS) {
                return [];  // 빈 응답 → candles 폴백
            }
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '71000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '70500', 'currency' => 'KRW'],
            ]]];
        });

        // 라이브 분기 2 → candles[0](어제 종가) 71,000 폴백 (price-limits 실패해도 NULL 아님)
        $this->assertSame(71000.0, $this->calculator->getPrevClose('005930'));
    }

    /**
     * @test
     * 개장 전(장마감, 분기 3) 보존: candles[1](전전일) 기준가 유지 + price-limits 미호출.
     * price-limits 는 실시간 당일 기준가만 주므로 개장 전 호출 시 다음 거래일 기준가로 0% 회귀 위험.
     */
    public function testGetPrevClose_KrPreOpen_DoesNotUsePriceLimits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');

        $calledPaths = [];
        $this->clientMock->method('get')->willReturnCallback(function (string $path) use (&$calledPaths) {
            $calledPaths[] = $path;
            if ($path === self::PRICE_LIMITS) {
                // 만약 (잘못) 호출되면 71,000 이 되어 어제 등락이 깨진다 — 아래 assert 로 미호출 확인
                return ['result' => ['upperLimitPrice' => '92300', 'lowerLimitPrice' => '49700']];
            }
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '71000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-13T00:00:00.000+09:00', 'closePrice' => '70500', 'currency' => 'KRW'],
            ]]];
        });

        // 장마감(개장 전) → candles[1](전전일) 70,500 유지 = 어제 하루 등락 보존
        $this->assertSame(70500.0, $this->calculator->getPrevClose('005930'));
        $this->assertNotContains(self::PRICE_LIMITS, $calledPaths, '개장 전엔 price-limits 를 호출하면 안 된다');
    }

    // ──────────────────────────────────────────────────────────────────
    // price-limits 롤오버 가드 (장마감 후 조기 롤오버 → candles[1] 폴백)
    //   장마감 후 일부 종목(특히 ETF)의 price-limits 가 다음 거래일 기준가(≈오늘 종가)로
    //   먼저 갱신되면 오늘 등락이 0% 로 뭉개진다. price-limits 기준가가 오늘 정규장 종가와
    //   사실상 같으면(ε<0.1%) 롤오버로 보고 candles[1](어제 종가)로 폴백한다.
    //   판정용 오늘 종가 = getKrRegularClose()(분봉 plateau, 장마감·거래일에만 값 존재).
    // ──────────────────────────────────────────────────────────────────

    /**
     * 장마감 3-경로(1d candles · price-limits · 1m plateau) 응답을 한 콜백으로 만든다.
     *
     * @param array{0:string,1:string} $daily1d   [오늘 종가, 어제 종가]
     * @param array{0:string,1:string} $limits    [상한가, 하한가]
     * @param string                   $plateau   1m plateau close(=오늘 정규장 종가)
     */
    private function krClosedResponder(array $daily1d, array $limits, string $plateau, string $date = '2026-07-15'): \Closure
    {
        [$todayClose, $yesterdayClose] = $daily1d;
        [$upper, $lower]               = $limits;
        $yesterday                     = date('Y-m-d', strtotime($date . ' -1 day'));

        return function (string $path, array $query) use ($todayClose, $yesterdayClose, $upper, $lower, $plateau, $date, $yesterday) {
            if ($path === self::PRICE_LIMITS) {
                return ['result' => ['upperLimitPrice' => $upper, 'lowerLimitPrice' => $lower]];
            }
            if (($query['interval'] ?? null) === '1m') {
                // getKrRegularClose 용 plateau (15:31~15:40)
                return $this->krMinuteCandles($date, [['1531', $plateau], ['1535', $plateau], ['1540', $plateau]]);
            }
            // 1d: 오늘봉 존재 → 분기 1, candles[1] = 어제 종가
            return ['result' => ['candles' => [
                ['timestamp' => "{$date}T00:00:00.000+09:00", 'closePrice' => $todayClose, 'currency' => 'KRW'],
                ['timestamp' => "{$yesterday}T00:00:00.000+09:00", 'closePrice' => $yesterdayClose, 'currency' => 'KRW'],
            ]]];
        };
    }

    /**
     * @test
     * SOL(0167A0, ETF) 롤오버: price-limits 기준가 20,600 = 오늘 종가 20,600 → 롤오버 판정
     * → candles[1](어제 종가) 18,830 폴백 → +9.40% (토스 실측 일치).
     */
    public function testGetPrevClose_KrPriceLimitRolledOver_FallsBackToYesterdayCandle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));  // 장마감
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        // 1d: 오늘 종가 20,600 · 어제 종가 18,830 / price-limits (26,780+14,420)/2 = 20,600(=오늘 종가) / plateau 20,600
        $this->clientMock->method('get')->willReturnCallback(
            $this->krClosedResponder(['20600', '18830'], ['26780', '14420'], '20600')
        );

        // 롤오버 → price-limits 20,600 버리고 candles[1] 18,830 유지
        $this->assertSame(18830.0, $this->calculator->getPrevClose('0167A0'));

        $result = $this->calculator->calculate('0167A0', 20600.0);
        $this->assertSame(18830.0, $result['prev_close']);
        $this->assertEqualsWithDelta(9.40, $result['change_percent'], 0.05);
    }

    /**
     * @test
     * 삼성(005930) 미롤오버: price-limits 263,000 vs 오늘 종가 279,500 → 5.9% 차이(>ε)
     * → 롤오버 아님 → price-limits 263,000 유지 → +6.27%.
     */
    public function testGetPrevClose_KrPriceLimitNotRolledOver_Samsung_KeepsPriceLimits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        // price-limits (341,500+184,500)/2 = 263,000 · 오늘 종가(plateau) 279,500 → 미롤오버
        $this->clientMock->method('get')->willReturnCallback(
            $this->krClosedResponder(['279500', '265000'], ['341500', '184500'], '279500')
        );

        $this->assertSame(263000.0, $this->calculator->getPrevClose('005930'));

        $result = $this->calculator->calculate('005930', 279500.0);
        $this->assertSame(263000.0, $result['prev_close']);
        $this->assertEqualsWithDelta(6.27, $result['change_percent'], 0.05);
    }

    /**
     * @test
     * 하이닉스(000660) 미롤오버: price-limits 1,913,000 vs 오늘 종가 2,082,000 → 8.1% 차이(>ε)
     * → 롤오버 아님 → price-limits 1,913,000 유지 → +8.83%.
     */
    public function testGetPrevClose_KrPriceLimitNotRolledOver_Hynix_KeepsPriceLimits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        // price-limits (2,486,000+1,340,000)/2 = 1,913,000 · 오늘 종가(plateau) 2,082,000 → 미롤오버
        $this->clientMock->method('get')->willReturnCallback(
            $this->krClosedResponder(['2082000', '1900000'], ['2486000', '1340000'], '2082000')
        );

        $this->assertSame(1913000.0, $this->calculator->getPrevClose('000660'));

        $result = $this->calculator->calculate('000660', 2082000.0);
        $this->assertSame(1913000.0, $result['prev_close']);
        $this->assertEqualsWithDelta(8.83, $result['change_percent'], 0.05);
    }

    /** @test */
    public function testGetPrevClose_OnlyOneCandle_ReturnsNull(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertNull($prevClose);
    }

    /** @test */
    public function testGetPrevClose_ReverseOrderCandles_CorrectlyPicksOldest(): void
    {
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        // 실측과 동일: 최신 먼저 (index 0 = 최신, index 1 = 전일)
        // 정렬 후 index 0 = 최신, index 1 = 전일 → prevClose = index 1 closePrice
        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // 정렬 후 index 1(오래된 봉) closePrice = 70500 이 prevClose
        $this->assertSame(70500.0, $prevClose);
    }

    /** @test */
    public function testGetPrevClose_EmptyApiResponse_ReturnsNull(): void
    {
        $this->clientMock
            ->method('get')
            ->willReturn([]);

        $prevClose = $this->calculator->getPrevClose('005930');

        $this->assertNull($prevClose);
    }

    // ──────────────────────────────────────────────────────────────────
    // 등락률 계산 정확도
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testCalculate_ChangePercentPrecision(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        // 현재가 71500, prevClose 71000 → change_percent = 500/71000*100 ≈ 0.7042
        $result = $this->calculator->calculate('005930', 71500.0);

        $expected = round((71500.0 - 71000.0) / 71000.0 * 100.0, 4);
        $this->assertSame($expected, $result['change_percent']);
    }

    /** @test */
    public function testCalculate_ZeroChange_WhenCurrentEqualsClose(): void
    {
        Cache::put('toss_prev_close_005930', 71000.0, 3600);

        $result = $this->calculator->calculate('005930', 71000.0);

        $this->assertSame(0.0, $result['change_amount']);
        $this->assertSame(0.0, $result['change_percent']);
    }

    // ──────────────────────────────────────────────────────────────────
    // "오늘 진행중 봉" 판별 → prevClose 선택 (부호 반전 버그 재발 방지)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @test
     * 미국 프리마켓~개장 직후: 토스에 오늘 일봉이 아직 없어 [전일, 전전일] 이 온다.
     * 최신 봉(index 0 = 전일)이 "오늘 봉"이 아니므로 prevClose = index 0(전일).
     *
     * 실제 버그(MU 7/10): 991.64(7/9)/948.80(7/8) 인데 무조건 index 1(948.80)을
     * 기준가로 잡아 등락 부호가 반전됐다. 이 케이스가 핵심 재발 방지.
     */
    public function testGetPrevClose_UsNoTodayBar_UsesLatestBar(): void
    {
        // NY 기준 오늘 = 2026-07-10 프리마켓(08:00 ET). 최신 봉은 7/9 (오늘 봉 없음).
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        // 미국 프리마켓 = 라이브 세션(lastPrice 진행) → candles[0](어제 종가) 기준 유지.
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // 오늘(7/10) 봉이 없으므로 최신 봉(7/9 = 991.64)이 prevClose
        $this->assertSame(991.64, $prevClose);
    }

    /**
     * @test
     * 위 케이스의 계산 결과까지 — 현재가 < 991.64 이면 반드시 하락(음수) 이어야 한다.
     * 옛 로직(948.80 기준)이면 상승으로 반전됐을 값.
     */
    public function testCalculate_UsNoTodayBar_SignNotFlipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        // 현재가 969.03 (991.64 대비 약 -2.28%) — 옛 로직이면 948.80 기준 +2.13% 로 반전
        $result = $this->calculator->calculate('MU', 969.03);

        $this->assertSame(991.64, $result['prev_close']);
        $this->assertLessThan(0, $result['change_amount']);
        $this->assertLessThan(0, $result['change_percent']);
    }

    /**
     * @test
     * 미국 장중/마감 후: 오늘 봉이 존재해 [당일, 전일] 이 온다.
     * 최신 봉(index 0)이 "오늘 봉"이므로 prevClose = index 1(전일).
     */
    public function testGetPrevClose_UsTodayBarPresent_UsesPrevBar(): void
    {
        // NY 기준 오늘 = 2026-07-10 장중(11:00 ET). 최신 봉이 7/10 = 오늘 봉.
        Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00', 'America/New_York'));

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '150', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // 오늘(7/10) 봉이 진행중 → 전일 봉(7/9 = 991.64)이 prevClose
        $this->assertSame(991.64, $prevClose);
    }

    /**
     * @test
     * 국내 개장 전(장마감): 오늘 봉이 아직 없어 [전일, 전전일] 이 온다.
     * lastPrice 가 어제 종가에 고정된 구간이므로 candles[0](어제 종가)을 기준가로 쓰면 0.00% 로 초기화된다.
     * → 장마감이면 candles[1](전전일 종가) 기준 = "어제 하루 등락"이 유지되어야 한다(토스 앱 동일).
     */
    public function testGetPrevClose_KrPreOpen_UsesPrevPrevBar_KeepsYesterdayChange(): void
    {
        // KST 기준 오늘 = 2026-07-10 장전(08:00 KST, 정규장 아님). 최신 봉은 7/9.
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        // 현재가 = 어제(7/9) 종가 71000 에 고정 → 전전일(7/8=70500) 대비 = 어제 하루 등락(+0.71%)
        $prevClose = $this->calculator->getPrevClose('005930');
        $this->assertSame(70500.0, $prevClose);

        $result = $this->calculator->calculate('005930', 71000.0);
        $this->assertSame(500.0, $result['change_amount']);   // 0% 초기화가 아니라 어제 등락 유지
        $this->assertGreaterThan(0, $result['change_percent']);
    }

    /**
     * @test
     * 국내 정규장 중인데 오늘 봉이 아직 안 생긴 순간(개장 직후) → candles[0](어제 종가) 기준 = 오늘 등락.
     */
    public function testGetPrevClose_KrRegularSessionNoTodayBar_UsesLatestBar(): void
    {
        // KST 09:05 정규장, 최신 봉은 아직 7/9(오늘 봉 미생성).
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:05:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        // 정규장 진행 중 → 어제(7/9=71000) 종가 기준
        $this->assertSame(71000.0, $this->calculator->getPrevClose('005930'));
    }

    /**
     * @test
     * 미국 주말/휴장(장마감): 오늘 봉 없음 → candles[1](전전일) 기준 = 직전 거래일 하루 등락 유지.
     * 옛 로직(candles[0])이면 현재가=금요일 종가와 같아 0.00% 로 초기화됐다.
     */
    public function testGetPrevClose_UsMarketClosed_UsesPrevPrevBar(): void
    {
        // NY 기준 토요일(2026-07-11) — 장마감. 최신 봉은 금(7/10).
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        // 장마감 → 전전일(7/9=991.64) 기준 → 현재가=금 종가 985 대비 = 금요일 하루 등락(음수)
        $this->assertSame(991.64, $this->calculator->getPrevClose('MU'));
        $result = $this->calculator->calculate('MU', 985.00);
        $this->assertLessThan(0, $result['change_amount']);
    }

    /**
     * @test
     * 엣지: currency 누락 → KRW 기본값(Asia/Seoul) 으로 '오늘' 판별해야 한다.
     * 오늘 봉이 존재하면 전일 봉을 prevClose 로 선택.
     */
    public function testGetPrevClose_CurrencyMissing_DefaultsToKrwTimezone(): void
    {
        // KST 기준 오늘 = 2026-07-10 장중(10:00 KST). 최신 봉이 7/10 = 오늘 봉.
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Seoul'));

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000+09:00', 'closePrice' => '71500', 'volume' => '150'],
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // currency 없음 → KRW/Asia/Seoul 기본 → 오늘(7/10) 봉 진행중 → 전일(7/9 = 71000)
        $this->assertSame(71000.0, $prevClose);
    }

    /**
     * @test
     * 엣지: US 종목 + currency 누락 + 프리마켓 → 심볼로 시장 판별 폴백(NY TZ) → prevClose=전일.
     * currency 결측 시 서울TZ 로 폴백하면 오늘봉 판별이 어긋나 부호반전을 재발시킬 수 있어,
     * TossSymbolMapper::market() 로 US 를 판별해 America/New_York 로 날짜 비교해야 한다.
     */
    public function testGetPrevClose_UsCurrencyMissing_FallsBackToSymbolMarket(): void
    {
        // NY 기준 오늘 = 2026-07-10 프리마켓(08:00 ET). 최신 봉은 7/9 (오늘 봉 없음).
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'America/New_York'));
        $this->sessionMock->method('getUsSession')->willReturn('프리마켓');

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('MU');

        // currency 누락이어도 심볼(MU=US)로 NY TZ 판별 → 오늘(7/10) 봉 없음 → 전일(7/9 = 991.64)
        $this->assertSame(991.64, $prevClose);
    }

    // ──────────────────────────────────────────────────────────────────
    // 전일종가 캐시 TTL — US 세션 stale 버그 수정 회귀 가드
    //   US: '다음 16:05 ET(정규장 마감 직후)' 만료  ·  KR: 'KST 자정' 만료(현행 유지)
    //   버그: US 정규장이 KST 자정을 걸쳐 진행 → 자정 TTL이면 새벽 캐시가 다음 밤 세션까지
    //         살아남아 한 거래일 stale → 등락 부호까지 반전(MU 7/14 실측).
    //
    //   주의: US 브랜치는 네이티브 new \DateTime('today 16:05')·time() 를 써
    //         Carbon::setTestNow 로 고정되지 않는다 → 동일 알고리즘을 재현해 대조한다(실시간 안전).
    // ──────────────────────────────────────────────────────────────────

    /**
     * @test
     * US 종목 TTL 이 '다음 16:05 ET' 만료 기준인지 — KST 자정 기준이 아님을 함께 확인.
     */
    public function testFetchAndCache_UsTtl_ExpiresAtNext1605Et(): void
    {
        // 라이브 세션(정규장) → 1·2번 분기 TTL(16:05 ET) 경로임을 고정.
        $this->sessionMock->method('getUsSession')->willReturn('정규장');

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-08T00:00:00.000-04:00', 'closePrice' => '948.80', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('MU');

        $this->assertNotNull($capturedTtl, 'US 전일종가는 캐시에 저장되어야 한다');

        // 프로덕션과 동일 알고리즘 재현 (다음 16:05 ET, 300초 하한)
        $nyTz   = new \DateTimeZone('America/New_York');
        $target = new \DateTime('today 16:05', $nyTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 16:05', $nyTz);
        }
        $expectedEtTtl = max($target->getTimestamp() - time(), 300);

        $this->assertGreaterThanOrEqual(300, $capturedTtl, 'TTL 하한 300초');
        $this->assertEqualsWithDelta($expectedEtTtl, $capturedTtl, 2, 'US TTL 은 다음 16:05 ET 만료 기준이어야 한다');

        // KST 자정 TTL 과는 명확히 다르다 (버그 회귀 가드) — 두 경계는 항상 5시간 이상 벌어진다.
        $kstMidnight = new \DateTime('tomorrow', new \DateTimeZone('Asia/Seoul'));
        $kstTtl      = max($kstMidnight->getTimestamp() - time(), 300);
        $this->assertNotEqualsWithDelta($kstTtl, $capturedTtl, 3600, 'US TTL 이 KST 자정 기준이면 안 된다');
    }

    /**
     * @test
     * KR 종목 TTL 은 여전히 KST 자정 만료인지 (US 분기가 국내로 새지 않았는지 회귀 가드).
     * secondsUntilKstMidnight() 는 Carbon::now('Asia/Seoul') 기반 → setTestNow 로 고정 가능.
     */
    public function testFetchAndCache_KrTtl_StillExpiresAtKstMidnight(): void
    {
        // KST 10:00 고정 → 다음 자정(11일 00:00)까지 14시간 = 50400초
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Seoul'));

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('005930');

        // 10:00 → 다음 KST 자정까지 14h = 50400초 (KST 자정 만료, 16:05 ET 아님)
        $this->assertSame(50400, $capturedTtl, 'KR TTL 은 여전히 KST 자정 만료여야 한다');
    }

    // ──────────────────────────────────────────────────────────────────
    // 3번 분기(장마감 기준가) TTL — '다음 개장 시각까지'만 유효
    //   개장 순간 기준가가 어제 종가로 바뀌어야 하므로, 장마감 캐시가 개장 이후까지 살아남으면
    //   전전일 기준으로 stale → 부호반전(H-3 교훈). KR=다음 09:00 KST · US=다음 09:30 ET.
    // ──────────────────────────────────────────────────────────────────

    /**
     * @test
     * KR 개장 전(장마감) 기준가 TTL 은 다음 09:00 KST 만료여야 한다(자정 아님).
     */
    public function testFetchAndCache_KrClosedTtl_ExpiresAtNextKrOpen(): void
    {
        $this->sessionMock->method('getKrSession')->willReturn('장마감');

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-09T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-07-08T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('005930');

        $this->assertNotNull($capturedTtl);

        // 프로덕션과 동일 알고리즘 재현 (다음 09:00 KST, 300초 하한)
        $seoulTz = new \DateTimeZone('Asia/Seoul');
        $target  = new \DateTime('today 09:00', $seoulTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 09:00', $seoulTz);
        }
        $expected = max($target->getTimestamp() - time(), 300);

        $this->assertGreaterThanOrEqual(300, $capturedTtl);
        $this->assertEqualsWithDelta($expected, $capturedTtl, 2, 'KR 장마감 TTL 은 다음 09:00 KST 만료여야 한다');

        // KST 자정 TTL 과는 다르다 (장마감 분기가 1번 분기로 새지 않았는지 가드)
        $kstMidnight = new \DateTime('tomorrow', $seoulTz);
        $kstTtl      = max($kstMidnight->getTimestamp() - time(), 300);
        $this->assertNotEqualsWithDelta($kstTtl, $capturedTtl, 60, 'KR 장마감 TTL 이 자정 기준이면 안 된다');
    }

    /**
     * @test
     * US 휴장(장마감) 기준가 TTL 은 다음 09:30 ET 만료여야 한다(16:05 ET 아님).
     */
    public function testFetchAndCache_UsClosedTtl_ExpiresAtNextUsOpen(): void
    {
        $this->sessionMock->method('getUsSession')->willReturn('장마감');

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->clientMock
            ->method('get')
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-07-10T00:00:00.000-04:00', 'closePrice' => '985.00', 'volume' => '200', 'currency' => 'USD'],
                        ['timestamp' => '2026-07-09T00:00:00.000-04:00', 'closePrice' => '991.64', 'volume' => '100', 'currency' => 'USD'],
                    ],
                ],
            ]);

        $this->calculator->getPrevClose('MU');

        $this->assertNotNull($capturedTtl);

        // 프로덕션과 동일 알고리즘 재현 (다음 09:30 ET, 300초 하한)
        $nyTz   = new \DateTimeZone('America/New_York');
        $target = new \DateTime('today 09:30', $nyTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 09:30', $nyTz);
        }
        $expected = max($target->getTimestamp() - time(), 300);

        $this->assertGreaterThanOrEqual(300, $capturedTtl);
        $this->assertEqualsWithDelta($expected, $capturedTtl, 2, 'US 장마감 TTL 은 다음 09:30 ET 만료여야 한다');

        // 16:05 ET TTL 과는 다르다 (장마감 분기가 2번 분기로 새지 않았는지 가드)
        $close1605 = new \DateTime('today 16:05', $nyTz);
        if ($close1605->getTimestamp() <= time()) {
            $close1605 = new \DateTime('tomorrow 16:05', $nyTz);
        }
        $ttl1605 = max($close1605->getTimestamp() - time(), 300);
        $this->assertNotEqualsWithDelta($ttl1605, $capturedTtl, 60, 'US 장마감 TTL 이 16:05 ET 기준이면 안 된다');
    }

    // ──────────────────────────────────────────────────────────────────
    // getKrRegularClose() — KR 정규장 마감 후 현재가 = 오늘 정규장 종가 고정
    //   시간외 lastPrice 무시, 토스 앱 종가 표시와 정합. 정규장 중·휴장·개장 전·US 는 미적용.
    //
    //   종가 = 마감(15:30) 직후 평탄 구간(15:31~15:40) 1m 분봉 close 의 최빈값.
    //   '지난 1분봉' close 는 확정 과거값이라 시간외 드리프트에도 불변 → 재시작·콜드스타트 재추출도 동일.
    //   15:30 이하(연속 체결 마지막가 ≠ 종가) · 15:41~(시간외단일가 드리프트)는 제외한다.
    // ──────────────────────────────────────────────────────────────────

    /**
     * 국내 1m 분봉 응답 헬퍼: [HHMM(KST) => closePrice] 를 오늘(givenDate) 봉으로 만든다.
     *
     * @param array<int,array{0:string,1:string}> $bars  ['1531','2082000'] 형태 목록
     */
    private function krMinuteCandles(string $date, array $bars): array
    {
        $candles = [];
        foreach ($bars as [$hhmm, $close]) {
            $h = substr($hhmm, 0, 2);
            $m = substr($hhmm, 2, 2);
            $candles[] = [
                'timestamp'  => "{$date}T{$h}:{$m}:00.000+09:00",
                'openPrice'  => $close,
                'highPrice'  => $close,
                'lowPrice'   => $close,
                'closePrice' => $close,
                'volume'     => '10',
                'currency'   => 'KRW',
            ];
        }

        return ['result' => ['candles' => $candles]];
    }

    /**
     * @test
     * KR 장마감(오늘 거래일) + 15:31~15:40 plateau → 정규장 종가 반환.
     * 실측(000660): 15:31~15:40 close = 2,082,000(마감 동시호가 체결가). 15:30 연속(2,093,000)·
     * 15:41~ 시간외단일가 드리프트(2,078,000→…)는 제외되어 시간외 lastPrice 대신 2,082,000 고정.
     */
    public function testGetKrRegularClose_MinutePlateau_Returns000660Close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));  // 15:30 이후
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1528', '2095000'],  // 연속(≤15:30) — 제외
            ['1530', '2093000'],  // 연속 마지막가 — 제외
            ['1531', '2082000'],  // ← plateau
            ['1535', '2082000'],  // ← plateau
            ['1540', '2082000'],  // ← plateau(경계 포함)
            ['1541', '2078000'],  // 시간외단일가 드리프트 — 제외
            ['1550', '2070000'],  // 드리프트 — 제외
        ]));

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * 삼성전자(005930) 동일 패턴: 15:31~15:40 close = 279,500 → 종가 정확 추출.
     */
    public function testGetKrRegularClose_MinutePlateau_Returns005930Close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:10:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1530', '279000'],   // 연속 — 제외
            ['1531', '279500'],   // plateau
            ['1535', '279500'],   // plateau
            ['1540', '279500'],   // plateau
            ['1541', '279200'],   // 드리프트 — 제외
        ]));

        $this->assertSame(279500.0, $this->calculator->getKrRegularClose('005930'));
    }

    /**
     * @test
     * 평탄 구간에 이상 봉이 섞이고 시간외 드리프트 봉이 함께 와도 최빈값으로 종가 정확 추출.
     * (15:33 에 튄 2,085,000 한 봉이 있어도 mode = 2,082,000, 15:41~ 드리프트는 창 밖 제외.)
     */
    public function testGetKrRegularClose_DriftAndOutlierMixed_StillPicksClose(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00', 'Asia/Seoul'));  // 저녁 콜드스타트 상황
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1530', '2093000'],  // 연속 — 제외
            ['1531', '2082000'],  // plateau
            ['1532', '2082000'],  // plateau
            ['1533', '2085000'],  // 창 안 이상 봉(단발) — mode 로 무시
            ['1540', '2082000'],  // plateau
            ['1541', '2078000'],  // 드리프트 — 제외
            ['1545', '2070000'],  // 드리프트 — 제외
            ['1730', '2065000'],  // 늦은 시간외 — 제외
        ]));

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * 폴백: 1m plateau 판정 불가(분봉 취득 실패) → 1d 오늘봉 close 로 graceful 폴백(NULL 방지).
     */
    public function testGetKrRegularClose_MinuteFails_FallsBackToDailyClose(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        $this->clientMock->method('get')->willReturnCallback(function (string $path, array $query) {
            if (($query['interval'] ?? null) === '1m') {
                return [];  // 분봉 취득 실패 → 폴백 유도
            }
            // 1d 폴백: 오늘봉(7/15) close 존재
            return ['result' => ['candles' => [
                ['timestamp' => '2026-07-15T00:00:00.000+09:00', 'closePrice' => '2081000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-14T00:00:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
            ]]];
        });

        $this->assertSame(2081000.0, $this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * KR 정규장 중 → null(라이브 lastPrice 유지). /candles 호출도 없어야 한다.
     */
    public function testGetKrRegularClose_RegularSession_ReturnsNull(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('정규장');
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * KR 장마감 + 휴장일(거래일 아님) → null(현행 전일 마감 표시 유지). /candles 미호출.
     */
    public function testGetKrRegularClose_NonTradingDay_ReturnsNull(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 16:00:00', 'Asia/Seoul'));  // 토요일 가정
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(false);
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * KR 장마감 + 개장 전(오늘 15:31+ 봉 미생성, 최신 봉 = 어제) → null(현행 유지, 종가 고정 미적용).
     * 1m plateau 공집합 + 1d 폴백도 오늘봉 아님 → 최종 null.
     */
    public function testGetKrRegularClose_PreOpenNoTodayBar_ReturnsNull(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00:00', 'Asia/Seoul'));  // 개장 전
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);

        // 어제(7/14) 봉만 존재 — 1m·1d 어느 경로도 오늘(7/15) plateau/오늘봉 없음
        $this->clientMock->method('get')->willReturn([
            'result' => ['candles' => [
                ['timestamp' => '2026-07-14T15:31:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
                ['timestamp' => '2026-07-14T15:35:00.000+09:00', 'closePrice' => '1913000', 'currency' => 'KRW'],
            ]],
        ]);

        $this->assertNull($this->calculator->getKrRegularClose('000660'));
    }

    /**
     * @test
     * US 종목 → null(미국은 프리/애프터 시간외를 그대로 표시 — 종가 고정 미적용).
     */
    public function testGetKrRegularClose_UsSymbol_ReturnsNull(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $this->assertNull($this->calculator->getKrRegularClose('TSLA'));
    }

    /**
     * @test
     * 장마감·거래일에 종가 취득이 null(1m plateau + 1d 폴백 둘 다 빈응답=일시 실패)이면
     * sentinel(0) 을 장TTL 이 아니라 짧은 TTL(120초)로 저장해야 한다.
     * 장TTL 로 0 을 고착시키면 API 회복 후에도 다음 개장까지 종가 고정을 못 하는 자가치유 실패가 난다.
     */
    public function testGetKrRegularClose_FetchFails_CachesSentinelWithShortTtl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        $this->clientMock->method('get')->willReturn([]);  // 1m·1d 둘 다 빈응답 → close=null(일시 실패)

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->assertNull($this->calculator->getKrRegularClose('000660'));

        // 장TTL(다음 09:00 KST, 최소 수시간) 이 아니라 단TTL 120초
        $this->assertSame(120, $capturedTtl, '취득 실패 sentinel 은 짧은 TTL(120초)로 저장돼 곧 재시도되어야 한다');
    }

    /**
     * @test
     * 실값(분봉 plateau 성공)일 때는 다음 09:00 KST 장TTL 로 저장(드리프트 없는 확정 종가 → 저녁 내내 유지).
     */
    public function testGetKrRegularClose_FetchSucceeds_CachesWithLongTtl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        $this->clientMock->method('get')->willReturn($this->krMinuteCandles('2026-07-15', [
            ['1531', '2082000'],
            ['1535', '2082000'],
            ['1540', '2082000'],
        ]));

        $capturedTtl = null;
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnUsing(function ($key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;
            return true;
        });

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));

        // 프로덕션과 동일 알고리즘 재현 (다음 09:00 KST, 300초 하한) → 단TTL(120)이 아님
        $seoulTz = new \DateTimeZone('Asia/Seoul');
        $target  = new \DateTime('today 09:00', $seoulTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 09:00', $seoulTz);
        }
        $expected = max($target->getTimestamp() - time(), 300);

        $this->assertEqualsWithDelta($expected, $capturedTtl, 2, '실값은 다음 09:00 KST 장TTL 로 저장돼야 한다');
        $this->assertGreaterThan(120, $capturedTtl, '실값 TTL 은 단TTL(120)보다 길어야 한다');
    }

    /**
     * @test
     * 캐시 hit → /candles 미호출(장마감·거래일 게이트 통과 후 캐시 우선). 재시작 시 동일값 재사용 근거.
     */
    public function testGetKrRegularClose_CacheHit_NoApiCall(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:00:00', 'Asia/Seoul'));
        $this->sessionMock->method('getKrSession')->willReturn('장마감');
        $this->sessionMock->method('isKrTradingDay')->willReturn(true);
        Cache::put('toss_kr_regclose_000660', 2082000.0, 3600);

        $this->clientMock->expects($this->never())->method('get');

        $this->assertSame(2082000.0, $this->calculator->getKrRegularClose('000660'));
    }
}
