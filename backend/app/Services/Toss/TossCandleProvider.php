<?php

declare(strict_types=1);

namespace App\Services\Toss;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 토스 /candles API 로 개별종목 차트 데이터를 제공.
 *
 * 책임:
 *   - 타임프레임별 토스 호출 전략 (1d → interval=1d, 분봉 → interval=1m 후 집계)
 *   - 페이지네이션 (nextBefore, 최대 20페이지 = 1m 원본 4000봉)
 *   - 봉 정규화 (ISO8601 → Unix timestamp 또는 Y-m-d)
 *   - 분봉 집계 (aggregateCandles)
 *   - 등락 계산 (TossChangeCalculator 위임)
 *   - 지수 심볼 skip → null 반환
 *
 * 캐싱: StockController 의 Cache::remember 가 담당. 이 클래스는 순수 데이터 반환.
 * 캐시 키 규칙(호출자): `yahoo_stock_data_{appSymbol}_{timeframe}_raw` (기존 키 유지)
 */
class TossCandleProvider
{
    private const CANDLES_ENDPOINT = '/api/v1/candles';

    /** 토스 /candles 단건 상한 — count>200 은 빈 응답. 페이지당 200봉 고정. */
    private const MAX_COUNT_PER_REQUEST = 200;

    /**
     * 페이지네이션 최대 루프 횟수 (hard cap) — 200봉 × 20페이지 = 1m 원본 최대 4000봉.
     * 2026-07-15 10→20 상향(분봉 커버리지 확대, needed 4배 요청). 근거(실측·성능):
     *   - 토스 US 1m 실소급 한계 ≈ 2070봉(약 1.5거래일, MU 11페이지에서 nextBefore=NULL).
     *     → US 는 needed 를 4배로 키워도 nextBefore=NULL 로 ~11페이지(2070봉)에서 자연 종료.
     *       MAX_PAGES=10(2000봉)은 US 를 약간 클리핑했으나, 20 으로 올려 US 자연 상한을 완전 도달.
     *       (2배→4배 needed 는 US 에 실효 없음 — 이미 2배 시점에 상한 근접.)
     *   - 국내 1m 은 8000봉+ 소급(사실상 제약 아님) → 국내만 4배가 실효.
     *   - 그러나 candles 엔드포인트 rate-limit = 200ms/페이지(5TPS). 국내 4배 full(1h 11200봉 = 56페이지)은
     *     56×200ms ≈ 11초 동기 블로킹 → WS 프리페처(refreshYahooCache, 전송 후 getChartData 동기 호출,
     *     ~3초 사이클)를 심각히 stall 시킨다. 그래서 20페이지(4000봉, 최악 ~4초)로 캡.
     * ponytail: 20페이지 = US 자연 상한(2070)을 다 담고 + 국내 1m/3m/5m 4배(≤12페이지)를 full 제공하는
     *   최소 상한. 10m/30m/1h 는 4배 needed(4800/7200/11200)가 이 4000봉 캡에 걸려 부분만 받는다
     *   (긴 봉은 봉 자체가 커 4배 필요성 낮음 — 성능 우선). 국내 full 4배가 필요하면 MAX_PAGES 를 올리되
     *   블로킹 시간을 감수해야 함(업그레이드 경로).
     */
    private const MAX_PAGES = 20;

    private TossApiClient $client;

    private TossSymbolMapper $mapper;

    private TossChangeCalculator $changeCalculator;

    public function __construct(
        TossApiClient $client,
        TossSymbolMapper $mapper,
        TossChangeCalculator $changeCalculator
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->changeCalculator = $changeCalculator;
    }

