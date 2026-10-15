<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestOptimizationRecord;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use DateTimeImmutable;
use Illuminate\Support\Arr;

final readonly class ResearchExperimentRunner
{
    public function __construct(private BacktestRunService $backtests, private StrategyRegistry $strategies) {}

    public function run(string $id): void
    {
        $experiment = ResearchExperimentRecord::query()->findOrFail($id);
        if ($experiment->status === 'CANCEL_REQUESTED') {
            $experiment->update(['status' => 'CANCELLED', 'completed_at' => now('UTC')]);

            return;
        }
        $experiment->update(['status' => 'RUNNING', 'started_at' => now('UTC')]);
        try {
            $strategy = $this->strategies->get($experiment->strategy_id);
            $config = (array) config('strategy');
            $config['version'] = $strategy->version();
            $config['_research_strategy_id'] = $strategy->id();
            $config['_research_parameters'] = $experiment->parameters;
            if ($strategy->id() !== 'baseline') {
                $config['_research_optimization'] = ['max_combinations' => (int) config('research.optimization.maximum_combinations', 100), 'grid' => (array) config('research.optimization.grids.'.$strategy->id(), [])];
            }
            $run = $this->backtests->run($experiment->symbols, new DateTimeImmutable($experiment->from_at->toISOString()), new DateTimeImmutable($experiment->to_at->toISOString()), $experiment->evaluation_mode === 'walk_forward', $config);
            [$selected, $stability] = $this->selectedParameters($run->id, $experiment->parameters, $strategy->id());
            $experiment->update(['status' => $run->status, 'backtest_run_id' => $run->id, 'metrics' => $run->metrics, 'warnings' => $run->warnings, 'tested_combinations' => BacktestOptimizationRecord::query()->where('backtest_run_id', $run->id)->count(), 'selected_parameters' => $selected, 'parameter_stability' => $stability, 'completed_at' => now('UTC')]);
        } catch (\Throwable $error) {
            $experiment->update(['status' => 'FAILED', 'warnings' => [['class' => $error::class, 'message' => $error->getMessage()]], 'completed_at' => now('UTC')]);
            throw $error;
        }
    }

    private function selectedParameters(string $runId, array $initial, string $strategyId): array
    {
        if ($strategyId === 'baseline') {
            return [$initial, ['windows' => 1, 'unique_configurations' => 1, 'dominant_fraction' => '1.00000000', 'stable' => true]];
        }
        $rows = BacktestOptimizationRecord::query()->where('backtest_run_id', $runId)->where('selected', true)->orderBy('window_index')->get(['parameters']);
        $counts = [];
        foreach ($rows as $row) {
            $parameters = $row->parameters;
            ksort($parameters);
            $key = json_encode($parameters, JSON_THROW_ON_ERROR);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $dominant = $counts === [] ? [] : json_decode((string) array_key_first($counts), true, flags: JSON_THROW_ON_ERROR);
        $selected = ['_research_parameters' => $initial];
        foreach ($dominant as $path => $value) {
            Arr::set($selected, $path, $value);
        }
        $windows = max(1, $rows->count());
        $fraction = ($counts === [] ? 0 : reset($counts)) / $windows;

        return [$selected['_research_parameters'], ['windows' => $rows->count(), 'unique_configurations' => count($counts), 'dominant_fraction' => number_format($fraction, 8, '.', ''), 'stable' => $fraction >= 0.60]];
    }
}
