<?php

declare(strict_types=1);

namespace App\Backtest\Console;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Jobs\RunBacktestJob;
use DateTimeImmutable;
use Illuminate\Console\Command;

class RunBacktestCommand extends Command
{
    protected $signature = 'backtest:run {--symbols=BTCUSDT,ETHUSDT} {--from=} {--to=} {--queue}';

    protected $description = 'Run an isolated chronological train/validation/test backtest';

    public function handle(BacktestRunService $service): int
    {
        return $this->runMode($service, false);
    }

    protected function runMode(BacktestRunService $service, bool $walk): int
    {
        try {
            if (! $this->option('from') || ! $this->option('to')) {
                throw new \InvalidArgumentException('--from and --to are required.');
            }
            $symbols = array_values(array_unique(array_filter(array_map('strtoupper', explode(',', (string) $this->option('symbols'))))));
            $from = new DateTimeImmutable((string) $this->option('from').' 00:00:00 UTC');
            $to = new DateTimeImmutable((string) $this->option('to').' 00:00:00 UTC');
            if ($this->option('queue')) {
                RunBacktestJob::dispatch($symbols, $from->format(DATE_ATOM), $to->format(DATE_ATOM), $walk);
                $this->info('Backtest queued.');

                return self::SUCCESS;
            }
            $run = $service->run($symbols, $from, $to, $walk);
            $this->line(json_encode(['id' => $run->id, 'status' => $run->status, 'metrics' => $run->metrics, 'warnings' => $run->warnings], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
