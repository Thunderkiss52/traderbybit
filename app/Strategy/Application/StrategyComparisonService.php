<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;

final readonly class StrategyComparisonService
{
    public function __construct(private StrategyRegistry $registry, private StrategyRankingService $ranking) {}

    public function latest(): array
    {
        $rows = [];
        foreach ($this->registry->metadata() as $strategy) {
            $experiment = ResearchExperimentRecord::query()
                ->where('strategy_id', $strategy['id'])
                ->where('strategy_version', $strategy['version'])
                ->where('configuration_hash', $strategy['configuration_hash'])
                ->where('status', 'COMPLETED')
                ->latest('completed_at')
                ->first();
            $metrics = $experiment?->metrics['test'] ?? $experiment?->metrics['out_of_sample'] ?? null;
            $rows[] = $strategy + ['experiment_id' => $experiment?->id, 'metrics' => $metrics, 'ranking' => $this->ranking->rank($metrics)];
        }

        return $rows;
    }
}
