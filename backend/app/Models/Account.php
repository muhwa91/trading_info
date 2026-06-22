<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /**
     * 주의: KIS 앱키·시크릿·계좌번호는 .env 전용. 이 모델에는 없다.
     */
    protected $fillable = [
        'user_id',
        'name',
        'account_type',
        'broker',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }
}
