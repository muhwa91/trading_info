<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PnlService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PnlService 단위 테스트.
 *
 * 검증 항목:
 *   1. 분해 검증: priceProfitKRW + fxProfitKRW === profitKRW (KR/US 모두)
 *   2. 한국장 환율 손익 = 0 (fxBuy = fxCur = 1)
 *   3. USD 종목 환율 상승 케이스 → 환율손익 양수
 *   4. USD 종목 환율 하락 케이스 → 환율손익 음수
 *   5. profitRate = (price - avg) / avg
 *   6. 수량·가격 0 경계
 *   7. summarize() 합산 정확성
 *   8. 구체 수치 검증 KR/US
 *
 * PHP 7.4 환경: named arguments 사용 금지.
 */
class PnlServiceTest extends TestCase
{
    /** @var PnlService */
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PnlService;
    }

    // ──────────────────────────────────────────────────────────────
    // 1. 분해 검증: priceProfitKRW + fxProfitKRW === profitKRW
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_decomposition_holds_for_kr_stock(): void
    {
        $result = $this->service->evaluate(
            10.0,       // quantity
            75000.0,    // averagePrice
            1.0,        // avgFxRate
            'KRW',      // currency
            78000.0,    // currentPrice
            1385.0      // fxNow (KR 종목엔 무시됨)
        );

        $this->assertEqualsWithDelta(
            $result['profitKRW'],
            $result['priceProfitKRW'] + $result['fxProfitKRW'],
            0.01,
            'KR: priceProfitKRW + fxProfitKRW must equal profitKRW'
        );
    }

    #[Test]
    public function test_decomposition_holds_for_us_stock(): void
    {
        $result = $this->service->evaluate(
            5.0,        // quantity
            190.0,      // averagePrice
            1300.0,     // avgFxRate
            'USD',      // currency
            210.0,      // currentPrice
            1380.0      // fxNow
        );

        $this->assertEqualsWithDelta(
            $result['profitKRW'],
            $result['priceProfitKRW'] + $result['fxProfitKRW'],
            0.02,
            'US: priceProfitKRW + fxProfitKRW must equal profitKRW'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 2. 한국장 환율손익 = 0
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_kr_stock_has_zero_fx_profit(): void
    {
        $result = $this->service->evaluate(
            20.0,       // quantity
            100000.0,   // averagePrice
            1.0,        // avgFxRate
            'KRW',      // currency
            105000.0,   // currentPrice
            1400.0      // fxNow
        );

        $this->assertSame(0.0, $result['fxProfitKRW'], '한국장 환율손익은 0이어야 한다.');
        $this->assertEqualsWithDelta(
            $result['profitKRW'],
            $result['priceProfitKRW'],
            0.01,
            '한국장은 총손익 = 주가손익'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 3. USD 종목 환율 상승 → 환율손익 양수
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_usd_stock_fx_rise_gives_positive_fx_profit(): void
    {
        // 매입환율 1300, 현재환율 1400, 주가 변동 없음
        // fxProfitKRW = (fxCur - fxBuy) * avg * qty = (1400-1300)*100*10 = 100,000
        $result = $this->service->evaluate(
            10.0,   // quantity
            100.0,  // averagePrice
            1300.0, // avgFxRate
            'USD',  // currency
            100.0,  // currentPrice (주가 변동 없음)
            1400.0  // fxNow
        );

        $this->assertSame(0.0, $result['priceProfitKRW'], '주가 변화 없으면 주가손익 0');
        $this->assertGreaterThan(0.0, $result['fxProfitKRW'], '환율 상승 시 환율손익 양수');
        // (1400-1300) * 100 * 10 = 100,000
        $this->assertEqualsWithDelta(100_000.0, $result['fxProfitKRW'], 0.01);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. USD 종목 환율 하락 → 환율손익 음수
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_usd_stock_fx_fall_gives_negative_fx_profit(): void
    {
        // 매입환율 1400, 현재환율 1300, 주가 변동 없음
        // fxProfitKRW = (fxCur - fxBuy) * avg * qty = (1300-1400)*100*10 = -100,000
        $result = $this->service->evaluate(
            10.0,   // quantity
            100.0,  // averagePrice
            1400.0, // avgFxRate
            'USD',  // currency
            100.0,  // currentPrice
            1300.0  // fxNow
        );

        $this->assertSame(0.0, $result['priceProfitKRW'], '주가 변화 없으면 주가손익 0');
        $this->assertLessThan(0.0, $result['fxProfitKRW'], '환율 하락 시 환율손익 음수');
        // (1300-1400) * 100 * 10 = -100,000
        $this->assertEqualsWithDelta(-100_000.0, $result['fxProfitKRW'], 0.01);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. profitRate 검증
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_profit_rate_calculation(): void
    {
        // avg=100, price=110 → profitRate = 0.1
        $result = $this->service->evaluate(
            1.0,    // quantity
            100.0,  // averagePrice
            1.0,    // avgFxRate
            'KRW',  // currency
            110.0,  // currentPrice
            1.0     // fxNow
        );

        $this->assertEqualsWithDelta(0.1, $result['profitRate'], 0.000001);
    }

    #[Test]
    public function test_profit_rate_is_currency_agnostic_for_usd(): void
    {
        // avg=$200, price=$220 → profitRate = 0.1 (환율과 무관)
        $result = $this->service->evaluate(
            1.0,    // quantity
            200.0,  // averagePrice
            1300.0, // avgFxRate
            'USD',  // currency
            220.0,  // currentPrice
            1400.0  // fxNow
        );

        $this->assertEqualsWithDelta(0.1, $result['profitRate'], 0.000001, '수익률은 통화 무관');
    }

    // ──────────────────────────────────────────────────────────────
    // 6. 경계: avg=0 → profitRate=0 (division by zero 방지)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_zero_avg_price_returns_zero_profit_rate(): void
    {
        $result = $this->service->evaluate(
            1.0,    // quantity
            0.0,    // averagePrice
            1.0,    // avgFxRate
            'KRW',  // currency
            100.0,  // currentPrice
            1.0     // fxNow
        );

        $this->assertSame(0.0, $result['profitRate'], 'avg=0 시 profitRate=0 (division by zero 방지)');
    }

    // ──────────────────────────────────────────────────────────────
    // 7. summarize() 합산
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_summarize_aggregates_correctly(): void
    {
        $evalKr = $this->service->evaluate(10.0, 70000.0, 1.0, 'KRW', 75000.0, 1380.0);
        $evalUs = $this->service->evaluate(5.0, 200.0, 1300.0, 'USD', 210.0, 1380.0);

        $summary = $this->service->summarize([$evalKr, $evalUs]);

        $this->assertEqualsWithDelta(
            $evalKr['marketValueKRW'] + $evalUs['marketValueKRW'],
            $summary['totalMarketValueKRW'],
            0.02
        );

        $this->assertEqualsWithDelta(
            $evalKr['profitKRW'] + $evalUs['profitKRW'],
            $summary['totalProfitKRW'],
            0.02
        );

        // 분해 검증 합산레벨
        $this->assertEqualsWithDelta(
            $summary['totalProfitKRW'],
            $summary['totalPriceProfitKRW'] + $summary['totalFxProfitKRW'],
            0.02,
            '합산 분해 검증: totalPriceProfit + totalFxProfit = totalProfit'
        );
    }

    #[Test]
    public function test_summarize_empty_returns_zeros(): void
    {
        $summary = $this->service->summarize([]);

        $this->assertSame(0.0, $summary['totalMarketValueKRW']);
        $this->assertSame(0.0, $summary['totalProfitKRW']);
        $this->assertSame(0.0, $summary['totalProfitRate']);
    }

    // ──────────────────────────────────────────────────────────────
    // 8. 구체 수치 검증 (KR 종목)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_concrete_values_kr_stock(): void
    {
        // 삼성전자 10주, 평단 75,000원, 현재가 78,000원
        $result = $this->service->evaluate(10.0, 75000.0, 1.0, 'KRW', 78000.0, 1.0);

        $this->assertEqualsWithDelta(780000.0, $result['marketValueKRW'], 0.01);
        $this->assertEqualsWithDelta(750000.0, $result['costKRW'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $result['profitKRW'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $result['priceProfitKRW'], 0.01);
        $this->assertSame(0.0, $result['fxProfitKRW']);
        $this->assertEqualsWithDelta(0.04, $result['profitRate'], 0.000001);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. 구체 수치 검증 (USD 종목)
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_concrete_values_usd_stock(): void
    {
        // AAPL 5주, 평단 $200, 현재가 $210, 매입환율 1300, 현재환율 1380
        $result = $this->service->evaluate(5.0, 200.0, 1300.0, 'USD', 210.0, 1380.0);

        // marketValueKRW = 210 * 5 * 1380 = 1,449,000
        $this->assertEqualsWithDelta(1_449_000.0, $result['marketValueKRW'], 0.01);
        // costKRW = 200 * 5 * 1300 = 1,300,000
        $this->assertEqualsWithDelta(1_300_000.0, $result['costKRW'], 0.01);
        // profitKRW = 149,000
        $this->assertEqualsWithDelta(149_000.0, $result['profitKRW'], 0.01);

        // priceProfitKRW = (210-200)*5*1380 = 69,000
        $this->assertEqualsWithDelta(69_000.0, $result['priceProfitKRW'], 0.01);
        // fxProfitKRW = (1380-1300)*200*5 = 80,000
        $this->assertEqualsWithDelta(80_000.0, $result['fxProfitKRW'], 0.01);

        // 분해: 69,000 + 80,000 = 149,000
        $this->assertEqualsWithDelta(
            $result['profitKRW'],
            $result['priceProfitKRW'] + $result['fxProfitKRW'],
            0.02
        );

        // profitRate = (210-200)/200 = 0.05
        $this->assertEqualsWithDelta(0.05, $result['profitRate'], 0.000001);
    }
}
