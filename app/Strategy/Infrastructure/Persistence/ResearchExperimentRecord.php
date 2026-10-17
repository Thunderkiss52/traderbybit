<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ResearchExperimentRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_experiments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_shadow' => 'boolean',
            'parameters' => 'array',
            'selected_parameters' => 'array',
            'parameter_stability' => 'array',
            'symbols' => 'array',
            'metrics' => 'array',
            'warnings' => 'array',
            'resource_budget' => 'array',
            'from_at' => 'immutable_datetime',
            'to_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
