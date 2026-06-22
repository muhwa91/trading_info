<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDividendsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * ETF 분배금 포함. amount는 세전 금액(종목 원래 통화).
     * tax는 미장 원천징수 15% 등 실제 공제액 직접 입력.
     */
    public function up(): void
    {
        Schema::create('dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 4)->comment('배당금 세전 (종목 원래 통화)');
            $table->decimal('tax', 18, 4)->default(0)->comment('원천징수세액');
            $table->date('paid_at')->comment('배당 지급일');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividends');
    }
}
