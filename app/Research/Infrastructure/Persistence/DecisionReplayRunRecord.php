<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DecisionReplayRunRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'decision_replay_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'as_of_time' => 'immutable_datetime',
            'recomputed_decision' => 'array',
            'stored_decision' => 'array',
            'diff' => 'array',
            'is_reproducible' => 'boolean',
        ];
    }
}
