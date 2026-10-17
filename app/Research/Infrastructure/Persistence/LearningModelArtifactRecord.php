<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LearningModelArtifactRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'learning_model_artifacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'weights' => 'array',
            'metrics' => 'array',
        ];
    }
}
