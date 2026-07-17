<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Toss\TossSymbolMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TossSymbolMapper — 심볼 정규화·시장분류·지수 skip 단위 테스트.
 *
 * 검증 대상 (설계 §2.3, Phase 1 요구사항):
 *   toTossSymbol(): 정규화 + 지수 시 null
 *   market(): KR / US / INDEX 분류
 *   isIndex() / shouldSkip(): 지수 판정
 *   toTossSymbols(): 복수 변환 + 지수 필터링
 *
 * 대표 케이스:
 *   TSLA       → TSLA  (US)
 *   005930.KS  → 005930 (KR)
 *   005935.KS  → 005935 (KR, 우선주)
 *   0167A0     → 0167A0 (KR, 신형 영숫자)
 *   0167AO     → 0167A0 (KR, 오타교정 O→0)
 *   NQ=F       → null   (INDEX skip)
 *   KOSPI200   → null   (INDEX skip)
 */
class TossSymbolMapperTest extends TestCase
{
    private TossSymbolMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new TossSymbolMapper;
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 미국 티커
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_us_ticker_tsl_a_passes_through(): void
    {
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('TSLA'));
    }

    #[Test]
    public function test_us_ticker_m_u_passes_through(): void
    {
        $this->assertSame('MU', $this->mapper->toTossSymbol('MU'));
    }

    #[Test]
    public function test_us_ticker_sox_l_passes_through(): void
    {
        $this->assertSame('SOXL', $this->mapper->toTossSymbol('SOXL'));
    }

    #[Test]
    public function test_us_ticker_tqq_q_passes_through(): void
    {
        $this->assertSame('TQQQ', $this->mapper->toTossSymbol('TQQQ'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 국내 종목 (.KS/.KQ 접미사 제거)
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_domestic_005930_k_s_removes_suffix(): void
    {
        $this->assertSame('005930', $this->mapper->toTossSymbol('005930.KS'));
    }

    #[Test]
    public function test_domestic_preferred_stock_005935_k_s_removes_suffix(): void
    {
        $this->assertSame('005935', $this->mapper->toTossSymbol('005935.KS'));
    }

    #[Test]
    public function test_domestic_kq_suffix_removes_suffix(): void
    {
        $this->assertSame('000660', $this->mapper->toTossSymbol('000660.KQ'));
    }

    #[Test]
    public function test_domestic_lowercase_suffix_removes_suffix(): void
    {
        $this->assertSame('005930', $this->mapper->toTossSymbol('005930.ks'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 신형 영숫자 코드
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_alphanumeric_code_0167_a0_passes_through(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167A0'));
    }

    #[Test]
    public function test_alphanumeric_code_0167_a0_with_kq_suffix_removes_suffix(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167A0.KQ'));
    }

    #[Test]
    public function test_typo_correction_0167_a_o_converts_o_to_zero(): void
    {
        // 0167AO (영문 O) → 0167A0 (숫자 0) 오타교정
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167AO'));
    }

    #[Test]
    public function test_typo_correction_0167_a_o_with_ks_suffix(): void
    {
        $this->assertSame('0167A0', $this->mapper->toTossSymbol('0167AO.KS'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbol — 지수 skip (null 반환)
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_index_nq_f_returns_null(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('NQ=F'));
    }

    #[Test]
    public function test_index_k_s200_returns_null(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('^KS200'));
    }

    #[Test]
    public function test_index_usdkr_w_returns_null(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('USDKRW=X'));
    }

    #[Test]
    public function test_index_kosp_i_nigh_t_returns_null(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('KOSPI_NIGHT'));
    }

    #[Test]
    public function test_index_kosp_i200_returns_null(): void
    {
        $this->assertNull($this->mapper->toTossSymbol('KOSPI200'));
    }

    // ──────────────────────────────────────────────────────────────────
    // market() — 시장 분류
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_market_us_ticker_returns_us(): void
    {
        $this->assertSame('US', $this->mapper->market('TSLA'));
        $this->assertSame('US', $this->mapper->market('MU'));
        $this->assertSame('US', $this->mapper->market('SOXL'));
    }

    #[Test]
    public function test_market_domestic_k_s_returns_kr(): void
    {
        $this->assertSame('KR', $this->mapper->market('005930.KS'));
        $this->assertSame('KR', $this->mapper->market('005935.KS'));
    }

    #[Test]
    public function test_market_alphanumeric_code_returns_kr(): void
    {
        $this->assertSame('KR', $this->mapper->market('0167A0'));
    }

    #[Test]
    public function test_market_pure_numeric_code_returns_kr(): void
    {
        $this->assertSame('KR', $this->mapper->market('005930'));
        $this->assertSame('KR', $this->mapper->market('000660'));
    }

    #[Test]
    public function test_market_index_symbols_returns_index(): void
    {
        $this->assertSame('INDEX', $this->mapper->market('NQ=F'));
        $this->assertSame('INDEX', $this->mapper->market('KOSPI200'));
        $this->assertSame('INDEX', $this->mapper->market('KOSPI_NIGHT'));
    }

    // ──────────────────────────────────────────────────────────────────
    // isIndex() / shouldSkip()
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_is_index_index_symbols_returns_true(): void
    {
        $this->assertTrue($this->mapper->isIndex('NQ=F'));
        $this->assertTrue($this->mapper->isIndex('^KS200'));
        $this->assertTrue($this->mapper->isIndex('USDKRW=X'));
        $this->assertTrue($this->mapper->isIndex('KOSPI_NIGHT'));
        $this->assertTrue($this->mapper->isIndex('KOSPI200'));
    }

    #[Test]
    public function test_is_index_normal_symbols_returns_false(): void
    {
        $this->assertFalse($this->mapper->isIndex('TSLA'));
        $this->assertFalse($this->mapper->isIndex('005930.KS'));
        $this->assertFalse($this->mapper->isIndex('0167A0'));
    }

    #[Test]
    public function test_should_skip_alias_of_is_index(): void
    {
        $this->assertTrue($this->mapper->shouldSkip('KOSPI200'));
        $this->assertFalse($this->mapper->shouldSkip('TSLA'));
    }

    // ──────────────────────────────────────────────────────────────────
    // toTossSymbols() — 복수 변환 + 지수 필터
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_to_toss_symbols_filters_index_and_normalizes_rest(): void
    {
        $input = [
            'TSLA',        // US → TSLA
            '005930.KS',   // KR → 005930
            '0167AO',      // 오타교정 → 0167A0
            'NQ=F',        // INDEX → 제거
            'KOSPI200',    // INDEX → 제거
        ];

        $result = $this->mapper->toTossSymbols($input);

        $this->assertSame(['TSLA', '005930', '0167A0'], $result);
    }

    #[Test]
    public function test_to_toss_symbols_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->mapper->toTossSymbols([]));
    }

    #[Test]
    public function test_to_toss_symbols_all_index_skipped_returns_empty(): void
    {
        $result = $this->mapper->toTossSymbols(['NQ=F', 'KOSPI200', '^KS200']);
        $this->assertSame([], $result);
    }

    // ──────────────────────────────────────────────────────────────────
    // 엣지 케이스
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function test_whitespace_trimmed(): void
    {
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('  TSLA  '));
        $this->assertSame('005930', $this->mapper->toTossSymbol('  005930.KS  '));
    }

    #[Test]
    public function test_lowercase_us_ticker_uppercased_to_us(): void
    {
        // 소문자 미국 티커 → 대문자화 (US 분류 유지)
        $this->assertSame('TSLA', $this->mapper->toTossSymbol('tsla'));
        $this->assertSame('US', $this->mapper->market('tsla'));
    }
}
