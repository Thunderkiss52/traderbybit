<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LevelInteractionForecastRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'level_interaction_forecasts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'immutable_datetime', 'horizon_ends_at' => 'immutable_datetime', 'outcome_at' => 'immutable_datetime',
            'features' => 'array', 'success_probability' => 'string', 'failure_probability' => 'string',
            'unresolved_probability' => 'string', 'confidence_low' => 'string', 'confidence_high' => 'string', 'brier_score' => 'string',
        ];
    }
}
