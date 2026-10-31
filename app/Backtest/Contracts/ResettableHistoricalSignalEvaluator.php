<?php

declare(strict_types=1);

namespace App\Backtest\Contracts;

interface ResettableHistoricalSignalEvaluator extends HistoricalSignalEvaluator
{
    public function resetInputCache(): void;
}
