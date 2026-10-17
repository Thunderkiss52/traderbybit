<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PortfolioCorrelationSnapshotRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'portfolio_correlation_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pair_matrix' => 'array',
            'concurrent_overlap' => 'array',
            'alerts' => 'array',
            'concentration_score' => 'decimal:4',
            'evaluated_at' => 'immutable_datetime',
        ];
    }
}
