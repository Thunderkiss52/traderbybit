<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LearningCandidateStrategyRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'learning_candidate_strategies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'parameter_diff' => 'array',
            'search_metadata' => 'array',
            'validation_gates' => 'array',
            'metrics' => 'array',
            'promoted_at' => 'immutable_datetime',
            'rolled_back_at' => 'immutable_datetime',
        ];
    }
}
