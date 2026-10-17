<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ResearchRecommendationRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_recommendations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'proposed_changes' => 'array',
        ];
    }
}
