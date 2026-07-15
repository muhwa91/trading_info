<?php

declare(strict_types=1);

namespace App\Services\Toss;

use App\Services\MarketSessionService;
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

    /** KR 정규장 마감 시각(KST, HHMM). 이 시각 초과 첫 분봉부터 마감 동시호가 종가가 찍힌다. */
    private const KR_REGULAR_CLOSE_HHMM = 1530;

    /** 마감 직후 '종가 평탄(시간외종가)' 구간 끝(KST, HHMM). 15:31~15:40 close = 확정 정규장 종가. */
    private const KR_CLOSE_PLATEAU_END_HHMM = 1540;

    /** 정규장 종가 추출용 1m 봉 취득 수 — 저녁 콜드스타트(마감후 최대 ~150분)에도 15:31 도달 보장. */
    private const KR_CLOSE_CANDLE_COUNT = 200;

    /** 종가 취득 일시 실패(null) 시 sentinel 캐시의 짧은 TTL(초) — 다음 개장까지 0 고착 방지, 곧 재시도. */
    private const KR_CLOSE_FAIL_TTL = 120;

    /** 캔들 엔드포인트 */
    private const CANDLES_ENDPOINT = '/api/v1/candles';

    /** 국내 기준가(가격제한폭) 엔드포인트 — (상한가+하한가)/2 = 당일 거래소 기준가 */
    private const PRICE_LIMITS_ENDPOINT = '/api/v1/price-limits';

    /**
     * price-limits 롤오버 판정 허용오차(비율). 다음 거래일 기준가 ≈ 오늘 정규장 종가라,
     * price-limits 기준가가 오늘 종가와 이 오차 미만이면 '조기 롤오버'로 보고 candles[1] 로 폴백.
     */
    private const ROLLOVER_EPSILON = 0.001;  // 0.1%

    private TossApiClient $client;

    private TossSymbolMapper $mapper;

    private MarketSessionService $session;

    public function __construct(TossApiClient $client, TossSymbolMapper $mapper, MarketSessionService $session)
    {
        $this->client  = $client;
        $this->mapper  = $mapper;
        $this->session = $session;
    }

    /**
     * prevClose 를 기반으로 등락액·등락률을 계산하여 반환한다.
     *
     * prevClose 캐시 miss 시 /candles 호출 후 캐시 저장.
     * API 실패 시 graceful — change=0, percent=0.
     *
     * @param  string  $tossSymbol  토스 API 심볼 (국내: 005930 등)
     * @param  float   $lastPrice   토스 /prices 에서 받은 현재가
     * @return array{change_amount:float,change_percent:float,prev_close:float|null}
     */
    public function calculate(string $tossSymbol, float $lastPrice): array
    {
        $prevClose = $this->getPrevClose($tossSymbol);

        if ($prevClose === null || $prevClose <= 0.0) {
            return [
                'change_amount'  => 0.0,
                'change_percent' => 0.0,
                'prev_close'     => null,
            ];
        }

        $changeAmount  = $lastPrice - $prevClose;
        $changePercent = ($changeAmount / $prevClose) * 100.0;

        return [
            'change_amount'  => round($changeAmount, 4),
            'change_percent' => round($changePercent, 4),
            'prev_close'     => $prevClose,
        ];
    }

    /**
     * 전일 종가를 반환한다 (캐시 우선, miss 시 /candles 호출).
     *
     * TTL: 당일 KST 자정까지 남은 초.
     * 국내 장(KST 09:00~15:30) 기준 — 토스 1d 봉이 완료된 것만 취득.
     *
     * @return float|null  prevClose (없으면 null)
     */
    public function getPrevClose(string $tossSymbol): ?float
    {
        $cacheKey = self::CACHE_PREFIX . $tossSymbol;
        $cached   = Cache::get($cacheKey);

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
        if (!$this->session->isKrTradingDay($nowTs)) {
            return null;  // 휴장·주말 → 현행(전일 마감) 유지
        }

        $cacheKey = self::KR_CLOSE_PREFIX . $tossSymbol;
        $cached   = Cache::get($cacheKey);
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

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * 오늘(KST) 국내 정규장 종가를 1m 분봉의 '마감 직후 plateau'에서 추출. 못 구하면 null.
     *
     * 추출 규칙 (재시작에도 안정한 이유):
     *   - /candles?interval=1m&count=200 로 최근 분봉을 받는다(마감후 최대 ~150분 → 15:31 도달 보장).
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
            $response = $this->client->get(self::CANDLES_ENDPOINT, [
                'symbol'   => $tossSymbol,
                'interval' => '1m',
                'count'    => self::KR_CLOSE_CANDLE_COUNT,
            ]);

            $candles = $this->extractCandles($response);
            if ($candles === null) {
                return null;
            }

            $today         = Carbon::now('Asia/Seoul')->toDateString();
            $plateauCloses = [];  // 등장 순서 유지 (tie → 최초값 우선)
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
                if ($hhmm > self::KR_REGULAR_CLOSE_HHMM && $hhmm <= self::KR_CLOSE_PLATEAU_END_HHMM) {
                    $close = isset($c['closePrice']) ? (float) $c['closePrice'] : 0.0;
                    if ($close > 0.0) {
                        $plateauCloses[] = $close;
                    }
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
                'symbol'   => $tossSymbol,
                'interval' => '1d',
                'count'    => 2,
            ]);

            $candles = $this->extractCandles($response);
            if ($candles === null) {
                return null;
            }

            // 최신 봉 = timestamp 내림차순 정렬 후 index 0
            usort($candles, function (array $a, array $b): int {
                return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
            });

            $latest     = $candles[0];
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

        $result  = $response['result'] ?? null;
        $candles = null;
        if (is_array($result) && isset($result['candles'])) {
            $candles = $result['candles'];
        } elseif (isset($response['candles'])) {
            $candles = $response['candles'];
        } elseif (is_array($result)) {
            $candles = $result;
        }

        return (is_array($candles) && !empty($candles)) ? $candles : null;
    }

    /**
     * 종가 목록의 최빈값(mode)을 반환. 동률이면 목록 등장순 최초값 우선(이상 봉 방어).
     *
     * @param array<int,float> $closes  (비어있지 않음 전제)
     */
    private function modeClose(array $closes): float
    {
        $counts = [];
        foreach ($closes as $c) {
            $key          = (string) $c;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $best      = $closes[0];
        $bestCount = -1;
        foreach ($closes as $c) {  // 등장순 순회 + strict > → 동률 시 최초값 유지
            if ($counts[(string) $c] > $bestCount) {
                $bestCount = $counts[(string) $c];
                $best      = $c;
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
                'symbol'   => $tossSymbol,
                'interval' => '1d',
                'count'    => 2,
            ]);

            if (empty($response)) {
                Log::warning("[TossChangeCalculator] /candles 빈응답: {$tossSymbol}");
                return null;
            }

            // 실측 구조: result.candles 배열
            $result  = $response['result'] ?? null;
            $candles = null;

            if (is_array($result) && isset($result['candles'])) {
                // 정상 응답: { result: { candles: [...] } }
                $candles = $result['candles'];
            } elseif (is_array($result) && !isset($result['candles'])) {
                // result 가 직접 배열인 경우 (키가 숫자 인덱스) — 호환 처리
                $candles = $result;
            } elseif (isset($response['candles'])) {
                // 루트에 candles 가 있는 경우
                $candles = $response['candles'];
            }

            if (!is_array($candles) || count($candles) < 2) {
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
            $currency   = $candles[0]['currency'] ?? null;
            $isUsMarket = $currency !== null
                ? $currency === 'USD'
                : $this->mapper->market($tossSymbol) === 'US';
            $tz         = $isUsMarket ? 'America/New_York' : 'Asia/Seoul';
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
                $prevCandle   = $candles[1];
                $marketClosed = true;
            }
            $prevClose  = isset($prevCandle['closePrice']) ? (float) $prevCandle['closePrice'] : null;

            if ($prevClose === null || $prevClose <= 0.0) {
                Log::warning("[TossChangeCalculator] {$tossSymbol} prevClose 이상: " . json_encode($prevCandle));
                return null;
            }

            // 국내 기준가 교체:
            //   토스 앱 등락은 candles 종가가 아니라 '당일 거래소 기준가'에 대해 계산된다.
            //   한국 가격제한폭은 기준가에 대칭 → (상한가+하한가)/2 = 당일 기준가 (candles[1].close 와 분리됨).
            //   실증: 000660 (2,486,000+1,340,000)/2 = 1,913,000 (토스 앱 기준가 일치, candles 7/14 종가 1,941,000 ≠).
            //   분기 1·2(오늘봉 존재 OR 라이브 정규장)에서만 교체 — 분기 3(장마감·개장 전, marketClosed)은
            //   candles[1](전전일 종가)로 '어제 하루 등락'을 유지해야 하므로 건드리지 않는다
            //   (price-limits 는 실시간 당일 기준가만 주므로 개장 전엔 다음 거래일 기준가로 0% 회귀 위험).
            //   US 는 한국 price-limits 없음 → 위에서 구한 candles/Yahoo 기준가 그대로 유지.
            //   price-limits 호출 실패·빈 응답 시엔 위 candles 기반 $prevClose 로 graceful 폴백(캐시 기아 방지).
            if (!$isUsMarket && !$marketClosed) {
                $refPrice = $this->fetchKrReferencePrice($tossSymbol);
                if ($refPrice !== null && !$this->isPriceLimitRolledOver($tossSymbol, $refPrice)) {
                    $prevClose = $refPrice;
                }
                // 롤오버로 판정되면 price-limits 를 버리고 candles[1](어제 종가)인 $prevClose 를 유지.
            }

            // TTL 결정:
            //   3번 분기(장마감 기준가)는 '다음 개장 시각까지'만 유효 — 개장 순간 기준가가 어제 종가로
            //   바뀌어야 하므로 그 이후까지 캐시가 살아남으면 전전일 기준으로 stale → 부호반전(H-3 교훈).
            //   KR = 다음 09:00 KST · US = 다음 09:30 ET.
            //   1·2번 분기(라이브/오늘봉)는 현행 TTL 유지 — US = 다음 16:05 ET(정규장이 KST 자정을 걸쳐
            //   진행되어 자정 TTL 이면 한 거래일 stale, MU 7/14) · KR = KST 자정.
            if ($marketClosed) {
                $ttl = $isUsMarket ? $this->secondsUntilNextUsOpen() : $this->secondsUntilNextKrOpen();
            } elseif ($isUsMarket) {
                $nyTz   = new \DateTimeZone('America/New_York');
                $target = new \DateTime('today 16:05', $nyTz);
                if ($target->getTimestamp() <= time()) {
                    $target = new \DateTime('tomorrow 16:05', $nyTz);
                }
                $ttl = max($target->getTimestamp() - time(), 300);
            } else {
                $ttl = $this->secondsUntilKstMidnight();
            }

            Cache::put(self::CACHE_PREFIX . $tossSymbol, $prevClose, $ttl);

            Log::debug("[TossChangeCalculator] prevClose 캐싱", [
                'symbol'    => $tossSymbol,
                'prevClose' => $prevClose,
                'ttl'       => $ttl,
            ]);

            return $prevClose;
        } catch (\Throwable $e) {
            Log::error("[TossChangeCalculator] {$tossSymbol} 캔들 조회 실패: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 국내 종목 당일 거래소 기준가 = /api/v1/price-limits 의 (상한가+하한가)/2.
     *
     * 한국 가격제한폭은 기준가에 대칭이라 (upper+lower)/2 = 당일 기준가(전일 종가와 분리).
     * 토스 앱 등락률은 candles 종가가 아니라 이 기준가에 대해 계산된다(실증: 000660 = 1,913,000).
     *
     * 단건 전용 — symbols=(복수)는 [] 반환이므로 symbol=(단건)으로만 호출한다.
     * rate-limit(500ms) 은 TossApiClient 에 이미 등록됨. 캐시 miss(1일 1회)에서만 호출되어 콜 폭증 없음.
     *
     * 빈 응답·파싱 실패 시 null → 호출부에서 candles 기반 기준가로 폴백.
     *
     * 실측 응답 구조(단건): { "result": { "upperLimitPrice": "...", "lowerLimitPrice": "...", ... } }.
     *   문자열일 수 있어 (float) 캐스팅. result 가 리스트([0])이거나 루트 직배치인 변형도 방어.
     */
    private function fetchKrReferencePrice(string $tossSymbol): ?float
    {
        $response = $this->client->get(self::PRICE_LIMITS_ENDPOINT, ['symbol' => $tossSymbol]);

        if (empty($response)) {
            Log::warning("[TossChangeCalculator] price-limits 빈응답 — candles 폴백: {$tossSymbol}");
            return null;
        }

        // 응답 구조 방어: result.assoc / result[0] / 루트 직배치
        $result = $response['result'] ?? null;
        if (is_array($result) && array_key_exists('upperLimitPrice', $result)) {
            $data = $result;
        } elseif (is_array($result) && isset($result[0]) && is_array($result[0])) {
            $data = $result[0];
        } elseif (array_key_exists('upperLimitPrice', $response)) {
            $data = $response;
        } else {
            Log::warning("[TossChangeCalculator] price-limits 구조 불명 — candles 폴백: {$tossSymbol}", [
                'keys' => array_keys($response),
            ]);
            return null;
        }

        $upper = isset($data['upperLimitPrice']) ? (float) $data['upperLimitPrice'] : 0.0;
        $lower = isset($data['lowerLimitPrice']) ? (float) $data['lowerLimitPrice'] : 0.0;

        if ($upper <= 0.0 || $lower <= 0.0) {
            Log::warning("[TossChangeCalculator] price-limits 값 이상 — candles 폴백: {$tossSymbol}", [
                'upper' => $upper,
                'lower' => $lower,
            ]);
            return null;
        }

        return ($upper + $lower) / 2.0;
    }

    /**
     * price-limits 기준가가 '다음 거래일 기준가'로 조기 롤오버됐는지 판정한다.
     *
     * 배경: 국내 price-limits 는 장마감 후 일부 종목(특히 ETF)에서 다음 거래일 기준가로
     *   먼저 갱신된다. 다음 거래일 기준가 ≈ 오늘 정규장 종가이므로, 롤오버된 기준가는
     *   오늘 종가와 사실상 같아져 오늘 등락이 0%로 뭉개진다(실측 SOL 0167A0: 기준가 20,600 = 종가).
     *   미롤오버 종목은 기준가가 종가와 뚜렷이 벌어진다(삼성 263,000 ≠ 종가 279,500).
     *
     * 판정: |기준가 − 오늘 정규장 종가| / 오늘 종가 < ε(0.1%) 이면 롤오버.
     *   '오늘 정규장 종가'는 getKrRegularClose()(분봉 plateau)로 얻는다 — 장마감·거래일에만 값이 있고,
     *   정규장 중엔 null 을 돌려주므로 이 가드는 자연히 스킵된다(정규장 땐 롤오버가 없어 불필요).
     *   종가를 못 구하면(콜드스타트 등) false → price-limits 를 그대로 신뢰(기존 동작 유지).
     */
    private function isPriceLimitRolledOver(string $tossSymbol, float $refPrice): bool
    {
        $todayClose = $this->getKrRegularClose($tossSymbol);

        if ($todayClose === null || $todayClose <= 0.0) {
            return false;  // 정규장 중·종가 미확보 → 가드 스킵, price-limits 신뢰
        }

        return abs($refPrice - $todayClose) / $todayClose < self::ROLLOVER_EPSILON;
    }

    /**
     * 당일 KST(Asia/Seoul) 자정까지 남은 초를 계산한다.
     *
     * 최솟값 300초(5분) — 자정 직전에도 너무 짧은 TTL 방지.
     */
    private function secondsUntilKstMidnight(): int
    {
        $now      = Carbon::now('Asia/Seoul');
        $midnight = $now->copy()->endOfDay()->addSecond();  // 다음날 00:00:00 KST
        $seconds  = max(300, (int) $now->diffInSeconds($midnight, false));

        return $seconds;
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
     * 다음 국내 정규장 개장(09:00 KST)까지 남은 초. 최솟값 300초.
     */
    private function secondsUntilNextKrOpen(): int
    {
        $seoulTz = new \DateTimeZone('Asia/Seoul');
        $target  = new \DateTime('today 09:00', $seoulTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 09:00', $seoulTz);
        }

        return max($target->getTimestamp() - time(), 300);
    }

    /**
     * 다음 미국 정규장 개장(09:30 ET)까지 남은 초. 최솟값 300초.
     */
    private function secondsUntilNextUsOpen(): int
    {
        $nyTz   = new \DateTimeZone('America/New_York');
        $target = new \DateTime('today 09:30', $nyTz);
        if ($target->getTimestamp() <= time()) {
            $target = new \DateTime('tomorrow 09:30', $nyTz);
        }

        return max($target->getTimestamp() - time(), 300);
    }
}
