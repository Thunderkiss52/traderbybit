<?php

declare(strict_types=1);

namespace App\MarketData\Console;

use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use App\MarketData\Jobs\SyncCandlesJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class SyncCandlesCommand extends Command
{
    protected $signature = 'market:sync-candles
        {--symbol=BTCUSDT : Uppercase Bybit symbol}
        {--interval=15 : Bybit interval: 5, 15, or 60}
        {--from= : Inclusive UTC date/time}
        {--to= : Exclusive UTC date/time}
        {--queue : Dispatch the synchronization to Laravel Queue}';

    protected $description = 'Synchronize closed Bybit linear candles and repair gaps';

    public function handle(CandleSynchronizer $synchronizer): int
    {
        try {
            $symbol = strtoupper((string) $this->option('symbol'));
            $interval = Interval::parse((string) $this->option('interval'));
            $to = $this->parseDate($this->option('to') ?: 'now');
            $from = $this->parseDate($this->option('from') ?: $to->subDays((int) config('market_data.sync.initial_history_days'))->toIso8601String());
            $query = new CandleQuery('linear', $symbol, $interval, $from->getTimestampMs(), $to->getTimestampMs());

            if ((bool) $this->option('queue')) {
                SyncCandlesJob::dispatch($symbol, $interval->value, $query->fromMs, $query->toMs);
                $this->info('Synchronization job dispatched.');

                return self::SUCCESS;
            }

            $report = $synchronizer->sync($query);
            $this->table(['Fetched', 'Stored/upserted', 'Backfill requests', 'Remaining gaps'], [[
                $report->fetched,
                $report->stored,
                $report->backfillRequests,
                count($report->missingOpenTimesMs),
            ]]);

            if (! $report->isComplete()) {
                $first = CarbonImmutable::createFromTimestampMsUTC($report->missingOpenTimesMs[0])->toIso8601String();
                $this->warn("Bybit did not provide a complete series; first unresolved gap: {$first}.");

                return self::FAILURE;
            }

            $this->info('Closed candle series is complete for the requested range.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parseDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
