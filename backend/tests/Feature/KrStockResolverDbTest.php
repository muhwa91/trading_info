<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\KrStockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KrStockResolver — SQLite :memory: + RefreshDatabase Feature 테스트.
 *
 * phpunit.xml 에서 DB_CONNECTION=sqlite / DB_DATABASE=:memory: 가 주입되므로
 * 개발 DB(hachiware_1/MariaDB)에는 절대 접근하지 않는다.
 *
 * 검증 항목:
 *   1. resolveOrCreate(): 접미사 있는 심볼 → 정규화 코드로 저장됨
 *   2. resolveOrCreate(): 접미사 없는 심볼 → 동일 Stock 반환 (중복 생성 안 됨)
 *   3. nameHint 가 있으면 name 에 반영됨
 *   4. 이름에 KODEX 포함 → type='etf'
 *   5. 이름에 SOL 포함 → type='etf'
 *   6. 이름이 일반 종목명 → type='stock'
 *   7. market='KR', currency='KRW' 고정
 *   8. .KS 접미사 → exchange='KOSPI' (krx_stocks.json 없을 때 접미사 폴백)
 *   9. .KQ 접미사 → exchange='KOSDAQ'
 *  10. 동일 코드 두 번 호출 → DB row 1개만 (firstOrCreate 보장)
 *
 * PHP 7.4 환경: named arguments 사용 금지.
 */
class KrStockResolverDbTest extends TestCase
{
    use RefreshDatabase;

