<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Log;

/**
 * 국내(KR) 종목 심볼 정규화 + lazy-create 헬퍼.
 *
 * - 접미사 제거: "005930.KS" → "005930", "0167A0.KQ" → "0167A0"
 * - stocks 테이블에 없으면 자동 생성 (firstOrCreate, UNIQUE 보장)
 * - 종목명: krx_stocks.json → getStockName 배열 → symbol 자체 순으로 폴백
 * - ETF 판정: 이름에 ETF 브랜드 키워드 포함 시 type='etf'
 * - exchange: krx_stocks.json market 필드(KOSPI/KOSDAQ) → ticker 접미사 → null
 */
class KrStockResolver
{
    /** ETF 브랜드 키워드 (대소문자 무관) */
    private const ETF_KEYWORDS = [
        'ETF', 'KODEX', 'TIGER', 'PLUS', 'SOL', 'ACE', 'KBSTAR', 'ARIRANG',
        'KOSEF', 'HANARO', 'FOCUS', 'TIMEFOLIO', 'TREX', 'KTOP',
    ];

    /** StockController::getStockName 의 KR 이름 맵 (하드코딩 동기화 유지) */
    private const KR_NAME_MAP = [
        '0167A0' => 'SOL AI반도체TOP2플러스',
        '0167AO' => 'SOL AI반도체TOP2플러스',
        '005930' => '삼성전자',
        '000660' => 'SK하이닉스',
        '035420' => 'NAVER',
        '035720' => '카카오',
    ];

    /**
     * 들어온 symbol 에서 .KS/.KQ 접미사를 제거해 정규화된 6자리 코드 반환.
     *
     * @param  string $rawSymbol  "005930", "005930.KS", "0167A0.KQ" 등
     * @return string             정규화 코드 ("005930", "0167A0" 등)
     */
    public function normalize(string $rawSymbol): string
    {
        $upper = strtoupper(trim($rawSymbol));
        return (string)preg_replace('/\.(KS|KQ)$/i', '', $upper);
    }

    /**
     * 정규화된 코드로 KR Stock 을 조회하거나 자동 생성해 반환.
     *
     * @param  string      $rawSymbol  접미사 포함/미포함 원본 심볼
     * @param  string|null $nameHint   프론트가 함께 전달한 종목명 힌트 (없으면 null)
     * @return Stock
     */
    public function resolveOrCreate(string $rawSymbol, ?string $nameHint = null): Stock
    {
        $code = $this->normalize($rawSymbol);

        return Stock::firstOrCreate(
            ['symbol' => $code, 'market' => 'KR'],
            $this->buildAttributes($code, $rawSymbol, $nameHint)
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * firstOrCreate 의 기본값(attributes) 배열 구성.
     *
     * @param  string      $code       정규화된 6자리 코드
     * @param  string      $rawSymbol  원본 심볼 (접미사 추론에 사용)
     * @param  string|null $nameHint   외부 힌트 종목명
     * @return array<string, mixed>
     */
    private function buildAttributes(string $code, string $rawSymbol, ?string $nameHint): array
    {
        $name     = $this->resolveName($code, $nameHint);
        $type     = $this->resolveType($name);
        $exchange = $this->resolveExchange($code, $rawSymbol);

        return [
            'name'     => $name,
            'type'     => $type,
            'currency' => 'KRW',
            'exchange' => $exchange,
        ];
    }

    /**
     * 종목명 결정 우선순위:
     * 1) nameHint (프론트 제공)
     * 2) krx_stocks.json code 매칭
     * 3) KR_NAME_MAP 하드코딩
     * 4) 코드 자체를 이름으로 사용
     *
     * @param  string      $code
     * @param  string|null $nameHint
     * @return string
     */
    private function resolveName(string $code, ?string $nameHint): string
    {
        if ($nameHint !== null && trim($nameHint) !== '') {
            return trim($nameHint);
        }

        $fromJson = $this->lookupKrxJson($code);
        if ($fromJson !== null) {
            return $fromJson['name'];
        }

        if (isset(self::KR_NAME_MAP[$code])) {
            return self::KR_NAME_MAP[$code];
        }

        return $code;
    }

    /**
     * exchange 결정 우선순위:
     * 1) krx_stocks.json market 필드 ("KOSPI"/"KOSDAQ")
     * 2) 원본 심볼 접미사 (.KS → KOSPI, .KQ → KOSDAQ)
     * 3) null
     *
     * @param  string $code
     * @param  string $rawSymbol
     * @return string|null
     */
    private function resolveExchange(string $code, string $rawSymbol): ?string
    {
        $fromJson = $this->lookupKrxJson($code);
        if ($fromJson !== null && !empty($fromJson['market'])) {
            return $fromJson['market']; // "KOSPI" or "KOSDAQ"
        }

        $upper = strtoupper($rawSymbol);
        if (preg_match('/\.KS$/i', $upper)) {
            return 'KOSPI';
        }
        if (preg_match('/\.KQ$/i', $upper)) {
            return 'KOSDAQ';
        }

        return null;
    }

    /**
     * ETF 여부 판정: 이름에 ETF 브랜드 키워드가 포함되면 'etf', 아니면 'stock'.
     *
     * @param  string $name
     * @return string  'etf'|'stock'
     */
    private function resolveType(string $name): string
    {
        $upper = strtoupper($name);
        foreach (self::ETF_KEYWORDS as $kw) {
            if (strpos($upper, strtoupper($kw)) !== false) {
                return 'etf';
            }
        }
        return 'stock';
    }

    /**
     * krx_stocks.json 에서 code 로 단일 항목 검색. 없으면 null.
     * 결과는 요청당 1회 파일 읽기(동일 요청 내 반복 호출 시 PHP 정적 캐시 활용).
     *
     * @param  string $code
     * @return array<string, string>|null  ['name'=>..., 'market'=>...]
     */
    private function lookupKrxJson(string $code): ?array
    {
        static $cache = null;

        if ($cache === null) {
            $path = storage_path('app/krx_stocks.json');
            if (!file_exists($path)) {
                Log::warning('[KrStockResolver] krx_stocks.json not found at: ' . $path);
                $cache = [];
            } else {
                $decoded = json_decode((string)file_get_contents($path), true);
                $cache   = is_array($decoded) ? $decoded : [];
            }
        }

        foreach ($cache as $item) {
            if (isset($item['code']) && $item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }
}
