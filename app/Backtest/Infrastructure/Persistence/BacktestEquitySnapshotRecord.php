<?php

declare(strict_types=1);

namespace App\Backtest\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BacktestEquitySnapshotRecord extends Model
{
    protected $table = 'backtest_equity_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'exposed' => 'boolean'];
    }
}
