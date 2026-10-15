<?php

declare(strict_types=1);

namespace App\Backtest\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class BacktestSegmentRecord extends Model
{
    protected $table = 'backtest_segments';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['parameters' => 'array', 'metrics' => 'array', 'warnings' => 'array', 'decisions' => 'array', 'from_at' => 'immutable_datetime', 'to_at' => 'immutable_datetime'];
    }
}
