<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStocksTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('name');
            $table->enum('type', ['stock', 'etf'])->default('stock');
            $table->enum('market', ['KR', 'US']);
            $table->string('exchange')->nullable()->comment('KOSPI/KOSDAQ/NAS/NYS — KIS 해외호출 시 사용');
            $table->enum('currency', ['KRW', 'USD']);
            $table->timestamps();

            $table->unique(['symbol', 'market']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
}
