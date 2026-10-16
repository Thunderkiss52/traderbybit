<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StrategyObservationRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'strategy_observations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision_time' => 'immutable_datetime',
            'reasons' => 'array',
        ];
    }
}
