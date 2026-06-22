<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dividend extends Model
{
    protected $fillable = [
        'account_id',
        'stock_id',
        'amount',
        'tax',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'tax' => 'decimal:4',
        'paid_at' => 'date',
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
