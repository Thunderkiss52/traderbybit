<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class EquitySnapshotRecord extends Model
{
    protected $table = 'equity_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'equity' => 'string', 'realized_pnl' => 'string', 'unrealized_pnl' => 'string', 'drawdown_percent' => 'string'];
    }
}
