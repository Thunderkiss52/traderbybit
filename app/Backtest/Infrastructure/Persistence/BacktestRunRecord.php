<?php

declare(strict_types=1);

namespace App\Backtest\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BacktestRunRecord extends Model
{
    protected $table = 'backtest_runs';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['symbols' => 'array', 'strategy_config' => 'array', 'risk_config' => 'array', 'execution_config' => 'array', 'warnings' => 'array', 'errors' => 'array', 'metrics' => 'array', 'from_at' => 'immutable_datetime', 'to_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
