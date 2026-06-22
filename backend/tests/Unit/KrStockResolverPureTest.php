<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\KrStockResolver;
use PHPUnit\Framework\TestCase;

/**
 * KrStockResolver — DB 없이 순수 로직 단위 테스트.
 *
 * 검증 항목:
 *   1. normalize(): 접미사 제거 (.KS/.KQ 대소문자 포함)
 *   2. normalize(): 접미사 없는 경우 그대로 반환
 *   3. normalize(): 공백 트림
 *   4. normalize(): 특수 코드 (0167A0.KQ)
 *   5. ETF 판정: KODEX/TIGER/SOL/PLUS/ACE/KBSTAR 포함 시 'etf'
 *   6. ETF 판정: 일반 종목명 → 'stock'
 *   7. ETF 판정: 대소문자 혼합도 인식
 *
 * PHP 7.4 환경: Reflection 으로 private 메서드 접근.
 */
class KrStockResolverPureTest extends TestCase
{
    /** @var KrStockResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KrStockResolver();
    }

    // ──────────────────────────────────────────────────────────────
    // 1. normalize: .KS 접미사 제거
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testNormalizeRemovesKsSuffix(): void
    {
        $this->assertSame('005930', $this->resolver->normalize('005930.KS'));
    }

    /** @test */
    public function testNormalizeRemovesKqSuffix(): void
    {
        $this->assertSame('0167A0', $this->resolver->normalize('0167A0.KQ'));
    }

    /** @test */
    public function testNormalizeCaseInsensitiveSuffix(): void
    {
        // 소문자 .ks 도 제거
        $this->assertSame('005930', $this->resolver->normalize('005930.ks'));
        // 소문자 .kq 도 제거
        $this->assertSame('000660', $this->resolver->normalize('000660.kq'));
    }

    /** @test */
    public function testNormalizeUppercasesResult(): void
    {
        // 알파벳 포함 코드: 소문자 입력이어도 대문자로
        $this->assertSame('0167A0', $this->resolver->normalize('0167a0.KQ'));
    }

    /** @test */
    public function testNormalizeNoSuffixPassThrough(): void
    {
        // 접미사 없는 6자리 코드 → 그대로(대문자화)
        $this->assertSame('005930', $this->resolver->normalize('005930'));
    }

    /** @test */
    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('005930', $this->resolver->normalize('  005930.KS  '));
    }

    /** @test */
    public function testNormalizeSpecialAlphanumericCode(): void
    {
        // 0167AO (오 아님 영문 O) 접미사 포함
        $this->assertSame('0167AO', $this->resolver->normalize('0167AO.KS'));
    }

    // ──────────────────────────────────────────────────────────────
    // ETF 판정 (private resolveType → Reflection 으로 테스트)
    // ──────────────────────────────────────────────────────────────

    /**
     * private resolveType(string $name): string 을 Reflection 으로 호출.
     */
    private function resolveType(string $name): string
    {
        $ref    = new \ReflectionMethod(KrStockResolver::class, 'resolveType');
        $ref->setAccessible(true);
        return $ref->invoke($this->resolver, $name);
    }

    /** @test */
    public function testEtfKeywordKodex(): void
    {
        $this->assertSame('etf', $this->resolveType('KODEX 200'));
    }

    /** @test */
    public function testEtfKeywordTiger(): void
    {
        $this->assertSame('etf', $this->resolveType('TIGER 미국나스닥100'));
    }

    /** @test */
    public function testEtfKeywordSol(): void
    {
        $this->assertSame('etf', $this->resolveType('SOL AI반도체TOP2플러스'));
    }

    /** @test */
    public function testEtfKeywordPlus(): void
    {
        $this->assertSame('etf', $this->resolveType('PLUS 고배당주'));
    }

    /** @test */
    public function testEtfKeywordAce(): void
    {
        $this->assertSame('etf', $this->resolveType('ACE 미국빅테크TOP7Plus'));
    }

    /** @test */
    public function testEtfKeywordKbstar(): void
    {
        $this->assertSame('etf', $this->resolveType('KBSTAR 200'));
    }

    /** @test */
    public function testEtfKeywordArirang(): void
    {
        $this->assertSame('etf', $this->resolveType('ARIRANG 200'));
    }

    /** @test */
    public function testEtfKeywordHanaro(): void
    {
        $this->assertSame('etf', $this->resolveType('HANARO 200TR'));
    }

    /** @test */
    public function testEtfKeywordMixedCase(): void
    {
        // 소문자 tiger 도 ETF 로 인식
        $this->assertSame('etf', $this->resolveType('tiger 미국나스닥100'));
        // 혼합
        $this->assertSame('etf', $this->resolveType('Kodex 레버리지'));
    }

    /** @test */
    public function testNonEtfReturnsStock(): void
    {
        $this->assertSame('stock', $this->resolveType('삼성전자'));
        $this->assertSame('stock', $this->resolveType('SK하이닉스'));
        $this->assertSame('stock', $this->resolveType('NAVER'));
        $this->assertSame('stock', $this->resolveType('카카오'));
    }

    /** @test */
    public function testEtfKeywordEtfLiteralInName(): void
    {
        // 이름 자체에 'ETF' 문자 포함 시
        $this->assertSame('etf', $this->resolveType('국내ETF상품'));
    }

}
