<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExchangeRatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * 현재는 USD→KRW만 사용하지만 string 타입으로 확장 여지를 둔다.
     * 최신 환율 = recorded_at 기준 가장 최근 행.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 8)->comment('예: USD');
            $table->string('to_currency', 8)->comment('예: KRW');
            $table->decimal('rate', 12, 4)->comment('1 from_currency = ? to_currency');
            $table->timestamp('recorded_at')->comment('환율 기준 시각 (스냅샷)');

            $table->index(['from_currency', 'to_currency', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
}
