<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\HistoricalData\Console\UniversalAcquisitionCommand;
use App\MarketData\Application\MarketCandleConflictService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class UniversalAcquisitionService
{
    public const INTERVAL_MAP = [
        '1m' => 60,
        '3m' => 180,
        '5m' => 300,
        '15m' => 900,
        '30m' => 1800,
        '1h' => 3600,
        '2h' => 7200,
        '4h' => 14400,
        '6h' => 21600,
        '12h' => 43200,
        '1d' => 86400,
        '1w' => 604800,
    ];

    public const DB_INTERVAL_MAP = [
        '1m' => '1',
        '3m' => '3',
        '5m' => '5',
        '15m' => '15',
        '30m' => '30',
        '1h' => '60',
        '2h' => '120',
        '4h' => '240',
        '6h' => '360',
        '12h' => '720',
        '1d' => '1440',
        '1w' => '10080',
    ];

    private mixed $chunkFetcher;

    public function __construct(
        private readonly ?OfficialTransport $transport = null,
        private readonly ?MarketCandleConflictService $conflictService = null,
        ?callable $chunkFetcher = null,
    ) {
        $this->chunkFetcher = $chunkFetcher;

        if (app()->runningInConsole() && app()->bound(Kernel::class)) {
            try {
                app(Kernel::class)->registerCommand(
                    app(UniversalAcquisitionCommand::class)
                );
            } catch (\Throwable) {
                // Command registration will be handled by caller or test setup.
            }
        }
    }

    public function setChunkFetcher(?callable $fetcher): self
    {
        $this->chunkFetcher = $fetcher;

        return $this;
    }

    /**
     * Plan historical acquisition chunks, expected candle counts, and provenance.
     *
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    public function plan(
        string $exchange,
        string $marketType,
        string $symbol,
        string $interval,
        string $startDate,
        string $endDate,
        array $categories = ['candles']
    ): array {
        $exchange = strtolower(trim($exchange));
        $marketType = strtolower(trim($marketType));
        $symbol = strtoupper(trim($symbol));
        $normInterval = self::normalizeInterval($interval);
        $displayInterval = self::displayInterval($interval);
        $intervalSeconds = self::intervalToSeconds($interval);

        $from = CarbonImmutable::parse($startDate)->utc();
        $to = CarbonImmutable::parse($endDate)->utc();

        if ($to <= $from) {
            throw new InvalidArgumentException("End date ({$endDate}) must be strictly after start date ({$startDate}).");
        }

        // Support inclusive .999 precision cleanly
        $toExclusive = ($to->format('v') === '999' || $to->getTimestampMs() % 1000 === 999)
            ? $to->addMillisecond()
            : $to;

        $dbCategory = $this->resolveDbCategory($marketType);
        $totalSeconds = $toExclusive->getTimestamp() - $from->getTimestamp();
        $expectedCandles = intdiv($totalSeconds, $intervalSeconds);

        $chunks = $this->buildChunks(
            exchange: $exchange,
            marketType: $marketType,
            dbCategory: $dbCategory,
            symbol: $symbol,
            normInterval: $normInterval,
            displayInterval: $displayInterval,
            intervalSeconds: $intervalSeconds,
            from: $from,
            toExclusive: $toExclusive,
            categories: $categories
        );

        $provenance = [
            'planner_version' => '1.0.0',
            'generator' => 'UniversalAcquisitionService',
            'planned_at' => CarbonImmutable::now('UTC')->toISOString(),
            'exchange' => $exchange,
            'market_type' => $marketType,
            'symbol' => $symbol,
            'interval' => $displayInterval,
            'license' => 'NONCOMMERCIAL_RESEARCH',
            'intended_use' => 'RESEARCH_AND_LEARNING',
            'categories' => $categories,
        ];

        return [
            'exchange' => $exchange,
            'market_type' => $marketType,
            'db_category' => $dbCategory,
            'symbol' => $symbol,
            'interval' => $normInterval,
            'display_interval' => $displayInterval,
            'interval_seconds' => $intervalSeconds,
            'start_date' => $from->toISOString(),
            'end_date' => $toExclusive->toISOString(),
            'categories' => $categories,
            'expected_candle_count' => $expectedCandles,
            'chunks' => $chunks,
            'provenance' => $provenance,
        ];
    }

    /**
     * Execute acquisition, SHA-256 verification, persistence with conflict detection, and pinning.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function acquire(
        array $plan,
        bool $dryRun = false,
        ?callable $progressCallback = null
    ): array {
        if ($dryRun) {
            return [
                'status' => 'DRY_RUN_COMPLETED',
                'dry_run' => true,
                'exchange' => $plan['exchange'],
                'market_type' => $plan['market_type'],
                'symbol' => $plan['symbol'],
                'interval' => $plan['interval'],
                'from' => $plan['start_date'],
                'to' => $plan['end_date'],
                'expected_candles' => $plan['expected_candle_count'],
                'acquired_candles' => 0,
                'duplicate_candles' => 0,
                'conflicts_count' => 0,
                'chunks_total' => count($plan['chunks']),
                'chunks_acquired' => 0,
                'chunks_skipped' => 0,
                'missing_chunks' => [],
                'coverage' => [
                    'expected_candles' => $plan['expected_candle_count'],
                    'actual_candles' => 0,
                    'coverage_ratio' => 0.0,
                    'has_gaps' => true,
                    'gaps_count' => 0,
                    'gaps' => [],
                ],
                'dataset_pin' => null,
                'provenance' => $plan['provenance'],
            ];
        }

        $this->ensurePinsTableExists();

        $chunksTotal = count($plan['chunks']);
        $chunksAcquired = 0;
        $chunksSkipped = 0;
        $candlesAcquired = 0;
        $candlesDuplicate = 0;
        $conflictsCount = 0;
        $conflicts = [];
        $missingChunks = [];
        $hashes = [];

        foreach ($plan['chunks'] as $index => $chunk) {
            $isAlreadyImported = false;

            if ($chunk['category'] === 'candles' && $chunk['expected_candles'] > 0) {
                $existingCount = DB::table('market_candles')
                    ->where('exchange', $chunk['exchange'])
                    ->where('category', $chunk['db_category'])
                    ->where('symbol', $chunk['symbol'])
                    ->where('interval', $chunk['interval'])
                    ->where('open_time', '>=', $chunk['from'])
                    ->where('open_time', '<', $chunk['to'])
                    ->where('is_closed', true)
                    ->count();

                if ($existingCount >= $chunk['expected_candles']) {
                    $isAlreadyImported = true;
                }
            }

            if ($isAlreadyImported) {
                $chunksSkipped++;
                $hashes[$chunk['id']] = $this->hashExistingCandles($chunk);

                if ($progressCallback !== null) {
                    $progressCallback($index, $chunksTotal, $chunk, ['status' => 'SKIPPED_ALREADY_IMPORTED']);
                }

                continue;
            }

            $data = $this->fetchChunkData($chunk);

            if ($data === null) {
                $missingChunks[] = $chunk['id'];

                if ($progressCallback !== null) {
                    $progressCallback($index, $chunksTotal, $chunk, ['status' => 'FAILED_OR_MISSING']);
                }

                continue;
            }

            $chunkHash = $data['sha256'] ?? hash('sha256', (string) ($data['raw'] ?? json_encode($data['candles'] ?? $data, JSON_THROW_ON_ERROR)));
            $hashes[$chunk['id']] = $chunkHash;

            if ($chunk['category'] === 'candles') {
                $candles = $data['candles'] ?? (is_array($data) ? $data : []);
                $persistResult = $this->persistCandles($chunk, $candles);

                $candlesAcquired += $persistResult['inserted'];
                $candlesDuplicate += $persistResult['duplicates'];
                $conflictsCount += $persistResult['conflicts_count'];
                $conflicts = array_merge($conflicts, $persistResult['conflicts']);
                $chunksAcquired++;
            } elseif ($chunk['category'] === 'funding') {
                $fundingRows = $data['funding'] ?? (is_array($data) ? $data : []);
                $this->persistFunding($chunk, $fundingRows);
                $chunksAcquired++;
            } elseif ($chunk['category'] === 'open_interest') {
                $oiRows = $data['open_interest'] ?? (is_array($data) ? $data : []);
                $this->persistOpenInterest($chunk, $oiRows);
                $chunksAcquired++;
            }

            if ($progressCallback !== null) {
                $progressCallback($index, $chunksTotal, $chunk, ['status' => 'ACQUIRED']);
            }
        }

        $coverage = $this->calculateCoverage(
            exchange: $plan['exchange'],
            dbCategory: $plan['db_category'],
            symbol: $plan['symbol'],
            interval: $plan['interval'],
            fromIso: $plan['start_date'],
            toIso: $plan['end_date'],
            expectedCandleCount: (int) $plan['expected_candle_count'],
            missingChunks: $missingChunks
        );

        $existingPin = DB::table('market_dataset_pins')
            ->where('exchange', $plan['exchange'])
            ->where('market_type', $plan['market_type'])
            ->where('symbol', $plan['symbol'])
            ->where('interval', $plan['interval'])
            ->where('from_time', $plan['start_date'])
            ->where('to_time', $plan['end_date'])
            ->first();

        // Deterministic dataset checksum
        if ($existingPin !== null && $chunksAcquired === 0) {
            $datasetSha256 = (string) $existingPin->sha256;
        } else {
            ksort($hashes);
            $datasetHashCtx = hash_init('sha256');
            foreach ($hashes as $cid => $chash) {
                hash_update($datasetHashCtx, "{$cid}:{$chash}\n");
            }
            $datasetSha256 = hash_final($datasetHashCtx);
        }

        $pin = $this->createDatasetPin(
            plan: $plan,
            datasetSha256: $datasetSha256,
            actualCandleCount: $coverage['actual_candles'],
            coverage: $coverage,
            provenance: $plan['provenance'],
            existingPin: $existingPin
        );

        $status = 'SUCCESS';
        if (! empty($missingChunks)) {
            $status = $chunksAcquired > 0 ? 'PARTIAL' : 'FAILED';
        } elseif ($conflictsCount > 0) {
            $status = 'PARTIAL_WITH_CONFLICTS';
        }

        return [
            'status' => $status,
            'dry_run' => false,
            'exchange' => $plan['exchange'],
            'market_type' => $plan['market_type'],
            'symbol' => $plan['symbol'],
            'interval' => $plan['interval'],
            'from' => $plan['start_date'],
            'to' => $plan['end_date'],
            'expected_candles' => $plan['expected_candle_count'],
            'acquired_candles' => $candlesAcquired,
            'duplicate_candles' => $candlesDuplicate,
            'conflicts_count' => $conflictsCount,
            'conflicts' => $conflicts,
            'chunks_total' => $chunksTotal,
            'chunks_acquired' => $chunksAcquired,
            'chunks_skipped' => $chunksSkipped,
            'missing_chunks' => $missingChunks,
            'coverage' => $coverage,
            'hashes' => $hashes,
            'dataset_pin' => $pin,
            'provenance' => $plan['provenance'],
        ];
    }

    /**
     * Ensure market_dataset_pins table exists in PostgreSQL.
     */
    public function ensurePinsTableExists(): void
    {
        if (! Schema::hasTable('market_dataset_pins')) {
            Schema::create('market_dataset_pins', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('exchange', 32);
                $table->string('market_type', 32);
                $table->string('symbol', 32);
                $table->string('interval', 16);
                $table->timestampTz('from_time');
                $table->timestampTz('to_time');
                $table->unsignedBigInteger('candle_count')->default(0);
                $table->string('sha256', 64);
                $table->json('categories')->nullable();
                $table->json('provenance')->nullable();
                $table->json('coverage')->nullable();
                $table->timestampTz('pinned_at');
                $table->timestampsTz();

                $table->unique(
                    ['exchange', 'market_type', 'symbol', 'interval', 'from_time', 'to_time'],
                    'uq_market_dataset_pins_scope'
                );
                $table->index(['exchange', 'symbol', 'interval'], 'idx_market_dataset_pins_lookup');
            });
        }
    }

    /**
     * Persist candle rows to market_candles with conflict detection and idempotency.
     *
     * @param  array<string, mixed>  $chunk
     * @param  list<array<string, mixed>>  $candles
     * @return array{inserted: int, duplicates: int, conflicts_count: int, conflicts: list<array<string, mixed>>}
     */
    private function persistCandles(array $chunk, array $candles): array
    {
        $inserted = 0;
        $duplicates = 0;
        $conflicts = [];
        $exchange = $chunk['exchange'];
        $category = $chunk['db_category'];
        $symbol = $chunk['symbol'];
        $interval = $chunk['interval'];

        $openTimes = [];
        $formattedRows = [];
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');

        foreach ($candles as $c) {
            $openTimeStr = CarbonImmutable::parse($c['open_time'])->toISOString();
            $openTimes[] = $openTimeStr;
            $formattedRows[$openTimeStr] = [
                'exchange' => $exchange,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => $openTimeStr,
                'open' => (string) $c['open'],
                'high' => (string) $c['high'],
                'low' => (string) $c['low'],
                'close' => (string) $c['close'],
                'volume' => (string) ($c['volume'] ?? '0'),
                'turnover' => (string) ($c['turnover'] ?? '0'),
                'is_closed' => (bool) ($c['is_closed'] ?? true),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($formattedRows)) {
            return [
                'inserted' => 0,
                'duplicates' => 0,
                'conflicts_count' => 0,
                'conflicts' => [],
            ];
        }

        $existingRows = DB::table('market_candles')
            ->where('exchange', $exchange)
            ->where('category', $category)
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->whereIn('open_time', $openTimes)
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->open_time)->toISOString());

        $toInsert = [];
        foreach ($formattedRows as $timeStr => $row) {
            if ($existingRow = $existingRows->get($timeStr)) {
                $conflictCol = null;
                foreach (['open', 'high', 'low', 'close', 'volume', 'turnover'] as $col) {
                    if (isset($row[$col]) && isset($existingRow->{$col})) {
                        if (! BigDecimal::of((string) $row[$col])->isEqualTo((string) $existingRow->{$col})) {
                            $conflictCol = $col;
                            break;
                        }
                    }
                }

                if ($conflictCol !== null) {
                    $this->recordConflict(
                        exchange: $exchange,
                        category: $category,
                        symbol: $symbol,
                        interval: $interval,
                        openTimeUtc: $timeStr,
                        storedValues: (array) $existingRow,
                        incomingValues: $row,
                        conflictColumn: $conflictCol,
                        errorMessage: "Value mismatch on {$conflictCol}: stored {$existingRow->{$conflictCol}} vs incoming {$row[$conflictCol]}"
                    );
                    $conflicts[] = [
                        'open_time' => $timeStr,
                        'column' => $conflictCol,
                        'stored' => $existingRow->{$conflictCol},
                        'incoming' => $row[$conflictCol],
                    ];
                } else {
                    $duplicates++;
                }
            } else {
                $toInsert[] = $row;
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 500) as $chunkRows) {
                DB::table('market_candles')->insert($chunkRows);
                $inserted += count($chunkRows);
            }
        }

        return [
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'conflicts_count' => count($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Persist funding observations if the table exists.
     */
    private function persistFunding(array $chunk, array $rows): void
    {
        if (Schema::hasTable('historical_funding_observations')) {
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            $pending = [];
            foreach ($rows as $r) {
                $fundingAt = CarbonImmutable::parse($r['funding_at'] ?? $r['time'])->toISOString();
                $pending[] = [
                    'exchange' => $chunk['exchange'],
                    'category' => $chunk['db_category'],
                    'symbol' => $chunk['symbol'],
                    'funding_at' => $fundingAt,
                    'rate' => (string) ($r['rate'] ?? $r['fundingRate']),
                    'interval_hours' => $r['hours'] ?? 8,
                    'mark_price' => isset($r['mark_price']) ? (string) $r['mark_price'] : null,
                    'created_at' => $now,
                ];
            }
            if (! empty($pending)) {
                foreach (array_chunk($pending, 500) as $chunkRows) {
                    DB::table('historical_funding_observations')->insertOrIgnore($chunkRows);
                }
            }
        }
    }

    /**
     * Persist open interest observations if the table exists.
     */
    private function persistOpenInterest(array $chunk, array $rows): void
    {
        if (Schema::hasTable('market_open_interest_events')) {
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            $pending = [];
            foreach ($rows as $r) {
                $observedAt = CarbonImmutable::parse($r['observed_at'] ?? $r['time'])->toISOString();
                $pending[] = [
                    'exchange' => $chunk['exchange'],
                    'category' => $chunk['db_category'],
                    'symbol' => $chunk['symbol'],
                    'interval' => $chunk['interval'],
                    'observed_at' => $observedAt,
                    'open_interest' => (string) ($r['open_interest'] ?? $r['openInterest']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (! empty($pending)) {
                foreach (array_chunk($pending, 500) as $chunkRows) {
                    DB::table('market_open_interest_events')->insertOrIgnore($chunkRows);
                }
            }
        }
    }

    /**
     * Record candle conflict durably into market_candle_conflicts.
     */
    private function recordConflict(
        string $exchange,
        string $category,
        string $symbol,
        string $interval,
        string $openTimeUtc,
        array $storedValues,
        array $incomingValues,
        ?string $conflictColumn,
        string $errorMessage
    ): void {
        try {
            if ($this->conflictService !== null) {
                $this->conflictService->recordConflict(
                    exchange: $exchange,
                    category: $category,
                    symbol: $symbol,
                    interval: $interval,
                    openTimeUtc: $openTimeUtc,
                    storedValues: $storedValues,
                    incomingValues: $incomingValues,
                    conflictColumn: $conflictColumn,
                    errorMessage: $errorMessage,
                    sourceProvenance: 'universal_acquisition'
                );

                return;
            }
        } catch (\Throwable) {
            // Fall back to direct database insertion below
        }

        if (Schema::hasTable('market_candle_conflicts')) {
            DB::table('market_candle_conflicts')->updateOrInsert(
                [
                    'exchange' => $exchange,
                    'category' => $category,
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time' => $openTimeUtc,
                ],
                [
                    'stored_values' => json_encode($storedValues, JSON_THROW_ON_ERROR),
                    'incoming_values' => json_encode($incomingValues, JSON_THROW_ON_ERROR),
                    'conflict_column' => $conflictColumn,
                    'error_message' => $errorMessage,
                    'source_provenance' => 'universal_acquisition',
                    'payload_fingerprint' => hash('sha256', json_encode([$storedValues, $incomingValues], JSON_THROW_ON_ERROR)),
                    'first_seen_at' => CarbonImmutable::now('UTC')->toISOString(),
                    'last_seen_at' => CarbonImmutable::now('UTC')->toISOString(),
                    'occurrence_count' => 1,
                    'status' => 'UNRESOLVED',
                    'created_at' => CarbonImmutable::now('UTC')->toISOString(),
                    'updated_at' => CarbonImmutable::now('UTC')->toISOString(),
                ]
            );
        }
    }

    /**
     * Fetch chunk data using chunk fetcher callback or HTTP adapter.
     */
    private function fetchChunkData(array $chunk): ?array
    {
        if ($this->chunkFetcher !== null) {
            $result = ($this->chunkFetcher)($chunk);
            if ($result === null) {
                return null;
            }
            if (is_string($result)) {
                $sha256 = hash('sha256', $result);
                $candles = $this->parseRawContent($result, $chunk);

                return ['raw' => $result, 'sha256' => $sha256, 'candles' => $candles];
            }
            if (is_array($result)) {
                if (isset($result['candles'])) {
                    $raw = $result['raw'] ?? json_encode($result['candles'], JSON_THROW_ON_ERROR);
                    $sha256 = $result['sha256'] ?? hash('sha256', (string) $raw);

                    return ['raw' => $raw, 'sha256' => $sha256, 'candles' => $result['candles']];
                }

                $raw = json_encode($result, JSON_THROW_ON_ERROR);

                return ['raw' => $raw, 'sha256' => hash('sha256', $raw), 'candles' => $result];
            }
        }

        if (! empty($chunk['source_url'])) {
            try {
                $response = Http::timeout(15)->get($chunk['source_url']);
                if ($response->successful()) {
                    $body = $response->body();
                    $sha256 = hash('sha256', $body);
                    $candles = $this->parseRawContent($body, $chunk);

                    return ['raw' => $body, 'sha256' => $sha256, 'candles' => $candles];
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parse raw string content (JSON or CSV) into uniform candle rows.
     */
    private function parseRawContent(string $raw, array $chunk): array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Handle Bybit format: result.list
            if (isset($decoded['result']['list']) && is_array($decoded['result']['list'])) {
                $rows = [];
                foreach ($decoded['result']['list'] as $item) {
                    $rows[] = [
                        'open_time' => CarbonImmutable::createFromTimestampMs((int) $item[0], 'UTC')->toISOString(),
                        'open' => (string) $item[1],
                        'high' => (string) $item[2],
                        'low' => (string) $item[3],
                        'close' => (string) $item[4],
                        'volume' => (string) $item[5],
                        'turnover' => (string) ($item[6] ?? '0'),
                        'is_closed' => true,
                    ];
                }

                return $rows;
            }

            // Handle Binance format: array of arrays [[time, o, h, l, c, v, close_time, turnover, ...]]
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $rows = [];
                foreach ($decoded as $item) {
                    if (count($item) >= 6) {
                        $rows[] = [
                            'open_time' => CarbonImmutable::createFromTimestampMs((int) $item[0], 'UTC')->toISOString(),
                            'open' => (string) $item[1],
                            'high' => (string) $item[2],
                            'low' => (string) $item[3],
                            'close' => (string) $item[4],
                            'volume' => (string) $item[5],
                            'turnover' => (string) ($item[7] ?? '0'),
                            'is_closed' => true,
                        ];
                    }
                }

                return $rows;
            }

            // Handle dictionary of candles
            if (isset($decoded[0]['open_time'])) {
                return $decoded;
            }
        }

        // CSV parsing
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $candles = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'open_time')) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) >= 5) {
                $candles[] = [
                    'open_time' => is_numeric($parts[0])
                        ? CarbonImmutable::createFromTimestampMs((int) $parts[0], 'UTC')->toISOString()
                        : CarbonImmutable::parse($parts[0])->toISOString(),
                    'open' => (string) $parts[1],
                    'high' => (string) $parts[2],
                    'low' => (string) $parts[3],
                    'close' => (string) $parts[4],
                    'volume' => (string) ($parts[5] ?? '0'),
                    'turnover' => (string) ($parts[6] ?? '0'),
                    'is_closed' => true,
                ];
            }
        }

        return $candles;
    }

    /**
     * Compute deterministic hash of existing closed candles for a chunk.
     */
    private function hashExistingCandles(array $chunk): string
    {
        $candles = DB::table('market_candles')
            ->where('exchange', $chunk['exchange'])
            ->where('category', $chunk['db_category'])
            ->where('symbol', $chunk['symbol'])
            ->where('interval', $chunk['interval'])
            ->where('open_time', '>=', $chunk['from'])
            ->where('open_time', '<', $chunk['to'])
            ->where('is_closed', true)
            ->orderBy('open_time')
            ->get();

        $ctx = hash_init('sha256');
        foreach ($candles as $c) {
            hash_update($ctx, implode('|', [
                $chunk['symbol'],
                $chunk['interval'],
                CarbonImmutable::parse($c->open_time)->toISOString(),
                $c->open,
                $c->high,
                $c->low,
                $c->close,
                $c->volume,
                $c->turnover,
            ])."\n");
        }

        return hash_final($ctx);
    }

    /**
     * Calculate coverage ratio and detect series gaps.
     */
    private function calculateCoverage(
        string $exchange,
        string $dbCategory,
        string $symbol,
        string $interval,
        string $fromIso,
        string $toIso,
        int $expectedCandleCount,
        array $missingChunks
    ): array {
        $actualCandles = DB::table('market_candles')
            ->where('exchange', $exchange)
            ->where('category', $dbCategory)
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('open_time', '>=', $fromIso)
            ->where('open_time', '<', $toIso)
            ->where('is_closed', true)
            ->orderBy('open_time')
            ->get(['open_time']);

        $actualCount = $actualCandles->count();
        $coverageRatio = $expectedCandleCount > 0
            ? round($actualCount / $expectedCandleCount, 4)
            : 1.0;

        $stepSec = self::intervalToSeconds($interval);
        $gaps = [];
        $prevTime = null;

        foreach ($actualCandles as $candle) {
            $currentTime = CarbonImmutable::parse($candle->open_time)->getTimestamp();
            if ($prevTime !== null) {
                $diff = $currentTime - $prevTime;
                if ($diff > $stepSec) {
                    $gaps[] = [
                        'gap_start' => CarbonImmutable::createFromTimestamp($prevTime + $stepSec, 'UTC')->toISOString(),
                        'gap_end' => CarbonImmutable::createFromTimestamp($currentTime, 'UTC')->toISOString(),
                        'missing_seconds' => $diff - $stepSec,
                        'missing_bars' => intdiv($diff - $stepSec, $stepSec),
                    ];
                }
            }
            $prevTime = $currentTime;
        }

        return [
            'expected_candles' => $expectedCandleCount,
            'actual_candles' => $actualCount,
            'coverage_ratio' => $coverageRatio,
            'has_gaps' => ! empty($gaps) || ($actualCount < $expectedCandleCount) || ! empty($missingChunks),
            'gaps_count' => count($gaps),
            'gaps' => $gaps,
            'missing_chunks' => $missingChunks,
        ];
    }

    /**
     * Create an immutable dataset pin in market_dataset_pins and historical_dataset_pins.
     */
    private function createDatasetPin(
        array $plan,
        string $datasetSha256,
        int $actualCandleCount,
        array $coverage,
        array $provenance,
        ?object $existingPin = null
    ): array {
        $exchange = $plan['exchange'];
        $marketType = $plan['market_type'];
        $symbol = $plan['symbol'];
        $interval = $plan['interval'];
        $fromTime = $plan['start_date'];
        $toTime = $plan['end_date'];

        if ($existingPin !== null) {
            return [
                'id' => (string) $existingPin->id,
                'exchange' => (string) $existingPin->exchange,
                'symbol' => (string) $existingPin->symbol,
                'interval' => (string) $existingPin->interval,
                'candle_count' => (int) $existingPin->candle_count,
                'sha256' => (string) $existingPin->sha256,
                'pinned_at' => (string) $existingPin->pinned_at,
                'table' => 'market_dataset_pins',
            ];
        }

        $pinId = (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "traderbybit:market-dataset-pin:{$exchange}:{$marketType}:{$symbol}:{$interval}:{$fromTime}:{$toTime}:{$datasetSha256}"
        );

        $nowIso = CarbonImmutable::now('UTC')->toISOString();
        $now = CarbonImmutable::now('UTC');

        $record = [
            'id' => $pinId,
            'exchange' => $exchange,
            'market_type' => $marketType,
            'symbol' => $symbol,
            'interval' => $interval,
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'candle_count' => $actualCandleCount,
            'sha256' => $datasetSha256,
            'categories' => json_encode($plan['categories'], JSON_THROW_ON_ERROR),
            'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
            'coverage' => json_encode($coverage, JSON_THROW_ON_ERROR),
            'pinned_at' => $nowIso,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('market_dataset_pins')->updateOrInsert(
            [
                'exchange' => $exchange,
                'market_type' => $marketType,
                'symbol' => $symbol,
                'interval' => $interval,
                'from_time' => $fromTime,
                'to_time' => $toTime,
            ],
            $record
        );

        if (Schema::hasTable('historical_dataset_pins')) {
            $scopeFingerprint = hash('sha256', implode('|', [
                $exchange,
                $plan['db_category'],
                $symbol,
                $fromTime,
                $toTime,
                '0',
            ]));

            DB::table('historical_dataset_pins')->updateOrInsert(
                ['scope_fingerprint' => $scopeFingerprint],
                [
                    'id' => $pinId,
                    'exchange' => $exchange,
                    'category' => $plan['db_category'],
                    'symbols' => json_encode([$symbol], JSON_THROW_ON_ERROR),
                    'from_at' => $fromTime,
                    'to_at' => $toTime,
                    'warmup_bars' => 0,
                    'checksum' => $datasetSha256,
                    'warnings' => json_encode([], JSON_THROW_ON_ERROR),
                    'series_counts' => json_encode(["{$symbol}|{$interval}" => $actualCandleCount], JSON_THROW_ON_ERROR),
                    'pinned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        return [
            'id' => $pinId,
            'exchange' => $exchange,
            'symbol' => $symbol,
            'interval' => $interval,
            'candle_count' => $actualCandleCount,
            'sha256' => $datasetSha256,
            'pinned_at' => $nowIso,
            'table' => 'market_dataset_pins',
        ];
    }

    /**
     * Build planned chunk intervals partitioned daily or monthly based on total span.
     */
    private function buildChunks(
        string $exchange,
        string $marketType,
        string $dbCategory,
        string $symbol,
        string $normInterval,
        string $displayInterval,
        int $intervalSeconds,
        CarbonImmutable $from,
        CarbonImmutable $toExclusive,
        array $categories
    ): array {
        $chunks = [];
        $totalSeconds = $toExclusive->getTimestamp() - $from->getTimestamp();

        $windows = [];
        if ($totalSeconds <= 86400) {
            $windows[] = [$from, $toExclusive];
        } elseif ($totalSeconds <= 30 * 86400) {
            for ($curr = $from->startOfDay(); $curr < $toExclusive; $curr = $curr->addDay()) {
                $wStart = $from->max($curr);
                $wEnd = $toExclusive->min($curr->addDay());
                if ($wStart < $wEnd) {
                    $windows[] = [$wStart, $wEnd];
                }
            }
        } else {
            for ($curr = $from->startOfMonth(); $curr < $toExclusive; $curr = $curr->addMonth()) {
                $wStart = $from->max($curr);
                $wEnd = $toExclusive->min($curr->addMonth());
                if ($wStart < $wEnd) {
                    $windows[] = [$wStart, $wEnd];
                }
            }
        }

        foreach ($windows as [$wStart, $wEnd]) {
            foreach ($categories as $cat) {
                $chunkId = sprintf(
                    '%s_%s_%s_%s_%s_%s',
                    $exchange,
                    $symbol,
                    $normInterval,
                    $cat,
                    $wStart->format('YmdHi'),
                    $wEnd->format('YmdHi')
                );
                $chunkExpected = ($cat === 'candles')
                    ? intdiv($wEnd->getTimestamp() - $wStart->getTimestamp(), $intervalSeconds)
                    : 0;

                $chunks[] = [
                    'id' => $chunkId,
                    'category' => $cat,
                    'exchange' => $exchange,
                    'market_type' => $marketType,
                    'db_category' => $dbCategory,
                    'symbol' => $symbol,
                    'interval' => $normInterval,
                    'display_interval' => $displayInterval,
                    'from' => $wStart->toISOString(),
                    'to' => $wEnd->toISOString(),
                    'from_ts' => $wStart->getTimestamp(),
                    'to_ts' => $wEnd->getTimestamp(),
                    'expected_candles' => $chunkExpected,
                    'source_url' => $this->resolveSourceUrl(
                        exchange: $exchange,
                        marketType: $marketType,
                        symbol: $symbol,
                        interval: $displayInterval,
                        category: $cat,
                        start: $wStart,
                        end: $wEnd
                    ),
                ];
            }
        }

        return $chunks;
    }

    /**
     * Resolve default public REST or archive URL for exchange.
     */
    private function resolveSourceUrl(
        string $exchange,
        string $marketType,
        string $symbol,
        string $interval,
        string $category,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): string {
        $startMs = $start->getTimestampMs();
        $endMs = $end->getTimestampMs();

        return match ($exchange) {
            'bybit' => "https://api.bybit.com/v5/market/kline?category={$this->resolveDbCategory($marketType)}&symbol={$symbol}&interval={$this->normalizeInterval($interval)}&start={$startMs}&end={$endMs}&limit=1000",
            'binance' => ($marketType === 'spot')
                ? "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&startTime={$startMs}&endTime={$endMs}&limit=1000"
                : "https://fapi.binance.com/fapi/v1/klines?symbol={$symbol}&interval={$interval}&startTime={$startMs}&endTime={$endMs}&limit=1500",
            'coinbase' => "https://api.exchange.coinbase.com/products/{$symbol}/candles?granularity=".self::intervalToSeconds($interval)."&start={$start->toISOString()}&end={$end->toISOString()}",
            'okx' => "https://www.okx.com/api/v5/market/history-candles?instId={$symbol}&bar={$interval}&before={$startMs}&after={$endMs}&limit=100",
            default => "https://api.{$exchange}.com/market/candles?symbol={$symbol}&interval={$interval}&from={$startMs}&to={$endMs}",
        };
    }

    /**
     * Normalize timeframe string to database interval (e.g. '5m' -> '5').
     */
    public static function normalizeInterval(string $interval): string
    {
        $interval = strtolower(trim($interval));

        return self::DB_INTERVAL_MAP[$interval] ?? $interval;
    }

    /**
     * Display timeframe with unit (e.g. '5' -> '5m', '60' -> '1h').
     */
    public static function displayInterval(string $interval): string
    {
        $norm = self::normalizeInterval($interval);
        $flipped = array_flip(self::DB_INTERVAL_MAP);

        return $flipped[$norm] ?? $interval;
    }

    /**
     * Convert timeframe to seconds.
     */
    public static function intervalToSeconds(string $interval): int
    {
        $display = self::displayInterval($interval);
        if (isset(self::INTERVAL_MAP[$display])) {
            return self::INTERVAL_MAP[$display];
        }

        $norm = self::normalizeInterval($interval);
        if (is_numeric($norm)) {
            return ((int) $norm) * 60;
        }

        return 300;
    }

    /**
     * Resolve database category column value (e.g. 'linear' vs 'spot').
     */
    public static function resolveDbCategory(string $marketType): string
    {
        $marketType = strtolower(trim($marketType));

        return match ($marketType) {
            'spot' => 'spot',
            'futures', 'futures/um' => 'futures',
            'delivery' => 'delivery',
            default => 'linear',
        };
    }
}
