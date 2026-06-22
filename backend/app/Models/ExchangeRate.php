<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    /**
     * exchange_rates 테이블은 recorded_at 만 사용(updated_at · created_at 없음).
     */
    public $timestamps = false;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'recorded_at',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];
}
