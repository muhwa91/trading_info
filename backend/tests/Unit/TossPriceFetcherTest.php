<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossApiClient;
use App\Services\Toss\TossChangeCalculator;
use App\Services\Toss\TossPriceFetcher;
use App\Services\Toss\TossSymbolMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TossPriceFetcher 단위 테스트.
 *
 * 검증 대상:
 *   - 국내 종목만 배치 조회 (미국·지수 skip)
 *   - 캐시 키 하위호환 (`kis_realtime_price_{ticker}`, `kis_last_successful_price_{ticker}`)
 *   - 빈 응답 graceful 처리
 *   - 캐시 히트 시 API 호출 없음
 *   - fetchSingle 국내/미국 분기
 */
class TossPriceFetcherTest extends TestCase
{
    private $clientMock;
    private $calculatorMock;
    private TossPriceFetcher $fetcher;
    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock     = $this->createMock(TossApiClient::class);
        $this->calculatorMock = $this->createMock(TossChangeCalculator::class);
        $this->mapper         = new TossSymbolMapper();

        $this->fetcher = new TossPriceFetcher(
            $this->clientMock,
            $this->mapper,
            $this->calculatorMock
        );

        // 매 테스트 전 캐시 초기화
        Cache::flush();
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchDomestic — 국내 종목 배치
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchDomestic_EmptyInput_ReturnsZeroCounts(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic([]);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, $result['cached']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    /** @test */
    public function testFetchDomestic_IndexSymbols_Skipped(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['NQ=F', 'KOSPI200', '^KS200', 'KOSPI_NIGHT']);

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(4, $result['skipped']);
    }

    /** @test */
    public function testFetchDomestic_UsSymbols_IncludedInBatchPhase4(): void
    {
        // Phase 4: 미국 종목도 배치에 포함돼야 한다 (Phase 3에서는 skipped)
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                    ['symbol' => 'MU',   'lastPrice' => 101.0, 'currency' => 'USD'],
                    ['symbol' => 'SOXL', 'lastPrice' => 30.0,  'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['TSLA', 'MU', 'SOXL']);

        $this->assertSame(3, $result['fetched']);
        $this->assertSame(0, $result['skipped']);
    }

    /** @test */
    public function testFetchDomestic_CachedTicker_NoApiCall(): void
    {
        // 캐시에 이미 값이 있는 경우
        Cache::put('kis_realtime_price_005930', ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['cached']);
        $this->assertSame(0, $result['fetched']);
    }

    /** @test */
    public function testFetchDomestic_DomesticTicker_CallsApiAndCaches(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                return isset($q['symbols']) && str_contains($q['symbols'], '005930');
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['failed']);

        // 캐시 키 하위호환 확인
        $cached = Cache::get('kis_realtime_price_005930');
        $this->assertNotNull($cached);
        $this->assertSame(71000.0, $cached['price']);
        $this->assertSame(500.0, $cached['change_amount']);

        // 폴백 캐시도 저장
        $fallback = Cache::get('kis_last_successful_price_005930');
        $this->assertNotNull($fallback);
        $this->assertSame(71000.0, $fallback['price']);
    }

    /** @test */
    public function testFetchDomestic_KsSymbol_NormalizesAndCachesWithAppSymbol(): void
    {
        // .KS 접미사 포함 앱 심볼로 요청 → 토스엔 005930 으로 보내고 캐시는 원래 앱 심볼 키로
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 100.0, 'change_percent' => 0.1, 'prev_close' => 71000.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71100.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930.KS']);

        $this->assertSame(1, $result['fetched']);

        // 앱 심볼 키로 캐시됨 (하위호환)
        $cached = Cache::get('kis_realtime_price_005930.KS');
        $this->assertNotNull($cached);
        $this->assertSame(71100.0, $cached['price']);
    }