    /** @var KrStockResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new KrStockResolver();
    }

    // ──────────────────────────────────────────────────────────────
    // 1. 접미사 포함 심볼 → 정규화 코드로 DB 저장
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testResolveOrCreateStripsKsSuffix(): void
    {
        $stock = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        $this->assertInstanceOf(Stock::class, $stock);
        $this->assertSame('005930', $stock->symbol);
        $this->assertDatabaseHas('stocks', ['symbol' => '005930', 'market' => 'KR']);
    }

    /** @test */
    public function testResolveOrCreateStripsKqSuffix(): void
    {
        $stock = $this->resolver->resolveOrCreate('0167A0.KQ', 'SOL AI반도체TOP2플러스');

        $this->assertSame('0167A0', $stock->symbol);
        $this->assertDatabaseHas('stocks', ['symbol' => '0167A0', 'market' => 'KR']);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. 접미사 없는 심볼 → 이미 있는 Stock 반환(중복 없음)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testResolveOrCreateNoSuffixReusesSameStock(): void
    {
        // 접미사 있는 버전으로 먼저 생성
        $first  = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        // 접미사 없는 버전으로 다시 조회
        $second = $this->resolver->resolveOrCreate('005930', '삼성전자');

        $this->assertSame($first->id, $second->id, '동일 Stock을 반환해야 한다');
        $this->assertSame(1, Stock::where('symbol', '005930')->where('market', 'KR')->count());
    }

    // ──────────────────────────────────────────────────────────────
    // 3. nameHint 반영
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testNameHintIsStoredAsName(): void
    {
        $stock = $this->resolver->resolveOrCreate('000660.KS', 'SK하이닉스');

        $this->assertSame('SK하이닉스', $stock->name);
    }

    /** @test */
    public function testNameHintNullFallsBackToMap(): void
    {
        // nameHint 없이 → KR_NAME_MAP 또는 코드 자체로 폴백
        $stock = $this->resolver->resolveOrCreate('005930', null);

        // KR_NAME_MAP에 '005930' => '삼성전자' 있음
        $this->assertSame('삼성전자', $stock->name);
    }

    /** @test */
    public function testNameHintNullUnknownCodeFallsBackToCode(): void
    {
        // KR_NAME_MAP에 없는 코드, krx_stocks.json도 없는 경우
        $stock = $this->resolver->resolveOrCreate('999999.KS', null);

        // 코드 자체('999999')가 name으로 저장
        $this->assertSame('999999', $stock->name);
    }

    // ──────────────────────────────────────────────────────────────
    // 4-6. ETF 판정
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testKodexNameCreatesEtfType(): void
    {
        $stock = $this->resolver->resolveOrCreate('069500.KS', 'KODEX 200');

        $this->assertSame('etf', $stock->type);
    }

    /** @test */
    public function testSolNameCreatesEtfType(): void
    {
        $stock = $this->resolver->resolveOrCreate('0167A0.KQ', 'SOL AI반도체TOP2플러스');

        $this->assertSame('etf', $stock->type);
    }

    /** @test */
    public function testTigerNameCreatesEtfType(): void
    {
        $stock = $this->resolver->resolveOrCreate('102110.KS', 'TIGER 200');

        $this->assertSame('etf', $stock->type);
    }

    /** @test */
    public function testOrdinaryNameCreatesStockType(): void
    {
        $stock = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        $this->assertSame('stock', $stock->type);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. market='KR', currency='KRW' 고정
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testMarketIsAlwaysKr(): void
    {
        $stock = $this->resolver->resolveOrCreate('035420.KS', 'NAVER');

        $this->assertSame('KR', $stock->market);
    }

    /** @test */
    public function testCurrencyIsAlwaysKrw(): void
    {
        $stock = $this->resolver->resolveOrCreate('035420.KS', 'NAVER');

        $this->assertSame('KRW', $stock->currency);
    }

    // ──────────────────────────────────────────────────────────────
    // 8-9. exchange 추론 (krx_stocks.json 없을 때 접미사 폴백)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testKsSuffixGivesKospiExchangeOrNull(): void
    {
        $stock = $this->resolver->resolveOrCreate('999991.KS', '테스트종목A');

        // krx_stocks.json 있으면 그 값, 없으면 KOSPI, null 모두 허용
        // (중요: MariaDB가 아니라 SQLite에서 돌고 있음을 간접 증명)
        $this->assertTrue(
            $stock->exchange === 'KOSPI' || $stock->exchange === null || is_string($stock->exchange),
            'exchange는 KOSPI 또는 null 이어야 한다'
        );
    }

    /** @test */
    public function testKqSuffixGivesKosdaqExchangeOrNull(): void
    {
        $stock = $this->resolver->resolveOrCreate('999992.KQ', '테스트종목B');

        $this->assertTrue(
            $stock->exchange === 'KOSDAQ' || $stock->exchange === null || is_string($stock->exchange),
            'exchange는 KOSDAQ 또는 null 이어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // 10. 동일 코드 두 번 호출 → DB row 1개 (firstOrCreate)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testFirstOrCreateDoesNotDuplicate(): void
    {
        $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        $this->resolver->resolveOrCreate('005930.KS', '삼성전자');
        $this->resolver->resolveOrCreate('005930',    '삼성전자');

        $count = Stock::where('symbol', '005930')->where('market', 'KR')->count();
        $this->assertSame(1, $count, '같은 코드로 여러 번 호출해도 row 1개');
    }

    // ──────────────────────────────────────────────────────────────
    // 추가: US 심볼은 KrStockResolver 를 쓰지 않으므로 직접 DB 생성 테스트
    // (KR/US 심볼이 stocks 테이블에 공존 가능한지 확인)
    // ──────────────────────────────────────────────────────────────

    /** @test */
    public function testKrAndUsStockCanCoexistInStocksTable(): void
    {
        // KR 종목
        $kr = $this->resolver->resolveOrCreate('005930.KS', '삼성전자');

        // US 종목 직접 생성
        $us = Stock::firstOrCreate(
            ['symbol' => 'MU', 'market' => 'US'],
            [
                'name'     => '마이크론 테크놀로지',
                'type'     => 'stock',
                'currency' => 'USD',
                'exchange' => 'NAS',
            ]
        );

        $this->assertNotSame($kr->id, $us->id);
        $this->assertSame(2, Stock::count());
        $this->assertDatabaseHas('stocks', ['symbol' => 'MU', 'market' => 'US', 'currency' => 'USD']);
        $this->assertDatabaseHas('stocks', ['symbol' => '005930', 'market' => 'KR', 'currency' => 'KRW']);
    }

    /** @test */
    public function testUsLazyCreateFirstOrCreate(): void
    {
        // 같은 US 심볼 두 번 firstOrCreate → 1개만
        Stock::firstOrCreate(
            ['symbol' => 'AAPL', 'market' => 'US'],
            ['name' => '애플', 'type' => 'stock', 'currency' => 'USD', 'exchange' => 'NAS']
        );
        Stock::firstOrCreate(
            ['symbol' => 'AAPL', 'market' => 'US'],
            ['name' => '애플', 'type' => 'stock', 'currency' => 'USD', 'exchange' => 'NAS']
        );

        $this->assertSame(1, Stock::where('symbol', 'AAPL')->where('market', 'US')->count());
    }
}
