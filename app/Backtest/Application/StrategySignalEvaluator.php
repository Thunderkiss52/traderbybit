<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Domain\SignalDecision;
use DateTimeImmutable;

final readonly class StrategySignalEvaluator implements HistoricalSignalEvaluator
{
    public function __construct(private StrategyInputFactory $inputs) {}

    public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
    {
        return (new StrategyEngine($strategyConfig))->evaluate($this->inputs->make('bybit', 'linear', $symbol, $at));
    }
}
