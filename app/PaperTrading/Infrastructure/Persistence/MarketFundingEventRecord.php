<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class MarketFundingEventRecord extends Model
{
    protected $table = 'market_funding_events';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['funding_at' => 'immutable_datetime', 'rate' => 'string', 'mark_price' => 'string'];
    }
}
