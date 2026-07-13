<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\FxService;
use App\Services\Toss\TossFxProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FxService::getUsdKrw() 의 prev_close(전일 종가) 필드 검증.
 *
 * SQLite :memory: — 개발 DB(hachiware_1)에는 접근하지 않는다.
 * exchange_rates 테이블만 직접 생성(RefreshDatabase 미사용):
 *   전체 마이그레이션 중 ->change() 사용분이 doctrine/dbal 을 요구하는데,
 *   본 로컬 환경(PHP 7.4)에는 미설치라 마이그레이션 전량 실행이 불가하기 때문.
 * Yahoo HTTP 는 호출하지 않는다: 전일 종가 캐시(yahoo_fx_prev_close_usdkrw)를
 * 미리 시드하면 fetchPrevClose() 가 캐시 히트로 즉시 반환하기 때문이다.
 *
 * 검증 항목:
 *   1. DB 신선값 경로: 캐시된 prev_close 가 반환 배열에 실림
 *   2. 토스 취득 경로: prev_close 가 실려 반환됨
 *   3. 캐시가 비양수(0/음수)면 prev_close = null (>0 가드, graceful)
 *
 * PHP 7.4 환경: named arguments 사용 금지.
 */
class FxServicePrevCloseTest extends TestCase
{
    private const PREV_CLOSE_KEY = 'yahoo_fx_prev_close_usdkrw';

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();

        // 마이그레이션 전량 대신 필요한 테이블만 직접 생성 (doctrine/dbal 회피).
        Schema::create('exchange_rates', function ($table): void {
            $table->id();
            $table->string('from_currency', 8);
            $table->string('to_currency', 8);
            $table->decimal('rate', 12, 4);
            $table->timestamp('recorded_at')->nullable();
            $table->string('source')->nullable();
            $table->unique(['from_currency', 'to_currency']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('exchange_rates');
        parent::tearDown();
    }

    /** @test */
    public function testGetUsdKrw_FreshDbRow_IncludesCachedPrevClose(): void
    {
        Cache::put(self::PREV_CLOSE_KEY, 1505.91, 60);

        // 신선한 DB 값 → 외부 호출(토스) 없이 조기 반환 경로
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency'   => 'KRW',
            'rate'          => 1496.3,
            'recorded_at'   => Carbon::now(),
        ]);

        $fxProvider = $this->createMock(TossFxProvider::class);
        $fxProvider->expects($this->never())->method('fetchUsdKrw');

        $service = new FxService($fxProvider);
        $result  = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('prev_close', $result);
        $this->assertSame(1505.91, $result['prev_close']);
    }

    /** @test */
    public function testGetUsdKrw_TossFetchedPath_IncludesPrevClose(): void
    {
        Cache::put(self::PREV_CLOSE_KEY, 1505.91, 60);

        // DB 값 없음 → 토스 취득 경로
        $fxProvider = $this->createMock(TossFxProvider::class);
        $fxProvider->method('fetchUsdKrw')->willReturn([
            'rate'        => 1496.3,
            'recorded_at' => Carbon::now()->toDateTimeString(),
            'source'      => 'Toss_ExchangeRate',
        ]);

        $service = new FxService($fxProvider);
        $result  = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertSame(1496.3, $result['rate']);
        $this->assertSame(1505.91, $result['prev_close']);
    }

    /** @test */
    public function testGetUsdKrw_NonPositiveCachedPrevClose_ReturnsNull(): void
    {
        // 캐시에 비양수(파싱 실패/이상값) → prev_close 는 null 로 graceful 처리
        Cache::put(self::PREV_CLOSE_KEY, 0, 60);

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency'   => 'KRW',
            'rate'          => 1496.3,
            'recorded_at'   => Carbon::now(),
        ]);

        $service = new FxService($this->createMock(TossFxProvider::class));
        $result  = $service->getUsdKrw();

        $this->assertNotNull($result);
        $this->assertNull($result['prev_close']);
    }
}
