<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ResearchJobRecord extends Model
{
    protected $table = 'research_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'random_seed' => 'integer',
            'limits' => 'array',
            'payload' => 'array',
            'fencing_token' => 'integer',
            'progress' => 'integer',
            'result_payload' => 'array',
            'runtime_stats' => 'array',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'as_of_utc' => 'immutable_datetime',
            'training_cutoff_utc' => 'immutable_datetime',
            'requested_at_utc' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
