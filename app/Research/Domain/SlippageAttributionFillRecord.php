<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SlippageAttributionFillRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'slippage_attribution_fills';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market_event_at' => 'immutable_datetime',
            'ambiguous_execution' => 'boolean',
            'quantity' => 'string',
            'contract_multiplier' => 'string',
            'reference_price' => 'string',
            'fill_price' => 'string',
            'reference_notional' => 'string',
            'slippage_drag_usdt' => 'string',
            'slippage_bps' => 'string',
        ];
    }
}
