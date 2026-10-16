<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PatternMatchRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_pattern_matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['matched_at' => 'immutable_datetime', 'outcome_at' => 'immutable_datetime',
            'feature_snapshot' => 'array', 'net_r' => 'string'];
    }
}
