<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /** Quarantine inconsistent attribution and signals changed after job submission. */
    public function scopeSourceTimeframeConsistent(Builder $query): void
    {
        $query->whereExists(static function (QueryBuilder $signal): void {
            $signal->selectRaw('1')->from('signals')->join('research_jobs', 'research_jobs.job_id', '=', 'signal_outcome_evaluations.research_job_id')
                ->whereColumn('signals.id', 'signal_outcome_evaluations.signal_id')
                ->whereColumn('signals.created_at', '<=', 'research_jobs.created_at')
                ->whereColumn('signals.updated_at', '<=', 'research_jobs.created_at')
                ->whereRaw("CASE signals.timeframe WHEN '1' THEN 1 WHEN '1m' THEN 1 WHEN '3' THEN 3 WHEN '3m' THEN 3 WHEN '5' THEN 5 WHEN '5m' THEN 5 WHEN '15' THEN 15 WHEN '15m' THEN 15 WHEN '30' THEN 30 WHEN '30m' THEN 30 WHEN '60' THEN 60 WHEN '60m' THEN 60 WHEN '1h' THEN 60 ELSE NULL END = signal_outcome_evaluations.source_timeframe_minutes");
        });
    }

    protected function casts(): array
    {
        return ['result' => 'array', 'decision_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime',
            'entry_at' => 'immutable_datetime', 'horizon_end_at' => 'immutable_datetime',
            'evaluated_as_of' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
