<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prices 테이블에 등락 컬럼 추가.
 * STEP 2에서 QuoteProvider 가 반환하는 change_amount / change_percent 를 저장.
 */
class AddChangeColumnsToPrices extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->decimal('change_amount', 18, 4)->default(0)->after('price')
                ->comment('전일 대비 등락폭 (종목 원래 통화)');
            $table->decimal('change_percent', 10, 4)->default(0)->after('change_amount')
                ->comment('전일 대비 등락률 (%)');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn(['change_amount', 'change_percent']);
        });
    }
}
