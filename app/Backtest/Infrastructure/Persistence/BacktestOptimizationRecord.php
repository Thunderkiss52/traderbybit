<?php

declare(strict_types=1);

namespace App\Backtest\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BacktestOptimizationRecord extends Model
{
    protected $table = 'backtest_optimizations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'train_metrics' => 'array', 'validation_metrics' => 'array', 'selected' => 'boolean'];
    }
}
