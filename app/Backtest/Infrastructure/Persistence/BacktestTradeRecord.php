<?php

declare(strict_types=1);

namespace App\Backtest\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BacktestTradeRecord extends Model
{
    protected $table = 'backtest_trades';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['executions' => 'array', 'signal_at' => 'immutable_datetime', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'ambiguous_execution' => 'boolean', 'funding_complete' => 'boolean'];
    }
}
