<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class MarketContextRecord extends Model
{
    protected $table = 'market_context_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime', 'bid' => 'string', 'ask' => 'string', 'turnover_24h' => 'string', 'tick_size' => 'string',
            'funding_rate' => 'string', 'open_interest' => 'string', 'open_interest_change' => 'string',
            'qty_step' => 'string', 'min_order_qty' => 'string', 'max_order_qty' => 'string', 'min_notional_value' => 'string',
        ];
    }
}
