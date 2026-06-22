<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * USD→KRW 환율 관리 서비스.
 *
 * 소스 우선순위:
 *   1순위 — KIS 해외주식 현재가상세(HHDFS76200200) 의 t_rate 필드
 *            (KIS 고시환율 = 증권사 포트폴리오 평가에 쓰이는 당일 적용환율)
 *   2순위 — DB 직전 캐시값
 *   3순위 — Yahoo Finance USDKRW=X (최종 폴백)
 *
 * exchange_rates 테이블에 (from_currency, to_currency) 고유 키로 최신 1건 upsert.
 * 신선도: FX_STALE_SECONDS(기본 300초=5분) 이내 데이터는 외부 호출 생략.
 *
 * t_rate 출처 근거:
 *   HHDFS76200200 output.t_rate = "당일 적용 환율" (KIS 고시환율).
 *   검증: output.t_xprc(원화 평가가) / output.last(달러 현재가) ≈ t_rate 로 수치 일치 확인.
 */
class FxService
{
    /** 환율 신선도 임계값(초). 5분. */
    private const FX_STALE_SECONDS = 300;

    private const FROM = 'USD';
    private const TO   = 'KRW';

    /**
     * KIS 환율 조회에 사용할 기준 종목 (MSFT: 유동성 높고 NAS 상장 확실).
     * t_rate 는 종목 무관 동일 값이므로 아무 US 종목이나 사용 가능.
     */
    private const KIS_RATE_SYMBOL   = 'MSFT';
    private const KIS_RATE_EXCHANGE = 'NAS';

