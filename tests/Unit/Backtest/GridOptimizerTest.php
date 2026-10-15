<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Backtest\Application\BacktestSimulationEngine;
use App\Backtest\Application\GridOptimizer;
use App\Backtest\Application\PerformanceMetricCalculator;
use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\RiskEngine;
use App\Strategy\Domain\SignalDecision;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GridOptimizerTest extends TestCase
{
    public function test_grid_is_deterministic_and_resource_capped(): void
    {
        $paper = require dirname(__DIR__, 3).'/config/paper.php';
        $signals = new class implements HistoricalSignalEvaluator
        {
            public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
            {
                throw new RuntimeException('not called');
            }
        };
        $engine = new BacktestSimulationEngine($signals, new RiskEngine($paper), new CorrelationService($paper), $paper);
        $optimizer = new GridOptimizer($engine, new PerformanceMetricCalculator, ['grid' => [], 'max_combinations' => 2]);
        self::assertSame([['a' => '1', 'b' => 'x'], ['a' => '1', 'b' => 'y'], ['a' => '2', 'b' => 'x'], ['a' => '2', 'b' => 'y']], $optimizer->combinations(['a' => ['1', '2'], 'b' => ['x', 'y']], 4));
        $this->expectException(RuntimeException::class);
        $optimizer->combinations(['a' => [1, 2], 'b' => [3, 4]], 3);
    }
}
