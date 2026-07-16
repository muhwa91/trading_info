<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Toss\TossApiClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 미국/한국 시장 세션 판정 서비스.
 *
 * StockController 에 있던 세션 관련 private 메서드들을 추출·공개한 클래스.
 * Cache 키는 StockController 와 동일해 캐시가 공유된다:
 *   - US 거래일: us_trading_day_{Y-m-d}  (NY 기준)
 *   - KR 거래일: kis_trading_day_{Ymd}   (Seoul 기준, 7일 TTL)
 */
class MarketSessionService
{
    private TossApiClient $tossApiClient;

    public function __construct(TossApiClient $tossApiClient)
    {
        $this->tossApiClient = $tossApiClient;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 unix timestamp 기준의 미국 시장 세션명을 반환한다.
     *
     * 반환값: '주간거래' | '프리마켓' | '정규장' | '애프터마켓' | '장마감'
     */
    public function getUsSession(int $timestamp): string
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N'); // 1=Mon … 7=Sun
        $timeVal   = (int)$dt->format('H') * 100 + (int)$dt->format('i');

        // 주말 휴장 경계(NY): 금 20:00 ~ 일 20:00 은 어떤 세션도 없음
        if ($dayOfWeek === 6) {
            return '장마감';
        }
        if ($dayOfWeek === 7 && $timeVal < 2000) {
            return '장마감';
        }
        if ($dayOfWeek === 5 && $timeVal >= 2000) {
            return '장마감';
        }

        // 주간거래: ET 20:00 ~ 익일 03:30 — 날짜를 넘나드는 세션이라 거래일 게이트 미적용(주말만).
        if ($timeVal >= 2000 || $timeVal < 330) {
            return '주간거래';
        }

        // 데이 세션(프리/정규/애프터)은 'NY 오늘'이 거래일일 때만 — 공휴일이면 장마감
        if (!$this->isUsMarketTradingToday($timestamp)) {
            return '장마감';
        }

        if ($timeVal >= 400 && $timeVal < 930) {
            return '프리마켓';
        }
        if ($timeVal >= 930 && $timeVal < 1600) {
            return '정규장';
        }
        if ($timeVal >= 1600 && $timeVal < 1930) {
            return '애프터마켓';
        }
        // ET 03:30~04:00, 19:30~20:00 공백
        return '장마감';
    }

