<?php

declare(strict_types=1);

namespace App\Services\Toss;

use App\Services\MarketSessionService;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 토스 /prices 응답에 없는 등락액·등락률을 직접 계산.
 *
 * 전략:
 *   - `/api/v1/candles?symbol=&interval=1d&count=2` 로 직전 완료봉 종가(prevClose) 취득
 *   - 캐시 키 `toss_prev_close_{symbol}` — TTL = 당일 자정까지(KST) 초
 *   - 종목당 1일 1~2회 조회면 충분
 *
 * 등락 계산:
 *   change_amount  = lastPrice − prevClose
 *   change_percent = change_amount / prevClose × 100
 *   부호는 차액 부호 자동 반영 (KIS sign 4/5 분기 불필요)
 *
 * 설계 §2.4 준거.
 * prevClose 없을 시 0 반환(graceful fallback) — 캐시 기아 시 UI 에 0% 표시.
 *
 * 보안:
 *   응답 캔들 값만 로그 (토큰·시크릿 없음 — TossApiClient 책임).
 */
class TossChangeCalculator
{
    /** 캐시 키 접두 */
    private const CACHE_PREFIX = 'toss_prev_close_';

    /** 국내 정규장 종가(현재가 고정용) 캐시 키 접두 */
    private const KR_CLOSE_PREFIX = 'toss_kr_regclose_';

    /** US 직전거래일 정규장 종가(day-over-day 기준, 롤포워드 이전) 캐시 키 접두 — 연장세션 통합/정규장 분리용 */
    private const PREV_REGULAR_PREFIX = 'toss_prev_regular_close_';

    /** KR 정규장 마감 시각(KST, HHMM). 이 시각 초과 첫 분봉부터 마감 동시호가 종가가 찍힌다. */
    private const KR_REGULAR_CLOSE_HHMM = 1530;

    /** 마감 직후 '종가 평탄(시간외종가)' 구간 끝(KST, HHMM). 15:31~15:40 close = 확정 정규장 종가. */
    private const KR_CLOSE_PLATEAU_END_HHMM = 1540;

    /**
     * 정규장 종가 추출용 1m 봉 페이지당 취득 수 — 토스 1m 단건 상한(실측: count>200 은 에러가 아니라 빈 배열).
     * 상향 금지 — 300·400 은 0봉을 반환해 1d 폴백을 상시 유발한다. 더 소급하려면 페이지네이션(아래)만.
     */
    private const KR_CLOSE_CANDLE_COUNT = 200;

    /**
     * 정규장 종가 추출용 1m 봉 최대 페이지 수(before/nextBefore 소급).
     *
     * 1페이지(200봉)로는 부족하다 — 1m 봉은 시간외를 20:00 까지 채우고 멈추므로, 윈도우는 '지금'이 아니라
     * '마지막 봉' 기준으로 200분이다. 19:01+ 콜드스타트면 200봉이 20:00→16:40 만 덮어 plateau(15:31~15:40)가
     * 통째로 이탈 → 1d 드리프트 종가로 폴백 → 부호 반전(실측 000660 +8.83% → −2.88%).
     * 실제 필요 = 20:00 − 15:31 = 269분 → 2페이지(400봉)로 충분. plateau 시작(15:30 이하 봉)에 도달하면 조기 종료.
     */
    private const KR_CLOSE_MAX_PAGES = 2;

    /** Yahoo Finance v8 chart 기본 URL — TossPriceFetcher::YAHOO_CHART_URL 과 동일 패턴. */
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    /** Yahoo 조회 타임아웃(초) — TossPriceFetcher::YAHOO_TIMEOUT 과 동일. */
    private const YAHOO_TIMEOUT = 5;

    /** Yahoo 국내 심볼 접미사 — 코스피(.KS) 우선, 없으면 코스닥(.KQ). 토스심볼엔 접미사가 없어 붙여 시도한다. */
    private const KR_YAHOO_SUFFIXES = ['.KS', '.KQ'];

    /** 종가 취득 일시 실패(null) 시 sentinel 캐시의 짧은 TTL(초) — 다음 개장까지 0 고착 방지, 곧 재시도. */
    private const KR_CLOSE_FAIL_TTL = 120;

    /** 캔들 엔드포인트 */
    private const CANDLES_ENDPOINT = '/api/v1/candles';

    private TossApiClient $client;

    private TossSymbolMapper $mapper;

    private MarketSessionService $session;

    /** Yahoo 일봉 조회용 HTTP 클라이언트. 선택 주입 — 테스트에서 MockHandler 로 갈아끼워 네트워크 없이 검증한다. */
    private Client $http;

    public function __construct(
        TossApiClient $client,
        TossSymbolMapper $mapper,
        MarketSessionService $session,
        ?Client $http = null
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->session = $session;
        $this->http = $http ?? new Client;
    }

    /**
     * prevClose 를 기반으로 등락액·등락률을 계산하여 반환한다.
     *
     * prevClose 캐시 miss 시 /candles 호출 후 캐시 저장.
     * API 실패 시 graceful — change=0, percent=0.
     *
     * @param  string  $tossSymbol  토스 API 심볼 (국내: 005930 등)
     * @param  float  $lastPrice  토스 /prices 에서 받은 현재가
     * @return array{change_amount:float,change_percent:float,prev_close:float|null}
     */
    public function calculate(string $tossSymbol, float $lastPrice): array
    {
        $prevClose = $this->getPrevClose($tossSymbol);

        if ($prevClose === null || $prevClose <= 0.0) {
            return [
                'change_amount' => 0.0,
                'change_percent' => 0.0,
                'prev_close' => null,
            ];
        }

        $changeAmount = $lastPrice - $prevClose;
        $changePercent = ($changeAmount / $prevClose) * 100.0;

        return [
            'change_amount' => round($changeAmount, 4),
            'change_percent' => round($changePercent, 4),
            'prev_close' => $prevClose,
        ];
    }

    /**
     * 전일 종가를 반환한다 (캐시 우선, miss 시 /candles 호출).
     *
     * TTL: 당일 KST 자정까지 남은 초.
     * 국내 장(KST 09:00~15:30) 기준 — 토스 1d 봉이 완료된 것만 취득.
     *
     * @return float|null prevClose (없으면 null)
     */
    public function getPrevClose(string $tossSymbol): ?float
    {
        $cacheKey = self::CACHE_PREFIX . $tossSymbol;
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (float) $cached;
        }

