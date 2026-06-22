<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePricesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * recorded_at: 가격 기준 시각 (KIS가 반환한 호가 시각)
     * updated_at:  레코드를 DB에 저장/갱신한 시각 (신선도 판단용)
     * timestamps() 대신 두 컬럼을 명시적으로 정의한다.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 4)->comment('종목 원래 통화 기준 가격');
            $table->enum('session', ['regular', 'pre', 'after'])->default('regular')->comment('정규장/프리마켓/애프터마켓');
            $table->timestamp('recorded_at')->comment('가격 기준 시각');
            $table->timestamp('updated_at')->comment('DB 저장/갱신 시각 (신선도 판단)');

            $table->index(['stock_id', 'session', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
}
