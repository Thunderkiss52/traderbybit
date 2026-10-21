<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PROFIT-1 / P-4 — research-owned MFE/MAE observation record (§9, §12.2).
 */
final class ResearchMfeMaeRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_mfe_mae_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'holding_start_at' => 'immutable_datetime',
            'holding_end_at' => 'immutable_datetime',
            'mfe_candle_close_time' => 'immutable_datetime',
            'mae_candle_close_time' => 'immutable_datetime',
            'as_of' => 'immutable_datetime',
            'open_at_cutoff' => 'boolean',
            'ambiguous_path' => 'boolean',
            'candle_count' => 'integer',
            'mfe_price' => 'string',
            'mae_price' => 'string',
            'mfe_usdt' => 'string',
            'mae_usdt' => 'string',
            'mfe_r' => 'string',
            'mae_r' => 'string',
            'source_candle_ids' => 'array',
            'source_candle_close_times' => 'array',
        ];
    }
}
