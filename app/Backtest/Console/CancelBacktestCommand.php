<?php

declare(strict_types=1);

namespace App\Backtest\Console;

use App\Backtest\Application\BacktestRunService;
use Illuminate\Console\Command;

final class CancelBacktestCommand extends Command
{
    protected $signature = 'backtest:cancel {--run=}';

    protected $description = 'Request cooperative cancellation of a queued or running backtest';

    public function handle(BacktestRunService $service): int
    {
        if (! $service->requestCancellation((string) $this->option('run'))) {
            $this->error('Run is not queued/running or was not found.');

            return self::FAILURE;
        }
        $this->info('Cancellation requested.');

        return self::SUCCESS;
    }
}
