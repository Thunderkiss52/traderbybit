<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ReconciliationRunRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'reconciliation_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'starting_balance' => 'string',
            'recalculated_balance' => 'string',
            'stored_balance' => 'string',
            'recalculated_equity' => 'string',
            'stored_equity' => 'string',
            'recalculated_realized_pnl' => 'string',
            'recalculated_unrealized_pnl' => 'string',
            'recalculated_fees' => 'string',
            'recalculated_funding' => 'string',
            'recalculated_drawdown_percent' => 'string',
            'stored_drawdown_percent' => 'string',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
