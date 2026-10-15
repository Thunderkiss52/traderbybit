<?php

declare(strict_types=1);

namespace App\Backtest\Console;

use App\Backtest\Application\BacktestRunService;

final class WalkForwardCommand extends RunBacktestCommand
{
    protected $signature = 'backtest:walk-forward {--symbols=BTCUSDT,ETHUSDT} {--from=} {--to=} {--queue}';

    protected $description = 'Run rolling train/validation/out-of-sample windows';

    public function handle(BacktestRunService $service): int
    {
        return $this->runMode($service, true);
    }
}
