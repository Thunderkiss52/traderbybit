<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class OpenInterestEventRecord extends Model
{
    protected $table = 'market_open_interest_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'open_interest' => 'string'];
    }
}