    /**
     * 개별종목 차트 데이터 반환.
     *
     * 지수 심볼이면 null 반환 (호출자가 Yahoo 폴백 처리).
     * 봉이 비어있거나 API 실패 시 null 반환 (graceful).
     *
     * @param  string  $appSymbol  앱 내부 심볼 (005930.KS, TSLA 등)
     * @param  string  $timeframe  1m|3m|5m|10m|30m|1h|1d
     * @param  bool  $raw  true면 집계 안 함(1m 원본 그대로)
     * @return array<string,mixed>|null
     */
    public function getChartData(string $appSymbol, string $timeframe = '1d', bool $raw = false): ?array
    {
        // 지수/환율 skip
        if ($this->mapper->shouldSkip($appSymbol)) {
            return null;
        }

        $tossSymbol = $this->mapper->toTossSymbol($appSymbol);
        if ($tossSymbol === null) {
            return null;
        }

        // 타임프레임별 전략 결정
        [$interval, $needed] = $this->resolveStrategy($timeframe);

        // 봉 취득
        $candles = $this->fetchCandles($tossSymbol, $interval, $needed);

        if (empty($candles)) {
            Log::debug("[TossCandleProvider] {$appSymbol} 봉 없음");

            return null;
        }

        // 시간 오름차순 정렬 (오래된 것 먼저 — 집계·UI 공통 요건)
        usort($candles, fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        // 집계 (분봉 + $raw=false 일 때)
        $intervalSeconds = $this->intervalSeconds($timeframe);
        if ($interval === '1m' && ! $raw && $intervalSeconds > 60) {
            $candles = $this->aggregateCandles($candles, $intervalSeconds);
        }

        if (empty($candles)) {
            return null;
        }

        // 현재가 = 마지막 봉 close
        $lastCandle = end($candles);
        $currentPrice = (float) $lastCandle['close'];

        // 등락 계산
        $change = $this->changeCalculator->calculate($tossSymbol, $currentPrice);

        return [
            'ticker' => $appSymbol,
            'name' => $appSymbol,
            'current_price' => round($currentPrice, 4),
            'change_amount' => $change['change_amount'],
            'change_percent' => $change['change_percent'],
            'candles' => $candles,
            'source' => 'Toss (' . $timeframe . ')',
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // 내부 전용
    // ──────────────────────────────────────────────────────────────────

    /**
     * 타임프레임 → [토스 interval, 필요한 원본 봉 수] 결정.
     *
     * @return array{0:string,1:int}
     */
    private function resolveStrategy(string $timeframe): array
    {
        switch ($timeframe) {
            // 일봉 ~2년치(약 500 거래일). fetchCandles 가 페이지당 200(토스 단건 상한)씩
            // before/nextBefore 로 페이지네이션 → 3페이지로 500봉 확보(MAX_PAGES=20 이내).
            // ponytail: 토스 실측 단건 상한 = 200(count>200 은 빈 응답). 순서·인덱싱 불변(개수만 확대).
            // 분봉 needed 2026-07-15 baseline 대비 4배 상향(커버리지 확대 요청).
            //   baseline: 1m/3m 400 · 5m 600 · 10m 1200 · 30m 1800 · 1h 2800
            //   4배:      1m/3m 1600 · 5m 2400 · 10m 4800 · 30m 7200 · 1h 11200
            // 실제 취득량은 두 상한에 의해 잘린다(fetchCandles 가 nextBefore=NULL 이면 조기 종료):
            //   - US: 토스 1m 실소급 한계 ≈ 2070봉 → 어떤 needed 든 ~2070봉에서 멈춤(4배 실효 없음).
            //   - 국내: 8000봉+ 여유지만 MAX_PAGES=20(4000봉) 캡에 걸림 →
            //       1m/3m/5m(needed ≤ 2400)은 full 4배 도달, 10m/30m/1h(4800~11200)는 4000봉까지만.
            case '1d':  return ['1d', 500];
            case '1m':  return ['1m', 1600];
            case '3m':  return ['1m', 1600];
            case '5m':  return ['1m', 2400];
            case '10m': return ['1m', 4800];
            case '30m': return ['1m', 7200];
            case '1h':  return ['1m', 11200];
            default:    return ['1m', 1600];
        }
    }

    /**
     * 타임프레임 → 집계 초 단위.
     */
    private function intervalSeconds(string $timeframe): int
    {
        switch ($timeframe) {
            case '1m':  return 60;
            case '3m':  return 180;
            case '5m':  return 300;
            case '10m': return 600;
            case '30m': return 1800;
            case '1h':  return 3600;
            case '1d':  return 86400;
            default:    return 60;
        }
    }

    /**
     * 토스 /candles API 를 페이지네이션으로 호출해 정규화된 봉 배열 반환.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchCandles(string $tossSymbol, string $interval, int $needed): array
    {
        $candles = [];
        $before = null;

        for ($page = 0; $page < self::MAX_PAGES && count($candles) < $needed; $page++) {
            $count = min(self::MAX_COUNT_PER_REQUEST, $needed - count($candles) + 10);
            $query = [
                'symbol' => $tossSymbol,
                'interval' => $interval,
                'count' => $count,
            ];
            if ($before !== null) {
                $query['before'] = $before;
            }

            $resp = $this->client->get(self::CANDLES_ENDPOINT, $query);

            if (empty($resp)) {
                break;
            }

            $result = $resp['result'] ?? [];
            $rawCandles = $result['candles'] ?? [];

            if (! is_array($rawCandles) || empty($rawCandles)) {
                break;
            }

            foreach ($rawCandles as $raw) {
                $parsed = $this->parseCandle($raw, $interval);
                if ($parsed !== null) {
                    $candles[] = $parsed;
                }
            }

            $before = $result['nextBefore'] ?? null;
            if ($before === null) {
                break;
            }
        }

        return $candles;
    }

    /**
     * 원시 캔들 배열 → 정규화된 봉.
     *
     * 분봉: time = Unix timestamp (int)
     * 일봉: time = 'Y-m-d' (string, KST 기준)
     * openPrice 등이 null 이거나 '0' 이면 skip → null 반환.
     *
     * @param  array<string,mixed>  $raw
     * @param  string  $interval  '1m' | '1d'
     * @return array<string,mixed>|null
     */
    private function parseCandle(array $raw, string $interval): ?array
    {
        $open = $raw['openPrice'] ?? null;
        $high = $raw['highPrice'] ?? null;
        $low = $raw['lowPrice'] ?? null;
        $close = $raw['closePrice'] ?? null;
        $vol = $raw['volume'] ?? '0';
        $ts = $raw['timestamp'] ?? null;

        // null 봉 제거
        if ($open === null || $high === null || $low === null || $close === null || $ts === null) {
            return null;
        }

        // '0' 봉 제거 (거래 없음)
        if ((float) $open === 0.0 || (float) $close === 0.0) {
            return null;
        }

        if ($interval === '1d') {
            $time = Carbon::parse((string) $ts)->timezone('Asia/Seoul')->format('Y-m-d');
        } else {
            $time = Carbon::parse((string) $ts)->timestamp;
        }

        return [
            'time' => $time,
            'open' => (float) $open,
            'high' => (float) $high,
            'low' => (float) $low,
            'close' => (float) $close,
            'volume' => (int) $vol,
        ];
    }

    /**
     * 1m 봉 배열을 지정 초 단위로 집계.
     *
     * OHLCV 규칙:
     *   - open  = 버킷 첫 봉 open
     *   - high  = max(all highs)
     *   - low   = min(all lows)
     *   - close = 버킷 마지막 봉 close
     *   - volume = sum
     *
     * 전제: $candles1m 은 time 오름차순 정렬 완료 상태.
     *
     * @param  array<int,array<string,mixed>>  $candles1m
     * @return array<int,array<string,mixed>>
     */
    private function aggregateCandles(array $candles1m, int $intervalSeconds): array
    {
        $buckets = [];

        foreach ($candles1m as $c) {
            $time = (int) $c['time'];
            $bucket = $time - ($time % $intervalSeconds);

            if (! isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'time' => $bucket,
                    'open' => (float) $c['open'],
                    'high' => (float) $c['high'],
                    'low' => (float) $c['low'],
                    'close' => (float) $c['close'],
                    'volume' => (int) $c['volume'],
                ];
            } else {
                if ((float) $c['high'] > $buckets[$bucket]['high']) {
                    $buckets[$bucket]['high'] = (float) $c['high'];
                }
                if ((float) $c['low'] < $buckets[$bucket]['low']) {
                    $buckets[$bucket]['low'] = (float) $c['low'];
                }
                $buckets[$bucket]['close'] = (float) $c['close'];
                $buckets[$bucket]['volume'] += (int) $c['volume'];
            }
        }

        return array_values($buckets);
    }
}
