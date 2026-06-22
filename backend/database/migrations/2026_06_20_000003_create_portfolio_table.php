<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePortfolioTable extends Migration
{
    /**
     * Run the migrations.
     *
     * 원화 매입원가 = average_price × quantity × avg_fx_rate (고정)
     * → 주가손익 · 환율손익 분리 계산에 사용.
     * KR 종목은 avg_fx_rate = 1.
     */
    public function up(): void
    {
        Schema::create('portfolio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 6)->comment('보유 수량');
            $table->decimal('average_price', 18, 4)->comment('평균 매입가 (종목 원래 통화)');
            $table->decimal('avg_fx_rate', 12, 4)->default(1)->comment('매입 시 환율 USD→KRW. 국내 종목은 1');
            $table->enum('source', ['manual', 'synced'])->default('manual')->comment('manual=직접입력 / synced=KIS 잔고 동기화');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio');
    }
}
