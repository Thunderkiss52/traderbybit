<?php

declare(strict_types=1);

namespace App\Backtest\Contracts;

use App\Strategy\Domain\SignalDecision;
use DateTimeImmutable;

interface HistoricalSignalEvaluator
{
    public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision;
}
