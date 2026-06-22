<?php

declare(strict_types=1);

namespace App\Services\Quote;

use App\Models\Stock;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 국내(KR) 종목 현재가 — KIS inquire-price 재사용.
 * 국내는 session='regular' 단일 세션으로 통일(프리/애프터 개념 약함).
 *
 * 기존 StockController::fetchDomesticPriceFromKis 로직을 서비스로 추출.
 * 원본 컨트롤러 메서드는 그대로 유지(기존 엔드포인트 회귀 방지).
 */
class KisDomesticQuoteProvider implements QuoteProviderInterface
{
    /** KIS 액세스 토큰(캐시). 만료 시 forceRefresh=true 로 재발급. */
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

            $apiUrl  = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey  = env('KIS_APP_KEY');
            $appSec  = env('KIS_APP_SECRET');

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

            Log::error('[KisDomesticQuoteProvider] 토큰 발급 실패: ' . ($data['msg1'] ?? 'Unknown'));
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
        // 국내 종목은 regular 세션만 지원
        if ($session !== 'regular') {
            return null;
        }

        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSec = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSec) || $appKey === 'your_app_key_here') {
            Log::info('[KisDomesticQuoteProvider] KIS 키 미설정 — null 반환');
            return null;
        }

        // symbol: '005930.KS' → '005930', 순수 6자리 그대로
        $code = preg_replace('/(\.KS|\.KQ)$/i', '', $stock->symbol);

        $fallbackKey = "kis_last_successful_price_{$stock->symbol}";

        try {
            $accessToken = $this->getAccessToken();
            if ($accessToken === null) {
                return Cache::get($fallbackKey);
            }

            $client = new Client();
            // 실거래 여부에 따라 tr_id 전환
            $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHPST01010000' : 'FHPST01010000';

            $response = $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/inquire-price", [
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$accessToken}",
                    'appkey'        => $appKey,
                    'appsecret'     => $appSec,
                    'tr_id'         => $trId,
                ],
                'query' => [
                    'FID_COND_MRKT_DIV_CODE' => 'J',
                    'FID_INPUT_ISCD'         => $code,
                ],
                'http_errors' => false,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // 토큰 만료 시 1회 재발급
            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                $accessToken = $this->getAccessToken(true);
                if ($accessToken === null) {
                    return Cache::get($fallbackKey);
                }
                $response = $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/inquire-price", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$accessToken}",
                        'appkey'        => $appKey,
                        'appsecret'     => $appSec,
                        'tr_id'         => $trId,
                    ],
                    'query' => [
                        'FID_COND_MRKT_DIV_CODE' => 'J',
                        'FID_INPUT_ISCD'         => $code,
                    ],
                    'http_errors' => false,
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
            }

            if (!isset($data['output']['stck_prpr'])) {
                Log::warning("[KisDomesticQuoteProvider] {$stock->symbol} 응답 이상: " . json_encode($data));
                return Cache::get($fallbackKey);
            }

            $price   = (float)$data['output']['stck_prpr'];
            $change  = (float)$data['output']['prdy_vrss'];
            $pct     = (float)$data['output']['prdy_ctrt'];
            $sign    = $data['output']['prdy_vrss_sign'] ?? '3';

            if ($sign === '4' || $sign === '5') {
                $change = -abs($change);
                $pct    = -abs($pct);
            }

            $result = [
                'price'          => $price,
                'change_amount'  => $change,
                'change_percent' => $pct,
                'recorded_at'    => now()->toDateTimeString(),
            ];

            Cache::put($fallbackKey, $result, 86400);
            return $result;
        } catch (\Throwable $e) {
            Log::error("[KisDomesticQuoteProvider] {$stock->symbol} 호출 실패: " . $e->getMessage());
            return Cache::get($fallbackKey);
        }
    }
}
