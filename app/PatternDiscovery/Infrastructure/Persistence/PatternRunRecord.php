<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PatternRunRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_pattern_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_at' => 'immutable_datetime', 'to_at' => 'immutable_datetime', 'parameters' => 'array',
            'data_quality' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
