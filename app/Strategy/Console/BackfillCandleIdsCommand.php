<?php

declare(strict_types=1);

namespace App\Strategy\Console;

use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;

final class BackfillCandleIdsCommand extends Command
{
    protected $signature = 'signals:backfill-candle-ids
        {--apply : Execute updates to signals.candle_id (default is dry-run preview)}
        {--dry-run : Explicitly run in preview mode without updating records}
        {--batch-size=100 : Number of candidate signals to inspect per batch}
        {--max-rows=1000 : Maximum total signals to inspect (0 for unlimited)}
        {--max-duration=60 : Maximum execution duration in seconds (0 for unlimited)}
        {--exchange= : Filter signals by exchange (e.g. bybit, binance)}
        {--symbol= : Filter signals by symbol (e.g. BTCUSDT)}
        {--retry-all : Re-evaluate previously unmatched or retryable signals}
        {--reset-checkpoint : Reset the saved progress checkpoint before starting}';

    protected $description = 'Safely and idempotently backfill candle_id references on historical signals in bounded batches';

    public function handle(CacheFactory $cacheFactory): int
    {
        $isApply = (bool) $this->option('apply');
        $isDryRun = (bool) $this->option('dry-run') || ! $isApply;
        $batchSize = max(1, (int) $this->option('batch-size'));
        $maxRows = max(0, (int) $this->option('max-rows'));
        $maxDuration = max(0, (int) $this->option('max-duration'));
        $exchange = $this->option('exchange') ? (string) $this->option('exchange') : null;
        $symbol = $this->option('symbol') ? strtoupper((string) $this->option('symbol')) : null;
        $retryAll = (bool) $this->option('retry-all');
        $resetCheckpoint = (bool) $this->option('reset-checkpoint');

        $scope = implode(':', array_filter(['backfill', $exchange, $symbol])) ?: 'backfill:all';

        $this->info(sprintf(
            'Starting signal candle backfill [%s mode] (Scope: %s, Batch: %d, MaxRows: %s, MaxDuration: %ss)...',
            $isDryRun ? 'DRY-RUN' : 'APPLY',
            $scope,
            $batchSize,
            $maxRows > 0 ? (string) $maxRows : 'unlimited',
            $maxDuration > 0 ? (string) $maxDuration : 'unlimited'
        ));

        // 1. Concurrency Control: PostgreSQL Advisory Lock + Redis Lock
        $isPgsql = DB::getDriverName() === 'pgsql';
        $advisoryKey = abs(crc32("signals:backfill-candle-ids:{$scope}"));
        if ($isPgsql) {
            $acquiredAdvisory = (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [$advisoryKey])->locked;
            if (! $acquiredAdvisory) {
                $this->warn("Another backfill worker holds the PostgreSQL advisory lock for scope [{$scope}]. Exiting.");

                return self::FAILURE;
            }
        }

        $lockTtl = max(300, $maxDuration > 0 ? $maxDuration + 60 : 3600);
        $redisLock = $cacheFactory->lock("signals:backfill-candle-ids:{$scope}", $lockTtl);
        if (! $redisLock->get()) {
            if ($isPgsql) {
                DB::statement('SELECT pg_advisory_unlock(?)', [$advisoryKey]);
            }
            $this->warn("Another backfill worker holds the Redis lock for scope [{$scope}]. Exiting.");

            return self::FAILURE;
        }

        $startTime = microtime(true);
        $statusReason = 'COMPLETED';

        try {
            // 2. Handle Checkpoint
            if ($resetCheckpoint) {
                DB::table('signal_candle_backfill_checkpoints')->where('scope', $scope)->delete();
                $this->line("Reset checkpoint for scope [{$scope}].");
            }

            $checkpoint = DB::table('signal_candle_backfill_checkpoints')->where('scope', $scope)->first();
            $cursorTime = $checkpoint?->last_decision_time;
            $cursorId = $checkpoint?->last_signal_id;

            $totalInspected = 0;
            $totalMatched = 0;
            $totalUpdated = 0;
            $totalUnmatched = 0;
            $totalAmbiguous = 0;
            $totalUnclosed = 0;

            while (true) {
                if ($maxDuration > 0 && (microtime(true) - $startTime) >= $maxDuration) {
                    $statusReason = 'TIME_LIMIT_REACHED';
                    break;
                }
                if ($maxRows > 0 && $totalInspected >= $maxRows) {
                    $statusReason = 'ROW_LIMIT_REACHED';
                    break;
                }

                $queryLimit = $batchSize;
                if ($maxRows > 0 && ($totalInspected + $queryLimit) > $maxRows) {
                    $queryLimit = $maxRows - $totalInspected;
                }

                // 3. Query Next Candidate Batch
                $query = SignalRecord::query()
                    ->from('signals as s')
                    ->select([
                        's.id', 's.exchange', 's.category', 's.symbol',
                        's.timeframe', 's.decision_time', 's.candle_close_time', 's.candle_id',
                    ])
                    ->leftJoin('signal_candle_backfill_items as bi', 's.id', '=', 'bi.signal_id')
                    ->whereNull('s.candle_id');

                if (! $retryAll) {
                    $query->where(function ($q): void {
                        $q->whereNull('bi.status')
                            ->orWhere('bi.retry_after', '<=', now());
                    });
                }

                if ($exchange !== null) {
                    $query->where('s.exchange', $exchange);
                }
                if ($symbol !== null) {
                    $query->where('s.symbol', $symbol);
                }

                if ($cursorTime !== null && $cursorId !== null) {
                    $query->where(function ($q) use ($cursorTime, $cursorId): void {
                        $q->where('s.decision_time', '>', $cursorTime)
                            ->orWhere(function ($q2) use ($cursorTime, $cursorId): void {
                                $q2->where('s.decision_time', '=', $cursorTime)
                                    ->where('s.id', '>', $cursorId);
                            });
                    });
                }

                $signals = $query->orderBy('s.decision_time', 'asc')
                    ->orderBy('s.id', 'asc')
                    ->limit($queryLimit)
                    ->get();

                if ($signals->isEmpty()) {
                    break;
                }

                // 4. Batch Lookup Candles (Bulk Set-Based to prevent N+1 queries)
                $batchCalculations = [];
                $lookupOpenTimes = [];
                $lookupSymbols = [];
                $lookupIntervals = [];

                foreach ($signals as $sig) {
                    $tf = (string) $sig->timeframe;
                    $interval = $this->mapTimeframeToInterval($tf);
                    $openTime = $this->deriveCandleOpenTime($sig->candle_close_time, $interval);
                    $openTimeStr = $openTime->format('Y-m-d H:i:s.uP');

                    $batchCalculations[$sig->id] = [
                        'signal' => $sig,
                        'interval' => $interval,
                        'open_time' => $openTime,
                        'open_time_str' => $openTimeStr,
                        'key' => "{$sig->exchange}:{$sig->category}:{$sig->symbol}:{$interval}:{$openTimeStr}",
                    ];

                    $lookupSymbols[] = $sig->symbol;
                    $lookupIntervals[] = $interval;
                    $lookupOpenTimes[] = $openTime;
                }

                $symbolsDistinct = array_values(array_unique($lookupSymbols));
                $intervalsDistinct = array_values(array_unique($lookupIntervals));

                $minOpenTime = min($lookupOpenTimes);
                $maxOpenTime = max($lookupOpenTimes);

                $candles = CandleRecord::query()
                    ->whereIn('symbol', $symbolsDistinct)
                    ->whereIn('interval', $intervalsDistinct)
                    ->where('open_time', '>=', $minOpenTime)
                    ->where('open_time', '<=', $maxOpenTime)
                    ->get();

                $candlesByKey = [];
                foreach ($candles as $c) {
                    $cOpenStr = $c->open_time instanceof \DateTimeInterface
                        ? $c->open_time->format('Y-m-d H:i:s.uP')
                        : (new DateTimeImmutable((string) $c->open_time, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');
                    $k = "{$c->exchange}:{$c->category}:{$c->symbol}:{$c->interval}:{$cOpenStr}";
                    $candlesByKey[$k][] = $c;
                }

                // 5. Evaluate Matches
                $batchUpdates = [];
                $journalItems = [];
                $now = now();

                foreach ($signals as $sig) {
                    $totalInspected++;
                    $calc = $batchCalculations[$sig->id];
                    $matches = $candlesByKey[$calc['key']] ?? [];

                    $cursorTime = $sig->decision_time;
                    $cursorId = $sig->id;

                    if (count($matches) > 1) {
                        $totalAmbiguous++;
                        $journalItems[] = [
                            'signal_id' => $sig->id,
                            'status' => 'AMBIGUOUS',
                            'candle_id' => null,
                            'checked_at' => $now,
                            'retry_after' => null,
                            'reason' => 'Multiple matching candles found for slot',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    if (empty($matches)) {
                        $totalUnmatched++;
                        $journalItems[] = [
                            'signal_id' => $sig->id,
                            'status' => 'NO_CANDLE',
                            'candle_id' => null,
                            'checked_at' => $now,
                            'retry_after' => $now->copy()->addHours(1),
                            'reason' => 'No matching candle found for interval and open time',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    $matchedCandle = $matches[0];
                    if (! $matchedCandle->is_closed) {
                        $totalUnclosed++;
                        $journalItems[] = [
                            'signal_id' => $sig->id,
                            'status' => 'UNCLOSED_CANDLE',
                            'candle_id' => null,
                            'checked_at' => $now,
                            'retry_after' => $now->copy()->addMinutes(15),
                            'reason' => 'Matching candle is not marked as closed',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    $totalMatched++;
                    $batchUpdates[$sig->id] = (int) $matchedCandle->id;
                    $journalItems[] = [
                        'signal_id' => $sig->id,
                        'status' => 'MATCHED',
                        'candle_id' => (int) $matchedCandle->id,
                        'checked_at' => $now,
                        'retry_after' => null,
                        'reason' => 'Closed candle resolved and matched',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // 6. Persist Bounded Batch in Short Transaction
                if (! $isDryRun && (! empty($batchUpdates) || ! empty($journalItems))) {
                    DB::transaction(function () use ($batchUpdates, $journalItems, $isPgsql): void {
                        if ($isPgsql) {
                            DB::statement("SET LOCAL lock_timeout = '2s'");
                            DB::statement("SET LOCAL statement_timeout = '5s'");
                        }

                        foreach ($batchUpdates as $sigId => $cId) {
                            DB::table('signals')
                                ->where('id', $sigId)
                                ->whereNull('candle_id') // Double check: never overwrite valid candle_id
                                ->update([
                                    'candle_id' => $cId,
                                    'updated_at' => now(),
                                ]);
                        }

                        if (! empty($journalItems)) {
                            DB::table('signal_candle_backfill_items')->upsert(
                                $journalItems,
                                ['signal_id'],
                                ['status', 'candle_id', 'checked_at', 'retry_after', 'reason', 'updated_at']
                            );
                        }
                    });

                    $totalUpdated += count($batchUpdates);
                }

                // 7. Update Durable Checkpoint
                if (! $isDryRun && $cursorTime !== null && $cursorId !== null) {
                    $prevCheckpoint = DB::table('signal_candle_backfill_checkpoints')->where('scope', $scope)->first();
                    $newProcessed = ($prevCheckpoint ? (int) $prevCheckpoint->processed_count : 0) + $signals->count();
                    $newMatched = ($prevCheckpoint ? (int) $prevCheckpoint->matched_count : 0) + count($batchUpdates);
                    $newUnmatched = ($prevCheckpoint ? (int) $prevCheckpoint->unmatched_count : 0) + $totalUnmatched;
                    $newAmbiguous = ($prevCheckpoint ? (int) $prevCheckpoint->ambiguous_count : 0) + $totalAmbiguous;
                    $newUnclosed = ($prevCheckpoint ? (int) $prevCheckpoint->unclosed_count : 0) + $totalUnclosed;

                    DB::table('signal_candle_backfill_checkpoints')->updateOrInsert(
                        ['scope' => $scope],
                        [
                            'last_decision_time' => $cursorTime,
                            'last_signal_id' => $cursorId,
                            'processed_count' => $newProcessed,
                            'matched_count' => $newMatched,
                            'unmatched_count' => $newUnmatched,
                            'ambiguous_count' => $newAmbiguous,
                            'unclosed_count' => $newUnclosed,
                            'last_run_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            $elapsed = round(microtime(true) - $startTime, 2);

            $cursorTimeFormatted = 'N/A';
            if ($cursorTime instanceof \DateTimeInterface) {
                $cursorTimeFormatted = $cursorTime->format('Y-m-d H:i:s');
            } elseif (is_string($cursorTime) && $cursorTime !== '') {
                $cursorTimeFormatted = $cursorTime;
            }

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Mode', $isDryRun ? 'DRY-RUN (Preview)' : 'APPLY (Committed)'],
                    ['Scope', $scope],
                    ['Inspected Signals', (string) $totalInspected],
                    ['Matched Signals', (string) $totalMatched],
                    ['Updated Signals', (string) $totalUpdated],
                    ['Unmatched (No Candle)', (string) $totalUnmatched],
                    ['Ambiguous Candidates', (string) $totalAmbiguous],
                    ['Unclosed Candidates', (string) $totalUnclosed],
                    ['Last Checkpoint Time', $cursorTimeFormatted],
                    ['Last Checkpoint ID', (string) ($cursorId ?? 'N/A')],
                    ['Elapsed Duration', "{$elapsed}s"],
                    ['Exit Status', $statusReason],
                ]
            );

            return self::SUCCESS;
        } finally {
            $redisLock->release();
            if ($isPgsql) {
                DB::statement('SELECT pg_advisory_unlock(?)', [$advisoryKey]);
            }
        }
    }

    private function mapTimeframeToInterval(string $timeframe): string
    {
        return match ($timeframe) {
            '1m', '1' => Interval::OneMinute->value,
            '3m', '3' => Interval::ThreeMinutes->value,
            '5m', '5' => Interval::FiveMinutes->value,
            '15m', '15' => Interval::FifteenMinutes->value,
            '30m', '30' => Interval::ThirtyMinutes->value,
            '1h', '60' => Interval::OneHour->value,
            '4h', '240' => Interval::FourHours->value,
            '1d', 'D' => Interval::OneDay->value,
            default => preg_replace('/[^0-9]/', '', $timeframe) ?: Interval::FifteenMinutes->value,
        };
    }

    private function deriveCandleOpenTime(mixed $candleCloseTime, string $interval): DateTimeImmutable
    {
        $minutes = match ($interval) {
            Interval::OneMinute->value => 1,
            Interval::ThreeMinutes->value => 3,
            Interval::FiveMinutes->value => 5,
            Interval::FifteenMinutes->value => 15,
            Interval::ThirtyMinutes->value => 30,
            Interval::OneHour->value => 60,
            Interval::FourHours->value => 240,
            Interval::OneDay->value => 1440,
            default => (int) $interval ?: 15,
        };

        $close = $candleCloseTime instanceof \DateTimeInterface
            ? DateTimeImmutable::createFromInterface($candleCloseTime)
            : new DateTimeImmutable((string) $candleCloseTime, new DateTimeZone('UTC'));

        return $close->modify("-{$minutes} minutes");
    }
}
