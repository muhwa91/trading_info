<?php

declare(strict_types=1);

namespace Tests\Unit;

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
    private TossChangeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(TossApiClient::class);
        $this->calculator = new TossChangeCalculator($this->clientMock, new TossSymbolMapper());

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
        // 최신 봉(06-24)이 "오늘 진행중 봉"인 시나리오 → 시간 고정
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'Asia/Seoul'));

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/candles', $this->callback(function (array $q): bool {
                return $q['symbol'] === '005930'
                    && $q['interval'] === '1d'
                    && $q['count'] === 2;
            }))
            ->willReturn([
                'result' => [
                    'candles' => [
                        ['timestamp' => '2026-06-24T00:00:00.000+09:00', 'closePrice' => '71000', 'volume' => '200', 'currency' => 'KRW'],
                        ['timestamp' => '2026-06-23T00:00:00.000+09:00', 'closePrice' => '70500', 'volume' => '100', 'currency' => 'KRW'],
                    ],
                ],
            ]);

        $prevClose = $this->calculator->getPrevClose('005930');

        // index 1 (오래된 봉)의 closePrice = 70500
        $this->assertSame(70500.0, $prevClose);

        // 두 번째 호출은 캐시 히트 → API 미호출
        $prevClose2 = $this->calculator->getPrevClose('005930');
        $this->assertSame(70500.0, $prevClose2);
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
     * 국내: 오늘 봉이 아직 없어 [전일, 전전일] 이 오는 경우 → 최신 봉(index 0) 사용.
     */
    public function testGetPrevClose_KrNoTodayBar_UsesLatestBar(): void
    {
        // KST 기준 오늘 = 2026-07-10 장전(08:00 KST). 최신 봉은 7/9.
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Asia/Seoul'));

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

        $prevClose = $this->calculator->getPrevClose('005930');

        // 오늘(7/10) 봉 없음 → 최신 봉(7/9 = 71000)이 prevClose
        $this->assertSame(71000.0, $prevClose);
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
}
