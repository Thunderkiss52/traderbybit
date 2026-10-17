<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StrategyHealthSnapshotRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'strategy_health_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'funnel_metrics' => 'array',
            'diagnostics' => 'array',
            'snapshot_at' => 'immutable_datetime',
        ];
    }
}
