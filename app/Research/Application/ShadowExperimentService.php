<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use Illuminate\Validation\ValidationException;

final class ShadowExperimentService
{
    private const MAX_SHADOW_PER_STRATEGY = 2;

    private const MAX_SHADOW_TOTAL = 4;

    /**
     * Start a forward shadow experiment respecting capacity bounds.
     *
     * @throws ValidationException
     */
    public function startExperiment(string $experimentId): ResearchExperimentRecord
    {
        $experiment = ResearchExperimentRecord::query()->findOrFail($experimentId);

        if ($experiment->shadow_status === 'RUNNING') {
            return $experiment;
        }

        // Check total active running shadow variants
        $totalActive = ResearchExperimentRecord::query()
            ->where('is_shadow', true)
            ->where('shadow_status', 'RUNNING')
            ->count();

        if ($totalActive >= self::MAX_SHADOW_TOTAL) {
            throw ValidationException::withMessages([
                'capacity' => 'Server capacity limit reached: maximum '.self::MAX_SHADOW_TOTAL.' running shadow variants allowed globally.',
            ]);
        }

        // Check per-strategy active running shadow variants
        $strategyActive = ResearchExperimentRecord::query()
            ->where('is_shadow', true)
            ->where('strategy_id', $experiment->strategy_id)
            ->where('shadow_status', 'RUNNING')
            ->count();

        if ($strategyActive >= self::MAX_SHADOW_PER_STRATEGY) {
            throw ValidationException::withMessages([
                'strategy_capacity' => "Strategy {$experiment->strategy_id} capacity reached: maximum ".self::MAX_SHADOW_PER_STRATEGY.' running shadow variants allowed.',
            ]);
        }

        $experiment->update([
            'is_shadow' => true,
            'shadow_status' => 'RUNNING',
            'status' => 'RUNNING',
            'started_at' => now('UTC'),
        ]);

        return $experiment->fresh();
    }

    /**
     * Stop a forward shadow experiment.
     */
    public function stopExperiment(string $experimentId): ResearchExperimentRecord
    {
        $experiment = ResearchExperimentRecord::query()->findOrFail($experimentId);

        $experiment->update([
            'shadow_status' => 'PAUSED',
            'status' => 'PAUSED',
            'completed_at' => now('UTC'),
        ]);

        return $experiment->fresh();
    }
}
