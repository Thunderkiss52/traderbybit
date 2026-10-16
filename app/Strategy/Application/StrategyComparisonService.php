<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;

final readonly class StrategyComparisonService
{
    public function __construct(private StrategyRegistry $registry, private StrategyRankingService $ranking, private PerformanceStatisticsService $performance) {}

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
            $account = PaperAccountRecord::query()->where('strategy_id', $strategy['id'])
                ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER', 'RESEARCH_FORWARD_PAPER'])
                ->latest('created_at')->first();
            $forward = $account === null ? null : $this->performance->calculate($account->id)->metrics;
            $rows[] = $strategy + [
                'experiment_id' => $experiment?->id, 'metrics' => $metrics, 'ranking' => $this->ranking->rank($metrics),
                'forward_paper' => $forward === null ? null : [
                    'account_id' => $account->id, 'mode' => $account->mode, 'started_at' => $account->created_at,
                    'metrics' => $forward, 'evidence_status' => ($forward['closed_positions'] ?? 0) >= 30 ? 'PRELIMINARY' : 'INSUFFICIENT_SAMPLE',
                ],
            ];
        }

        return $rows;
    }
}
