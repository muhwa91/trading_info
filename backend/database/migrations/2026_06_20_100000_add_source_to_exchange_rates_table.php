<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceToExchangeRatesTable extends Migration
{
    /**
     * exchange_rates 테이블에 source 컬럼 추가.
     * 환율 출처를 기록해 KIS 고시환율 / Yahoo 폴백 구분에 활용.
     */
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 32)
                ->default('unknown')
                ->after('recorded_at')
                ->comment('환율 출처: KIS_HHDFS76200200 | Yahoo_USDKRW | db_fallback 등');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
}
