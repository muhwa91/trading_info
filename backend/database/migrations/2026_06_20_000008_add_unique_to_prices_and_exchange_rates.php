<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prices: (stock_id, session) 유니크 — "최신 1건 upsert" 방침.
 * exchange_rates: (from_currency, to_currency) 유니크 — 쌍당 1건만 유지.
 *
 * 기존 중복 행이 있으면 unique 추가 전에 최신 1건만 남기고 삭제한다.
 */
class AddUniqueToPricesAndExchangeRates extends Migration
{
    public function up(): void
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        // prices: 중복 제거 후 unique 추가
        // MySQL 전용 multi-table DELETE — SQLite(:memory: 테스트)에서는 건너뜀
        if ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                "DELETE p1 FROM prices p1
                 INNER JOIN prices p2
                 ON p1.stock_id = p2.stock_id
                 AND p1.session = p2.session
                 AND p1.updated_at < p2.updated_at"
            );
        }

        Schema::table('prices', function (Blueprint $table) {
            $table->unique(['stock_id', 'session'], 'prices_stock_session_unique');
        });

        // exchange_rates: 중복 제거 후 unique 추가
        if ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                "DELETE e1 FROM exchange_rates e1
                 INNER JOIN exchange_rates e2
                 ON e1.from_currency = e2.from_currency
                 AND e1.to_currency = e2.to_currency
                 AND e1.recorded_at < e2.recorded_at"
            );
        }

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->unique(['from_currency', 'to_currency'], 'exchange_rates_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropUnique('prices_stock_session_unique');
        });

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropUnique('exchange_rates_pair_unique');
        });
    }
}
