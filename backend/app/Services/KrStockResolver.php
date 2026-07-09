<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Stock;

/**
 * 국내(KR) 종목 심볼 정규화 + lazy-create 헬퍼.
 *
 * - 접미사 제거: "005930.KS" → "005930", "0167A0.KQ" → "0167A0"
 * - stocks 테이블에 없으면 자동 생성 (firstOrCreate, UNIQUE 보장)
 * - 종목명·타입·통화: TossStockMaster accessor 가 제공(로컬 파일 미의존)
 * - exchange: ticker 접미사(.KS → KOSPI, .KQ → KOSDAQ)로 판정, 그 외 null
 *
 * 검색이 네이버 API 로 전환되며 로컬 종목 마스터(krx_stocks.json) 의존은 완전히 제거됨.
 */
class KrStockResolver
{
    // ETF_KEYWORDS·KR_NAME_MAP 은 Phase 7 이후 제거됨.
    // name·type 은 TossStockMaster accessor 가 제공하므로 로컬 판정 불필요.

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
     * Phase 7 이후: name·type·currency 컬럼이 삭제됐으므로 포함하지 않는다.
     * 종목명·타입·통화는 TossStockMaster accessor 가 제공한다.
     *
     * @param  string      $code       정규화된 6자리 코드
     * @param  string      $rawSymbol  원본 심볼 (접미사 추론에 사용)
     * @param  string|null $nameHint   외부 힌트 종목명 (현재 미사용 — accessor 에서 처리)
     * @return array<string, mixed>
     */
    private function buildAttributes(string $code, string $rawSymbol, ?string $nameHint): array
    {
        $exchange = $this->resolveExchange($rawSymbol);

        return [
            'exchange' => $exchange,
        ];
    }

    /**
     * exchange 결정 — 원본 심볼 접미사 폴백만 사용.
     * 검색 결과가 항상 .KS/.KQ 를 달고 오므로 이걸로 충분하다.
     *   .KS → KOSPI, .KQ → KOSDAQ, 그 외 → null
     *
     * @param  string $rawSymbol
     * @return string|null
     */
    private function resolveExchange(string $rawSymbol): ?string
    {
        $upper = strtoupper($rawSymbol);
        if (preg_match('/\.KS$/i', $upper)) {
            return 'KOSPI';
        }
        if (preg_match('/\.KQ$/i', $upper)) {
            return 'KOSDAQ';
        }

        return null;
    }
}
