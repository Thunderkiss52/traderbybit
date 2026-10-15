<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VirtualPositionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'virtual_positions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime', 'maximum_holding_until' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'last_processed_event_at' => 'immutable_datetime',
            'ambiguous_execution' => 'boolean', 'funding_complete' => 'boolean', 'awaiting_market_data' => 'boolean',
            'entry_price' => 'string', 'stop_loss' => 'string', 'take_profit_1' => 'string', 'take_profit_2' => 'string',
            'initial_quantity' => 'string', 'remaining_quantity' => 'string', 'qty_step' => 'string', 'risk_capital' => 'string', 'gross_pnl' => 'string',
            'net_pnl' => 'string', 'unrealized_pnl' => 'string', 'fees' => 'string', 'funding' => 'string', 'r_multiple' => 'string',
        ];
    }
}
