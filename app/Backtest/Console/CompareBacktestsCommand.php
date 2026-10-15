<?php

declare(strict_types=1);

namespace App\Backtest\Console;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use Illuminate\Console\Command;

final class CompareBacktestsCommand extends Command
{
    protected $signature = 'backtest:compare {--runs=}';

    protected $description = 'Compare stored backtest metrics without recomputing history';

    public function handle(): int
    {
        $ids = array_values(array_filter(explode(',', (string) $this->option('runs'))));
        if (count($ids) < 2) {
            $this->error('Provide at least two comma-separated run UUIDs.');

            return self::FAILURE;
        }
        $runs = BacktestRunRecord::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($runs->count() !== count(array_unique($ids))) {
            $this->error('One or more runs were not found.');

            return self::FAILURE;
        }
        $payload = [];
        foreach ($ids as $id) {
            $payload[] = ['id' => $id, 'type' => $runs[$id]->type, 'dataset_checksum' => $runs[$id]->dataset_checksum, 'metrics' => $runs[$id]->metrics];
        }
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
