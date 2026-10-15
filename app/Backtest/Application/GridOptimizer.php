<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Domain\Segment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use RuntimeException;

final readonly class GridOptimizer
{
    public function __construct(private BacktestSimulationEngine $engine, private PerformanceMetricCalculator $metrics, private array $config) {}

    public function optimize(array $symbols, Segment $train, Segment $validation, array $baseStrategy, array $execution, string $balance): array
    {
        $rows = [];
        $best = null;
        foreach ($this->combinations((array) $this->config['grid'], (int) $this->config['max_combinations']) as $index => $parameters) {
            $strategy = $baseStrategy;
            foreach ($parameters as $path => $value) {
                Arr::set($strategy, $path, $value);
            }
            $trainResult = $this->engine->simulate($symbols, $train, $strategy, $execution, $balance);
            $validationResult = $this->engine->simulate($symbols, $validation, $strategy, $execution, $balance);
            $trainMetrics = $this->metrics->calculate($trainResult->trades, $trainResult->equity, (array) config('backtest.returns'));
            $validationMetrics = $this->metrics->calculate($validationResult->trades, $validationResult->equity, (array) config('backtest.returns'));
            $score = $this->objective($trainMetrics, $validationMetrics);
            $row = ['combination_index' => $index, 'parameters' => $parameters, 'train_metrics' => $trainMetrics, 'validation_metrics' => $validationMetrics, 'objective_score' => $score, 'strategy' => $strategy];
            $rows[] = $row;
            if ($best === null || BigDecimal::of($score)->isGreaterThan(BigDecimal::of($best['objective_score']))) {
                $best = $row;
            }
        }
        if ($best === null) {
            throw new RuntimeException('Optimization grid is empty.');
        }

        return ['selected' => $best, 'results' => $rows];
    }

    public function combinations(array $grid, int $maximum): array
    {
        $result = [[]];
        foreach ($grid as $path => $values) {
            $next = [];
            foreach ($result as $existing) {
                foreach ((array) $values as $value) {
                    $next[] = $existing + [(string) $path => $value];
                    if (count($next) > $maximum) {
                        throw new RuntimeException("Grid exceeds maximum of {$maximum} combinations.");
                    }
                }
            }
            $result = $next;
        }

        return $result;
    }

    private function objective(array $train, array $validation): string
    {
        if ((int) $validation['total_trades'] < (int) $this->config['minimum_trades']) {
            return '-999999999';
        }
        $validationPnl = BigDecimal::of($validation['net_pnl']);
        $drawdownPenalty = BigDecimal::of($validation['maximum_drawdown_percent'])->multipliedBy((string) $this->config['drawdown_penalty']);
        $stability = BigDecimal::of($train['net_pnl'])->minus($validation['net_pnl'])->abs()->multipliedBy((string) $this->config['stability_penalty']);

        return (string) $validationPnl->minus($drawdownPenalty)->minus($stability)->toScale(12, RoundingMode::HalfEven);
    }
}