    /**
     * 주어진 unix timestamp 기준의 한국 시장 세션명을 반환한다.
     *
     * 반환값: '정규장' | '장마감'
     */
    public function getKrSession(int $timestamp): string
    {
        if (!$this->isKrTradingDay($timestamp)) {
            return '장마감';
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int)$dt->format('Hi'); // e.g. 0900
        if ($timeVal >= 900 && $timeVal <= 1530) {
            return '정규장';
        }

        return '장마감';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // US trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 timestamp(기본: 현재) 의 NY 날짜가 미국 거래일인지 판정한다.
     *
     * Cache 키 `us_trading_day_{Y-m-d}` 는 StockController 와 공유된다.
     * Yahoo SPY meta 는 '지금'의 거래일만 알려주므로, 오늘이 아닌 날짜는 주말 폴백으로만 판정한다.
     * API 실패 시 폴백: 주말 여부(평일=거래일 가정) — 공휴일 하드코딩 없음.
     */
    public function isUsMarketTradingToday(?int $timestamp = null): bool
    {
        $nyTz    = new \DateTimeZone('America/New_York');
        $refDt   = new \DateTime($timestamp !== null ? "@{$timestamp}" : 'now');
        $refDt->setTimezone($nyTz);
        $refNy   = $refDt->format('Y-m-d');
        $todayNy = (new \DateTime('now', $nyTz))->format('Y-m-d');

        $cacheKey = "us_trading_day_{$refNy}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        // Yahoo SPY meta 는 '지금'의 거래 세션만 알려준다 — NY 오늘일 때만 조회, 그 외는 폴백.
        if ($refNy === $todayNy) {
            try {
                $client = new Client();
                // SPY 는 NYSE Arca 상장 ETF — 가벼운 1d/1d 요청으로 meta 만 취득
                $url = 'https://query1.finance.yahoo.com/v8/finance/chart/SPY?interval=1d&range=1d';
                $response = $client->get($url, [
                    'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
                    'timeout' => 5,
                ]);
                $data = json_decode($response->getBody()->getContents(), true);

                $regularStart = $data['chart']['result'][0]['meta']['currentTradingPeriod']['regular']['start'] ?? null;
                if ($regularStart !== null) {
                    $startDt = new \DateTime("@{$regularStart}");
                    $startDt->setTimezone($nyTz);
                    $startNyDate = $startDt->format('Y-m-d');

                    $isTradingDay = ($startNyDate === $todayNy);

                    // 오늘 자정(NY)까지 캐싱
                    $midnight = new \DateTime('tomorrow', $nyTz);
                    $ttl = $midnight->getTimestamp() - time();
                    Cache::put($cacheKey, $isTradingDay, max($ttl, 300));

                    return $isTradingDay;
                }
            } catch (\Exception $e) {
                Log::warning('MarketSessionService::isUsMarketTradingToday: Yahoo fetch 실패, 주말 폴백 사용: ' . $e->getMessage());
            }
        }

        // 폴백: 요청 날짜의 주말 여부로만 판정 — 짧게 캐싱해 API 회복 시 갱신
        $isWeekday = ((int)$refDt->format('N')) <= 5;
        Cache::put($cacheKey, $isWeekday, 300);
        return $isWeekday;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // KR trading day check
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 주어진 timestamp 가 KRX 거래일인지 토스 마켓캘린더 API 로 판정.
     *
     * Cache 키 `kis_trading_day_{Ymd}` 는 하위 호환을 위해 유지한다.
     * API 실패 시 폴백: 주말 + 고정공휴일 판정 (TTL 30분, API 회복 시 갱신).
     */
    public function isKrTradingDay(int $timestamp): bool
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        $dateStr  = $dt->format('Ymd');
        $cacheKey = "kis_trading_day_{$dateStr}"; // 캐시 키 하위 호환 유지

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        // 토스 캘린더로 확정 가능한 날짜 조회 (전 영업일 ~ 다음 영업일)
        $opened = $this->fetchTossOpenedDays();
        if (!empty($opened)) {
            // 확정값만 담기므로 7일 캐싱 안전 (폴백 추정치는 아래에서 30분만 캐싱)
            foreach ($opened as $d => $isOpen) {
                Cache::put("kis_trading_day_{$d}", $isOpen, 60 * 60 * 24 * 7);
            }
            if (array_key_exists($dateStr, $opened)) {
                return $opened[$dateStr];
            }
        }

        // 폴백: 주말 + 고정공휴일
        $isWeekday = ((int)$dt->format('N')) <= 5;
        if (!$isWeekday) {
            Cache::put($cacheKey, false, 60 * 30);
            return false;
        }
        $mmdd           = $dt->format('md');
        $fixedHolidays  = ['0101', '0301', '0505', '0815', '1003', '1009', '1225'];
        $isHoliday      = in_array($mmdd, $fixedHolidays, true);
        $result         = !$isHoliday;
        Cache::put($cacheKey, $result, 60 * 30);
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal — Toss market calendar API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 토스 마켓캘린더 API → [Ymd => 거래일여부(bool)] 맵 반환.
     *
     * 응답은 from/to 를 무시하고 **오늘 기준 3일**만 준다:
     *   { result: { today:{date,integrated}, previousBusinessDay:{...}, nextBusinessDay:{...} } }
     *   integrated !== null → 거래일 · integrated === null → 휴장.
     *
     * 이 3일에서 **확정** 가능한 날짜만 맵에 담는다:
     *   - previousBusinessDay.date / nextBusinessDay.date → 거래일(true)
     *   - today → integrated 유무로 true/false
     *   - prev < d < today, today < d < next → 그 사이엔 영업일이 없으므로 비거래일(false)
     * 그 밖의 날짜는 담지 않는다(호출부가 폴백으로 처리).
     *
     * API 실패 또는 응답 형식 미인식 시 빈 배열 반환.
     *
     * @return array<string,bool>
     */
    private function fetchTossOpenedDays(): array
    {
        try {
            // from/to 는 서버가 무시한다(실측) — 항상 '오늘' 기준 3일치가 온다.
            $data = $this->tossApiClient->get('/api/v1/market-calendar/KR');

            if (empty($data)) {
                return [];
            }

            $result    = $data['result'] ?? null;
            $todayDate = $this->toYmd($result['today']['date'] ?? null);
            if (!is_array($result) || $todayDate === null) {
                Log::warning('MarketSessionService: 토스 캘린더 응답 형식 미인식', ['keys' => array_keys($data)]);
                return [];
            }

            $prevDate = $this->toYmd($result['previousBusinessDay']['date'] ?? null);
            $nextDate = $this->toYmd($result['nextBusinessDay']['date'] ?? null);

            $map = [];
            if ($prevDate !== null) {
                $map[$prevDate] = true;
                $this->fillNonTradingGap($map, $prevDate, $todayDate);
            }
            $map[$todayDate] = ($result['today']['integrated'] ?? null) !== null;
            if ($nextDate !== null) {
                $this->fillNonTradingGap($map, $todayDate, $nextDate);
                $map[$nextDate] = true;
            }

            return $map;
        } catch (\Exception $e) {
            Log::error('MarketSessionService: 토스 캘린더 조회 실패: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 토스 응답의 'Y-m-d' 날짜 문자열 → 'Ymd'. 형식이 아니면 null.
     */
    private function toYmd($date): ?string
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return str_replace('-', '', $date);
    }

    /**
     * $from 과 $to (둘 다 Ymd, 배타) 사이의 날짜를 비거래일로 채운다.
     * 연속한 두 영업일 사이 = 휴장 확정.
     *
     * @param  array<string,bool>  $map
     */
    private function fillNonTradingGap(array &$map, string $from, string $to): void
    {
        $cursor = date('Ymd', strtotime($from . ' +1 day'));
        while ($cursor < $to) {
            $map[$cursor] = false;
            $cursor = date('Ymd', strtotime($cursor . ' +1 day'));
        }
    }
}
