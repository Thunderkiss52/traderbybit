<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class SignalOutcomeEvaluationRecord extends Model
{
    protected $table = 'signal_outcome_evaluations';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Signal outcome evidence is immutable.'));
        self::deleting(fn () => throw new \LogicException('Signal outcome evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['result' => 'array', 'decision_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime',
            'entry_at' => 'immutable_datetime', 'horizon_end_at' => 'immutable_datetime',
            'evaluated_as_of' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
