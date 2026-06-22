<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Watchlist extends Model
{
    /**
     * watchlist 테이블은 updated_at 이 없다(created_at 만 있음).
     * 테이블명 단수형 — Eloquent 복수형 자동변환 방지.
     */
    protected $table = 'watchlist';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'stock_id',
        'sort_order',
        'created_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
