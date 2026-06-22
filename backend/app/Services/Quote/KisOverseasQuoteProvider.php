<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\Stock;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 해외(US) 종목 현재가 — KIS 해외주식 현재가(HHDFS00000300) 재사용.
 *
 * 세션별 동작:
 *   regular  — KIS HHDFS00000300 (정규 현재가; 장중이면 실시간, 마감 후엔 마지막 거래가)
 *   pre      — TODO: KIS 해외주식 시간외 현재가 API 파라미터 미확인. 현재는 regular 로 대체.
 *   after    — TODO: 동상.
 *
 * 기존 StockController::fetchOverseasPriceFromKis 로직을 서비스로 추출.
 * 원본 컨트롤러 메서드는 그대로 유지(기존 엔드포인트 회귀 방지).
 *
 * 거래소 우선순위: NAS → NYS → AMS (기존 순서 유지).
 */
class KisOverseasQuoteProvider implements QuoteProviderInterface
{
    private const EXCHANGES = ['NAS', 'NYS', 'AMS'];

    /** KIS 액세스 토큰(캐시). */
    private function getAccessToken(bool $forceRefresh = false): ?string
    {
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token !== null) {
            return $token;
        }

        $lock = Cache::lock('kis_token_lock', 15);

        try {
            $attempts = 0;
            while (!$lock->get() && $attempts < 10) {
                usleep(500000);
                $token = Cache::get('kis_access_token');
                if ($token !== null) {
                    return $token;
                }
                $attempts++;
            }

            $token = Cache::get('kis_access_token');
            if ($token !== null) {
                return $token;
            }

            $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey = env('KIS_APP_KEY');
            $appSec = env('KIS_APP_SECRET');

            if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
                return null;
            }

            $client   = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey'     => $appKey,
                    'appsecret'  => $appSec,
                ],
                'headers' => ['content-type' => 'application/json'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            Log::error('[KisOverseasQuoteProvider] 토큰 발급 실패: ' . ($data['msg1'] ?? 'Unknown'));
            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{price:float,change_amount:float,change_percent:float,recorded_at:string}|null
     */
    public function fetchQuote(Stock $stock, string $session): ?array
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSec = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
            Log::info('[KisOverseasQuoteProvider] KIS 키 미설정 — null 반환');
            return null;
        }

        // pre / after 세션은 현재 KIS 파라미터 미확인 → regular 로 대체 (TODO: 시간외 tr_id 확인 후 분기)
        if ($session === 'pre' || $session === 'after') {
            Log::info("[KisOverseasQuoteProvider] {$session} 세션 미구현 — regular 로 대체");
        }

        $symbol      = $stock->symbol;
        $fallbackKey = "kis_last_successful_overseas_price_{$symbol}";

        try {
            $accessToken = $this->getAccessToken();
            if ($accessToken === null) {
                return Cache::get($fallbackKey);
            }

            $client = new Client();

            foreach (self::EXCHANGES as $exchange) {
                try {
                    $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                        'headers' => [
                            'content-type' => 'application/json',
                            'authorization' => "Bearer {$accessToken}",
                            'appkey'        => $appKey,
                            'appsecret'     => $appSec,
                            'tr_id'         => 'HHDFS00000300',
                        ],
                        'query' => [
                            'AUTH' => '',
                            'EXCD' => $exchange,
                            'SYMB' => $symbol,
                        ],
                        'http_errors' => false,
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);

                    // 토큰 만료 시 1회 재발급 후 재시도
                    if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                        $accessToken = $this->getAccessToken(true);
                        if ($accessToken === null) {
                            return Cache::get($fallbackKey);
                        }
                        $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                            'headers' => [
                                'content-type' => 'application/json',
                                'authorization' => "Bearer {$accessToken}",
                                'appkey'        => $appKey,
                                'appsecret'     => $appSec,
                                'tr_id'         => 'HHDFS00000300',
                            ],
                            'query' => [
                                'AUTH' => '',
                                'EXCD' => $exchange,
                                'SYMB' => $symbol,
                            ],
                            'http_errors' => false,
                        ]);
                        $data = json_decode($response->getBody()->getContents(), true);
                    }

                    if (!isset($data['output']['last']) || (float)$data['output']['last'] <= 0) {
                        continue;
                    }

                    $output = $data['output'];
                    $price  = (float)$output['last'];
                    $change = (float)($output['diff'] ?? 0);
                    $pct    = (float)($output['rate'] ?? 0);
                    $sign   = $output['sign'] ?? '3';

                    // sign: 1상한/2상승/3보합/4하한/5하락
                    if ($sign === '4' || $sign === '5') {
                        $change = -abs($change);
                        $pct    = -abs($pct);
                    } else {
                        $change = abs($change);
                        $pct    = abs($pct);
                    }

                    $result = [
                        'price'          => $price,
                        'change_amount'  => $change,
                        'change_percent' => $pct,
                        'recorded_at'    => now()->toDateTimeString(),
                    ];

                    Cache::put($fallbackKey, $result, 86400);
                    return $result;
                } catch (\Throwable $ex) {
                    Log::debug("[KisOverseasQuoteProvider] {$symbol}@{$exchange} 실패: " . $ex->getMessage());
                    continue;
                }
            }

            // 모든 거래소 실패 → 폴백
            Log::warning("[KisOverseasQuoteProvider] {$symbol} 모든 거래소 조회 실패");
            return Cache::get($fallbackKey);
        } catch (\Throwable $e) {
            Log::error("[KisOverseasQuoteProvider] {$symbol} 예외: " . $e->getMessage());
            return Cache::get($fallbackKey);
        }
    }
}
