<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    protected $fillable = [
        'symbol',
        'name',
        'type',
        'market',
        'exchange',
        'currency',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    public function watchlistEntries(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }
}
