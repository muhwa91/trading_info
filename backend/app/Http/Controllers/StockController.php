<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;

class StockController extends Controller
{
    private function getAccessToken($forceRefresh = false)
    {
        // 만료 토큰 감지 시 강제 갱신 — 캐시를 비워 새 토큰을 발급받는다.
        if ($forceRefresh) {
            Cache::forget('kis_access_token');
        }

        $token = Cache::get('kis_access_token');
        if ($token) {
            return $token;
        }

        // Use a lock to prevent concurrent requests from hitting the KIS token API rate limit (1 req/min)
        $lock = Cache::lock('kis_token_lock', 15);
        
        try {
            $attempts = 0;
            while (!$lock->get() && $attempts < 10) {
                usleep(500000); // Wait 0.5 seconds
                $token = Cache::get('kis_access_token');
                if ($token) {
                    return $token;
                }
                $attempts++;
            }

            // Re-check cache after acquiring lock
            $token = Cache::get('kis_access_token');
            if ($token) {
                return $token;
            }

            $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
            $appKey = env('KIS_APP_KEY');
            $appSecret = env('KIS_APP_SECRET');

            if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
                throw new \Exception('KIS_APP_KEY or KIS_APP_SECRET is not configured in .env');
            }

            $client = new Client();
            $response = $client->post("{$apiUrl}/oauth2/tokenP", [
                'json' => [
                    'grant_type' => 'client_credentials',
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                ],
                'headers' => [
                    'content-type' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['access_token'])) {
                Cache::put('kis_access_token', $data['access_token'], 72000);
                return $data['access_token'];
            }

            throw new \Exception('Failed to retrieve KIS access token: ' . ($data['msg1'] ?? 'Unknown error'));
        } finally {
            $lock->release();
        }
    }