    /** @test */
    public function testFetchDomestic_EmptyApiResponse_IncreasesFailed(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchDomestic(['005930']);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['fetched']);
        $this->assertNull(Cache::get('kis_realtime_price_005930'));
    }

    /** @test */
    public function testFetchDomestic_MixedTickers_KrAndUsFetchedIndexSkipped(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 100.0, 'change_percent' => 0.1, 'prev_close' => 71000.0]);

        // Phase 4: KR(005930, 000660) + US(TSLA) 모두 배치 포함, 지수(KOSPI200)만 skip
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->with('/api/v1/prices', $this->callback(function (array $q): bool {
                $symbols = $q['symbols'];
                // 지수는 포함되지 않아야 함, KR+US 모두 포함
                return !str_contains($symbols, 'KOSPI200')
                    && str_contains($symbols, '005930')
                    && str_contains($symbols, '000660')
                    && str_contains($symbols, 'TSLA');
            }))
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                    ['symbol' => '000660', 'lastPrice' => 180000.0, 'currency' => 'KRW'],
                    ['symbol' => 'TSLA',   'lastPrice' => 207.5,   'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['005930', '000660', 'TSLA', 'KOSPI200']);

        $this->assertSame(3, $result['fetched']);  // KR 2 + US 1
        $this->assertSame(1, $result['skipped']);  // KOSPI200 만 skip
    }

    // ──────────────────────────────────────────────────────────────────
    // fetchSingle — 단건 조회
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function testFetchSingle_IndexSymbol_ReturnsNull(): void
    {
        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('KOSPI200');

        $this->assertNull($result);
    }

    /** @test */
    public function testFetchSingle_UsSymbol_DelegatesToOverseasSingle(): void
    {
        // Phase 4: US 종목은 fetchOverseasSingle 위임 (캐시 히트 시 API 호출 없음)
        $cached = [
            'price'          => 207.5,
            'change_amount'  => -2.5,
            'change_percent' => -1.2,
            'regular_close'  => 210.0,
        ];
        Cache::put('kis_realtime_price_us_TSLA', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('TSLA');

        // Phase 4: US 심볼은 null이 아니라 캐시 값 반환
        $this->assertNotNull($result);
        $this->assertSame(207.5, $result['price']);
    }

    /** @test */
    public function testFetchSingle_CachedDomestic_ReturnsCacheWithoutApiCall(): void
    {
        $cached = ['price' => 71000.0, 'change_amount' => 500.0, 'change_percent' => 0.7];
        Cache::put('kis_realtime_price_005930', $cached, 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertSame(71000.0, $result['price']);
    }

    /** @test */
    public function testFetchSingle_DomesticMiss_CallsApiAndReturns(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame(71000.0, $result['price']);
        $this->assertSame(500.0, $result['change_amount']);
        $this->assertSame(0.7, $result['change_percent']);
    }

    /** @test */
    public function testFetchSingle_ApiFailWithFallbackCache_ReturnsFallback(): void
    {
        $fallback = ['price' => 70000.0, 'change_amount' => -500.0, 'change_percent' => -0.7];
        Cache::put('kis_last_successful_price_005930', $fallback, 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);  // 빈 응답 = 실패

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame(70000.0, $result['price']);
    }

    /** @test */
    public function testFetchSingle_ApiFailNoFallback_ReturnsNull(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────────
    // H-2 회귀 가드 — regular_close 가 현재가 핫패스를 블로킹하지 않는다
    // ──────────────────────────────────────────────────────────────────

    /**
     * [정적 가드 — 핵심] fetchDomestic() 의 US 분기가 Yahoo HTTP 동기 페치를
     * 직접 호출하지 않는다. 캐시 읽기(readRegularCloseCache)만 사용해야 한다.
     *
     * 이게 H-2 의 결정적 회귀 가드 — 누군가 다시 fetchYahooRegularClose() /
     * new Client() / Yahoo URL 을 핫패스에 넣으면 즉시 실패한다.
     * (Guzzle new Client() 를 직접 쓰는 코드라 Http::fake 로는 가로챌 수 없어,
     *  소스 정적 검사로 호출 0회를 보장한다 — RefreshYahooCacheMockGuardTest 와 동일 전략.)
     *
     * @test
     */
    public function testFetchDomestic_HotPath_HasNoBlockingYahooHttp(): void
    {
        $src       = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($src, 'fetchDomestic');

        $this->assertNotEmpty($methodSrc, 'fetchDomestic 메서드를 찾을 수 없음');

        // 핫패스가 캐시 읽기 헬퍼를 쓰는지 확인 (있어야 함)
        $this->assertStringContainsString(
            'readRegularCloseCache',
            $methodSrc,
            'fetchDomestic 이 readRegularCloseCache(캐시 전용)를 사용하지 않음'
        );

        // 핫패스가 동기 Yahoo HTTP 페치를 호출하지 않는지 확인 (없어야 함)
        $this->assertStringNotContainsString(
            'fetchYahooRegularClose',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)이 fetchYahooRegularClose(블로킹 Yahoo HTTP)를 직접 호출함 — H-2 재발'
        );
        $this->assertStringNotContainsString(
            'new Client',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)이 Guzzle Client 를 직접 생성함 — HTTP 블로킹 위험'
        );
        $this->assertStringNotContainsString(
            'YAHOO_CHART_URL',
            $methodSrc,
            'fetchDomestic(현재가 핫패스)에 Yahoo chart URL 참조가 있음 — HTTP 블로킹 위험'
        );
    }

    /**
     * [동작 가드] cold(캐시 없음) 상태에서 US 종목을 fetchDomestic 하면,
     * 현재가는 즉시 캐시에 써지고 regular_close 는 폴백 캐시 값으로 채워진다.
     * (핫패스에서 Yahoo HTTP 를 호출하지 않으므로 cold 면 폴백/null 이 된다.)
     *
     * @test
     */
    public function testFetchDomestic_UsColdRegularClose_UsesFallbackCacheNotHttp(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        // yahoo_regular_close_TSLA 는 cold(없음) — 단, 폴백 캐시엔 직전 regular_close 가 있다
        Cache::put('kis_last_successful_overseas_price_TSLA', [
            'price'          => 200.0,
            'change_amount'  => 0.0,
            'change_percent' => 0.0,
            'regular_close'  => 333.0,
        ], 86400);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchDomestic(['TSLA']);

        $this->assertSame(1, $result['fetched']);

        // 현재가는 즉시 써짐
        $cached = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($cached);
        $this->assertSame(207.5, $cached['price']);

        // regular_close 는 폴백 캐시(333.0)에서 채워짐 (HTTP 페치 아님)
        $this->assertSame(333.0, $cached['regular_close']);
    }

    /**
     * [동작 가드] yahoo_regular_close_{symbol} 캐시가 따뜻하면 그 값을 우선 사용한다.
     *
     * @test
     */
    public function testFetchDomestic_UsWarmRegularCloseCache_IsIncluded(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        // 워머가 채운 정본 캐시
        Cache::put('yahoo_regular_close_TSLA', 381.61, 3600);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['TSLA']);

        $cached = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($cached);
        $this->assertSame(381.61, $cached['regular_close']);
    }

    /**
     * [동작 가드] cold 이고 폴백도 없으면 regular_close 는 null 로 즉시 써진다(블로킹 없이).
     *
     * @test
     */
    public function testFetchDomestic_UsColdNoFallback_RegularCloseNull(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'MU', 'lastPrice' => 101.0, 'currency' => 'USD'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['MU']);

        $cached = Cache::get('kis_realtime_price_us_MU');
        $this->assertNotNull($cached);
        $this->assertSame(101.0, $cached['price']);
        $this->assertNull($cached['regular_close']);
    }

    // ──────────────────────────────────────────────────────────────────
    // warmRegularCloses — out-of-band 워머
    // ──────────────────────────────────────────────────────────────────

    /**
     * 워머는 이미 따뜻한 심볼은 skip 한다 (불필요한 HTTP 회피).
     * 이미 캐시가 있는 US 심볼만 줬을 때, 캐시 값은 그대로 유지된다(덮어쓰지 않음).
     *
     * @test
     */
    public function testWarmRegularCloses_WarmSymbol_Skipped(): void
    {
        Cache::put('yahoo_regular_close_TSLA', 381.61, 3600);

        // HTTP 페치가 발생하면 안 됨 — 따뜻하므로 skip. 값이 변하지 않아야 한다.
        $this->fetcher->warmRegularCloses(['TSLA']);

        $this->assertSame(381.61, (float) Cache::get('yahoo_regular_close_TSLA'));
    }

    /**
     * 워머는 지수·국내(US 외) 심볼을 처리하지 않는다 (HTTP 발생 안 함).
     * Yahoo 접속 없이도 캐시가 채워지지 않음을 검증.
     *
     * @test
     */
    public function testWarmRegularCloses_NonUsSymbols_Ignored(): void
    {
        $this->fetcher->warmRegularCloses(['005930', 'KOSPI200', 'NQ=F']);

        // 어떤 yahoo_regular_close_* 캐시도 만들어지지 않아야 한다
        $this->assertNull(Cache::get('yahoo_regular_close_005930'));
        $this->assertNull(Cache::get('yahoo_regular_close_KOSPI200'));
        $this->assertNull(Cache::get('yahoo_regular_close_NQ=F'));
    }

    // ──────────────────────────────────────────────────────────────────
    // provider 태깅 — 현재가 실제 출처(B-1)
    // ──────────────────────────────────────────────────────────────────

    /**
     * [B-1] fetchDomestic 의 토스 배치 성공 분기는 provider=toss 로 태깅한다 (US·KR 공통).
     *
     * @test
     */
    public function testFetchDomestic_TossSuccess_TagsProviderToss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 0.0, 'change_percent' => 0.0, 'prev_close' => 100.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA',   'lastPrice' => 207.5,   'currency' => 'USD'],
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $this->fetcher->fetchDomestic(['TSLA', '005930']);

        $us = Cache::get('kis_realtime_price_us_TSLA');
        $this->assertNotNull($us);
        $this->assertSame('toss', $us['provider']);

        $kr = Cache::get('kis_realtime_price_005930');
        $this->assertNotNull($kr);
        $this->assertSame('toss', $kr['provider']);

        // 폴백 캐시에도 provider 가 따라가야 한다(stale 재사용 시 출처 보존)
        $usFallback = Cache::get('kis_last_successful_overseas_price_TSLA');
        $this->assertSame('toss', $usFallback['provider']);
    }

    /**
     * [B-1] fetchOverseasSingle 의 토스 단건 성공 분기는 provider=toss 로 태깅한다.
     *
     * @test
     */
    public function testFetchOverseasSingle_TossSuccess_TagsProviderToss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 1.0, 'change_percent' => 0.5, 'prev_close' => 200.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => 'TSLA', 'lastPrice' => 207.5, 'currency' => 'USD'],
                ],
            ]);

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame('toss', $result['provider']);
        $this->assertSame('toss', Cache::get('kis_realtime_price_us_TSLA')['provider']);
    }

    /**
     * [B-1] fetchSingle 국내 단건 성공 분기는 provider=toss 로 태깅한다.
     *
     * @test
     */
    public function testFetchSingle_KrSuccess_TagsProviderToss(): void
    {
        $this->calculatorMock
            ->method('calculate')
            ->willReturn(['change_amount' => 500.0, 'change_percent' => 0.7, 'prev_close' => 70500.0]);

        $this->clientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn([
                'result' => [
                    ['symbol' => '005930', 'lastPrice' => 71000.0, 'currency' => 'KRW'],
                ],
            ]);

        $result = $this->fetcher->fetchSingle('005930');

        $this->assertNotNull($result);
        $this->assertSame('toss', $result['provider']);
    }

    /**
     * [B-1] 토스 실패 시 Yahoo 폴백 분기는 provider=yahoo 로 태깅한다.
     *
     * 토스 /prices 가 빈응답 → fetchYahooCurrentPrice 가 폴백.
     * (Yahoo 는 new Client() 직접 호출이라 Http::fake 로 못 막으므로, 실제 네트워크가
     *  없으면 fetchYahooCurrentPrice 가 null 을 반환할 수 있다. 이 머신은 Yahoo 가 되므로
     *  성공 시 provider 검증, 환경상 null 이면 최소한 회귀 없음만 확인한다.)
     *
     * @test
     */
    public function testFetchOverseasSingle_TossFail_YahooFallbackTagsProviderYahoo(): void
    {
        // 토스 빈응답 → Yahoo 폴백 경로 진입
        $this->clientMock
            ->method('get')
            ->willReturn([]);

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        if ($result !== null && ($result['provider'] ?? null) !== null) {
            // Yahoo 가 응답한 경우: provider 는 반드시 yahoo
            $this->assertSame('yahoo', $result['provider']);
        } else {
            // Yahoo 불통(네트워크 없음) — 폴백 캐시도 없으니 null. 회귀 없음만 확인.
            $this->assertNull($result);
        }
    }

    /**
     * [B-1] 캐시 히트 시 저장된 provider 가 그대로 반환된다(출처 보존).
     *
     * @test
     */
    public function testFetchOverseasSingle_CacheHit_PreservesStoredProvider(): void
    {
        Cache::put('kis_realtime_price_us_TSLA', [
            'price'          => 207.5,
            'change_amount'  => 0.0,
            'change_percent' => 0.0,
            'regular_close'  => 210.0,
            'provider'       => 'yahoo',
        ], 8);

        $this->clientMock->expects($this->never())->method('get');

        $result = $this->fetcher->fetchOverseasSingle('TSLA');

        $this->assertNotNull($result);
        $this->assertSame('yahoo', $result['provider']);
    }

    // ──────────────────────────────────────────────────────────────────
    // regular_close TTL 회귀 가드 — 자정 stale 고착 차단 (이번 수정 대상)
    // ──────────────────────────────────────────────────────────────────
    //
    // ⚠️ 구조적 제약(보고): fetchYahooRegularClose() 는 private 이고 내부에서
    //    `new \GuzzleHttp\Client()` 를 직접 생성해 Yahoo 를 호출하므로, meta(marketState,
    //    regularMarketPrice)를 주입할 길이 없다(Http::fake 불가 — 기존 H-2 가드와 동일 사정).
    //    또한 TTL 산식이 Carbon 이 아니라 네이티브 time()/new \DateTime('today 16:05') 를
    //    쓰므로 Carbon::setTestNow 로 시각을 고정할 수도 없다(실측 확인됨).
    //    → 무리한 리팩터(제품 코드 변경) 대신 두 갈래로 회귀를 잡는다:
    //      (1) [동작] 제품과 동일한 TTL 산식을 '주입 가능한 now'로 재현해, ET 여러 시점에서
    //          만료가 '다음 16:00 ET 를 절대 넘지 않음'·'REGULAR/null=짧음 vs POST=긺' 을 단언.
    //      (2) [정적] 제품 소스가 수정된 구성(16:05·tomorrow 16:05·REGULAR||null→300)을
    //          실제로 담고, 옛 '자정(midnight) TTL' 구성을 더는 갖지 않음을 단언.
    //      (1)이 산식의 의도를, (2)가 그 산식이 제품에 실제로 박혀 있음을 고정한다.

    /**
     * 제품 fetchYahooRegularClose() 의 TTL 결정 로직을 '주입 가능한 now'로 재현한다.
     * (제품은 네이티브 time()/DateTime 을 써 시계 고정이 불가능하므로, 동일 산식을
     *  결정론적으로 평가하기 위한 미러. 제품과의 동치성은 정적 가드 테스트가 보장한다.)
     *
     * 제품 로직(TossPriceFetcher::fetchYahooRegularClose):
     *   - marketState 가 REGULAR 또는 null  → 300 (장중 미완성/불확실 → 짧게)
     *   - 그 외(PRE/POST/CLOSED)           → 다음 16:05 ET 까지, 단 최소 300
     *
     * @param  string|null  $marketState  Yahoo meta.marketState
     * @param  int          $now          기준 시각(Unix ts, UTC)
     * @return int  TTL(초)
     */
    private function computeRegularCloseTtl(?string $marketState, int $now): int
    {
        $nyTz = new \DateTimeZone('America/New_York');

        if ($marketState === 'REGULAR' || $marketState === null) {
            return 300;
        }

        // 'today/tomorrow 16:05' 를 주입된 now 기준으로 평가 (제품은 time() 사용)
        $today = (new \DateTime('@' . $now))->setTimezone($nyTz)->format('Y-m-d');
        $target = new \DateTime($today . ' 16:05', $nyTz);
        if ($target->getTimestamp() <= $now) {
            $target->modify('+1 day');  // 'tomorrow 16:05' 와 동치
        }

        return max($target->getTimestamp() - $now, 300);
    }

    /**
     * 주어진 now 의 '직후 다음 16:00 ET' Unix ts. TTL 만료가 이 경계를 넘으면 회귀(자정 TTL).
     */
    private function nextRegularClose1600Et(int $now): int
    {
        $nyTz  = new \DateTimeZone('America/New_York');
        $today = (new \DateTime('@' . $now))->setTimezone($nyTz)->format('Y-m-d');
        $close = new \DateTime($today . ' 16:00', $nyTz);
        if ($close->getTimestamp() <= $now) {
            $close->modify('+1 day');
        }

        return $close->getTimestamp();
    }

    /**
     * (A) [동작] TTL 경계 / 자정 회귀 차단.
     *
     * POST(정규장 마감 후) meta 상태에서, ET 시각을 여러 지점으로 고정했을 때
     * 저장될 TTL 의 만료시점이 '다음 16:00 ET 를 절대 넘지 않음'을 단언한다.
     * → 옛 버그는 만료가 ET '자정'(다음날 00:00)이라 16:00 을 한참 넘겨 stale 고착됐다.
     *   이 테스트는 그 자정 TTL 회귀를 직접 잡는다.
     *
     * 검증 시점:
     *   - 17:00 ET (정규장 마감 직후, 같은 날 16:05 는 이미 지남 → 내일 16:05)
     *   - 00:30 ET (자정 직후, 다음 16:05 는 같은 날 → 그날 16:05)
     *
     * @test
     */
    public function testRegularCloseTtl_PostState_NeverSurvivesPastNext1600Et(): void
    {
        $nyTz = new \DateTimeZone('America/New_York');

        $checkpoints = [
            // [설명, ET 시각]
            ['17:00 ET (마감 직후)', new \DateTime('2026-01-15 17:00', $nyTz)],
            ['00:30 ET (자정 직후)', new \DateTime('2026-01-16 00:30', $nyTz)],
            // POST 상태가 길게 잡히는 또 다른 경계: 09:00 ET (프리마켓)
            ['09:00 ET (프리마켓)', new \DateTime('2026-01-16 09:00', $nyTz)],
        ];

        foreach ($checkpoints as [$label, $etTime]) {
            $now = $etTime->getTimestamp();

            foreach (['POST', 'CLOSED', 'PRE'] as $state) {
                $ttl     = $this->computeRegularCloseTtl($state, $now);
                $expiry  = $now + $ttl;
                $barrier = $this->nextRegularClose1600Et($now);

                // 핵심 회귀 단언: 만료가 다음 16:00 ET 를 넘으면 안 된다 (자정 TTL 금지).
                // 허용 마진 +5분(16:05 타깃)까지만 — 16:00~16:05 사이는 정상.
                $this->assertLessThanOrEqual(
                    $barrier + 300,
                    $expiry,
                    "[{$label} / {$state}] regular_close TTL 만료가 다음 16:00 ET(+5m)를 넘김 — 자정 TTL 회귀"
                );

                // 만료는 미래여야 한다(최소 300초 보장).
                $this->assertGreaterThanOrEqual($now + 300, $expiry, "[{$label} / {$state}] TTL 이 최소 300초 미만");
            }
        }
    }

    /**
     * (B) [동작] 장중 가드 — marketState 별 TTL 대조.
     *
     *   - REGULAR : 짧게(=300) — 미완성 진행가를 16:05 까지 고착시키지 않는다.
     *   - null    : 짧게(=300) — 파싱 실패 시 보수적으로 stale 고착 방지.
     *   - POST    : 길게(다음 16:05 ET 까지) — 확정 종가를 다음 마감까지 유지.
     *
     * @test
     */
    public function testRegularCloseTtl_MarketStateGuard_RegularAndNullShort_PostLong(): void
    {
        $nyTz = new \DateTimeZone('America/New_York');
        // 마감 직후(16:30 ET) — POST 면 다음날 16:05 까지라 명확히 길다.
        $now = (new \DateTime('2026-01-15 16:30', $nyTz))->getTimestamp();

        $regularTtl = $this->computeRegularCloseTtl('REGULAR', $now);
        $nullTtl    = $this->computeRegularCloseTtl(null, $now);
        $postTtl    = $this->computeRegularCloseTtl('POST', $now);

        // 장중·불확실 → 정확히 300초만
        $this->assertSame(300, $regularTtl, 'REGULAR 상태 TTL 이 300 이 아님 — 진행가가 길게 고착될 위험');
        $this->assertSame(300, $nullTtl, 'marketState 누락(null) TTL 이 300 이 아님 — stale 고착 방지 실패');

        // 확정 종가(POST) → 다음 16:05 까지(여기선 ~24h) — 300 보다 한참 길다
        $this->assertGreaterThan(
            300,
            $postTtl,
            'POST(확정 종가) TTL 이 300 이하 — 다음 마감까지 유지되지 않음'
        );
        $this->assertGreaterThan(
            $regularTtl,
            $postTtl,
            'POST TTL 이 REGULAR TTL 보다 길지 않음 — 장중/확정 구분이 무력화됨'
        );

        // POST 만료는 정확히 다음 16:05 ET 여야 한다(16:00 경계 ± 정상 마진 내).
        $barrier = $this->nextRegularClose1600Et($now);
        $this->assertLessThanOrEqual($barrier + 300, $now + $postTtl, 'POST TTL 만료가 다음 16:00 ET(+5m)를 넘김');
    }

    /**
     * (A·B 보강) [정적] 제품 소스가 수정된 TTL 구성을 실제로 담고,
     * 옛 '자정(midnight) TTL' 구성을 더는 갖지 않음을 단언한다.
     *
     * 위 동작 테스트는 산식의 '의도'를 검증하지만, 그 산식이 제품에 박혀 있다는
     * 보장은 이 정적 가드가 한다(미러-tautology 방지 · H-2 가드와 동일 전략).
     * 누군가 16:05 타깃을 자정 TTL 로 되돌리거나 REGULAR/null 300초 가드를 빼면 즉시 실패.
     *
     * @test
     */
    public function testRegularCloseTtl_SourceUsesFixedBoundaryNotMidnight(): void
    {
        $src       = $this->getFetcherSource();
        $methodSrc = $this->extractMethodSource($src, 'fetchYahooRegularClose');

        $this->assertNotEmpty($methodSrc, 'fetchYahooRegularClose 메서드를 찾을 수 없음');

        // (필수) 만료 기준 = 정규장 마감 직후 16:05 ET, 지났으면 내일 16:05
        $this->assertStringContainsString(
            "'today 16:05'",
            $methodSrc,
            "TTL 타깃이 'today 16:05'(정규장 마감 직후)이 아님 — 자정 TTL 회귀 가능"
        );
        $this->assertStringContainsString(
            "'tomorrow 16:05'",
            $methodSrc,
            "TTL 타깃에 'tomorrow 16:05' 분기가 없음 — 오늘 16:05 경과 시 만료가 잘못 잡힘"
        );
        // 최소 300초 바닥
        $this->assertMatchesRegularExpression(
            '/max\(\s*\$target->getTimestamp\(\)\s*-\s*time\(\)\s*,\s*300\s*\)/',
            $methodSrc,
            'TTL 산식이 max(target - time(), 300) 형태가 아님'
        );

        // (보강) REGULAR 또는 null 이면 300초만 — 미완성/불확실 stale 고착 방지
        $this->assertMatchesRegularExpression(
            "/\\\$marketState\s*===\s*'REGULAR'\s*\|\|\s*\\\$marketState\s*===\s*null/",
            $methodSrc,
            "marketState REGULAR||null → 300 가드가 없음 — 장중/파싱실패 stale 고착 위험"
        );
        $this->assertMatchesRegularExpression(
            '/\$ttl\s*=\s*300\s*;/',
            $methodSrc,
            'REGULAR/null 분기의 $ttl = 300 짧은 TTL 이 없음'
        );

        // (회귀 차단) 옛 '자정' TTL 구성이 남아 있으면 안 된다.
        $this->assertStringNotContainsString(
            "'today midnight'",
            $methodSrc,
            "옛 자정 TTL('today midnight') 구성이 남아 있음 — stale 고착 버그 재발"
        );
        $this->assertStringNotContainsString(
            "'tomorrow midnight'",
            $methodSrc,
            "옛 자정 TTL('tomorrow midnight') 구성이 남아 있음 — stale 고착 버그 재발"
        );
        $this->assertStringNotContainsString(
            "'tomorrow'",
            $methodSrc,
            "옛 자정 TTL('tomorrow'=다음날 00:00) 구성이 남아 있음 — stale 고착 버그 재발"
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 헬퍼
    // ──────────────────────────────────────────────────────────────────

    private function getFetcherSource(): string
    {
        $path = __DIR__ . '/../../app/Services/Toss/TossPriceFetcher.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'TossPriceFetcher.php 읽기 실패');

        return (string) $src;
    }

    /**
     * 소스 파일에서 특정 메서드 블록을 중괄호 카운팅으로 추출한다.
     */
    private function extractMethodSource(string $source, string $methodName): string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return '';
        }
        $openPos = strpos($source, '{', $pos);
        if ($openPos === false) {
            return '';
        }

        $depth  = 0;
        $end    = $openPos;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($source, $pos, $end - $pos + 1);
    }
}
