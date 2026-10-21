<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ResearchGenerationRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_generations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'versioned_config' => 'array',
            'row_counts' => 'array',
            'metadata' => 'array',
            'coverage_manifest' => 'array',
            'missing_data_count' => 'integer',
        ];
    }
}
