<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SlippageAttributionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'slippage_attribution';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_slippage_usdt' => 'string',
            'partial_exit_slippage_usdt' => 'string',
            'final_exit_slippage_usdt' => 'string',
            'total_slippage_drag_usdt' => 'string',
            'total_reference_notional' => 'string',
            'weighted_slippage_bps' => 'string',
            'fill_count' => 'integer',
            'ambiguous_fill_count' => 'integer',
        ];
    }
}
