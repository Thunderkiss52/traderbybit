<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\HistoricalData\DownloadPlanner;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Domain\Interval;
use App\Trading\Application\TradingPipelineEventService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Explicit, bounded Binance ingestion. Never generates trading signals or paper fills. */
final readonly class BinanceCandleIngestion
{
    private MarketCandleConflictService $conflictService;

    private TradingPipelineEventService $pipelineService;

    public function __construct(
        private BinancePublicAdapter $adapter,
        ?MarketCandleConflictService $conflictService = null,
        ?TradingPipelineEventService $pipelineService = null,
    ) {
        $this->conflictService = $conflictService ?? app(MarketCandleConflictService::class);
        $this->pipelineService = $pipelineService ?? app(TradingPipelineEventService::class);
    }

    public function ingest(string $symbol, string $interval, int $limit = 100, bool $quarantineConflicts = false): array
    {
        $this->assertScope($symbol, $interval);
        $rows = $this->adapter->fetchCandles($symbol, $interval, $limit);

        return $this->persist($rows, $symbol, $interval, $quarantineConflicts);
    }

    /**
     * Bounded historical range ingest. The adapter paginates by start/end time
     * so a historical gap can be filled deterministically. Open candles are
     * always skipped; existing rows remain immutable.
     */
    public function ingestRange(string $symbol, string $interval, int $fromMs, int $toMs, bool $quarantineConflicts = false): array
    {
        $this->assertScope($symbol, $interval);
        $dbInterval = ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'][$interval];
        $stepMs = Interval::parse($dbInterval)->milliseconds();
        if ($fromMs < 0 || $fromMs >= $toMs || $fromMs % $stepMs !== 0 || $toMs % $stepMs !== 0
            || $toMs - $fromMs > 500 * $stepMs) {
            throw new InvalidArgumentException('Historical candle range must be aligned and contain at most 500 candles.');
        }
        $rows = $this->adapter->fetchCandles($symbol, $interval, 500, $fromMs, $toMs - 1);
        foreach ($rows as $row) {
            $openTime = new DateTimeImmutable($row['open_time_utc']);
            $openMs = (int) $openTime->format('U') * 1000 + (int) $openTime->format('v');
            if ($openMs < $fromMs || $openMs >= $toMs) {
                throw new DomainException('Binance returned a candle outside the requested historical range.');
            }
        }

        return $this->persist($rows, $symbol, $interval, $quarantineConflicts);
    }

    /** Historical acquisition shares the immutable writer but cannot dispatch trading events. */
    public function persistHistoricalBatch(array $rows, string $symbol, string $interval): array
    {
        if (! in_array($symbol, (array) config('historical_data.symbols'), true) || ! isset(DownloadPlanner::INTERVALS[$interval]) || count($rows) > 5000) {
            throw new InvalidArgumentException('Invalid bounded historical source scope.');
        }

        return $this->persist($rows, $symbol, $interval, true, true);
    }

    private function assertScope(string $symbol, string $interval): void
    {
        if (! in_array($symbol, (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            throw new InvalidArgumentException('Market symbol not configured.');
        }
        if (! isset(['5m' => 1, '15m' => 1, '30m' => 1, '1h' => 1, '4h' => 1][$interval])) {
            throw new InvalidArgumentException('Interval not enabled for Binance research.');
        }
    }

    private function persist(array $rows, string $symbol, string $interval, bool $quarantineConflicts, bool $historical = false): array
    {
        $map = ['1m' => '1', '5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'];
        $scope = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $map[$interval]];

        $conflictsToRecord = [];
        $integrityFailure = null;

        $inserted = DB::transaction(function () use ($rows, $symbol, $interval, $scope, $quarantineConflicts, &$conflictsToRecord, &$integrityFailure, $historical): int {
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
                        $conflict = $this->observeConflict($existing[$key], $candle, $quarantineConflicts, $integrityFailure, $historical ? 'binance_vision_archive' : 'binance_public_rest');
                        if ($conflict !== null) {
                            $conflictsToRecord[] = ['candle' => $candle, 'conflict' => $conflict];
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
                        $conflict = $this->observeConflict($reloaded[$key], $candle, $quarantineConflicts, $integrityFailure, $historical ? 'binance_vision_archive' : 'binance_public_rest');
                        if ($conflict !== null) {
                            $conflictsToRecord[] = ['candle' => $candle, 'conflict' => $conflict];
                        }
                    }
                }
                $inserted += $insertedNow;
            }

            return $inserted;
        });

        // Existing rows are immutable; retries verify that bytes did not
        // change economically. Conflicting exchange replays fail closed.
        $hasConflicts = ! empty($conflictsToRecord);

        $report = [
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
            'status' => $integrityFailure !== null ? 'INTEGRITY_UNAVAILABLE' : ($hasConflicts ? 'PARTIAL_INGESTION_WITH_CONFLICTS' : 'SUCCESS'),
            'execution_status' => 'PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED',
        ];
        if ($integrityFailure !== null) {
            // Quarantine retains unrelated valid rows, but never acknowledges a failed integrity write.
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: quarantine evidence not acknowledged.', previous: $integrityFailure, ingestionReport: $report);
        }

        if (! $historical && $interval === '15m' && config('trading.pipeline_mode') === 'EVENT_DRIVEN' && in_array($symbol, (array) config('trading.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            try {
                $latestCloseTs = 0;
                foreach ($rows as $candle) {
                    if (! empty($candle['is_closed']) && ! empty($candle['open_time_utc'])) {
                        $closeTs = strtotime((string) $candle['open_time_utc']) + 15 * 60;
                        if ($closeTs > $latestCloseTs) {
                            $latestCloseTs = $closeTs;
                        }
                    }
                }
                if ($latestCloseTs > 0) {
                    $closeAt = (new DateTimeImmutable('@'.$latestCloseTs))->setTimezone(new DateTimeZone('UTC'));
                    if ($this->pipelineService->isFresh($closeAt)) {
                        $this->pipelineService->recordCandleClosed('binance', $symbol, '15', $closeAt);
                    }
                }
            } catch (Throwable $e) {
                Log::warning("BinanceCandleIngestion: failed to dispatch closed candle event for {$symbol}: ".$e->getMessage(), [
                    'exception' => $e::class,
                ]);
            }
        }

        return $report;
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

    private function observeConflict(object $stored, array $incoming, bool $quarantine, ?CandleIntegrityUnavailable &$failure, string $provenance = 'binance_public_rest'): ?array
    {
        $conflict = BinanceSourceIdentity::findCandleConflict($stored, $incoming);
        if ($conflict === null) {
            return null;
        }
        try {
            $this->conflictService->recordConflict(
                $incoming['exchange'], $incoming['category'], $incoming['symbol'], $incoming['interval'],
                $incoming['open_time'], (array) $stored, $incoming, $conflict['column'], $conflict['error'], $provenance
            );
        } catch (CandleIntegrityUnavailable $e) {
            if (! $quarantine) {
                throw $e;
            }
            $failure ??= $e;

            return $conflict;
        }
        if (! $quarantine) {
            $this->assertSameCandle($stored, $incoming);
        }
        Log::warning('Binance immutable candle quarantined', [
            'exchange' => $incoming['exchange'], 'symbol' => $incoming['symbol'],
            'interval' => $incoming['interval'], 'open_time_utc' => $incoming['open_time'],
            'conflict_column' => $conflict['column'],
        ]);

        return $conflict;
    }
}
