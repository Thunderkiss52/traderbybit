<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Application\MarketCandleConflictService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Explicit, bounded Binance ingestion. Never generates trading signals or paper fills. */
final readonly class BinanceCandleIngestion
{
    private MarketCandleConflictService $conflictService;

    public function __construct(
        private BinancePublicAdapter $adapter,
        ?MarketCandleConflictService $conflictService = null
    ) {
        $this->conflictService = $conflictService ?? app(MarketCandleConflictService::class);
    }

    public function ingest(string $symbol, string $interval, int $limit = 100, bool $quarantineConflicts = false): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            throw new InvalidArgumentException('Market symbol not configured.');
        }
        if (! isset(['5m' => 1, '15m' => 1, '30m' => 1, '1h' => 1, '4h' => 1][$interval])) {
            throw new InvalidArgumentException('Interval not enabled for Binance research.');
        }
        $rows = $this->adapter->fetchCandles($symbol, $interval, $limit);
        $map = ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'];
        $scope = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $map[$interval]];

        $conflictsToRecord = [];

        try {
            $inserted = DB::transaction(function () use ($rows, $symbol, $interval, $scope, $quarantineConflicts, &$conflictsToRecord): int {
                $batch = [];
                $now = gmdate('Y-m-d H:i:s.uP');
                foreach ($rows as $candle) {
                    if ($candle['exchange_id'] !== 'binance' || $candle['symbol'] !== $symbol || $candle['interval'] !== $interval) {
                        throw new DomainException('Binance candle source mismatch.');
                    }
                    // Do not accept or store the open/unconfirmed candle.
                    if (! $candle['is_closed']) {
                        continue;
                    }
                    BinanceSourceIdentity::assertPersistable([
                        'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'],
                        'close' => $candle['close'], 'volume' => $candle['volume'], 'turnover' => $candle['turnover'],
                    ]);
                    $openTimeStr = $candle['open_time_utc'];
                    $batch[strtotime($openTimeStr)] = [
                        ...$scope, 'open_time' => $openTimeStr,
                        'open' => $candle['open'], 'high' => $candle['high'], 'low' => $candle['low'],
                        'close' => $candle['close'], 'volume' => $candle['volume'], 'turnover' => $candle['turnover'],
                        'is_closed' => true, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                $inserted = 0;
                // Bound query parameters even if a source returns more than the requested limit.
                foreach (array_chunk($batch, 500, preserve_keys: true) as $chunk) {
                    $existing = $this->storedCandles($scope, $chunk);
                    $missing = [];
                    foreach ($chunk as $key => $candle) {
                        if (isset($existing[$key])) {
                            if ($quarantineConflicts) {
                                $conflict = BinanceSourceIdentity::findCandleConflict($existing[$key], $candle);
                                if ($conflict !== null) {
                                    $conflictsToRecord[] = [
                                        'candle' => $candle,
                                        'stored' => (array) $existing[$key],
                                        'conflict' => $conflict,
                                    ];

                                    continue;
                                }
                            } else {
                                $this->assertSameCandle($existing[$key], $candle);
                            }
                        } else {
                            $missing[$key] = $candle;
                        }
                    }
                    if ($missing === []) {
                        continue;
                    }

                    $insertedNow = $this->insertCandlesUnnest(array_values($missing));
                    if ($insertedNow !== count($missing)) {
                        // A concurrent writer can win after the initial read. A separate
                        // statement sees its committed rows under PostgreSQL READ COMMITTED.
                        // Validate the entire attempted set: affected-row count cannot
                        // identify which identities lost the race. Never update history.
                        $reloaded = $this->storedCandles($scope, $missing);
                        foreach ($missing as $key => $candle) {
                            if (! isset($reloaded[$key])) {
                                throw new DomainException('Binance candle insertion was ignored without matching identity.');
                            }
                            if ($quarantineConflicts) {
                                $conflict = BinanceSourceIdentity::findCandleConflict($reloaded[$key], $candle);
                                if ($conflict !== null) {
                                    $conflictsToRecord[] = [
                                        'candle' => $candle,
                                        'stored' => (array) $reloaded[$key],
                                        'conflict' => $conflict,
                                    ];
                                }
                            } else {
                                $this->assertSameCandle($reloaded[$key], $candle);
                            }
                        }
                    }
                    $inserted += $insertedNow;
                }

                return $inserted;
            });
        } catch (DomainException $exception) {
            // Guarantee durable conflict evidence: ingestion transaction rolled back.
            // Persist the conflict record in a separate transaction outside the rollback.
            $this->recordBatchConflictOnRollback($scope, $rows, $exception);
            throw $exception;
        }

        // Quarantined conflicts are recorded durably after successful transaction commit of valid rows.
        if ($quarantineConflicts && ! empty($conflictsToRecord)) {
            foreach ($conflictsToRecord as $item) {
                $candle = $item['candle'];
                $stored = $item['stored'];
                $conflict = $item['conflict'];
                $this->conflictService->recordConflict(
                    $scope['exchange'],
                    $scope['category'],
                    $scope['symbol'],
                    $scope['interval'],
                    $candle['open_time'],
                    $stored,
                    $candle,
                    $conflict['column'],
                    $conflict['error'],
                    'binance_public_rest'
                );
                Log::warning('Binance immutable candle quarantined', [
                    'exchange' => $scope['exchange'],
                    'symbol' => $scope['symbol'],
                    'interval' => $scope['interval'],
                    'open_time_utc' => $candle['open_time'],
                    'conflict_column' => $conflict['column'],
                    'error' => $conflict['error'],
                ]);
            }
        }

        // Existing rows are immutable; retries verify that bytes did not
        // change economically. Conflicting exchange replays fail closed.
        $hasConflicts = ! empty($conflictsToRecord);

        return [
            'exchange_id' => 'binance',
            'symbol' => $symbol,
            'interval' => $interval,
            'fetched' => count($rows),
            'new_closed_candles' => $inserted,
            'conflicts_count' => count($conflictsToRecord),
            'conflicts' => array_map(static fn (array $c): array => [
                'open_time' => $c['candle']['open_time'],
                'column' => $c['conflict']['column'],
                'error' => $c['conflict']['error'],
            ], $conflictsToRecord),
            'status' => $hasConflicts ? 'PARTIAL_INGESTION_WITH_CONFLICTS' : 'SUCCESS',
            'execution_status' => 'PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED',
        ];
    }

    /**
     * High-throughput PostgreSQL unnest bulk insert.
     * Preserves strict NUMERIC types, idempotency and unique identity constraint.
     */
    private function insertCandlesUnnest(array $chunk): int
    {
        if (empty($chunk)) {
            return 0;
        }

        $count = count($chunk);
        $first = reset($chunk);

        // Scope columns and timestamps are uniform across a single chunk
        $pgExchange = '{'.implode(',', array_fill(0, $count, '"'.addcslashes((string) $first['exchange'], '"\\').'"')).'}';
        $pgCategory = '{'.implode(',', array_fill(0, $count, '"'.addcslashes((string) $first['category'], '"\\').'"')).'}';
        $pgSymbol = '{'.implode(',', array_fill(0, $count, '"'.addcslashes((string) $first['symbol'], '"\\').'"')).'}';
        $pgInterval = '{'.implode(',', array_fill(0, $count, '"'.addcslashes((string) $first['interval'], '"\\').'"')).'}';
        $pgCreatedAt = '{'.implode(',', array_fill(0, $count, '"'.(string) $first['created_at'].'"')).'}';
        $pgUpdatedAt = '{'.implode(',', array_fill(0, $count, '"'.(string) $first['updated_at'].'"')).'}';

        $openTimes = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];
        $turnovers = [];
        $isClosed = [];

        foreach ($chunk as $row) {
            $openTimes[] = '"'.(string) $row['open_time'].'"';
            $opens[] = (string) $row['open'];
            $highs[] = (string) $row['high'];
            $lows[] = (string) $row['low'];
            $closes[] = (string) $row['close'];
            $volumes[] = (string) $row['volume'];
            $turnovers[] = (string) $row['turnover'];
            $isClosed[] = $row['is_closed'] ? 't' : 'f';
        }

        return DB::affectingStatement('insert into "market_candles" (exchange, category, symbol, interval, open_time, open, high, low, close, volume, turnover, is_closed, created_at, updated_at) select * from unnest(?::varchar[], ?::varchar[], ?::varchar[], ?::varchar[], ?::timestamp[], ?::numeric[], ?::numeric[], ?::numeric[], ?::numeric[], ?::numeric[], ?::numeric[], ?::boolean[], ?::timestamptz[], ?::timestamptz[]) on conflict (exchange, category, symbol, interval, open_time) do nothing', [
            $pgExchange,
            $pgCategory,
            $pgSymbol,
            $pgInterval,
            '{'.implode(',', $openTimes).'}',
            '{'.implode(',', $opens).'}',
            '{'.implode(',', $highs).'}',
            '{'.implode(',', $lows).'}',
            '{'.implode(',', $closes).'}',
            '{'.implode(',', $volumes).'}',
            '{'.implode(',', $turnovers).'}',
            '{'.implode(',', $isClosed).'}',
            $pgCreatedAt,
            $pgUpdatedAt,
        ]);
    }

    private function storedCandles(array $scope, array $batch): array
    {
        return DB::table('market_candles')->where($scope)
            ->whereIn('open_time', array_column($batch, 'open_time'))
            ->sharedLock()
            ->get(['open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'])
            ->keyBy(static fn (object $row): int => strtotime($row->open_time))
            ->all();
    }

    private function assertSameCandle(object $stored, array $incoming): void
    {
        try {
            BinanceSourceIdentity::assertSameCandle($stored, $incoming);
        } catch (DomainException $exception) {
            Log::error('Binance immutable candle validation failed', [
                'exchange' => $incoming['exchange'], 'category' => $incoming['category'],
                'symbol' => $incoming['symbol'], 'interval' => $incoming['interval'],
                'open_time_utc' => $incoming['open_time'], 'source' => 'binance_public_rest',
                'path' => 'BinanceCandleIngestion::ingest', 'error' => $exception->getMessage(),
                'stored' => array_intersect_key((array) $stored, array_flip(['open', 'high', 'low', 'close', 'volume', 'turnover'])),
                'incoming' => array_intersect_key($incoming, array_flip(['open', 'high', 'low', 'close', 'volume', 'turnover'])),
            ]);
            throw $exception;
        }
    }

    /**
     * Persist durable conflict evidence after an ingestion transaction rollback.
     */
    private function recordBatchConflictOnRollback(array $scope, array $rows, DomainException $exception): void
    {
        $wasLogging = DB::logging();
        if ($wasLogging) {
            DB::disableQueryLog();
        }
        try {
            $times = [];
            $incomingByTime = [];
            foreach ($rows as $row) {
                if (! empty($row['is_closed']) && isset($row['open_time_utc'])) {
                    $times[] = $row['open_time_utc'];
                    $incomingByTime[strtotime((string) $row['open_time_utc'])] = $row;
                }
            }
            if ($times === []) {
                return;
            }

            $existing = DB::table('market_candles')->where($scope)
                ->whereIn('open_time', $times)
                ->get(['open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'])
                ->keyBy(static fn (object $row): int => strtotime((string) $row->open_time))
                ->all();

            foreach ($incomingByTime as $timeKey => $candle) {
                if (isset($existing[$timeKey])) {
                    $stored = $existing[$timeKey];
                    $conflict = BinanceSourceIdentity::findCandleConflict($stored, $candle);
                    if ($conflict !== null) {
                        $this->conflictService->recordConflict(
                            $scope['exchange'],
                            $scope['category'],
                            $scope['symbol'],
                            $scope['interval'],
                            $candle['open_time_utc'],
                            (array) $stored,
                            $candle,
                            $conflict['column'],
                            $conflict['error'],
                            'binance_public_rest'
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed to record batch conflict on rollback: '.$e->getMessage());
        } finally {
            if ($wasLogging) {
                DB::enableQueryLog();
            }
        }
    }
}