        return $this->fetchAndCachePrevClose($tossSymbol);
    }

    /**
     * 국내(KR) 정규장 마감 후 현재가로 고정할 '오늘 정규장 종가'를 반환한다.
     *
     * 배경: 토스 /prices lastPrice 는 정규장 마감(15:30) 후에도 시간외 체결을 따라 흔들리지만,
     *   토스 앱 화면은 정규장 종가로 고정 표시한다(예: 000660 종가 2,082,000 = +8.83%).
     *
     * 왜 일봉(1d) close 가 아니라 분봉(1m) plateau 인가 (재시작 안정성의 핵심):
     *   1d 오늘봉 close 는 시간외 체결이 진행될수록 계속 재집계돼 내려간다
     *   (000660: 2,082,000 → 2,073,000 → 2,071,000 …). 마감 후 WS 콜드스타트가 드리프트된
     *   1d close 를 잡으면 종가가 틀린다. 반면 '지난 1분봉'의 close 는 확정된 과거 데이터라
     *   나중에 시간외가 아무리 흘러도 값이 바뀌지 않는다. 정규장 종가 = 마감(15:30) 직후
     *   평탄(시간외종가) 구간(15:31~15:40)의 분봉 close → 언제(재시작·저녁 콜드스타트) 조회해도 동일.
     *
     * 종가 반환 조건(모두 만족, 아니면 null → 호출부는 lastPrice 유지):
     *   1) KR 종목
     *   2) 지금 KR 세션 = 장마감 (정규장 중이면 라이브 lastPrice 유지 → null)
     *   3) 오늘이 KR 거래일 (휴장·주말이면 현행 유지 → null)
     *   4) 오늘 15:31~15:40 분봉이 존재 (개장 전 등 마감후 봉 미생성이면 → null)
     *
     * 폴백: 1m plateau 판정 불가/취득 실패 → 1d 오늘봉 close → (그마저 없으면) null (NULL 방지 graceful).
     *
     * 캐시: toss_kr_regclose_{symbol} — 다음 개장(09:00 KST)까지. 개장 순간 만료되어 라이브 복귀.
     *   '오늘 봉 없음'도 sentinel(0)로 캐싱해 매 WS 사이클 중복 /candles 호출을 막는다.
     *   담기는 값은 드리프트 없는 확정 종가라, 캐시 만료(콜드스타트) 후 재추출해도 같은 값이다.
     */
    public function getKrRegularClose(string $tossSymbol): ?float
    {
        if ($this->mapper->market($tossSymbol) !== 'KR') {
            return null;
        }

        $nowTs = Carbon::now()->getTimestamp();
        if ($this->session->getKrSession($nowTs) !== '장마감') {
            return null;  // 정규장 중 → 라이브 lastPrice 유지
        }
        if (! $this->session->isKrTradingDay($nowTs)) {
            return null;  // 휴장·주말 → 현행(전일 마감) 유지
        }

        $cacheKey = self::KR_CLOSE_PREFIX . $tossSymbol;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float) $cached > 0.0 ? (float) $cached : null;
        }

        // 1순위 = 분봉 plateau(드리프트 없음) · 폴백 = 일봉 오늘봉 close.
        $close = $this->fetchTodayKrRegularClose($tossSymbol) ?? $this->fetchTodayKrDailyClose($tossSymbol);
        // 실값은 다음 개장까지 장TTL 고정. null(개장 전 정상 or 일시 취득 실패)은 짧은 TTL 로만
        // sentinel(0) 캐싱 → API 회복 시 다음 사이클에서 재추출(자가치유). 장TTL 0 고착 방지.
        $ttl = $close !== null ? $this->secondsUntilNextKrOpen() : self::KR_CLOSE_FAIL_TTL;
        Cache::put($cacheKey, $close ?? 0.0, $ttl);

        return $close;
    }

    /**
     * US 종목 '통합'/'정규장' 등락률을 분리 계산한다 (연장세션 차트 헤더 2줄용).
     *
     * 계약(02_계약.md, chart-regular-ext-split):
     *   - 통합(change_*)    = (현재가[시간외 포함] − 직전거래일 정규장 종가) / 직전거래일 정규장 종가 × 100
     *   - 정규장(regular_*) = (당일 정규장 종가        − 직전거래일 정규장 종가) / 직전거래일 정규장 종가 × 100
     *
     * 연장세션(프리·애프터·주간거래)에서만 통합/정규장을 분리한다:
     *   현재 애프터/주간거래는 calculate()가 prevClose를 '오늘 정규장 종가'로 롤포워드해 change_percent 가
     *   '시간외 변동분'이 되지만, 이 메서드는 롤포워드 이전 기준(직전거래일 정규장 종가=getUsPrevRegularClose)으로
     *   '통합'을 계산한다. 정규장 종가(regularClose)는 호출부가 캐시에서 읽어 넘긴다(HTTP 없음).
     *
     * 정규장·장마감(또는 직전거래일 종가 cold): 기존 calculate() 값을 그대로 쓰고 regular_* 는 null →
     *   프론트는 1줄 유지(회귀 없음). 정규장 중엔 통합=정규장이라 어차피 같은 값이다.
     *
     * 연장세션이라도 '당일 정규장 봉'이 없으면(프리마켓·주간거래 자정후) regular_* = null(1줄):
     *   당일 정규장 종가가 아직 없어 regularClose(Yahoo)와 prevRegular(candles[0])가 같은 날 종가라
     *   크로스소스 잔차·ET 자정 불연속만 남기 때문. 당일 정규장 봉 유무는 getUsPrevRegularClose 가
     *   $hasTodayRegularBar(참조)로 알려준다(캐시 히트에도 유효 — 값과 함께 캐싱).
     *
     * @param  string  $tossSymbol  US 앱심볼(=토스심볼, 대문자)
     * @param  float  $lastPrice  현재가(시간외 포함 라이브가)
     * @param  float|null  $regularClose  당일 정규장 종가(yahoo_regular_close 캐시). cold 면 null.
     * @return array{change_amount:float,change_percent:float,regular_change_amount:float|null,regular_change_percent:float|null}
     */
    public function calculateUsSplit(string $tossSymbol, float $lastPrice, ?float $regularClose): array
    {
        $session = $this->session->getUsSession(Carbon::now()->getTimestamp());
        $isExtended = in_array($session, ['프리마켓', '애프터마켓', '주간거래'], true);

        if ($isExtended) {
            $hasTodayRegularBar = false;
            $prevRegular = $this->getUsPrevRegularClose($tossSymbol, $hasTodayRegularBar);
            if ($prevRegular !== null && $prevRegular > 0.0) {
                // 통합 = 시간외 현재가 vs 직전거래일 정규장 종가
                $changeAmount = $lastPrice - $prevRegular;
                $changePercent = $changeAmount / $prevRegular * 100.0;

                // 정규장 = 당일 정규장 종가 vs 직전거래일 정규장 종가.
                //   당일 정규장 봉이 있을 때(애프터·주간거래 자정전)만 계산한다. 당일 정규장 봉이 없으면
                //   (프리마켓·주간거래 자정후) regularClose(Yahoo)와 prevRegular(candles[0])가 '같은 날 종가'라
                //   크로스소스 잔차(정규장 −0.1%대 노이즈)만 남고, ET 자정에 기준이 candles[1]→candles[0]로
                //   전진하며 불연속 점프도 생긴다 → regular_* = null 로 두어 프론트가 1줄로 degrade.
                //   (regularClose cold 여도 null → 1줄.)
                $regularChangeAmount = null;
                $regularChangePercent = null;
                if ($hasTodayRegularBar && $regularClose !== null && $regularClose > 0.0) {
                    $regularChangeAmount = $regularClose - $prevRegular;
                    $regularChangePercent = ($regularClose - $prevRegular) / $prevRegular * 100.0;
                }

                return [
                    'change_amount' => round($changeAmount, 4),
                    'change_percent' => round($changePercent, 2),
                    'regular_change_amount' => $regularChangeAmount !== null ? round($regularChangeAmount, 4) : null,
                    'regular_change_percent' => $regularChangePercent !== null ? round($regularChangePercent, 2) : null,
                ];
            }
            // 직전거래일 종가 cold → 아래 기존 calculate() 로 graceful 폴백(1줄).
        }

        // 정규장·장마감(또는 연장세션 cold): 기존 등락 그대로 — 현행 유지(회귀 없음).
        //   단, change_percent 는 계약(02_계약, 소수 2자리)에 맞춰 여기서만 재반올림한다.
        //   calculate() 자체는 round(4) 유지(KR 등 다른 소비처가 그 정밀도에 의존) — US split 폴백 반환 지점에서만 정규화.
        //   change_amount 는 정상 연장세션 경로도 round(4)라 그대로 두면 이미 일치.
        $base = $this->calculate($tossSymbol, $lastPrice);

        return [
            'change_amount' => $base['change_amount'],
            'change_percent' => round($base['change_percent'], 2),
            'regular_change_amount' => null,
            'regular_change_percent' => null,
        ];
    }

    /**
     * US '직전거래일 정규장 종가'(day-over-day 기준)를 반환한다. 캐시 우선, miss 시 /candles 호출.
     *
     * calculate()의 롤포워드 이전 $prevClose 와 동일한 선택 규칙을 쓴다:
     *   오늘(NY) 정규장 봉 존재 → candles[1](어제 종가) · 라이브(오늘봉 없음) → candles[0] · 장마감 → candles[1].
     * calculate()의 prev_close 캐시(롤포워드된 값)와 분리된 별도 캐시를 쓰지만, TTL 은 같은
     * secondsUntilNextUsBoundary() 를 쓴다 — 선택 규칙이 같은 경계들(ET 자정의 isTodayBar 반전,
     * 20:00 의 주간거래 개시 등)에 걸려 있어 자정만 보면 20:00 경계를 넘겨 stale 이 된다(D3).
     *
     * KR·지수는 null.
     *
     * @param  bool|null  $isTodayBar  (참조 out) 당일 정규장 봉 존재 여부. 캐시된 플래그라 히트에도 유효.
     *                                 프리마켓·주간거래 자정후엔 false(당일 정규장 미개장/미완결).
     */
    public function getUsPrevRegularClose(string $tossSymbol, ?bool &$isTodayBar = null): ?float
    {
        $isTodayBar = false;

        if ($this->mapper->market($tossSymbol) !== 'US') {
            return null;
        }

        $cacheKey = self::PREV_REGULAR_PREFIX . $tossSymbol;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $isTodayBar = (bool) ($cached['today_bar'] ?? false);
            $close = (float) ($cached['close'] ?? 0.0);

            return $close > 0.0 ? $close : null;  // sentinel(0) → null
        }

        return $this->fetchAndCacheUsPrevRegularClose($tossSymbol, $cacheKey, $isTodayBar);
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * US 직전거래일 정규장 종가를 /candles(1d, count=2)로 조회해 캐시 후 반환.
     *
     * 선택 규칙은 fetchAndCachePrevClose() 의 '롤포워드 이전 $prevClose' 와 동일:
     *   오늘봉 존재 → candles[1] · 라이브(오늘봉 없음) → candles[0] · 장마감 → candles[1].
     * 실패·봉부족 시 null. 이때 짧은 TTL 로 sentinel(close=0) 캐싱 → 연장세션 매 WS 사이클(~3초)마다
     *   /candles 를 재호출하는 핫패스 재유입을 막고, 다음 미스에 자가치유(getKrRegularClose 와 동일 패턴).
     *
     * 캐시 포맷 = ['close'=>float, 'today_bar'=>bool]: 당일 정규장 봉 유무(today_bar)를 값과 함께 담아
     *   캐시 히트에도 calculateUsSplit 이 정규장 줄 표시 여부를 알 수 있게 한다.
     */
    private function fetchAndCacheUsPrevRegularClose(string $tossSymbol, string $cacheKey, ?bool &$isTodayBar = null): ?float
    {
        $isTodayBar = false;
        try {
            $response = $this->client->get(self::CANDLES_ENDPOINT, [
                'symbol' => $tossSymbol,
                'interval' => '1d',
                'count' => 2,
            ]);

            $candles = $this->extractCandles($response);
            if ($candles === null || count($candles) < 2) {
                // 봉부족 → sentinel 캐싱(짧은 TTL). 핫패스 /candles 재유입 방지.
                Cache::put($cacheKey, ['close' => 0.0, 'today_bar' => false], self::KR_CLOSE_FAIL_TTL);

                return null;
            }

            // 최신 봉 먼저 (timestamp 내림차순)
            usort($candles, function (array $a, array $b): int {
                return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
            });

            $latestDate = Carbon::parse((string) ($candles[0]['timestamp'] ?? ''))
                ->setTimezone('America/New_York')->toDateString();
            $isTodayBar = $latestDate === Carbon::now('America/New_York')->toDateString();

            if ($isTodayBar) {
                $prevRegular = $candles[1]['closePrice'] ?? null;                          // 오늘봉 존재 → 어제 종가
            } elseif ($this->session->getUsSession(Carbon::now()->getTimestamp()) !== '장마감') {
                $prevRegular = $candles[0]['closePrice'] ?? null;                          // 라이브(프리·애프터·주간) → 직전거래일 종가
            } else {
                $prevRegular = $candles[1]['closePrice'] ?? null;                          // 장마감 → 전전일(직전거래일 하루 등락 유지)
            }

            $prevRegular = $prevRegular !== null ? (float) $prevRegular : null;
            if ($prevRegular === null || $prevRegular <= 0.0) {
                Cache::put($cacheKey, ['close' => 0.0, 'today_bar' => false], self::KR_CLOSE_FAIL_TTL);
                $isTodayBar = false;

                return null;
            }

            Cache::put($cacheKey, ['close' => $prevRegular, 'today_bar' => $isTodayBar], $this->secondsUntilNextUsBoundary());

            return $prevRegular;
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} US 직전거래일 정규장 종가 조회 실패: " . $e->getMessage());
            Cache::put($cacheKey, ['close' => 0.0, 'today_bar' => false], self::KR_CLOSE_FAIL_TTL);
            $isTodayBar = false;

            return null;
        }
    }

    /**
     * 오늘(KST) 국내 정규장 종가를 1m 분봉의 '마감 직후 plateau'에서 추출. 못 구하면 null.
     *
     * 추출 규칙 (재시작에도 안정한 이유):
     *   - /candles?interval=1m&count=200 을 before/nextBefore 로 최대 2페이지 소급해 받는다
     *     (1페이지=200분 윈도우는 19:01+ 콜드스타트에서 plateau 를 놓친다 — KR_CLOSE_MAX_PAGES 주석 참조).
     *   - 오늘(KST) 봉 중 15:30 초과 & 15:40 이하(=15:31~15:40) close 만 모은다.
     *     이 구간은 마감 동시호가 체결가(정규장 종가)가 시간외종가로 찍히는 '평탄 plateau'다.
     *     15:30 이하 = 연속 체결(마지막가 ≠ 종가), 15:41~ = 시간외단일가(드리프트) → 둘 다 제외.
     *   - 대표값 = 최빈값(mode). 드리프트/데이터 이상 봉이 한둘 섞여도 종가가 정확히 뽑힌다.
     *   - '지난 1분봉' close 는 확정된 과거 데이터라 시간외가 흘러도 불변 → 콜드스타트 재추출도 동일값.
     *
     * 마감 전(개장 전 등) 오늘 15:31+ 봉이 없으면 plateau 공집합 → null(호출부 폴백/현행 유지).
     */
    private function fetchTodayKrRegularClose(string $tossSymbol): ?float
    {
        try {
            $today = Carbon::now('Asia/Seoul')->toDateString();
            $plateauCloses = [];  // 등장 순서 유지 (tie → 최초값 우선)
            $before = null;

            // 페이지네이션: TossCandleProvider::fetchCandles 의 before/nextBefore 패턴 재사용.
            //   count 상향은 불가(토스 1m 실측 상한 200 — 초과 시 조용히 0봉) → 소급은 페이지로만.
            for ($page = 0; $page < self::KR_CLOSE_MAX_PAGES; $page++) {
                $query = [
                    'symbol' => $tossSymbol,
                    'interval' => '1m',
                    'count' => self::KR_CLOSE_CANDLE_COUNT,
                ];
                if ($before !== null) {
                    $query['before'] = $before;
                }

                $response = $this->client->get(self::CANDLES_ENDPOINT, $query);
                $candles = $this->extractCandles($response);
                if ($candles === null) {
                    break;
                }

                $reachedPlateauStart = false;  // 15:30 이하(오늘) 봉까지 소급됨 = plateau 전 구간 확보
                foreach ($candles as $c) {
                    $ts = (string) ($c['timestamp'] ?? '');
                    if ($ts === '') {
                        continue;
                    }
                    $dt = Carbon::parse($ts)->setTimezone('Asia/Seoul');
                    if ($dt->toDateString() !== $today) {
                        continue;  // 오늘(마감 당일) 봉만
                    }
                    $hhmm = (int) $dt->format('Hi');
                    if ($hhmm <= self::KR_REGULAR_CLOSE_HHMM) {
                        $reachedPlateauStart = true;

                        continue;
                    }
                    if ($hhmm <= self::KR_CLOSE_PLATEAU_END_HHMM) {
                        $close = isset($c['closePrice']) ? (float) $c['closePrice'] : 0.0;
                        if ($close > 0.0) {
                            $plateauCloses[] = $close;
                        }
                    }
                }

                if ($reachedPlateauStart) {
                    break;  // plateau 를 완전히 덮었다 → 추가 소급 불필요(16:00 콜드스타트는 1페이지로 끝)
                }

                $before = $response['result']['nextBefore'] ?? null;
                if ($before === null) {
                    break;
                }
            }

            if (empty($plateauCloses)) {
                return null;  // 마감 전·평탄구간 없음 → 폴백(일봉)
            }

            return $this->modeClose($plateauCloses);
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} 분봉 종가 추출 실패: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 폴백: 오늘(KST) 국내 일봉 종가를 /candles(1d) 로 조회. 오늘 봉이 아니면 null.
     *
     * 분봉 plateau 추출 실패 시에만 쓰인다. 일봉 close 는 시간외에 드리프트할 수 있으나
     * NULL(현재가 미고정)보다는 근사 종가가 낫다는 판단의 마지막 안전망.
     */
    private function fetchTodayKrDailyClose(string $tossSymbol): ?float
    {
        try {
            $response = $this->client->get(self::CANDLES_ENDPOINT, [
                'symbol' => $tossSymbol,
                'interval' => '1d',
                'count' => 2,
            ]);

            $candles = $this->extractCandles($response);
            if ($candles === null) {
                return null;
            }

            // 최신 봉 = timestamp 내림차순 정렬 후 index 0
            usort($candles, function (array $a, array $b): int {
                return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
            });

            $latest = $candles[0];
            $latestDate = Carbon::parse((string) ($latest['timestamp'] ?? ''))
                ->setTimezone('Asia/Seoul')->toDateString();

            // 오늘 봉이 아니면(개장 전 등) 종가 고정 미적용 → lastPrice 유지
            if ($latestDate !== Carbon::now('Asia/Seoul')->toDateString()) {
                return null;
            }

            $close = isset($latest['closePrice']) ? (float) $latest['closePrice'] : 0.0;

            return $close > 0.0 ? $close : null;
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} 일봉 폴백 종가 조회 실패: " . $e->getMessage());

            return null;
        }
    }

    /**
     * /candles 응답에서 candles 배열을 추출(응답 구조 변형 방어). 없으면 null.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function extractCandles(array $response): ?array
    {
        if (empty($response)) {
            return null;
        }

        $result = $response['result'] ?? null;
        $candles = null;
        if (is_array($result) && isset($result['candles'])) {
            $candles = $result['candles'];
        } elseif (isset($response['candles'])) {
            $candles = $response['candles'];
        } elseif (is_array($result)) {
            $candles = $result;
        }

        return (is_array($candles) && ! empty($candles)) ? $candles : null;
    }

    /**
     * 종가 목록의 최빈값(mode)을 반환. 동률이면 목록 등장순 최초값 우선(이상 봉 방어).
     *
     * @param  array<int,float>  $closes  (비어있지 않음 전제)
     */
    private function modeClose(array $closes): float
    {
        $counts = [];
        foreach ($closes as $c) {
            $key = (string) $c;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $best = $closes[0];
        $bestCount = -1;
        foreach ($closes as $c) {  // 등장순 순회 + strict > → 동률 시 최초값 유지
            if ($counts[(string) $c] > $bestCount) {
                $bestCount = $counts[(string) $c];
                $best = $c;
            }
        }

        return (float) $best;
    }

    /**
     * /candles 를 호출해 직전 완료봉 종가를 캐시에 저장 후 반환.
     *
     * count=2 로 최근 2봉을 받아 직전 봉의 closePrice 를 사용.
     *
     * 토스 /candles 실측 응답 구조 (2026-06-24 검증):
     *   {
     *     "result": {
     *       "candles": [
     *         { "timestamp": "2026-06-24T...", "openPrice": "...", "highPrice": "...",
     *           "lowPrice": "...", "closePrice": "...", "volume": "...", "currency": "KRW" },
     *         { "timestamp": "2026-06-23T...", ... }
     *       ],
     *       "nextBefore": "..."
     *     }
     *   }
     *
     * 정렬: 최신 봉(index 0) → 오래된 봉(index 1). timestamp 기준 내림차순.
     * 즉 index 1 이 "직전 완료 거래일" 종가 = prevClose.
     *
     * 휴장일 가짜봉 방지:
     *   /candles 는 실제 거래일 봉만 반환. 주말·공휴일에는 당일 봉이 없으므로
     *   count=2 면 "직전 2 거래일" 봉이 오고, index 0 이 마지막 거래일, index 1 이 그 전날.
     *   자연스럽게 "마지막 완료 거래일"의 전일 종가를 얻을 수 있다.
     */
    private function fetchAndCachePrevClose(string $tossSymbol): ?float
    {
        try {
            $response = $this->client->get(self::CANDLES_ENDPOINT, [
                'symbol' => $tossSymbol,
                'interval' => '1d',
                'count' => 2,
            ]);

            if (empty($response)) {
                Log::warning("[TossChangeCalculator] /candles 빈응답: {$tossSymbol}");

                return null;
            }

            // 실측 구조: result.candles 배열
            $result = $response['result'] ?? null;
            $candles = null;

            if (is_array($result) && isset($result['candles'])) {
                // 정상 응답: { result: { candles: [...] } }
                $candles = $result['candles'];
            } elseif (is_array($result) && ! isset($result['candles'])) {
                // result 가 직접 배열인 경우 (키가 숫자 인덱스) — 호환 처리
                $candles = $result;
            } elseif (isset($response['candles'])) {
                // 루트에 candles 가 있는 경우
                $candles = $response['candles'];
            }

            if (! is_array($candles) || count($candles) < 2) {
                Log::debug("[TossChangeCalculator] {$tossSymbol} 봉 데이터 부족: " . json_encode(array_keys($response)));

                return null;
            }

            // 봉 정렬: timestamp 기준 내림차순(최신 먼저) 확인 후 오래된 봉 선택
            // 실측: index 0 = 최신(당일), index 1 = 전일 → prevClose = index 1
            // 안전하게 timestamp 비교 정렬 후 index 1 선택
            usort($candles, function (array $a, array $b): int {
                // timestamp 는 ISO 8601 문자열 — 문자열 비교로 내림차순 정렬
                $tA = (string) ($a['timestamp'] ?? '');
                $tB = (string) ($b['timestamp'] ?? '');

                return strcmp($tB, $tA);  // 내림차순 (최신 먼저)
            });

            // 기준가(prevCandle) 선택 — 세션 인지형.
            //   봉 timestamp 는 거래소 로컬 오프셋 포함 ISO8601 → currency 로 거래소 TZ 판별 후 날짜 비교.
            //   currency 결측 시(US 종목인데 필드 누락) 서울TZ 폴백은 오늘봉 판별을 오판→부호반전 위험 →
            //   심볼로 시장 판별해 폴백(TossSymbolMapper 재사용). ponytail: 표준 심볼 분류를 그대로 씀.
            $currency = $candles[0]['currency'] ?? null;
            $isUsMarket = $currency !== null
                ? $currency === 'USD'
                : $this->mapper->market($tossSymbol) === 'US';
            $tz = $isUsMarket ? 'America/New_York' : 'Asia/Seoul';
            $latestDate = Carbon::parse((string) ($candles[0]['timestamp'] ?? ''))->setTimezone($tz)->toDateString();
            $isTodayBar = $latestDate === Carbon::now($tz)->toDateString();

            // 1) 오늘 봉 있음 → candles[1](어제 종가) 기준 = 오늘 등락.
            // 2) 오늘 봉 없음 + 라이브 세션(정규장, US 는 프리·애프터·주간거래 포함) → candles[0](어제 종가) 기준.
            //    개장 직후 오늘 봉이 아직 안 생긴 순간·미국 프리마켓 등 lastPrice 가 살아있는 구간.
            //    무조건 candles[1] 을 쓰면 전전일 종가를 기준가로 잡아 부호까지 반전된다(MU 7/10 수정 보존).
            // 3) 오늘 봉 없음 + 장마감(개장 전·휴장·주말) → candles[1](전전일 종가) 기준.
            //    lastPrice 가 어제 종가에 고정된 구간 → 전전일 대비 = "어제 하루 등락"이 유지됨
            //    (토스 앱 동일 동작 — 개장 전 0.00% 초기화 제거).
            $marketClosed = false;
            if ($isTodayBar) {
                $prevCandle = $candles[1];
            } elseif ($this->isMarketLiveNow($isUsMarket)) {
                $prevCandle = $candles[0];
            } else {
                $prevCandle = $candles[1];
                $marketClosed = true;
            }
            $prevClose = isset($prevCandle['closePrice']) ? (float) $prevCandle['closePrice'] : null;

            if ($prevClose === null || $prevClose <= 0.0) {
                Log::warning("[TossChangeCalculator] {$tossSymbol} prevClose 이상: " . json_encode($prevCandle));

                return null;
            }

            // US 시간외(애프터마켓·주간거래) 기준가 롤포워드 (2026-07-15 버그수정):
            //   위 분기 1(isTodayBar)은 candles[1](어제 종가)을 기준가로 잡는다 — US '정규장 중 장중 등락'엔 옳다.
            //   그러나 정규장 마감 후(애프터·주간거래)엔 오늘 정규장 봉이 이미 완결이라 isTodayBar 가 true 로 남는데,
            //   현재가는 시간외가라 기준가를 '오늘 정규장 종가'로 전진시켜야 정합하다(어제 종가 대비 → 부호까지 반전).
            //   실측: MU 애프터 기준가 937(7/13 종가, stale) → +4.22% 오표기. 정답 983.12(7/14 종가) 대비 -0.67%.
            //   오늘 정규장 종가 = yahoo_regular_close_{ticker}(regular_close 워머의 정본 — 애프터엔 오늘 종가 반환, 드리프트프리).
            //   candles[0].close 는 US 1d 봉이 시간외 체결로 재집계·드리프트할 수 있어 쓰지 않는다(KR 일봉 드리프트 전례).
            //   캐시 cold 시엔 candles[1](기존 동작) 유지 — graceful. 정규장 중(정규장)엔 스킵해 장중 등락을 보존한다.
            //   프리마켓은 isTodayBar=false 라 위 분기 2(candles[0])로 이미 정상 → 이 롤포워드가 필요 없다.
            //
            //   cold 폴백은 '값'만 graceful 이고 'TTL'까지 graceful 이면 안 된다(2026-07-17 수정):
            //     WS 사이클은 step4(전송·기준가 계산)가 step6a(warmRegularCloses)보다 먼저 돈다 → 워머가 아직
            //     안 채운 순간엔 롤포워드가 조용히 candles[1](어제 종가)로 떨어진다. 그 실패값을 정상 경계
            //     TTL(최대 4h)로 박으면 워머가 3초 뒤 정답을 채워도 자가치유가 안 된다(실측 MU 904.28 이 2h11m 고착,
            //     기준가 6.6% 오차). 특히 ET 16:05 엔 yahoo_regular_close 와 toss_prev_close 가 동시 만료돼
            //     100% 이 경로를 타고 19:30 까지 오염된다(실측 TTL 12300s).
            //     → 실패를 sentinel 로 표시해 아래 TTL 을 120초로 낮춘다(형제 fetchAndCacheUsPrevRegularClose 와 동일 패턴).
            //     워머를 step4 앞으로 옮기는 건 금물 — 핫패스에 Yahoo 블로킹 HTTP 가 유입된다(H-2 위배).
            $rollforwardFailed = false;
            if ($isTodayBar && $isUsMarket
                && $this->session->getUsSession(Carbon::now()->getTimestamp()) !== '정규장') {
                $regularClose = $this->readUsRegularClose($tossSymbol);
                if ($regularClose !== null) {
                    $prevClose = $regularClose;
                } else {
                    $rollforwardFailed = true;
                    Log::debug("[TossChangeCalculator] {$tossSymbol} US 시간외 롤포워드: yahoo_regular_close cold → candles[1] 유지(짧은 TTL 재시도)");
                }
            }

            // 국내 기준가 = Yahoo 일봉 종가(2026-07-17 드리프트 오염 수정):
            //   토스 1d 봉 종가는 '정규장 종가'가 아니라 시간외 체결을 따라 재집계·드리프트하는 값이다
            //   (실측 000660 7/15: 정규장 2,082,000 → 토스 1d봉 2,022,000, −2.88%. 7/14 는 +1.46% 로 부호도 뒤집힌다).
            //   거래소 기준가는 '전일 정규장 종가'로 고정이라, 드리프트값을 기준가로 쓰면 등락률이 통째로 틀린다
            //   (실측 −9.50% 서빙 vs 정답 −12.10%). 같은 파일 getKrRegularClose() 가 이미 같은 이유로 1d 종가를
            //   배척하고 분봉 plateau 를 쓰는데(위 docblock), 기준가 경로만 그 오염 소스를 그대로 쓰고 있었다.
            //
            //   교체 규칙(실측 3건 정확 일치): 기준가(D) == Yahoo {코드}.KS 일봉 종가(D−1)
            //     000660 7/15 = 1,913,000(=7/14) · 005930 7/15 = 263,000(=7/14) · 0167A0 7/17 = 18,625(=7/16).
            //   위 분기가 고른 $prevCandle 의 '날짜'(=기준 거래일)는 그대로 두고 close 만 Yahoo 값으로 바꾼다
            //   → 분기 1·2·3 이 한 곳에서 교정되고, 기준가가 '시계의 함수'가 아니라 '거래일의 함수'가 된다.
            //   ponytail: 옛 price-limits((상한+하한)/2) 경로는 삭제했다 — Yahoo 종가와 값이 같은데(실측 3/3)
            //     장마감 후 다음 거래일 기준가로 조기 롤오버돼(ε 가드·완화의 원인) 등락을 0% 로 뭉갰다.
            //     상장 이벤트(권리락·액면분할)로 기준가 ≠ 전일 종가인 날은 이 규칙이 어긋난다 — 그날이 문제되면
            //     정규장 중에 한해 price-limits 를 우선하는 경로를 되살리는 게 업그레이드 경로.
            //   US 는 무영향(위에서 구한 candles/Yahoo 기준가 유지).
            //   Yahoo 실패 시 candles 기반 $prevClose 로 graceful 폴백하되, 오염값이므로 아래 TTL 을
            //   KR_CLOSE_FAIL_TTL 로 짧게 잡아 다음 사이클에 자가치유시킨다(장TTL 고착 금지).
            $krYahooFailed = false;
            if (! $isUsMarket) {
                $prevTs = (string) ($prevCandle['timestamp'] ?? '');
                $prevDate = $prevTs !== '' ? Carbon::parse($prevTs)->setTimezone('Asia/Seoul')->toDateString() : null;

                $yahooClose = $prevDate !== null ? $this->fetchKrYahooDailyClose($tossSymbol, $prevDate) : null;
                if ($yahooClose !== null) {
                    $prevClose = $yahooClose;
                } else {
                    $krYahooFailed = true;
                    Log::debug("[TossChangeCalculator] {$tossSymbol} KR 기준가: Yahoo 일봉({$prevDate}) 실패 → 토스 1d 종가 유지(짧은 TTL 재시도)");
                }
            }

            // TTL 결정:
            //   US = 기준가 의미가 바뀌는 '다음 경계'까지 (secondsUntilNextUsBoundary — 경계 목록 단일 소스).
            //     분기별로 TTL 공식을 따로 두면(장마감=개장까지 / 라이브=16:05까지 …) 반대 경계를 넘겨
            //     stale 이 된다. 경계는 2곳이 아니라 8곳이라 열거로 단일화했다(7/14·7/16·7/17 3연속 재발).
            //   KR = 현행 유지: 장마감 기준가(분기 3)는 '다음 개장(09:00 KST)까지'만 유효 —
            //     개장 순간 기준가가 어제 종가로 바뀌어야 하므로 그 이후까지 살면 전전일 기준 stale(H-3 교훈).
            //     라이브/오늘봉(분기 1·2)은 KST 자정.
            //   단 US 롤포워드 실패분(워머 레이스)·KR + Yahoo 실패분은 오염된 폴백값이라 장TTL 로 박으면
            //     안 된다 → 120초만(다음 사이클 자가치유).
            if ($isUsMarket) {
                $ttl = $rollforwardFailed ? self::KR_CLOSE_FAIL_TTL : $this->secondsUntilNextUsBoundary();
            } elseif ($krYahooFailed) {
                $ttl = self::KR_CLOSE_FAIL_TTL;
            } elseif ($marketClosed) {
                $ttl = $this->secondsUntilNextKrOpen();
            } else {
                $ttl = $this->secondsUntilKstMidnight();
            }

            Cache::put(self::CACHE_PREFIX . $tossSymbol, $prevClose, $ttl);

            Log::debug('[TossChangeCalculator] prevClose 캐싱', [
                'symbol' => $tossSymbol,
                'prevClose' => $prevClose,
                'ttl' => $ttl,
            ]);

            return $prevClose;
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} 캔들 조회 실패: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 국내 종목의 '지정 거래일(KST) 정규장 종가'를 Yahoo 일봉에서 조회. 없으면 null.
     *
     * 왜 Yahoo 인가: 토스 1d 봉 종가는 시간외 체결로 재집계·드리프트하지만(실측 000660 7/15 −2.88%),
     *   Yahoo {코드}.KS 일봉 종가는 정규장 종가로 확정된다 — 거래소 기준가와 3/3 실측 일치
     *   (기준가(D) == Yahoo close(D−1)). 조회 패턴은 US 의 yahoo_regular_close 경로와 동일
     *   (TossPriceFetcher::fetchYahooRegularClose — Guzzle · v8/finance/chart · http_errors=false · 5s).
     *
     * 접미사: 토스심볼엔 .KS/.KQ 가 없어(TossSymbolMapper 가 제거) 코스피(.KS)→코스닥(.KQ) 순으로 시도한다.
     *   ponytail: 거래소를 따로 들고 다니지 않는다 — 코스닥만 2번째 호출을 타고, 호출부 캐시(toss_prev_close_)가
     *   종목당 1일 수회로 이미 게이팅한다. 잦아지면 exchange 를 심볼과 함께 넘기는 게 업그레이드 경로.
     *
     * @param  string  $kstDate  'Y-m-d'(KST) — 기준 거래일(=선택된 prevCandle 의 날짜)
     */
    private function fetchKrYahooDailyClose(string $tossSymbol, string $kstDate): ?float
    {
        foreach (self::KR_YAHOO_SUFFIXES as $suffix) {
            $close = $this->fetchYahooDailyCloseOn($tossSymbol . $suffix, $kstDate);
            if ($close !== null) {
                return $close;
            }
        }

        return null;
    }

    /**
     * Yahoo v8 chart 일봉에서 지정 KST 날짜의 종가를 찾아 반환. 실패·해당일 봉 없음 → null(graceful).
     *
     * range=1mo 로 최근 일봉 시계열을 받아 timestamp 를 KST 날짜로 환산해 매칭한다
     * (meta.regularMarketPrice 는 '지금 세션 기준'이라 특정 과거 거래일 종가를 못 준다 → 시계열 사용).
     */
    private function fetchYahooDailyCloseOn(string $yahooSymbol, string $kstDate): ?float
    {
        try {
            $url = self::YAHOO_CHART_URL . urlencode($yahooSymbol) . '?interval=1d&range=1mo';

            $res = $this->http->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'http_errors' => false,
                'timeout' => self::YAHOO_TIMEOUT,
            ]);

            $data = json_decode((string) $res->getBody(), true);
            $result = $data['chart']['result'][0] ?? null;
            $timestamps = is_array($result) ? ($result['timestamp'] ?? null) : null;
            $closes = is_array($result) ? ($result['indicators']['quote'][0]['close'] ?? null) : null;

            if (! is_array($timestamps) || ! is_array($closes)) {
                return null;
            }

            foreach ($timestamps as $i => $ts) {
                $close = $closes[$i] ?? null;
                if ($close === null || (float) $close <= 0.0) {
                    continue;  // 결측 봉
                }
                if (Carbon::createFromTimestamp((int) $ts, 'Asia/Seoul')->toDateString() === $kstDate) {
                    return (float) $close;
                }
            }

            return null;  // 해당 날짜 봉 없음(휴장·미상장 접미사)
        } catch (\Throwable $e) {
            Log::warning("[TossChangeCalculator] {$yahooSymbol} Yahoo 일봉 종가 조회 실패: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 당일 KST(Asia/Seoul) 자정까지 남은 초를 계산한다.
     *
     * 하한 없음(max(...,1)): 300초 하한은 방어가 아니라 '경계를 5분 넘겨 살아남는 stale' 의 원인이다
     * (US 경로에서 같은 이유로 이미 제거·검증됨). 자정 직전 짧은 TTL 은 정상 — 그게 경계다.
     *
     * diffInSeconds 를 쓰지 않고 타임스탬프 차를 쓴다 — 이유는 형제 함수
     * (secondsUntilNextUsBoundary · FxService::secondsUntilNextSeoulFxClose)와의 일관성뿐이다.
     * 여기엔 버그 방어가 없다. KST 는 DST 가 없어 FxService:226 이 말하는 '서머타임 전환일(23h·25h)'
     * 문제도 이 함수엔 해당하지 않는다.
     *
     * 실측(Carbon 3.13.1) — 잘못된 통설을 남기지 않기 위해 기록한다:
     *   - $now->diffInSeconds($midnight, false) = +2057.037 → 미래는 '양수'다.
     *     "Carbon 3 이 미래 시각의 부호를 뒤집는다"는 사실이 아니며, TTL 이 1초로 붕괴하지도 않는다.
     *   - 부호가 음수가 되는 건 수신자/인자를 뒤집었을 때뿐: $midnight->diffInSeconds($now) = -2057.037.
     *   - endOfDay()->addSecond() 는 00:00:00.999999 이므로 diff 는 소수(float)를 돌려준다.
     *     (int) 캐스팅은 내림이라 TTL 이 1초 짧아질 뿐, 타임스탬프 차와 실질 차이는 없다.
     */
    private function secondsUntilKstMidnight(): int
    {
        $now = Carbon::now('Asia/Seoul');
        $midnight = $now->copy()->endOfDay()->addSecond();  // 다음날 00:00:00 KST

        return max($midnight->getTimestamp() - $now->getTimestamp(), 1);
    }

    /**
     * 지금 해당 시장의 라이브 세션(체결 진행)이 열려 있는가.
     *
     * KR: 정규장(09:00~15:30) 진행 중. · US: '장마감'이 아닌 모든 세션(프리·정규·애프터·주간거래)
     *   — 미국은 프리/애프터에도 lastPrice 가 살아 움직여 candles[0](어제 종가) 기준이 옳다(7/10 보존).
     * 휴장일·주말은 MarketSessionService(토스 캘린더·Yahoo SPY meta)가 '장마감'으로 판정한다.
     * 시각은 Carbon::now()(테스트 시 setTestNow 반영) 기준.
     */
    private function isMarketLiveNow(bool $isUsMarket): bool
    {
        $nowTs = Carbon::now()->getTimestamp();

        return $isUsMarket
            ? $this->session->getUsSession($nowTs) !== '장마감'
            : $this->session->getKrSession($nowTs) === '정규장';
    }

    /**
     * US '오늘 정규장 종가'를 캐시에서만 읽는다(HTTP 없음). 없으면 null.
     *
     * TossPriceFetcher::readRegularCloseCache() 와 동일 키·우선순위를 재사용한다:
     *   1) yahoo_regular_close_{ticker} — regular_close 워머가 채우는 정본. 애프터마켓엔 오늘 정규장 종가.
     *   2) kis_last_successful_overseas_price_{ticker}.regular_close — 24h 폴백.
     *   3) null — 둘 다 cold(호출부는 candles[1] 유지).
     *
     * US 티커는 tossSymbol == 앱 심볼(대문자)이라 캐시 키가 일치한다(TossSymbolMapper::normalize 대문자화).
     */
    private function readUsRegularClose(string $ticker): ?float
    {
        $cached = Cache::get("yahoo_regular_close_{$ticker}");
        if ($cached !== null && (float) $cached > 0.0) {
            return (float) $cached;
        }

        $fallback = Cache::get("kis_last_successful_overseas_price_{$ticker}");
        if (is_array($fallback)
            && isset($fallback['regular_close'])
            && (float) $fallback['regular_close'] > 0.0
        ) {
            return (float) $fallback['regular_close'];
        }

        return null;
    }

    /**
     * 다음 국내 정규장 개장(09:00 KST)까지 남은 초.
     *
     * 하한 없음(max(...,1)) — 300초 하한은 개장(09:00) 경계를 5분 넘겨 기준가를 stale 로 살려두는 원인이다
     * (US 경로에서 동일 근거로 제거·검증됨).
     */
    private function secondsUntilNextKrOpen(): int
    {
        $nowTs = Carbon::now()->getTimestamp();
        $target = Carbon::now('Asia/Seoul')->setTime(9, 0);
        if ($target->getTimestamp() <= $nowTs) {
            $target->addDay();
        }

        return max($target->getTimestamp() - $nowTs, 1);
    }

    /**
     * US 기준가(prevClose)의 '의미가 바뀌는 다음 경계'까지 남은 초.
     *
     * 경계를 하나씩 추가하는 방식은 3번 연속 실패했다(7/14 자정만 → 7/16 16:05만 → 7/17 min(16:05,09:30)).
     * 어느 하나만 쓰면 나머지 경계를 넘겨 캐시가 stale 로 살아남는다. 그래서 '무엇이 뒤집히는 시각'을
     * 전부 열거해 최소값을 쓴다 — 새 경계가 생기면 이 목록에만 추가한다(호출부 공식 복제 금지).
     *
     * 경계 7개와 각각이 뒤집는 것(ET 기준):
     *   00:00 — 날짜가 바뀜 → isTodayBar(candles[0] 이 '오늘봉'인지) 반전 → 기준가 candles[0]↔candles[1] 전진
     *   04:00 — 주간거래 종료·프리마켓 개시 → 라이브 연속이라 지금은 아무것도 안 뒤집지만, 세션 라벨이
     *           바뀌는 시각이라 보수적으로 남긴다(경계 추가는 안전, 누락만 stale 을 만든다)
     *   09:30 — 정규장 개시            → 장마감 분기(candles[1]) → 라이브 분기(candles[0])
     *   16:00 — 정규장 마감/애프터 개시 → getUsSession '정규장' 이탈 → 롤포워드 조건 성립
     *   16:05 — 정규장 종가 확정       → yahoo_regular_close 워머 반영분으로 롤포워드 기준가 교체
     *   19:50 — 애프터마켓 종료        → isMarketLiveNow false
     *   20:00 — 주간거래 개시          → isMarketLiveNow true
     *
     * 03:30 은 목록에서 제거했다(2026-07-17) — 토스 앱 안내(docs/거래시간.jpg)의 "주간거래 ~03:30"은
     * 허구였다. 실측: ET 03:30~04:00 91/91분 전부 체결(무거래봉 0), 04:01 거래량 169→44,397 폭증 =
     * 진짜 프리마켓 개시. 애프터 종료도 19:30 → 19:50(토스 캘린더 정합, 19:51 NVDA 15,249주 체결).
     * MarketSessionService::getUsSession 의 경계와 항상 같이 움직여야 한다.
     *
     * 하한 없음(max(...,1)): 300초 하한은 그 자체가 경계를 넘겨버리는 구조라 제거했다.
     * 최대 TTL ≈ 4h(20:00→00:00) → 심볼당 /candles ≤ 8회/일.
     */
    private function secondsUntilNextUsBoundary(): int
    {
        $now = Carbon::now('America/New_York');
        $nowTs = $now->getTimestamp();
        $next = null;

        foreach ([[0, 0], [4, 0], [9, 30], [16, 0], [16, 5], [19, 50], [20, 0]] as [$h, $m]) {
            $t = $now->copy()->setTime($h, $m);
            if ($t->getTimestamp() <= $nowTs) {
                $t->addDay();
            }
            $next = $next === null ? $t->getTimestamp() : min($next, $t->getTimestamp());
        }

        return max($next - $nowTs, 1);
    }
}
