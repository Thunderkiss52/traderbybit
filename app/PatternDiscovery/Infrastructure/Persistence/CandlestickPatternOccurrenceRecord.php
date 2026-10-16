<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CandlestickPatternOccurrenceRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'candlestick_pattern_occurrences';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['formation_time' => 'immutable_datetime', 'detected_at' => 'immutable_datetime', 'outcome_at' => 'immutable_datetime',
            'forward_return' => 'string', 'mfe_return' => 'string', 'mae_return' => 'string', 'evidence' => 'array'];
    }
}
