<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use App\Services\Toss\TossFxProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * USD→KRW 환율 관리 서비스.
 *
 * 소스 우선순위 (Phase 2 교체 후):
 *   1순위 — 토스증권 Open API (/api/v1/exchange-rate)
 *   2순위 — DB 직전 캐시값 (신선도 FX_STALE_SECONDS 이내)
 *   3순위 — DB 최후 저장값 (신선도 만료여도 반환, 완전 폴백)
 *
 * exchange_rates 테이블에 (from_currency, to_currency) 고유 키로 최신 1건 upsert.
 * 신선도: FX_STALE_SECONDS(기본 300초=5분) 이내 데이터는 외부 호출 생략.
 *
 * 설계 참조: docs/features/toss-api-migration/01-계획.md §2.2 (FxService 행), Phase 2.
 */
class FxService
{
    /** 환율 신선도 임계값(초). 5분. */
    private const FX_STALE_SECONDS = 300;

    private const FROM = 'USD';
    private const TO   = 'KRW';

    /** USD/KRW 전일 종가 캐시 키 — 하루 1회 갱신(ET 자정 경계). */
    private const FX_PREV_CLOSE_CACHE_KEY = 'yahoo_fx_prev_close_usdkrw';

    /** Yahoo Finance v8 chart endpoint — USDKRW=X 전일 종가 소스. */
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/USDKRW=X?interval=1d&range=2d';

    /** Yahoo 전일 종가 요청 타임아웃(초). */
    private const YAHOO_TIMEOUT = 5;

    private TossFxProvider $tossFxProvider;

    public function __construct(TossFxProvider $tossFxProvider)
    {
        $this->tossFxProvider = $tossFxProvider;
    }

    /**
     * USD→KRW 최신 환율 반환.
     *
     * 신선하면 DB값 그대로, 오래됐으면:
     *   1. 토스 API 호출
     *   2. 실패 시 DB 직전값 (신선도 무관)
     *   3. DB도 없으면 null
     *
     * 반환 배열에는 전일 종가 prev_close(Yahoo USDKRW=X, float|null)가 항상 포함된다.
     * 프론트의 "환율 전일 대비 ▲/▼" 표시용 — 취득 실패 시 null(graceful).
     *
     * @return array{rate:float,recorded_at:string,source:string,prev_close:float|null}|null
     */
    public function getUsdKrw(): ?array
    {
        // 전일 종가는 하루 단위로 천천히 변하는 값 — 캐시 히트가 대부분(1일 1회 HTTP).
        $prevClose = $this->fetchPrevClose();

        $row = ExchangeRate::where('from_currency', self::FROM)
            ->where('to_currency', self::TO)
            ->first();

        $staleThreshold = Carbon::now()->subSeconds(self::FX_STALE_SECONDS);

        // DB 값이 신선하면 외부 호출 생략
        if ($row !== null && $row->recorded_at !== null && $row->recorded_at->gt($staleThreshold)) {
            return [
                'rate'        => (float) $row->rate,
                'recorded_at' => $row->recorded_at->toDateTimeString(),
                'source'      => (string) ($row->source ?? 'cached'),
                'prev_close'  => $prevClose,
            ];
        }

        // ── 1순위: 토스 환율 API ─────────────────────────────────────────
        $fetched = $this->tossFxProvider->fetchUsdKrw();

        // ── 2순위: DB 직전값 (토스 실패 시) ────────────────────────────────
        if ($fetched === null) {
            Log::warning('[FxService] 토스 환율 취득 실패 — DB 직전값 사용');
            if ($row !== null) {
                return [
                    'rate'        => (float) $row->rate,
                    'recorded_at' => $row->recorded_at ? $row->recorded_at->toDateTimeString() : null,
                    'source'      => 'db_fallback',
                    'prev_close'  => $prevClose,
                ];
            }

            // ── 3순위: DB 완전 폴백 (값은 있지만 신선도 만료) ────────────────
            Log::warning('[FxService] DB값 없음 — 환율 취득 불가');
            return null;
        }

        $this->upsertRate($fetched['rate'], $fetched['recorded_at'], $fetched['source']);

        $fetched['prev_close'] = $prevClose;

        return $fetched;
    }

    /**
     * USD/KRW 전일 종가를 Yahoo USDKRW=X chart meta.chartPreviousClose 에서 조회.
     *
     * 캐시 키: yahoo_fx_prev_close_usdkrw — 다음 ET 자정까지 TTL(일 단위 경계).
     *   전일 종가는 확정값(진행 중 아님)이라 regular_close 의 16:05 REGULAR 가드는 불필요.
     * 실패·필드 없음 시 null 반환 (예외 전파 금지 — 기존 폴백 스타일 유지).
     *
     * @return float|null
     */
    private function fetchPrevClose(): ?float
    {
        $cached = Cache::get(self::FX_PREV_CLOSE_CACHE_KEY);
        if ($cached !== null) {
            return (float) $cached > 0 ? (float) $cached : null;
        }

        try {
            $httpClient = new Client();
            $res        = $httpClient->get(self::YAHOO_CHART_URL, [
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'http_errors' => false,
                'timeout'     => self::YAHOO_TIMEOUT,
            ]);

            $data = json_decode((string) $res->getBody(), true);
            $prev = $data['chart']['result'][0]['meta']['chartPreviousClose'] ?? null;

            if ($prev === null || (float) $prev <= 0) {
                Log::debug('[FxService] Yahoo USDKRW=X chartPreviousClose 없음');
                return null;
            }

            $prev = (float) $prev;

            // 다음 ET 자정까지 캐시 (일 단위 경계) — 하루 1회만 HTTP.
            $nyTz   = new \DateTimeZone('America/New_York');
            $target = new \DateTime('tomorrow', $nyTz);
            $ttl    = max($target->getTimestamp() - time(), 300);

            Cache::put(self::FX_PREV_CLOSE_CACHE_KEY, $prev, $ttl);

            return $prev;
        } catch (\Throwable $e) {
            Log::warning('[FxService] USD/KRW 전일 종가 취득 실패: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * exchange_rates upsert: (from_currency, to_currency) 기준 최신 1건 유지.
     */
    public function upsertRate(float $rate, string $recordedAt, string $source = 'unknown'): void
    {
        DB::table('exchange_rates')->upsert(
            [
                'from_currency' => self::FROM,
                'to_currency'   => self::TO,
                'rate'          => $rate,
                'recorded_at'   => $recordedAt,
                'source'        => $source,
            ],
            ['from_currency', 'to_currency'],
            ['rate', 'recorded_at', 'source']
        );
    }
}
