<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 평가손익 계산 서비스 (읽기 전용 · 자문 아님).
 *
 * 원본 스펙 §06 evaluate() 를 PHP 7.4 로 이식.
 * 손익 공식: profitKRW = marketValueKRW − costKRW
 *   priceProfitKRW = (price − avg) * qty * fxCur   // 주가 손익
 *   fxProfitKRW    = (fxCur − fxBuy) * avg * qty   // 환율 손익
 *   → 두 항의 합 = profitKRW (교차항 없음)
 *
 * 전제: average_price 와 price 는 같은 통화(종목 원래 통화).
 * 한국장(KRW): fxBuy=fxCur=1 → 환율손익 0, 주가손익=총손익.
 */
class PnlService
{
    /**
     * 보유 종목 1건의 평가손익을 계산한다.
     *
     * @param  float  $quantity  보유 수량
     * @param  float  $averagePrice  매입 평균가(종목 원래 통화)
     * @param  float  $avgFxRate  매입 시 환율(USD 종목: USD→KRW, KR 종목: 1)
     * @param  string  $currency  'KRW' | 'USD'
     * @param  float  $currentPrice  현재가(종목 원래 통화, 선택 session)
     * @param  float  $fxNow  현재 USD→KRW 환율 (KR 종목은 1 로 넘겨도 무관)
     * @return array{
     *   marketValueKRW: float,
     *   costKRW: float,
     *   profitKRW: float,
     *   priceProfitKRW: float,
     *   fxProfitKRW: float,
     *   profitRate: float
     * }
     */
    public function evaluate(
        float $quantity,
        float $averagePrice,
        float $avgFxRate,
        string $currency,
        float $currentPrice,
        float $fxNow
    ): array {
        $isUSD = ($currency === 'USD');

        // 매입 시 환율: USD 종목은 avg_fx_rate, KR 종목은 1 (환율 손익 없음)
        $fxBuy = $isUSD ? $avgFxRate : 1.0;
        // 현재 환율: USD 종목은 장중 폴링 환율, KR 종목은 1
        $fxCur = $isUSD ? $fxNow : 1.0;

        $qty = $quantity;
        $avg = $averagePrice;

        // 평가액 (원화)
        $marketValueKRW = $currentPrice * $qty * $fxCur;
        // 원화 매입원가 (고정)
        $costKRW = $avg * $qty * $fxBuy;
        // 총 평가손익 (원화)
        $profitKRW = $marketValueKRW - $costKRW;

        // ── 손익 분리 ─────────────────────────────────────────────────
        // 주가손익: 가격 차이에 현재 환율 적용
        $priceProfitKRW = ($currentPrice - $avg) * $qty * $fxCur;
        // 환율손익: 환율 변동에 매입원가(원래 통화) 적용
        $fxProfitKRW = ($fxCur - $fxBuy) * $avg * $qty;
        // 검증: priceProfitKRW + fxProfitKRW == profitKRW (교차항 없음)

        // 수익률: 통화 무관 (환율 약분)
        $profitRate = $avg > 0.0 ? ($currentPrice - $avg) / $avg : 0.0;

        return [
            'marketValueKRW' => round($marketValueKRW, 2),
            'costKRW' => round($costKRW, 2),
            'profitKRW' => round($profitKRW, 2),
            'priceProfitKRW' => round($priceProfitKRW, 2),
            'fxProfitKRW' => round($fxProfitKRW, 2),
            'profitRate' => round($profitRate, 6),
        ];
    }

    /**
     * 다수 보유 종목의 손익을 합산해 포트폴리오 합계를 반환한다.
     *
     * @param  array<int, array{
     *   marketValueKRW: float,
     *   costKRW: float,
     *   profitKRW: float,
     *   priceProfitKRW: float,
     *   fxProfitKRW: float,
     *   profitRate: float
     * }> $evaluations evaluate() 반환값 배열
     * @return array{
     *   totalMarketValueKRW: float,
     *   totalCostKRW: float,
     *   totalProfitKRW: float,
     *   totalPriceProfitKRW: float,
     *   totalFxProfitKRW: float,
     *   totalProfitRate: float
     * }
     */
    public function summarize(array $evaluations): array
    {
        $totalMarketValue = 0.0;
        $totalCost = 0.0;
        $totalProfit = 0.0;
        $totalPriceProfit = 0.0;
        $totalFxProfit = 0.0;

        foreach ($evaluations as $e) {
            $totalMarketValue += $e['marketValueKRW'];
            $totalCost += $e['costKRW'];
            $totalProfit += $e['profitKRW'];
            $totalPriceProfit += $e['priceProfitKRW'];
            $totalFxProfit += $e['fxProfitKRW'];
        }

        $totalProfitRate = $totalCost > 0.0 ? $totalProfit / $totalCost : 0.0;

        return [
            'totalMarketValueKRW' => round($totalMarketValue, 2),
            'totalCostKRW' => round($totalCost, 2),
            'totalProfitKRW' => round($totalProfit, 2),
            'totalPriceProfitKRW' => round($totalPriceProfit, 2),
            'totalFxProfitKRW' => round($totalFxProfit, 2),
            'totalProfitRate' => round($totalProfitRate, 6),
        ];
    }
}
