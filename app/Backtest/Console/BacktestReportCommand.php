<?php

declare(strict_types=1);

namespace App\Backtest\Console;

use App\Backtest\Infrastructure\Persistence\BacktestReportRecord;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use Illuminate\Console\Command;

final class BacktestReportCommand extends Command
{
    protected $signature = 'backtest:report {--run=}';

    protected $description = 'Print a stored immutable backtest report';

    public function handle(): int
    {
        $run = BacktestRunRecord::query()->find((string) $this->option('run'));
        if ($run === null) {
            $this->error('Backtest run not found.');

            return self::FAILURE;
        }
        $reports = BacktestReportRecord::query()->where('backtest_run_id', $run->id)->orderBy('kind')->get()->mapWithKeys(fn ($r): array => [$r->kind => $r->payload]);
        $this->line(json_encode(['run' => $run->toArray(), 'reports' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