    /**
     * USD→KRW 최신 환율 반환.
     * 신선하면 DB값 그대로, 오래됐으면 KIS → (실패 시) DB캐시 → (없으면) Yahoo 순으로 시도.
     *
     * @return array{rate:float,recorded_at:string,source:string}|null
     */
    public function getUsdKrw(): ?array
    {
        $row = ExchangeRate::where('from_currency', self::FROM)
            ->where('to_currency', self::TO)
            ->first();

        $staleThreshold = Carbon::now()->subSeconds(self::FX_STALE_SECONDS);

        if ($row !== null && $row->recorded_at !== null && $row->recorded_at->gt($staleThreshold)) {
            return [
                'rate'        => (float)$row->rate,
                'recorded_at' => $row->recorded_at->toDateTimeString(),
                'source'      => (string)($row->source ?? 'cached'),
            ];
        }

        // ── 1순위: KIS 고시환율 ─────────────────────────────────────────
        $fetched = $this->fetchFromKis();

        // ── 2순위: DB 직전값 (KIS 실패 시) ────────────────────────────────
        if ($fetched === null) {
            Log::warning('[FxService] KIS 환율 취득 실패 — DB 직전값 사용');
            if ($row !== null) {
                return [
                    'rate'        => (float)$row->rate,
                    'recorded_at' => $row->recorded_at ? $row->recorded_at->toDateTimeString() : null,
                    'source'      => 'db_fallback',
                ];
            }

            // ── 3순위: Yahoo 최종 폴백 ────────────────────────────────────
            Log::warning('[FxService] DB값도 없음 — Yahoo Finance 폴백');
            $fetched = $this->fetchFromYahoo();
            if ($fetched === null) {
                return null;
            }
        }

        $this->upsertRate($fetched['rate'], $fetched['recorded_at'], $fetched['source']);

        return $fetched;
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

    /**
     * KIS 해외주식 현재가상세(HHDFS76200200) 의 t_rate 필드에서 당일 고시환율 취득.
     *
     * t_rate: KIS 가 포트폴리오 평가에 적용하는 당일 환율 (매매기준율 성격).
     * 검증식: output.t_xprc / output.last ≈ t_rate
     *
     * @return array{rate:float,recorded_at:string,source:string}|null
     */
    private function fetchFromKis(): ?array
    {
        $apiUrl = env('KIS_API_URL', 'https://openapi.koreainvestment.com:9443');
        $appKey = env('KIS_APP_KEY');
        $appSec = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
            Log::info('[FxService] KIS 키 미설정 — KIS 환율 스킵');
            return null;
        }

        $token = $this->getKisToken($apiUrl, $appKey, $appSec);
        if ($token === null) {
            return null;
        }

        try {
            $client   = new Client();
            $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price-detail", [
                'headers' => [
                    'content-type'  => 'application/json',
                    'authorization' => "Bearer {$token}",
                    'appkey'        => $appKey,
                    'appsecret'     => $appSec,
                    'tr_id'         => 'HHDFS76200200',
                ],
                'query' => [
                    'AUTH' => '',
                    'EXCD' => self::KIS_RATE_EXCHANGE,
                    'SYMB' => self::KIS_RATE_SYMBOL,
                ],
                'http_errors' => false,
                'timeout'     => 8,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // 토큰 만료 시 1회 재발급 후 재시도
            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                Cache::forget('kis_access_token');
                $token = $this->getKisToken($apiUrl, $appKey, $appSec, true);
                if ($token === null) {
                    return null;
                }
                $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price-detail", [
                    'headers' => [
                        'content-type'  => 'application/json',
                        'authorization' => "Bearer {$token}",
                        'appkey'        => $appKey,
                        'appsecret'     => $appSec,
                        'tr_id'         => 'HHDFS76200200',
                    ],
                    'query' => [
                        'AUTH' => '',
                        'EXCD' => self::KIS_RATE_EXCHANGE,
                        'SYMB' => self::KIS_RATE_SYMBOL,
                    ],
                    'http_errors' => false,
                    'timeout'     => 8,
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
            }

            if (($data['rt_cd'] ?? '1') !== '0') {
                Log::warning('[FxService] KIS HHDFS76200200 오류: ' . ($data['msg1'] ?? 'N/A'));
                return null;
            }

            $tRate = isset($data['output']['t_rate']) ? trim((string)$data['output']['t_rate']) : '';
            $rate  = $tRate !== '' ? (float)$tRate : 0.0;

            if ($rate <= 0.0) {
                Log::warning('[FxService] KIS t_rate 값 이상: ' . $tRate);
                return null;
            }

            Log::info("[FxService] KIS 고시환율 취득 성공: {$rate} (t_rate)");

            return [
                'rate'        => round($rate, 4),
                'recorded_at' => Carbon::now()->toDateTimeString(),
                'source'      => 'KIS_HHDFS76200200',
            ];
        } catch (\Throwable $e) {
            Log::error('[FxService] KIS HHDFS76200200 호출 실패: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * KIS 액세스 토큰 반환 (캐시 우선).
     * KisOverseasQuoteProvider 의 getAccessToken 과 동일 패턴 — 캐시 키 공유.
     */
    private function getKisToken(
        string $apiUrl,
        string $appKey,
        string $appSec,
        bool $forceRefresh = false
    ): ?string {
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token !== null) {
            return $token;
        }

        try {
            $client   = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey'     => $appKey,
                    'appsecret'  => $appSec,
                ],
                'headers' => ['content-type' => 'application/json'],
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            Log::error('[FxService] KIS 토큰 발급 실패: ' . ($data['msg1'] ?? 'Unknown'));
            return null;
        } catch (\Throwable $e) {
            Log::error('[FxService] KIS 토큰 호출 예외: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Yahoo Finance v8 chart USDKRW=X 에서 현재 환율 취득.
     * 모든 소스 실패 시 최종 폴백으로만 사용.
     *
     * @return array{rate:float,recorded_at:string,source:string}|null
     */
    private function fetchFromYahoo(): ?array
    {
        try {
            $client   = new Client();
            $response = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/USDKRW=X', [
                'query' => ['interval' => '1d', 'range' => '1d'],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'timeout' => 8,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $meta = $data['chart']['result'][0]['meta'] ?? null;

            if ($meta === null) {
                Log::warning('[FxService] Yahoo USDKRW=X 응답 이상');
                return null;
            }

            $rate = (float)($meta['regularMarketPrice'] ?? 0);
            if ($rate <= 0) {
                Log::warning('[FxService] Yahoo USDKRW=X rate=0');
                return null;
            }

            Log::info("[FxService] Yahoo 폴백 환율 사용: {$rate}");

            return [
                'rate'        => round($rate, 4),
                'recorded_at' => Carbon::now()->toDateTimeString(),
                'source'      => 'Yahoo_USDKRW',
            ];
        } catch (\Throwable $e) {
            Log::error('[FxService] Yahoo USDKRW=X 호출 실패: ' . $e->getMessage());
            return null;
        }
    }
}
