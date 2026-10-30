<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BinanceCandleBackfillEventRecord extends Model
{
    protected $table = 'binance_candle_backfill_events';
    public $timestamps = true;
    protected $fillable = [
        'exchange','symbol','interval','from_ms','to_ms','requested_count','inserted_count','conflict_count','remaining_gap_count','status','attempt_token','completed_at','report',
    ];
    protected $casts = [
        'from_ms' => 'integer','to_ms' => 'integer','requested_count' => 'integer',
        'inserted_count' => 'integer','conflict_count' => 'integer','remaining_gap_count' => 'integer',
        'completed_at' => 'datetime',
    ];
}
