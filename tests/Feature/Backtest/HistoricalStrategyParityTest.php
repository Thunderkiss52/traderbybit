<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\StrategySignalEvaluator;
use App\Strategy\Domain\SignalSide;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HistoricalStrategyParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_primary_strategies_fail_closed_without_history(): void
    {
        $evaluator = app(StrategySignalEvaluator::class);
        $at = new DateTimeImmutable('2026-01-01T12:00:00Z');
        foreach (config('paper.primary_strategies') as $id) {
            $configuration = config('strategy');
            $configuration['_research_strategy_id'] = $id;
            $configuration['_research_parameters'] = config("research.strategies.{$id}", []);
            $first = $evaluator->evaluate('BTCUSDT', $at, $configuration);
            $second = $evaluator->evaluate('BTCUSDT', $at, $configuration);
            self::assertSame(SignalSide::Skip, $first->side, $id);
            self::assertSame($first->reasons, $second->reasons, $id);
        }
    }
}