    private function getPreviousClose($ticker)
    {
        $cacheKey = "kis_prev_close_{$ticker}";
        $prevClose = Cache::get($cacheKey);
        if ($prevClose !== null) {
            return (float)$prevClose;
        }

        try {
            $dailyCacheKey = "kis_stock_data_{$ticker}_1d";
            $dailyData = Cache::get($dailyCacheKey);
            if (!$dailyData) {
                $response = $this->fetchFromKis($ticker);
                $dailyData = json_decode($response->getContent(), true);
            }

            if (isset($dailyData['candles']) && count($dailyData['candles']) >= 2) {
                $candles = $dailyData['candles'];
                end($candles);
                $prevCandle = prev($candles);
                $prevClose = (float)$prevCandle['close'];
            } elseif (isset($dailyData['candles']) && count($dailyData['candles']) === 1) {
                $prevClose = (float)$dailyData['candles'][0]['close'];
            } else {
                $prevClose = (float)($dailyData['current_price'] ?? 0.0);
            }

            if ($prevClose > 0) {
                Cache::put($cacheKey, $prevClose, 86400); // 24 hours
                return $prevClose;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch previous close for {$ticker}: " . $e->getMessage());
        }

        return null;
    }

    public function getStockData(Request $request, $ticker)
    {
        $ticker = strtoupper($ticker);
        $ticker = str_replace('0167AO', '0167A0', $ticker);
        $timeframe = strtolower($request->query('timeframe', '1d'));

        // 코스피 지수(KOSPI200) — KIS 국내업종 API 사용 (값 일관성)
        if ($ticker === 'KOSPI200') {
            $cacheKey = "kis_kospi_index_{$timeframe}";
            $response = Cache::remember($cacheKey, 3, function () use ($timeframe) {
                return $this->getKospiIndexData($timeframe);
            });

            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                $content['session'] = $this->getKrMarketSessionInfo(time());
                // 프론트가 휴장일(주말·공휴일)에 코스피 칸을 숨기는 판단에 사용
                $content['is_trading_day'] = $this->isKrTradingDay(time());
                return response()->json($content);
            }
            return $response;
        }

        if ($ticker === 'NQ=F' || $ticker === '^KS200' || $ticker === 'USDKRW=X') {
            $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}";
            $response = Cache::remember($cacheKey, 3, function () use ($ticker, $timeframe) {
                return $this->getYahooChartData($ticker, $timeframe);
            });
            
            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                if ($ticker === 'NQ=F') {
                    // 미국 거래일 판정: Yahoo currentTradingPeriod.regular.start NY날짜 기반
                    $isUsTradingDay = $this->isUsMarketTradingToday();

                    if (!$isUsTradingDay) {
                        $content['session'] = '장마감';
                    } else {
                        $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
                        $dayOfWeek = (int)$now->format('N');
                        $hour = (int)$now->format('H');
                        $isClosed = ($dayOfWeek === 6 && $hour >= 7) || ($dayOfWeek === 7) || ($dayOfWeek === 1 && $hour < 7);
                        $content['session'] = $isClosed ? '장마감' : '거래중';
                    }
                    $content['is_trading_day'] = $isUsTradingDay;
                } elseif ($ticker === 'USDKRW=X') {
                    $content['session'] = '거래중';
                } else {
                    $content['session'] = $this->getKrMarketSessionInfo(time());
                }
                return response()->json($content);
            }
            return $response;
        }

        if ($ticker === 'KOSPI_NIGHT') {
            $cacheKey = "kospi_night_data_{$timeframe}";
            $response = Cache::remember($cacheKey, 3, function () use ($timeframe) {
                return $this->getKOSPINightChartData($timeframe);
            });
            
            $content = json_decode($response->getContent(), true);
            if (is_array($content)) {
                $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
                $hour = (int)$now->format('H');
                // 야간선물은 개장일(거래일) 저녁에만 운영 — 공휴일이면 미운영 (KIS API 기준)
                $isNightActive = (($hour >= 18 || $hour < 5) && $this->isKrTradingDay(time()));
                $content['session'] = $isNightActive ? '거래중' : '장마감';
                return response()->json($content);
            }
            return $response;
        }

        // If it's a Korean stock/ETF (ends with .KS or .KQ, or is purely numeric)
        if (preg_match('/(\.KS|\.KQ)$/i', $ticker) || preg_match('/^\d+$/', $ticker)) {
            $isDaily = ($timeframe === '1d');
            $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}_raw";
            $dataResponse = Cache::remember($cacheKey, 3, function () use ($ticker, $timeframe, $isDaily) {
                return $this->getYahooChartData($ticker, $timeframe, !$isDaily);
            });

            // Fetch real-time price from KIS (cached for 3 seconds to avoid rate limits and ensure sync)
            $cacheKeyKis = "kis_realtime_price_{$ticker}";
            $kisPrice = Cache::remember($cacheKeyKis, 3, function() use ($ticker) {
                return $this->fetchDomesticPriceFromKis($ticker);
            });

            $session = $this->getKrMarketSessionInfo(time());

            $content = json_decode($dataResponse->getContent(), true);
            if (is_array($content) && !empty($content['candles'])) {
                if ($kisPrice !== null) {
                    $price = $kisPrice['price'];
                    $content['current_price'] = $price;
                    $content['change_amount'] = $kisPrice['change_amount'];
                    $content['change_percent'] = $kisPrice['change_percent'];
                    
                    if (!$isDaily) {
                        $lastYahooTime = (int)end($content['candles'])['time'];
                        $accumulated1m = $this->accumulateRealTimePrice($ticker, $price, $lastYahooTime);

                        // Filter accumulated candles to only keep those after the last Yahoo candle
                        $filteredAccumulated = array_filter($accumulated1m, function($c) use ($lastYahooTime) {
                            return (int)$c['time'] > $lastYahooTime;
                        });

                        // Merge!
                        $merged1m = array_merge($content['candles'], array_values($filteredAccumulated));

                        // Aggregate merged 1m candles into the target timeframe!
                        $intervalSeconds = 180;
                        if ($timeframe === '1m') $intervalSeconds = 60;
                        elseif ($timeframe === '5m') $intervalSeconds = 300;
                        elseif ($timeframe === '10m') $intervalSeconds = 600;
                        elseif ($timeframe === '30m') $intervalSeconds = 1800;
                        elseif ($timeframe === '1h') $intervalSeconds = 3600;
                        
                        $content['candles'] = $this->aggregateCandles($merged1m, $intervalSeconds);
                    } else {
                        $lastIdx = count($content['candles']) - 1;
                        $lastCandle = $content['candles'][$lastIdx];
                        $todayStr = date('Y-m-d');
                        
                        // 휴장일(주말·공휴일)에는 오늘자 가짜 일봉을 생성하지 않는다 (KIS API 기준)
                        $isTradingDay = $this->isKrTradingDay(time());

                        if ($lastCandle['time'] !== $todayStr) {
                            if ($isTradingDay) {
                                $lastClose = $lastCandle['close'];
                                $content['candles'][] = [
                                    'time' => $todayStr,
                                    'open' => $lastClose,
                                    'high' => max($lastClose, $price),
                                    'low' => min($lastClose, $price),
                                    'close' => $price,
                                    'volume' => 0
                                ];
                            }
                        } else {
                            $content['candles'][$lastIdx]['close'] = $price;
                            if ($price > $content['candles'][$lastIdx]['high']) {
                                $content['candles'][$lastIdx]['high'] = $price;
                            }
                            if ($price < $content['candles'][$lastIdx]['low']) {
                                $content['candles'][$lastIdx]['low'] = $price;
                            }
                        }
                    }
                }
                $content['session'] = $session;
                // 휴장일(주말·공휴일)이면 프론트가 차트 대신 텍스트(전일 마감)로 표시
                $content['is_trading_day'] = $this->isKrTradingDay(time());
                return response()->json($content);
            }

            return $dataResponse;
        }

        // US Stock flow (non-index, non-domestic)
        $isDaily = ($timeframe === '1d');
        $cacheKey = "yahoo_stock_data_{$ticker}_{$timeframe}_raw";
        
        $dataResponse = Cache::remember($cacheKey, 3, function () use ($ticker, $timeframe, $isDaily) {
            return $this->getYahooChartData($ticker, $timeframe, !$isDaily);
        });

        // Fetch real-time US price from KIS
        $cacheKeyKis = "kis_realtime_price_us_{$ticker}";
        $kisPrice = Cache::remember($cacheKeyKis, 3, function() use ($ticker) {
            return $this->fetchOverseasPriceFromKis($ticker);
        });

        $session = $this->getUsMarketSessionInfo(time());

        $content = json_decode($dataResponse->getContent(), true);
        if (is_array($content) && !empty($content['candles'])) {
            if ($kisPrice !== null) {
                $price = $kisPrice['price'];
                $content['current_price'] = $price;
                $content['change_amount'] = $kisPrice['change_amount'];
                $content['change_percent'] = $kisPrice['change_percent'];
                
                if (!$isDaily) {
                    $lastYahooTime = (int)end($content['candles'])['time'];
                    $accumulated1m = $this->accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime);

                    // Filter accumulated candles
                    $filteredAccumulated = array_filter($accumulated1m, function($c) use ($lastYahooTime) {
                        return (int)$c['time'] > $lastYahooTime;
                    });

                    // Merge!
                    $merged1m = array_merge($content['candles'], array_values($filteredAccumulated));

                    // Aggregate merged 1m candles
                    $intervalSeconds = 180;
                    if ($timeframe === '1m') $intervalSeconds = 60;
                    elseif ($timeframe === '5m') $intervalSeconds = 300;
                    elseif ($timeframe === '10m') $intervalSeconds = 600;
                    elseif ($timeframe === '30m') $intervalSeconds = 1800;
                    elseif ($timeframe === '1h') $intervalSeconds = 3600;
                    
                    $content['candles'] = $this->aggregateCandles($merged1m, $intervalSeconds);
                } else {
                    $lastIdx = count($content['candles']) - 1;
                    $lastCandle = $content['candles'][$lastIdx];
                    
                    $nyDate = new \DateTime('now', new \DateTimeZone('America/New_York'));
                    $todayStr = $nyDate->format('Y-m-d');
                    
                    if ($lastCandle['time'] !== $todayStr) {
                        $isClosedSession = ($session === '장마감');
                        if (!$isClosedSession) {
                            $lastClose = $lastCandle['close'];
                            $content['candles'][] = [
                                'time' => $todayStr,
                                'open' => $lastClose,
                                'high' => max($lastClose, $price),
                                'low' => min($lastClose, $price),
                                'close' => $price,
                                'volume' => 0
                            ];
                        }
                    } else {
                        $content['candles'][$lastIdx]['close'] = $price;
                        if ($price > $content['candles'][$lastIdx]['high']) {
                            $content['candles'][$lastIdx]['high'] = $price;
                        }
                        if ($price < $content['candles'][$lastIdx]['low']) {
                            $content['candles'][$lastIdx]['low'] = $price;
                        }
                    }
                }
            }
            // 미국 거래일 판정: Yahoo currentTradingPeriod.regular.start NY날짜 기반 (공휴일 포함)
            $usTradingDay = $this->isUsMarketTradingToday();
            $content['session'] = $usTradingDay ? $session : '장마감';
            $content['source'] = 'Yahoo + KIS (Daytime)';
            $content['is_trading_day'] = $usTradingDay;
            return response()->json($content);
        }

        return $dataResponse;
    }

    public function getIndices()
    {
        $nqPriced = null;
        $kospiPriced = null;
        $client = new Client();

        // 1. Fetch Nasdaq 100 Futures (NQ=F)
        try {
            $responseNq = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1d&range=2d', [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            $dataNq = json_decode($responseNq->getBody()->getContents(), true);
            $nqPriced = $this->parseYahooFinanceChart($dataNq, '나스닥100 선물');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Nasdaq Futures Fetch Error: " . $e->getMessage());
            $nqPriced = [
                'name' => '나스닥100 선물',
                'price' => 19482.25,
                'change' => 282.25,
                'change_percent' => 1.47,
            ];
        }

        // 2. 코스피 지수(0001=코스피 종합) 현재가 — KIS 국내업종 현재지수 API (tr_id FHPUP02100000)
        try {
            $data = $this->kisIndexRequest(
                '/uapi/domestic-stock/v1/quotations/inquire-index-price',
                'FHPUP02100000',
                [
                    'FID_COND_MRKT_DIV_CODE' => 'U', // 업종(지수)
                    'FID_INPUT_ISCD' => '0001',      // 코스피 종합(전체)
                ]
            );

            if ($data && ($data['rt_cd'] ?? '1') === '0' && isset($data['output']['bstp_nmix_prpr'])) {
                $o = $data['output'];
                $price = (float)$o['bstp_nmix_prpr'];
                [$change, $changePercent] = $this->kisIndexChange($o);

                $kospiPriced = [
                    'name' => '코스피 지수',
                    'price' => round($price, 2),
                    'change' => round($change, 2),
                    'change_percent' => round($changePercent, 2),
                    'source' => 'KIS API (0001)'
                ];
            } else {
                throw new \Exception($data['msg1'] ?? 'Invalid KIS response for KOSPI index');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("KIS KOSPI Index Fetch Error: " . $e->getMessage());

            $kospiPriced = [
                'name' => '코스피 지수',
                'price' => 362.45,
                'change' => -1.20,
                'change_percent' => -0.33,
                'source' => 'Mock'
            ];
        }

        // 3. KOSPI Night Futures
        $nightPriced = $this->generateKOSPINightFutures($kospiPriced, $nqPriced);

        return response()->json([
            'status' => 'success',
            'indices' => [
                'nasdaq_futures' => $nqPriced,
                'kospi_futures' => $kospiPriced,
                'kospi_night_futures' => $nightPriced,
            ]
        ]);
    }

    private function parseYahooFinanceChart($data, $name)
    {
        if (!isset($data['chart']['result'][0])) {
            throw new \Exception("Invalid Yahoo Finance chart response for {$name}");
        }
        
        $result = $data['chart']['result'][0];
        $meta = $result['meta'];
        
        $price = $meta['regularMarketPrice'] ?? 0.0;
        $prevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0.0;
        
        if (isset($result['indicators']['quote'][0]['close'])) {
            $closes = array_values(array_filter($result['indicators']['quote'][0]['close']));
            if (count($closes) >= 2) {
                if ($price == 0.0) {
                    $price = end($closes);
                }
                if ($prevClose == 0.0) {
                    $prevClose = $closes[0];
                }
            } elseif (count($closes) == 1) {
                if ($price == 0.0) {
                    $price = $closes[0];
                }
            }
        }
        
        if ($prevClose == 0.0) {
            $prevClose = $price;
        }
        
        $change = $price - $prevClose;
        $changePercent = ($prevClose > 0) ? ($change / $prevClose) * 100 : 0.0;
        
        return [
            'name' => $name,
            'price' => round($price, 2),
            'change' => round($change, 2),
            'change_percent' => round($changePercent, 2),
        ];
    }

    private function generateKOSPINightFutures($kospi, $nq)
    {
        $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
        $hour = (int)$now->format('H');
        $dayOfWeek = (int)$now->format('N');
        
        $isNightActive = false;

        // KRX 야간 세션은 개장일 18:00 ~ 익일 05:00. 공휴일은 KIS API 로 판정해 제외.
        if (($hour >= 18 || $hour < 5) && $this->isKrTradingDay(time())) {
            $isNightActive = true;
        }
        
        $price = $kospi['price'];
        $change = 0.0;
        $changePercent = 0.0;
        
        if ($isNightActive) {
            // In Korean market, futures changes are quoted in points (p)
            // A 1.57% Nasdaq increase corresponds to ~+5.56 points in KOSPI 200 Futures.
            // Scale: 1% Nasdaq move = ~3.54 points in KOSPI
            $nqMove = $nq['change_percent'];
            $change = $nqMove * 3.541; // 1.57 * 3.541 = +5.56 points!
            $price = $kospi['price'] + $change;
            $changePercent = ($kospi['price'] > 0) ? ($change / $kospi['price']) * 100 : 0.0;
        } else {
            $change = $kospi['change'];
            $changePercent = $kospi['change_percent'];
        }
        
        return [
            'name' => '야간 선물 (KOSPI 200)',
            'price' => round($price, 2),
            'change' => round($change, 2),
            'change_percent' => round($changePercent, 2),
            'status' => $isNightActive ? '거래중' : '장마감',
        ];
    }

    private function getMockIndices($reason = '')
    {
        return response()->json([
            'status' => 'success',
            'source' => 'Mock',
            'error' => $reason,
            'indices' => [
                'nasdaq_futures' => [
                    'name' => '나스닥100 선물',
                    'price' => 19482.25,
                    'change' => 282.25,
                    'change_percent' => 1.47,
                ],
                'kospi_futures' => [
                    'name' => '코스피200 선물',
                    'price' => 362.45,
                    'change' => -1.20,
                    'change_percent' => -0.33,
                ],
                'kospi_night_futures' => [
                    'name' => '야간 선물 (KOSPI 200)',
                    'price' => 362.85,
                    'change' => 0.40,
                    'change_percent' => 0.11,
                    'status' => '거래중'
                ]
            ]
        ]);
    }

    private function fetchFromKis($ticker)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        $accessToken = $this->getAccessToken();

        $exchanges = ['NAS', 'NYS', 'AMS'];
        $output2 = null;

        $client = new Client();

        foreach ($exchanges as $exchange) {
            try {
                $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/dailyprice", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$accessToken}",
                        'appkey' => $appKey,
                        'appsecret' => $appSecret,
                        'tr_id' => 'HHDFS76240000',
                    ],
                    'query' => [
                        'AUTH' => '',
                        'EXCD' => $exchange,
                        'SYMB' => $ticker,
                        'GUBN' => '0',
                        'BYMD' => '',
                        'MODP' => '1',
                    ]
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['output2']) && is_array($data['output2']) && count($data['output2']) > 0 && (float)$data['output2'][0]['clos'] > 0) {
                    $output2 = $data['output2'];
                    break;
                }
            } catch (\Exception $ex) {
                continue;
            }
        }

        if (empty($output2)) {
            throw new \Exception("No price data found for ticker {$ticker} on NAS, NYS, or AMS.");
        }

        $candles = [];
        foreach ($output2 as $row) {
            if (empty($row['xymd']) || (float)$row['open'] == 0) continue;

            $dateStr = substr($row['xymd'], 0, 4) . '-' . substr($row['xymd'], 4, 2) . '-' . substr($row['xymd'], 6, 2);

            $candles[] = [
                'time' => $dateStr,
                'open' => (float)$row['open'],
                'high' => (float)$row['high'],
                'low' => (float)$row['low'],
                'close' => (float)$row['clos'],
                'volume' => (int)$row['tvol'],
            ];
        }

        $candles = array_reverse($candles);

        if (empty($candles)) {
            throw new \Exception("Failed to parse stock candle data.");
        }

        $latestCandle = end($candles);
        $prevCandle = prev($candles) ?: $latestCandle;

        $current = $latestCandle['close'];
        $prevClose = $prevCandle['close'];

        // Cache the daily previous close for other timeframes to use
        Cache::put("kis_prev_close_{$ticker}", $prevClose, 86400); // 24 hours

        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;

        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'KIS API'
        ]);
    }

    private function fetchMinuteFromKis($ticker, $timeframe)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        $accessToken = $this->getAccessToken();

        $nmin = '1';
        if ($timeframe === '3m') $nmin = '3';
        elseif ($timeframe === '5m') $nmin = '5';
        elseif ($timeframe === '10m') $nmin = '10';
        elseif ($timeframe === '30m') $nmin = '30';
        elseif ($timeframe === '1h') $nmin = '60';

        $exchanges = ['NAS', 'NYS', 'AMS'];
        $output2 = null;

        $client = new Client();
        $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHDFS76950200' : 'HHDFS76950200';

        foreach ($exchanges as $exchange) {
            try {
                $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/inquire-time-itemchartprice", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$accessToken}",
                        'appkey' => $appKey,
                        'appsecret' => $appSecret,
                        'tr_id' => $trId,
                    ],
                    'query' => [
                        'AUTH' => '',
                        'EXCD' => $exchange,
                        'SYMB' => $ticker,
                        'NMIN' => $nmin,
                        'PINC' => '0',
                        'NEXT' => '',
                        'KEYB' => '',
                        'NREC' => '120',
                        'FILL' => 'N',
                    ]
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['output2']) && is_array($data['output2']) && count($data['output2']) > 0 && (float)$data['output2'][0]['last'] > 0) {
                    $output2 = $data['output2'];
                    break;
                }
            } catch (\Exception $ex) {
                continue;
            }
        }

        if (empty($output2)) {
            throw new \Exception("No minute price data found for ticker {$ticker} on NAS, NYS, or AMS.");
        }

        $candles = [];
        foreach ($output2 as $row) {
            if (empty($row['xymd']) || empty($row['xhms']) || (float)$row['open'] == 0) continue;

            $dateStr = substr($row['xymd'], 0, 4) . '-' . substr($row['xymd'], 4, 2) . '-' . substr($row['xymd'], 6, 2);
            $timeStr = substr($row['xhms'], 0, 2) . ':' . substr($row['xhms'], 2, 2) . ':' . substr($row['xhms'], 4, 2);
            
            $dateTime = new \DateTime($dateStr . ' ' . $timeStr, new \DateTimeZone('America/New_York'));
            $timestamp = $dateTime->getTimestamp();

            $candles[] = [
                'time' => $timestamp,
                'open' => (float)$row['open'],
                'high' => (float)$row['high'],
                'low' => (float)$row['low'],
                'close' => (float)$row['last'],
                'volume' => (int)$row['evol'],
            ];
        }

        $candles = array_reverse($candles);

        if (empty($candles)) {
            throw new \Exception("Failed to parse minute stock candle data.");
        }

        $latestCandle = end($candles);
        $current = $latestCandle['close'];

        // Get daily previous close for correct daily change percentage
        $dailyPrevClose = $this->getPreviousClose($ticker);
        $prevCloseForChange = ($dailyPrevClose !== null && $dailyPrevClose > 0) ? $dailyPrevClose : $latestCandle['close'];

        $changeAmount = $current - $prevCloseForChange;
        $changePercent = ($prevCloseForChange > 0) ? ($changeAmount / $prevCloseForChange) * 100 : 0.0;

        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'KIS API (' . $timeframe . ')'
        ]);
    }

    private function getMockStockData($ticker, $reason = '', $timeframe = '1d')
    {
        $basePrices = [
            'TSLA' => 180.0,
            'AAPL' => 175.0,
            'NVDA' => 120.0,
            'MSFT' => 420.0,
            'AMZN' => 180.0,
            'GOOGL' => 170.0,
            'MU' => 130.0,
        ];
        
        $basePrice = $basePrices[$ticker] ?? 100.0;
        
        $intervalSeconds = 86400; // 1d
        if ($timeframe === '1m') $intervalSeconds = 60;
        elseif ($timeframe === '3m') $intervalSeconds = 180;
        elseif ($timeframe === '5m') $intervalSeconds = 300;
        elseif ($timeframe === '10m') $intervalSeconds = 600;
        elseif ($timeframe === '30m') $intervalSeconds = 1800;
        elseif ($timeframe === '1h') $intervalSeconds = 3600;

        $candles = [];
        $currentPrice = $basePrice;
        
        $now = time();
        $now = $now - ($now % $intervalSeconds);
        
        $tempCandles = [];
        for ($i = 119; $i >= 0; $i--) {
            $timestamp = $now - ($i * $intervalSeconds);
            
            if ($timeframe === '1d') {
                $date = new \DateTime("@{$timestamp}");
                $dayOfWeek = (int)$date->format('N');
                if ($dayOfWeek === 6 || $dayOfWeek === 7) {
                    continue;
                }
            }
            
            if ($timeframe === '1d') {
                $change = (rand(-150, 150) / 100.0);
                $volMin = 50000;
                $volMax = 1000000;
                $highAdd = rand(0, 100) / 100.0;
                $lowSub = rand(0, 100) / 100.0;
            } else {
                $change = (rand(-25, 25) / 100.0);
                $volMin = 5000;
                $volMax = 80000;
                $highAdd = rand(0, 15) / 100.0;
                $lowSub = rand(0, 15) / 100.0;
            }
            
            $open = $currentPrice;
            $close = $currentPrice + $change;
            $high = max($open, $close) + $highAdd;
            $low = min($open, $close) - $lowSub;
            $volume = rand($volMin, $volMax);
            
            $item = [
                'open' => round($open, 2),
                'high' => round($high, 2),
                'low' => round($low, 2),
                'close' => round($close, 2),
                'volume' => $volume,
            ];
            
            if ($timeframe === '1d') {
                $date = new \DateTime("@{$timestamp}");
                $item['time'] = $date->format('Y-m-d');
            } else {
                $item['time'] = $timestamp;
            }
            
            $tempCandles[] = $item;
            $currentPrice = $close; // Open of next candle is close of this candle
        }
        
        $candles = $tempCandles;
        
        usort($candles, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        
        $latestCandle = end($candles);
        $current = $latestCandle['close'];

        if ($timeframe === '1d') {
            $prevCandle = prev($candles) ?: $latestCandle;
            $prevClose = $prevCandle['close'];
        } else {
            $dailyPrevClose = $this->getPreviousClose($ticker);
            if ($dailyPrevClose !== null && $dailyPrevClose > 0) {
                $prevClose = $dailyPrevClose;
            } else {
                $prevClose = $candles[0]['close'] ?? $basePrice;
            }
        }
        
        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
        
        return response()->json([
            'ticker' => $ticker,
            'name' => $this->getStockName($ticker),
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $candles,
            'source' => 'Mock (' . $timeframe . ' chart)',
            'error_reason' => $reason
        ]);
    }
    
    public function getYahooChartData($ticker, $timeframe, $raw = false)
    {
        $symbol = $ticker;
        if ($ticker === 'KOSPI200') {
            $symbol = '^KS200';
        }
        
        $interval = '1d';
        $range = '60d';
        $isAggregated = false;
        $intervalSeconds = 180;
        
        if ($timeframe === '1m') {
            $interval = '1m';
            $range = '1d';
            $isAggregated = false; // 1분봉은 집계 없이 원본 그대로
            $intervalSeconds = 60;
        } elseif ($timeframe === '3m') {
            $interval = '1m';
            $range = '2d';
            $isAggregated = !$raw;
            $intervalSeconds = 180;
        } elseif ($timeframe === '5m') {
            $interval = '1m';
            $range = '2d';
            $isAggregated = !$raw;
            $intervalSeconds = 300;
        } elseif ($timeframe === '10m') {
            $interval = '1m';
            $range = '3d';
            $isAggregated = !$raw;
            $intervalSeconds = 600;
        } elseif ($timeframe === '30m') {
            $interval = '1m';
            $range = '5d';
            $isAggregated = !$raw;
            $intervalSeconds = 1800;
        } elseif ($timeframe === '1h') {
            $interval = '1m';
            $range = '7d';
            $isAggregated = true;
            $intervalSeconds = 3600;
        }
        
        try {
            $client = new Client();
            $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval={$interval}&range={$range}&includePrePost=true";
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['chart']['result'][0])) {
                throw new \Exception("Invalid Yahoo Finance response");
            }
            
            $result = $data['chart']['result'][0];
            $timestamps = $result['timestamp'] ?? [];
            $quote = $result['indicators']['quote'][0] ?? [];
            
            $candles = [];
            $len = count($timestamps);
            for ($i = 0; $i < $len; $i++) {
                $open = $quote['open'][$i] ?? null;
                $high = $quote['high'][$i] ?? null;
                $low = $quote['low'][$i] ?? null;
                $close = $quote['close'][$i] ?? null;
                $volume = $quote['volume'][$i] ?? 0;
                
                if ($open === null || $high === null || $low === null || $close === null) {
                    continue;
                }
                
                $timestamp = $timestamps[$i];
                if ($timeframe === '1d') {
                    $date = new \DateTime("@{$timestamp}");
                    $date->setTimezone(new \DateTimeZone('Asia/Seoul'));
                    $timeVal = $date->format('Y-m-d');
                } else {
                    $timeVal = $timestamp;
                }
                
                $candles[] = [
                    'time' => $timeVal,
                    'open' => round((float)$open, 2),
                    'high' => round((float)$high, 2),
                    'low' => round((float)$low, 2),
                    'close' => round((float)$close, 2),
                    'volume' => (int)$volume,
                ];
            }
            
            if (empty($candles)) {
                throw new \Exception("No candles parsed");
            }

            if ($isAggregated && !empty($candles)) {
                $candles = $this->aggregateCandles($candles, $intervalSeconds);
            }
            
            $meta = $result['meta'];
            $latestCandle = end($candles);
            $current = $latestCandle['close'];

            if ($timeframe === '1d') {
                $prevCandle = prev($candles) ?: $latestCandle;
                $prevClose = $prevCandle['close'];
            } else {
                // For regular stocks, prioritize the daily previous close from KIS to match the Watchlist sidebar
                $dailyPrevClose = null;
                if ($ticker !== 'NQ=F' && $ticker !== 'KOSPI200' && $ticker !== '^KS200') {
                    $dailyPrevClose = $this->getPreviousClose($ticker);
                }

                if ($dailyPrevClose !== null && $dailyPrevClose > 0) {
                    $prevClose = $dailyPrevClose;
                } else {
                    $metaPrevClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0.0;
                    $prevClose = ($metaPrevClose > 0) ? $metaPrevClose : ($candles[0]['close'] ?? $current);
                }
            }
            
            $changeAmount = $current - $prevClose;
            $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
            
            $displayName = $this->getStockName($ticker);
            if ($ticker === 'NQ=F') {
                $displayName = '나스닥100 선물';
            } elseif ($ticker === 'KOSPI200' || $ticker === '^KS200') {
                $displayName = '코스피 지수';
            }
            
            return response()->json([
                'ticker' => $ticker,
                'name' => $displayName,
                'current_price' => round($current, 2),
                'change_amount' => round($changeAmount, 2),
                'change_percent' => round($changePercent, 2),
                'candles' => $candles,
                'source' => 'Yahoo Finance (' . $timeframe . ')'
            ]);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Yahoo Chart Error for {$ticker}: " . $e->getMessage());
            return $this->getMockStockData($ticker, $e->getMessage(), $timeframe);
        }
    }

    public function getKOSPINightChartData($timeframe)
    {
        $nqResponse = $this->getYahooChartData('NQ=F', $timeframe);
        $nqData = json_decode($nqResponse->getContent(), true);

        // 야간선물(코스피200 선물)의 베이스는 코스피200(0002) — KIS 지수 API 사용
        $kospiResponse = $this->getKospiIndexData($timeframe, '0002');
        $kospiData = json_decode($kospiResponse->getContent(), true);
        
        if (!isset($nqData['candles']) || !isset($kospiData['candles'])) {
            return $kospiResponse;
        }
        
        $nqCandles = $nqData['candles'];
        $kospiCandles = $kospiData['candles'];
        
        $lastKospiPrice = end($kospiCandles)['close'] ?? 362.45;
        
        $nightCandles = [];
        $firstNqClose = $nqCandles[0]['close'] ?? 1.0;
        
        foreach ($nqCandles as $nqCandle) {
            $nqChangePercent = (($nqCandle['close'] - $firstNqClose) / $firstNqClose) * 100;
            $pointMove = $nqChangePercent * 3.541;
            
            $nightClose = $lastKospiPrice + $pointMove;
            
            $nqOpenPct = (($nqCandle['open'] - $firstNqClose) / $firstNqClose) * 100;
            $nqHighPct = (($nqCandle['high'] - $firstNqClose) / $firstNqClose) * 100;
            $nqLowPct = (($nqCandle['low'] - $firstNqClose) / $firstNqClose) * 100;
            
            $nightOpen = $lastKospiPrice + ($nqOpenPct * 3.541);
            $nightHigh = $lastKospiPrice + ($nqHighPct * 3.541);
            $nightLow = $lastKospiPrice + ($nqLowPct * 3.541);
            
            $nightCandles[] = [
                'time' => $nqCandle['time'],
                'open' => round($nightOpen, 2),
                'high' => round($nightHigh, 2),
                'low' => round($nightLow, 2),
                'close' => round($nightClose, 2),
                'volume' => $nqCandle['volume'],
            ];
        }
        
        $latestCandle = end($nightCandles);
        $current = $latestCandle['close'];

        if ($timeframe === '1d') {
            $prevCandle = prev($nightCandles) ?: $latestCandle;
            $prevClose = $prevCandle['close'];
        } else {
            // For intraday KOSPI night session, the daily base price is the regular session close ($lastKospiPrice)
            $prevClose = $lastKospiPrice;
        }
        
        $changeAmount = $current - $prevClose;
        $changePercent = ($prevClose > 0) ? ($changeAmount / $prevClose) * 100 : 0.0;
        
        return response()->json([
            'ticker' => 'KOSPI_NIGHT',
            'name' => '야간 선물 (KOSPI 200)',
            'current_price' => round($current, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'candles' => $nightCandles,
            'source' => 'Simulated KRX Night Futures (' . $timeframe . ')'
        ]);
    }

    /**
     * KIS 국내업종(지수) API 공통 호출. 토큰 만료(EGW00123) 시 1회 강제 갱신·재시도.
     * 실패 시 null 반환.
     */
    private function kisIndexRequest($path, $trId, $query)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapi.koreainvestment.com:9443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }

        $client = new Client();
        $doRequest = function ($token) use ($client, $apiUrl, $path, $appKey, $appSecret, $trId, $query) {
            return $client->get("{$apiUrl}{$path}", [
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$token}",
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                    'tr_id' => $trId,
                    'custtype' => 'P',
                ],
                'query' => $query,
                'http_errors' => false,
            ]);
        };

        try {
            $token = $this->getAccessToken();
            $data = json_decode($doRequest($token)->getBody()->getContents(), true);

            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                $token = $this->getAccessToken(true);
                $data = json_decode($doRequest($token)->getBody()->getContents(), true);
            }
            return $data;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("KIS 지수 요청 실패 [{$trId}]: " . $e->getMessage());
            return null;
        }
    }

    private function kisIndexChange($row)
    {
        $chg = (float)($row['bstp_nmix_prdy_vrss'] ?? 0);
        $pct = (float)($row['bstp_nmix_prdy_ctrt'] ?? 0);
        $sign = $row['prdy_vrss_sign'] ?? '3'; // 1상한 2상승 3보합 4하한 5하락
        if ($sign === '4' || $sign === '5') {
            $chg = -abs($chg);
            $pct = -abs($pct);
        }
        return [round($chg, 2), round($pct, 2)];
    }

    private function kisIndexResponse($current, $chg, $pct, $candles, $source)
    {
        return response()->json([
            'ticker' => 'KOSPI200',
            'name' => '코스피 지수',
            'current_price' => round($current, 2),
            'change_amount' => round($chg, 2),
            'change_percent' => round($pct, 2),
            'candles' => $candles,
            'source' => $source,
        ]);
    }

    /**
     * 코스피 지수 차트 데이터를 KIS 국내업종 API 로 구성.
     * $iscd: '0001'=코스피 종합(전체, 기본), '0002'=코스피200.
     * 1d=일봉, 그 외=분봉(종가 기반 OHLC 합성 후 집계). 실패 시 Yahoo(^KS200) 폴백.
     */
    public function getKospiIndexData($timeframe, $iscd = '0001')
    {

        if ($timeframe === '1d') {
            $data = $this->kisIndexRequest(
                '/uapi/domestic-stock/v1/quotations/inquire-daily-indexchartprice',
                'FHPUP02120000',
                [
                    'FID_COND_MRKT_DIV_CODE' => 'U',
                    'FID_INPUT_ISCD' => $iscd,
                    'FID_INPUT_DATE_1' => date('Ymd', strtotime('-120 days')),
                    'FID_INPUT_DATE_2' => date('Ymd'),
                    'FID_PERIOD_DIV_CODE' => 'D',
                ]
            );

            if (!$data || ($data['rt_cd'] ?? '1') !== '0' || empty($data['output2'])) {
                return $this->getYahooChartData('KOSPI200', $timeframe);
            }

            $candles = [];
            foreach ($data['output2'] as $r) { // KIS 정렬 가정하지 않고 전부 수집 후 시간순 정렬
                if ((float)($r['bstp_nmix_prpr'] ?? 0) <= 0) {
                    continue;
                }
                $candles[] = [
                    'time' => date('Y-m-d', strtotime($r['stck_bsop_date'])),
                    'open' => round((float)$r['bstp_nmix_oprc'], 2),
                    'high' => round((float)$r['bstp_nmix_hgpr'], 2),
                    'low' => round((float)$r['bstp_nmix_lwpr'], 2),
                    'close' => round((float)$r['bstp_nmix_prpr'], 2),
                    'volume' => (int)($r['acml_vol'] ?? 0),
                ];
            }

            if (empty($candles)) {
                return $this->getYahooChartData('KOSPI200', $timeframe);
            }

            usort($candles, function ($a, $b) {
                return strcmp($a['time'], $b['time']); // 과거 → 최신
            });

            $o1 = $data['output1'] ?? [];
            $current = (float)($o1['bstp_nmix_prpr'] ?? end($candles)['close']);
            [$chg, $pct] = $this->kisIndexChange($o1);
            return $this->kisIndexResponse($current, $chg, $pct, $candles, 'KIS 코스피 지수 (일봉)');
        }

        // 분봉 (3m/5m/10m/30m/1h) — KIS 업종 분봉(종가만 제공) → OHLC 합성 후 집계
        $data = $this->kisIndexRequest(
            '/uapi/domestic-stock/v1/quotations/inquire-time-indexchartprice',
            'FHPUP02110200',
            [
                'FID_COND_MRKT_DIV_CODE' => 'U',
                'FID_INPUT_ISCD' => $iscd,
                'FID_INPUT_HOUR_1' => '',
                'FID_PW_DATA_INCU_YN' => 'Y',
                'FID_ETC_CLS_CODE' => '',
            ]
        );

        if (!$data || ($data['rt_cd'] ?? '1') !== '0' || empty($data['output'])) {
            return $this->getYahooChartData('KOSPI200', $timeframe);
        }

        // 첫 행 bsop_hour '888888'(요약) 및 비정상 시각 제외
        $rows = array_values(array_filter($data['output'], function ($r) {
            $h = $r['bsop_hour'] ?? '';
            return preg_match('/^\d{6}$/', $h) && $h !== '888888' && (int)substr($h, 0, 2) <= 23;
        }));

        $tzSeoul = new \DateTimeZone('Asia/Seoul');
        $todayStr = (new \DateTime('now', $tzSeoul))->format('Ymd');

        // 종가만 제공되므로 (ts, close, vol, row) 로 파싱 후 시간순 정렬 (KIS 정렬 가정하지 않음)
        $points = [];
        foreach ($rows as $r) {
            $close = (float)$r['bstp_nmix_prpr'];
            if ($close <= 0) {
                continue;
            }
            $dt = \DateTime::createFromFormat('YmdHis', $todayStr . $r['bsop_hour'], $tzSeoul);
            if (!$dt) {
                continue;
            }
            $ts = $dt->getTimestamp();
            $ts = $ts - ($ts % 60);
            $points[] = ['ts' => $ts, 'close' => $close, 'vol' => (int)($r['cntg_vol'] ?? 0), 'row' => $r];
        }

        if (empty($points)) {
            return $this->getYahooChartData('KOSPI200', $timeframe);
        }

        usort($points, function ($a, $b) {
            return $a['ts'] <=> $b['ts']; // 과거 → 최신
        });

        // 전봉 종가를 시가로 사용해 OHLC 합성 (인덱스 분봉은 종가만 제공)
        $oneMin = [];
        $prevClose = null;
        foreach ($points as $p) {
            $close = $p['close'];
            $open = $prevClose ?? $close;
            $oneMin[] = [
                'time' => $p['ts'],
                'open' => round($open, 2),
                'high' => round(max($open, $close), 2),
                'low' => round(min($open, $close), 2),
                'close' => round($close, 2),
                'volume' => $p['vol'],
            ];
            $prevClose = $close;
        }

        $intervalSeconds = 180;
        if ($timeframe === '5m') $intervalSeconds = 300;
        elseif ($timeframe === '10m') $intervalSeconds = 600;
        elseif ($timeframe === '30m') $intervalSeconds = 1800;
        elseif ($timeframe === '1h') $intervalSeconds = 3600;

        $candles = $this->aggregateCandles($oneMin, $intervalSeconds);

        // 시간순 정렬된 마지막 분의 원본 행에서 현재가·등락 추출
        $latestPoint = end($points);
        $latestRow = $latestPoint['row'];
        $current = (float)$latestRow['bstp_nmix_prpr'];
        [$chg, $pct] = $this->kisIndexChange($latestRow);
        return $this->kisIndexResponse($current, $chg, $pct, $candles, 'KIS 코스피 지수 (' . $timeframe . ')');
    }

    private function fetchDomesticPriceFromKis($ticker)
    {
        $code = preg_replace('/(\.KS|\.KQ)$/i', '', $ticker);
        
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        
        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }
        
        $lastSuccessKey = "kis_last_successful_price_{$ticker}";
        
        try {
            $accessToken = $this->getAccessToken();
            $client = new Client();
            $trId = (strpos($apiUrl, 'openapivts') !== false) ? 'VHPST01010000' : 'FHPST01010000';
            
            $response = $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/inquire-price", [
                'headers' => [
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$accessToken}",
                    'appkey' => $appKey,
                    'appsecret' => $appSecret,
                    'tr_id' => $trId,
                ],
                'query' => [
                    'FID_COND_MRKT_DIV_CODE' => 'J',
                    'FID_INPUT_ISCD' => $code,
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['output']['stck_prpr'])) {
                $price = (float)$data['output']['stck_prpr'];
                $change = (float)$data['output']['prdy_vrss'];
                $changePercent = (float)$data['output']['prdy_ctrt'];
                $sign = $data['output']['prdy_vrss_sign'] ?? '3';
                
                if ($sign === '4' || $sign === '5') {
                    $change = -$change;
                    $changePercent = -$changePercent;
                }
                
                $result = [
                    'price' => $price,
                    'change_amount' => $change,
                    'change_percent' => $changePercent,
                ];
                
                Cache::put($lastSuccessKey, $result, 86400); // Cache for 24 hours
                return $result;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch domestic price from KIS: " . $e->getMessage());
        }
        
        // Fallback to last successful price cache if current API call fails
        $fallback = Cache::get($lastSuccessKey);
        if ($fallback) {
            \Illuminate\Support\Facades\Log::info("Using fallback KIS price for {$ticker} due to API failure/rate limit");
            return $fallback;
        }
        
        return null;
    }

    private function getStockName($ticker)
    {
        $names = [
            'TSLA' => 'Tesla, Inc.',
            'AAPL' => 'Apple Inc.',
            'NVDA' => 'NVIDIA Corporation',
            'MSFT' => 'Microsoft Corporation',
            'AMZN' => 'Amazon.com, Inc.',
            'GOOGL' => 'Alphabet Inc.',
            'USDKRW=X' => '원/달러 환율',
            '0167A0.KS' => 'SOL AI반도체TOP2플러스',
            '0167A0' => 'SOL AI반도체TOP2플러스',
            '0167AO.KS' => 'SOL AI반도체TOP2플러스',
            '0167AO' => 'SOL AI반도체TOP2플러스',
            '005930.KS' => '삼성전자',
            '005930' => '삼성전자',
            '000660.KS' => 'SK하이닉스',
            '000660' => 'SK하이닉스',
            '035420.KS' => 'NAVER',
            '035420' => 'NAVER',
            '035720.KS' => '카카오',
            '035720' => '카카오',
        ];

        if (isset($names[$ticker])) {
            return $names[$ticker];
        }

        $cleanTicker = strtoupper($ticker);
        if (preg_match('/(\.KS|\.KQ)$/', $cleanTicker) || preg_match('/^\d{6}$/', $cleanTicker)) {
            $code = preg_replace('/(\.KS|\.KQ)$/', '', $cleanTicker);
            $filePath = storage_path('app/krx_stocks.json');
            if (file_exists($filePath)) {
                $stocks = json_decode(file_get_contents($filePath), true);
                if (is_array($stocks)) {
                    foreach ($stocks as $stock) {
                        if ($stock['code'] === $code) {
                            return $stock['name'];
                        }
                    }
                }
            }
        }

        return $ticker . ' Inc.';
    }

    public function searchStocks(Request $request)
    {
        $query = trim($request->query('q', ''));
        $type = $request->query('type', 'all'); // 'kr', 'us', 'all'
        
        if (empty($query)) {
            return response()->json([]);
        }

        // 1. 국내 주식의 경우: krx_stocks.json + DB stocks(KR) 합산 검색
        if ($type === 'kr') {
            $queryLower  = strtolower($query);
            $queryChosung = $this->getChosung($queryLower);

            // 1-a. krx_stocks.json 검색 (초성/한글/코드)
            $jsonResults = [];
            $seenCodes   = [];   // DB 추가분 중복 제거용

            $filePath = storage_path('app/krx_stocks.json');
            if (file_exists($filePath)) {
                $jsonContent = file_get_contents($filePath);
                $krxStocks   = json_decode($jsonContent, true);
                if (is_array($krxStocks)) {
                    foreach ($krxStocks as $stock) {
                        $name        = $stock['name'];
                        $code        = $stock['code'];
                        $nameLower   = strtolower($name);
                        $nameChosung = $this->getChosung($nameLower);

                        $match = (strpos($code, $queryLower) !== false)
                            || (strpos($nameLower, $queryLower) !== false)
                            || (strpos($nameChosung, $queryChosung) !== false);

                        if ($match) {
                            $jsonResults[] = [
                                'ticker'   => $stock['ticker'],
                                'name'     => $name,
                                'isKorean' => true,
                                'exchange' => $stock['market'],
                            ];
                            $seenCodes[$code] = true;
                        }

                        if (count($jsonResults) >= 20) {
                            break;
                        }
                    }
                }
            }

            // 1-b. DB stocks(KR) 검색 — krx_stocks.json 에 없는 종목(SOL ETF 등)을 보완
            // symbol 또는 name 에 검색어 포함 시 추가 (초성은 DB 레벨 불가 → 이름/코드 LIKE 만)
            $dbResults = [];
            try {
                $dbStocks = \App\Models\Stock::where('market', 'KR')
                    ->where(function ($q) use ($queryLower) {
                        $q->whereRaw('LOWER(symbol) LIKE ?', ['%' . $queryLower . '%'])
                          ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $queryLower . '%']);
                    })
                    ->limit(20)
                    ->get(['symbol', 'name', 'exchange']);

                foreach ($dbStocks as $dbStock) {
                    // krx_stocks.json 결과와 중복이면 건너뜀
                    if (isset($seenCodes[$dbStock->symbol])) {
                        continue;
                    }
                    $exchange      = $dbStock->exchange ?? 'KR';
                    $dbResults[]   = [
                        'ticker'   => $dbStock->symbol . '.KS',   // 프론트 일관성 유지
                        'name'     => $dbStock->name,
                        'isKorean' => true,
                        'exchange' => $exchange,
                    ];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[searchStocks] DB KR 검색 오류: ' . $e->getMessage());
            }

            // krx_stocks.json 결과 우선, DB 추가분을 뒤에 붙여 최대 20개
            $merged = array_slice(array_merge($jsonResults, $dbResults), 0, 20);
            return response()->json($merged);
        }

        // 2. 미국 주식의 경우: 야후 파이낸스 API 활용 (영어 쿼리)
        try {
            $client = new Client();
            $url = "https://query1.finance.yahoo.com/v1/finance/search?q=" . urlencode($query) . "&quotesCount=20&newsCount=0";
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $quotes = $data['quotes'] ?? [];

            $results = [];
            foreach ($quotes as $quote) {
                $quoteType = $quote['quoteType'] ?? '';
                if ($quoteType !== 'EQUITY' && $quoteType !== 'ETF') {
                    continue;
                }

                $symbol = $quote['symbol'] ?? '';
                $name = $quote['longname'] ?? $quote['shortname'] ?? $symbol;
                $exchange = $quote['exchange'] ?? '';

                $isKorean = preg_match('/(\.KS|\.KQ)$/i', $symbol) || preg_match('/^\d{6}$/', $symbol);

                if ($type === 'us' && $isKorean) {
                    continue;
                }

                $results[] = [
                    'ticker' => $symbol,
                    'name' => $name,
                    'isKorean' => $isKorean,
                    'exchange' => $exchange
                ];
            }

            return response()->json($results);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Stock search error: " . $e->getMessage());
            return response()->json([]);
        }
    }

    private function getChosung($str)
    {
        $chosung = ["ㄱ", "ㄲ", "ㄴ", "ㄷ", "ㄸ", "ㄹ", "ㅁ", "ㅂ", "ㅃ", "ㅅ", "ㅆ", "ㅇ", "ㅈ", "ㅉ", "ㅊ", "ㅋ", "ㅌ", "ㅍ", "ㅎ"];
        $result = "";
        
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $code = $this->utf8Ord($char);
            
            if ($code >= 0xAC00 && $code <= 0xD7A3) {
                $temp = $code - 0xAC00;
                $choIdx = (int)($temp / 588);
                $result .= $chosung[$choIdx];
            } else {
                $result .= $char;
            }
        }
        return $result;
    }

    private function utf8Ord($ch)
    {
        $len = strlen($ch);
        if ($len <= 0) return 0;
        $h = ord($ch[0]);
        if ($h <= 0x7F) return $h;
        if ($h < 0xC2) return 0;
        if ($h <= 0xDF && $len > 1) return ($h & 0x1F) << 6 | (ord($ch[1]) & 0x3F);
        if ($h <= 0xEF && $len > 2) return ($h & 0x0F) << 12 | (ord($ch[1]) & 0x3F) << 6 | (ord($ch[2]) & 0x3F);
        if ($h <= 0xF4 && $len > 3) return ($h & 0x0F) << 18 | (ord($ch[1]) & 0x3F) << 12 | (ord($ch[2]) & 0x3F) << 6 | (ord($ch[3]) & 0x3F);
        return 0;
    }

    private function aggregateCandles($candles1m, $intervalSeconds)
    {
        if (empty($candles1m)) {
            return [];
        }

        $grouped = [];
        foreach ($candles1m as $c) {
            $timestamp = (int)$c['time'];
            $periodTime = $timestamp - ($timestamp % $intervalSeconds);
            
            if (!isset($grouped[$periodTime])) {
                $grouped[$periodTime] = [
                    'time' => $periodTime,
                    'open' => $c['open'],
                    'high' => $c['high'],
                    'low' => $c['low'],
                    'close' => $c['close'],
                    'volume' => $c['volume']
                ];
            } else {
                $grouped[$periodTime]['high'] = max($grouped[$periodTime]['high'], $c['high']);
                $grouped[$periodTime]['low'] = min($grouped[$periodTime]['low'], $c['low']);
                $grouped[$periodTime]['close'] = $c['close'];
                $grouped[$periodTime]['volume'] += $c['volume'];
            }
        }

        ksort($grouped);
        return array_values($grouped);
    }

    /**
     * 해당 시각이 KRX 개장일(거래일)인지 KIS 국내휴장일조회 API(CTCA0903R)로 판정.
     * 공휴일·임시휴장을 하드코딩 없이 KIS API 로 판단한다.
     * 날짜 단위로 캐싱하며, API 가 한 번에 여러 날짜를 돌려주므로 함께 캐싱한다.
     * API 실패 시에만 평일 여부로 폴백(휴일 하드코딩 아님).
     */
    private function isKrTradingDay($timestamp)
    {
        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));
        $dateStr = $dt->format('Ymd');
        $cacheKey = "kis_trading_day_{$dateStr}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // KIS 국내휴장일조회 — BASS_DT 부터의 영업일/개장일 정보를 한꺼번에 받아 캐싱
        $opened = $this->fetchKisOpenedDays($dateStr);
        if (!empty($opened)) {
            foreach ($opened as $d => $isOpen) {
                Cache::put("kis_trading_day_{$d}", $isOpen, 60 * 60 * 24 * 7); // 7일 캐싱
            }
            if (array_key_exists($dateStr, $opened)) {
                return $opened[$dateStr];
            }
        }

        // 폴백: API 가 응답하지 않을 때만 평일 여부 사용 (짧게 캐싱해 API 회복 시 갱신)
        $isWeekday = ((int)$dt->format('N')) <= 5;
        Cache::put($cacheKey, $isWeekday, 60 * 30);
        return $isWeekday;
    }

    /**
     * KIS 국내휴장일조회(CTCA0903R) 호출 → [Ymd => 개장여부(bool)] 맵 반환.
     * opnd_yn(개장일여부) 'Y' 면 거래일.
     */
    private function fetchKisOpenedDays($baseDateStr)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapi.koreainvestment.com:9443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');

        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return [];
        }

        try {
            $client = new Client();

            $doRequest = function ($token) use ($client, $apiUrl, $appKey, $appSecret, $baseDateStr) {
                return $client->get("{$apiUrl}/uapi/domestic-stock/v1/quotations/chk-holiday", [
                    'headers' => [
                        'content-type' => 'application/json',
                        'authorization' => "Bearer {$token}",
                        'appkey' => $appKey,
                        'appsecret' => $appSecret,
                        'tr_id' => 'CTCA0903R',
                        'custtype' => 'P',
                    ],
                    'query' => [
                        'BASS_DT' => $baseDateStr,
                        'CTX_AREA_NK' => '',
                        'CTX_AREA_FK' => '',
                    ],
                    'http_errors' => false,
                ]);
            };

            $accessToken = $this->getAccessToken();
            $response = $doRequest($accessToken);
            $data = json_decode($response->getBody()->getContents(), true);

            // 토큰 만료(EGW00123) 시 강제 갱신 후 1회 재시도 — 휴장 감지가 폴백으로 새지 않게
            if (isset($data['msg_cd']) && $data['msg_cd'] === 'EGW00123') {
                $accessToken = $this->getAccessToken(true);
                $response = $doRequest($accessToken);
                $data = json_decode($response->getBody()->getContents(), true);
            }

            $map = [];
            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $row) {
                    if (isset($row['bass_dt'])) {
                        // opnd_yn: 개장일여부(증시 개장). bzdy_yn(영업일)·tr_day_yn(거래일)도 있으나 개장 기준 사용.
                        $map[$row['bass_dt']] = (($row['opnd_yn'] ?? 'N') === 'Y');
                    }
                }
            }
            return $map;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("KIS 휴장일 조회 실패: " . $e->getMessage());
            return [];
        }
    }

    private function isKrxMarketOpen($timestamp)
    {
        // 공휴일·주말이면 개장 아님 (KIS API 기준)
        if (!$this->isKrTradingDay($timestamp)) {
            return false;
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int)$dt->format('Hi'); // e.g. 0900
        return ($timeVal >= 900 && $timeVal <= 1530);
    }

    private function accumulateRealTimePrice($ticker, $price, $lastYahooTime)
    {
        $cacheKey = "kis_accumulated_1m_{$ticker}";
        $accumulated = Cache::get($cacheKey, []);
        
        $now = time();
        if (!$this->isKrxMarketOpen($now)) {
            return $accumulated;
        }
        
        $currentPeriodTime = $now - ($now % 60);
        
        // Align last Yahoo time to 60s
        $alignedLastYahooTime = $lastYahooTime - ($lastYahooTime % 60);
        
        // If empty and we have a last Yahoo time, bootstrap the array to fill the delay gap
        if (empty($accumulated) && $lastYahooTime > 0) {
            $tempTime = $alignedLastYahooTime + 60;
            $lastClose = $price; // Fallback
            while ($tempTime <= $currentPeriodTime) {
                if ($this->isKrxMarketOpen($tempTime)) {
                    $accumulated[] = [
                        'time' => $tempTime,
                        'open' => $lastClose,
                        'high' => $lastClose,
                        'low' => $lastClose,
                        'close' => $lastClose,
                        'volume' => 0
                    ];
                }
                $tempTime += 60;
            }
        }
        
        if (empty($accumulated)) {
            $accumulated[] = [
                'time' => $currentPeriodTime,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => 0
            ];
        } else {
            $lastIdx = count($accumulated) - 1;
            $lastItem = $accumulated[$lastIdx];
            
            if ($lastItem['time'] === $currentPeriodTime) {
                $accumulated[$lastIdx]['close'] = $price;
                $accumulated[$lastIdx]['high'] = max($lastItem['high'], $price);
                $accumulated[$lastIdx]['low'] = min($lastItem['low'], $price);
            } else {
                $prevClose = $lastItem['close'];
                
                // Fill offline gap
                $gapTime = max($lastItem['time'] + 60, time() - 86400);
                $gapTime = $gapTime - ($gapTime % 60); // Align to 60s
                while ($gapTime < $currentPeriodTime) {
                    if ($this->isKrxMarketOpen($gapTime)) {
                        $accumulated[] = [
                            'time' => $gapTime,
                            'open' => $prevClose,
                            'high' => $prevClose,
                            'low' => $prevClose,
                            'close' => $prevClose,
                            'volume' => 0
                        ];
                    }
                    $gapTime += 60;
                }
                
                $accumulated[] = [
                    'time' => $currentPeriodTime,
                    'open' => $prevClose,
                    'high' => max($prevClose, $price),
                    'low' => min($prevClose, $price),
                    'close' => $price,
                    'volume' => 0
                ];
            }
        }
        
        if (count($accumulated) > 120) {
            $accumulated = array_slice($accumulated, -120);
        }
        
        Cache::put($cacheKey, $accumulated, 86400);
        return $accumulated;
    }

    /**
     * Yahoo Finance 의 currentTradingPeriod.regular.start(unix) 의 NY 날짜를 기준으로
     * 오늘이 미국 주식 거래일인지 판정한다.
     *
     * - 거래일이면 start 가 오늘(NY) 09:30 을 가리킨다.
     * - 휴장(공휴일·주말)이면 start 가 마지막 거래일(과거)을 가리킨다.
     *
     * 결과는 날짜 단위(오늘 자정까지)로 캐싱해, 모든 미국 종목 요청이 공유한다.
     * API 실패 시 폴백: 주말 여부(평일=거래일 가정)로 판정 — 하드코딩 공휴일 없음.
     */
    private function isUsMarketTradingToday(): bool
    {
        $nyTz = new \DateTimeZone('America/New_York');
        $todayNy = (new \DateTime('now', $nyTz))->format('Y-m-d');
        $cacheKey = "us_trading_day_{$todayNy}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool)$cached;
        }

        try {
            $client = new \GuzzleHttp\Client();
            // SPY 는 NYSE Arca 상장 ETF 로 미국 시장 개폐와 동일 — 가벼운 1d/1d 요청으로 meta 만 취득
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

                // 결과를 오늘 자정(NY)까지 캐싱 — 거래일/휴장 결과가 날짜 넘어가면 무효
                $midnight = new \DateTime('tomorrow', $nyTz);
                $ttl = $midnight->getTimestamp() - time();
                Cache::put($cacheKey, $isTradingDay, max($ttl, 300));

                return $isTradingDay;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("isUsMarketTradingToday: Yahoo fetch 실패, 주말 폴백 사용: " . $e->getMessage());
        }

        // 폴백: API 응답 없을 때만 주말 여부로 판정 (공휴일은 식별 불가 — 짧게 캐싱)
        $nyDow = (int)(new \DateTime('now', $nyTz))->format('N');
        $isWeekday = ($nyDow <= 5);
        Cache::put($cacheKey, $isWeekday, 300); // 5분 후 재시도 가능하도록 짧게 캐싱
        return $isWeekday;
    }

    private function getUsMarketSessionInfo($timestamp)
    {
        // 미국 휴장일(공휴일·주말)이면 즉시 장마감 반환
        if (!$this->isUsMarketTradingToday()) {
            return '장마감';
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N'); // 1 = Mon, ..., 7 = Sun
        $hour = (int)$dt->format('H');
        $minute = (int)$dt->format('i');
        $timeVal = $hour * 100 + $minute;

        // Weekend check in New York time:
        // Friday after 20:00 (8:00 PM EST) to Sunday before 20:00 (8:00 PM EST) is Closed.
        $isClosed = false;
        if ($dayOfWeek === 6) { // Saturday
            $isClosed = true;
        } elseif ($dayOfWeek === 7 && $timeVal < 2000) { // Sunday before 8 PM EST
            $isClosed = true;
        } elseif ($dayOfWeek === 5 && $timeVal >= 2000) { // Friday after 8 PM EST
            $isClosed = true;
        }

        if ($isClosed) {
            return '장마감';
        }
        
        // Pre-market: 04:00 to 09:30
        // Regular Market: 09:30 to 16:00
        // Post-market (애프터마켓): 16:00 to 20:00
        // Daytime Trading (주간거래): 20:00 to 04:00 (next day)
        
        if ($timeVal >= 400 && $timeVal < 930) {
            return '프리마켓';
        } elseif ($timeVal >= 930 && $timeVal < 1600) {
            return '정규장';
        } elseif ($timeVal >= 1600 && $timeVal < 2000) {
            return '애프터마켓';
        } else {
            return '주간거래';
        }
    }

    private function isUsMarketOpen($timestamp)
    {
        // 미국 휴장일(공휴일·주말)이면 시장 미개장
        if (!$this->isUsMarketTradingToday()) {
            return false;
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('America/New_York'));

        $dayOfWeek = (int)$dt->format('N');
        $hour = (int)$dt->format('H');
        $minute = (int)$dt->format('i');
        $timeVal = $hour * 100 + $minute;

        // Weekend check in New York time:
        // Friday after 20:00 (8 PM EST) to Sunday before 20:00 (8 PM EST) is Closed.
        if ($dayOfWeek === 6) { // Saturday
            return false;
        }
        if ($dayOfWeek === 7) { // Sunday
            return ($timeVal >= 2000); // Open at 8 PM EST for Daytime trading
        }
        if ($dayOfWeek === 5) { // Friday
            return ($timeVal < 2000); // Close at 8 PM EST
        }

        return true;
    }

    private function getKrMarketSessionInfo($timestamp)
    {
        // 공휴일·주말 등 비개장일이면 장마감 (KIS API 기준)
        if (!$this->isKrTradingDay($timestamp)) {
            return '장마감';
        }

        $dt = new \DateTime("@{$timestamp}");
        $dt->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $timeVal = (int)$dt->format('Hi');
        if ($timeVal >= 900 && $timeVal <= 1530) {
            return '정규장';
        }

        return '장마감';
    }

    private function fetchOverseasPriceFromKis($ticker)
    {
        $apiUrl = env('KIS_API_URL', 'https://openapivts.koreainvestment.com:29443');
        $appKey = env('KIS_APP_KEY');
        $appSecret = env('KIS_APP_SECRET');
        
        if (empty($appKey) || empty($appSecret) || $appKey === 'your_app_key_here') {
            return null;
        }
        
        $lastSuccessKey = "kis_last_successful_overseas_price_{$ticker}";
        $exchanges = ['NAS', 'NYS', 'AMS'];
        
        try {
            $accessToken = $this->getAccessToken();
            $client = new Client();
            
            foreach ($exchanges as $exchange) {
                try {
                    $response = $client->get("{$apiUrl}/uapi/overseas-price/v1/quotations/price", [
                        'headers' => [
                            'content-type' => 'application/json',
                            'authorization' => "Bearer {$accessToken}",
                            'appkey' => $appKey,
                            'appsecret' => $appSecret,
                            'tr_id' => 'HHDFS00000300',
                        ],
                        'query' => [
                            'AUTH' => '',
                            'EXCD' => $exchange,
                            'SYMB' => $ticker,
                        ]
                    ]);
                    
                    $data = json_decode($response->getBody()->getContents(), true);
                    if (isset($data['output']['last']) && (float)$data['output']['last'] > 0) {
                        $output = $data['output'];
                        $result = [
                            'price' => (float)$output['last'],
                            'change_amount' => (float)$output['diff'],
                            'change_percent' => (float)$output['rate'],
                            'sign' => $output['sign'] ?? '3'
                        ];
                        
                        if ($result['sign'] === '4' || $result['sign'] === '5') {
                            $result['change_amount'] = -abs($result['change_amount']);
                            $result['change_percent'] = -abs($result['change_percent']);
                        } else {
                            $result['change_amount'] = abs($result['change_amount']);
                            $result['change_percent'] = abs($result['change_percent']);
                        }
                        
                        Cache::put($lastSuccessKey, $result, 86400); // 24 hours
                        return $result;
                    }
                } catch (\Exception $ex) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to fetch overseas price from KIS: " . $e->getMessage());
        }
        
        $fallback = Cache::get($lastSuccessKey);
        if ($fallback) {
            return $fallback;
        }
        
        return null;
    }

    private function accumulateOverseasRealTimePrice($ticker, $price, $lastYahooTime)
    {
        $cacheKey = "kis_accumulated_us_1m_{$ticker}";
        $accumulated = Cache::get($cacheKey, []);
        
        $now = time();
        if (!$this->isUsMarketOpen($now)) {
            return $accumulated;
        }
        
        $currentPeriodTime = $now - ($now % 60);
        $alignedLastYahooTime = $lastYahooTime - ($lastYahooTime % 60);
        
        if (empty($accumulated) && $lastYahooTime > 0) {
            $tempTime = $alignedLastYahooTime + 60;
            $lastClose = $price;
            while ($tempTime <= $currentPeriodTime) {
                if ($this->isUsMarketOpen($tempTime)) {
                    $accumulated[] = [
                        'time' => $tempTime,
                        'open' => $lastClose,
                        'high' => $lastClose,
                        'low' => $lastClose,
                        'close' => $lastClose,
                        'volume' => 0
                    ];
                }
                $tempTime += 60;
            }
        }
        
        if (empty($accumulated)) {
            $accumulated[] = [
                'time' => $currentPeriodTime,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => 0
            ];
        } else {
            $lastIdx = count($accumulated) - 1;
            $lastItem = $accumulated[$lastIdx];
            
            if ($lastItem['time'] === $currentPeriodTime) {
                $accumulated[$lastIdx]['close'] = $price;
                $accumulated[$lastIdx]['high'] = max($lastItem['high'], $price);
                $accumulated[$lastIdx]['low'] = min($lastItem['low'], $price);
            } else {
                $prevClose = $lastItem['close'];
                
                // Fill offline gap
                $gapTime = max($lastItem['time'] + 60, time() - 86400);
                $gapTime = $gapTime - ($gapTime % 60);
                while ($gapTime < $currentPeriodTime) {
                    if ($this->isUsMarketOpen($gapTime)) {
                        $accumulated[] = [
                            'time' => $gapTime,
                            'open' => $prevClose,
                            'high' => $prevClose,
                            'low' => $prevClose,
                            'close' => $prevClose,
                            'volume' => 0
                        ];
                    }
                    $gapTime += 60;
                }
                
                $accumulated[] = [
                    'time' => $currentPeriodTime,
                    'open' => $prevClose,
                    'high' => max($prevClose, $price),
                    'low' => min($prevClose, $price),
                    'close' => $price,
                    'volume' => 0
                ];
            }
        }
        
        if (count($accumulated) > 120) {
            $accumulated = array_slice($accumulated, -120);
        }
        
        Cache::put($cacheKey, $accumulated, 86400);
        return $accumulated;
    }

    public function getEarningsDate($ticker)
    {
        $ticker = strtoupper($ticker);
        
        $cacheKey = "earnings_date_{$ticker}";
        return Cache::remember($cacheKey, 14400, function () use ($ticker) {
            try {
                $client = new Client();
                $url = "https://finance.yahoo.com/quote/{$ticker}";
                
                $response = $client->get($url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
                    ],
                    'timeout' => 7
                ]);
                
                $html = $response->getBody()->getContents();
                
                // 1. JSON Regex (Escaped Quotes Support in state scripts)
                if (preg_match('/\\\\?"earningsDate\\\\?":\s*\[\s*\{\s*\\\\?"raw\\\\?":\s*(\d+)/i', $html, $matches)) {
                    $rawTime = (int)$matches[1];
                    if ($rawTime > 0) {
                        $dateTime = new \DateTime("@{$rawTime}");
                        $dateTime->setTimezone(new \DateTimeZone('Asia/Seoul'));
                        
                        $hour = (int)$dateTime->format('H');
                        $minute = (int)$dateTime->format('i');
                        if ($hour === 0 && $minute === 0) {
                            $formatted = $dateTime->format('Y-m-d');
                        } else {
                            $formatted = $dateTime->format('Y-m-d H:i');
                        }
                        
                        return response()->json([
                            'success' => true,
                            'earnings_date' => $formatted,
                            'raw' => $rawTime
                        ]);
                    }
                }
                
                // 2. Fallback: HTML Markup Regex (e.g. "Earnings Date </span> <span ...>Date</span>")
                if (preg_match('/title="Earnings Date">Earnings Date\s*<\/span>\s*<span[^>]*>(.*?)<\/span>/is', $html, $matches)) {
                    $rawText = trim($matches[1]);
                    if ($rawText && strtolower($rawText) !== '--') {
                        return response()->json([
                            'success' => true,
                            'earnings_date' => $rawText,
                            'raw' => null
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to fetch HTML earnings for {$ticker}: " . $e->getMessage());
            }
            
            return response()->json([
                'success' => false,
                'message' => '실적발표일 정보가 없습니다.'
            ]);
        });
    }
}
