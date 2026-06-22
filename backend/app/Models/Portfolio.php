<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Portfolio extends Model
{
    /**
     * 스펙 §04: 테이블명이 'portfolio'(단수) — 복수형 자동변환 방지.
     */
    protected $table = 'portfolio';

    protected $fillable = [
        'account_id',
        'stock_id',
        'quantity',
        'average_price',
        'avg_fx_rate',
        'source',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'average_price' => 'decimal:4',
        'avg_fx_rate' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
