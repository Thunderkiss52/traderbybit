<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StrategyDegradationSnapshotRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'strategy_degradation_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'diagnostics' => 'array',
            'sample_size' => 'integer',
            'expectancy_r' => 'decimal:4',
            'profit_factor' => 'decimal:4',
            'win_rate' => 'decimal:4',
            'win_rate_ci_lower' => 'decimal:4',
            'win_rate_ci_upper' => 'decimal:4',
            'max_drawdown_percent' => 'decimal:4',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
