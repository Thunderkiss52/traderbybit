<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class MarketCandleConflictRecord extends Model
{
    protected $table = 'market_candle_conflicts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'open_time' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'stored_values' => 'array',
            'incoming_values' => 'array',
            'occurrence_count' => 'integer',
        ];
    }
}
