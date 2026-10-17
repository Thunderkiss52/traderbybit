<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LearningFeatureSnapshotRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'learning_feature_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'data_quality' => 'array',
            'label_r_multiple' => 'decimal:4',
            'decision_time' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'outcome_at' => 'immutable_datetime',
        ];
    }
}
