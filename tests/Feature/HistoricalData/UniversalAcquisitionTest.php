<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\Console\UniversalAcquisitionCommand;
use App\HistoricalData\UniversalAcquisitionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UniversalAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private UniversalAcquisitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(UniversalAcquisitionCommand::class)
        );

        $this->service = $this->app->make(UniversalAcquisitionService::class);
        $this->app->instance(UniversalAcquisitionService::class, $this->service);
    }

    public function test_plan_generation_returns_expected_chunks_and_metadata(): void
    {
        // 24-hour window on Binance 5m -> 24 * 12 = 288 candles
        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-02T00:00:00Z',
            categories: ['candles']
        );

        self::assertSame('binance', $plan['exchange']);
        self::assertSame('linear', $plan['market_type']);
        self::assertSame('linear', $plan['db_category']);
        self::assertSame('BTCUSDT', $plan['symbol']);
        self::assertSame('5', $plan['interval']);
        self::assertSame('5m', $plan['display_interval']);
        self::assertSame(300, $plan['interval_seconds']);
        self::assertSame(288, $plan['expected_candle_count']);
        self::assertNotEmpty($plan['chunks']);

        self::assertSame('1.0.0', $plan['provenance']['planner_version']);
        self::assertSame('UniversalAcquisitionService', $plan['provenance']['generator']);
        self::assertSame('NONCOMMERCIAL_RESEARCH', $plan['provenance']['license']);

        // Multi-day window across Bybit 15m -> 48 hours = 48 * 4 = 192 candles
        $bybitPlan = $this->service->plan(
            exchange: 'bybit',
            marketType: 'perpetual',
            symbol: 'ETHUSDT',
            interval: '15m',
            startDate: '2024-02-01T00:00:00Z',
            endDate: '2024-02-03T00:00:00Z',
            categories: ['candles', 'funding']
        );

        self::assertSame('bybit', $bybitPlan['exchange']);
        self::assertSame('perpetual', $bybitPlan['market_type']);
        self::assertSame('linear', $bybitPlan['db_category']);
        self::assertSame('15', $bybitPlan['interval']);
        self::assertSame(192, $bybitPlan['expected_candle_count']);
        self::assertContains('funding', $bybitPlan['categories']);

        // Coinbase Spot 1h
        $coinbasePlan = $this->service->plan(
            exchange: 'coinbase',
            marketType: 'spot',
            symbol: 'BTC-USD',
            interval: '1h',
            startDate: '2024-03-01T00:00:00Z',
            endDate: '2024-03-01T12:00:00Z',
            categories: ['candles']
        );

        self::assertSame('coinbase', $coinbasePlan['exchange']);
        self::assertSame('spot', $coinbasePlan['market_type']);
        self::assertSame('spot', $coinbasePlan['db_category']);
        self::assertSame('60', $coinbasePlan['interval']);
        self::assertSame(12, $coinbasePlan['expected_candle_count']);
    }

    public function test_dry_run_execution_verifies_zero_database_writes(): void
    {
        Http::preventStrayRequests();

        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-02T00:00:00Z',
            categories: ['candles']
        );

        $result = $this->service->acquire($plan, dryRun: true);

        self::assertTrue($result['dry_run']);
        self::assertSame('DRY_RUN_COMPLETED', $result['status']);
        self::assertSame(288, $result['expected_candles']);
        self::assertSame(0, $result['acquired_candles']);
        self::assertNull($result['dataset_pin']);

        self::assertSame(0, DB::table('market_candles')->count());
        if (Schema::hasTable('market_dataset_pins')) {
            self::assertSame(0, DB::table('market_dataset_pins')->count());
        }

        // Test dry-run via Artisan command
        $exitCode = Artisan::call('historical:universal-acquire', [
            '--exchange' => 'binance',
            '--market-type' => 'linear',
            '--symbol' => 'BTCUSDT',
            '--interval' => '5m',
            '--from' => '2024-01-01T00:00:00Z',
            '--to' => '2024-01-02T00:00:00Z',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $output = Artisan::output();
        self::assertStringContainsString('Dry-run complete', $output);
        self::assertStringContainsString('288', $output);

        self::assertSame(0, DB::table('market_candles')->count());
    }

    public function test_mock_chunk_acquisition_sha256_hashing_and_dataset_pin_creation(): void
    {
        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-01T01:00:00Z', // 1 hour = 12 candles
            categories: ['candles']
        );

        self::assertSame(12, $plan['expected_candle_count']);

        // Wire mock chunk fetcher
        $this->service->setChunkFetcher(function (array $chunk): array {
            $candles = [];
            $from = CarbonImmutable::parse($chunk['from']);
            for ($i = 0; $i < 12; $i++) {
                $time = $from->addMinutes($i * 5);
                $candles[] = [
                    'open_time' => $time->toISOString(),
                    'open' => '42000.000000000000',
                    'high' => '42100.500000000000',
                    'low' => '41950.250000000000',
                    'close' => '42050.750000000000',
                    'volume' => '12.500000000000',
                    'turnover' => '525634.375000000000',
                    'is_closed' => true,
                ];
            }

            return [
                'raw' => json_encode($candles, JSON_THROW_ON_ERROR),
                'candles' => $candles,
            ];
        });

        $result = $this->service->acquire($plan, dryRun: false);

        self::assertSame('SUCCESS', $result['status']);
        self::assertSame(12, $result['acquired_candles']);
        self::assertSame(0, $result['duplicate_candles']);
        self::assertSame(0, $result['conflicts_count']);
        self::assertSame(1, $result['chunks_acquired']);
        self::assertSame(1.0, $result['coverage']['coverage_ratio']);
        self::assertFalse($result['coverage']['has_gaps']);

        // Verify rows in market_candles
        $rows = DB::table('market_candles')
            ->where('exchange', 'binance')
            ->where('category', 'linear')
            ->where('symbol', 'BTCUSDT')
            ->where('interval', '5')
            ->orderBy('open_time')
            ->get();

        self::assertCount(12, $rows);
        self::assertSame('42000.000000000000', $rows[0]->open);
        self::assertSame('42050.750000000000', $rows[0]->close);
        self::assertTrue((bool) $rows[0]->is_closed);

        // Verify dataset pin creation in market_dataset_pins
        self::assertTrue(Schema::hasTable('market_dataset_pins'));
        $pinRecord = DB::table('market_dataset_pins')->find($result['dataset_pin']['id']);
        self::assertNotNull($pinRecord);

        self::assertSame('binance', $pinRecord->exchange);
        self::assertSame('linear', $pinRecord->market_type);
        self::assertSame('BTCUSDT', $pinRecord->symbol);
        self::assertSame('5', $pinRecord->interval);
        self::assertSame(12, (int) $pinRecord->candle_count);
        self::assertSame(64, strlen($pinRecord->sha256));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $pinRecord->sha256);
        self::assertSame($result['dataset_pin']['sha256'], $pinRecord->sha256);
    }

    public function test_idempotency_rerunning_on_existing_data_does_not_duplicate(): void
    {
        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-01T01:00:00Z',
            categories: ['candles']
        );

        $fetchCount = 0;
        $this->service->setChunkFetcher(function (array $chunk) use (&$fetchCount): array {
            $fetchCount++;
            $candles = [];
            $from = CarbonImmutable::parse($chunk['from']);
            for ($i = 0; $i < 12; $i++) {
                $time = $from->addMinutes($i * 5);
                $candles[] = [
                    'open_time' => $time->toISOString(),
                    'open' => '42000.000000000000',
                    'high' => '42100.000000000000',
                    'low' => '41900.000000000000',
                    'close' => '42050.000000000000',
                    'volume' => '10.000000000000',
                    'turnover' => '420500.000000000000',
                    'is_closed' => true,
                ];
            }

            return ['raw' => json_encode($candles), 'candles' => $candles];
        });

        // First run
        $firstResult = $this->service->acquire($plan, dryRun: false);
        self::assertSame('SUCCESS', $firstResult['status']);
        self::assertSame(12, $firstResult['acquired_candles']);
        self::assertSame(1, $fetchCount);
        self::assertSame(12, DB::table('market_candles')->count());
        self::assertSame(1, DB::table('market_dataset_pins')->count());

        $firstPinSha = $firstResult['dataset_pin']['sha256'];

        // Second run (exact re-run)
        $secondResult = $this->service->acquire($plan, dryRun: false);

        // Resumable skipped downloading because interval was already imported!
        self::assertSame(1, $fetchCount, 'Already imported interval should skip calling chunk fetcher');
        self::assertSame(0, $secondResult['acquired_candles']);
        self::assertSame(1, $secondResult['chunks_skipped']);
        self::assertSame(12, DB::table('market_candles')->count(), 'Row count must remain strictly 12 without duplicates');
        self::assertSame(1, DB::table('market_dataset_pins')->count(), 'No duplicate pin records created');
        self::assertSame($firstPinSha, $secondResult['dataset_pin']['sha256'], 'Dataset pin SHA-256 must match deterministically');
    }

    public function test_conflict_detection_and_reporting(): void
    {
        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-01T00:15:00Z', // 3 candles
            categories: ['candles']
        );

        // Ingest initial 3 candles
        $this->service->setChunkFetcher(function (): array {
            return [
                'candles' => [
                    [
                        'open_time' => '2024-01-01T00:00:00Z',
                        'open' => '40000.00', 'high' => '40100.00', 'low' => '39900.00', 'close' => '40050.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                    [
                        'open_time' => '2024-01-01T00:05:00Z',
                        'open' => '40050.00', 'high' => '40150.00', 'low' => '40000.00', 'close' => '40100.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                    [
                        'open_time' => '2024-01-01T00:10:00Z',
                        'open' => '40100.00', 'high' => '40200.00', 'low' => '40050.00', 'close' => '40150.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                ],
            ];
        });

        $this->service->acquire($plan);
        self::assertSame(3, DB::table('market_candles')->count());

        // Now attempt to ingest conflicting data for the same open_time
        // Delete 1 candle to force chunk acquisition to re-run
        DB::table('market_candles')->where('open_time', '2024-01-01T00:10:00.000Z')->delete();
        self::assertSame(2, DB::table('market_candles')->count());

        $this->service->setChunkFetcher(function (): array {
            return [
                'candles' => [
                    // Conflicting close price for 00:00:00 (45000 vs stored 40050)
                    [
                        'open_time' => '2024-01-01T00:00:00Z',
                        'open' => '40000.00', 'high' => '40100.00', 'low' => '39900.00', 'close' => '45000.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                    // Identical candle for 00:05:00
                    [
                        'open_time' => '2024-01-01T00:05:00Z',
                        'open' => '40050.00', 'high' => '40150.00', 'low' => '40000.00', 'close' => '40100.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                    // Replaced 00:10:00 candle
                    [
                        'open_time' => '2024-01-01T00:10:00Z',
                        'open' => '40100.00', 'high' => '40200.00', 'low' => '40050.00', 'close' => '40150.00',
                        'volume' => '5.0', 'turnover' => '200000.0', 'is_closed' => true,
                    ],
                ],
            ];
        });

        $reResult = $this->service->acquire($plan);

        self::assertSame(1, $reResult['conflicts_count']);
        self::assertNotEmpty($reResult['conflicts']);
        self::assertSame('close', $reResult['conflicts'][0]['column']);

        // Historical record must be immutable (not overwritten!)
        $preservedCandle = DB::table('market_candles')
            ->where('open_time', '2024-01-01T00:00:00.000Z')
            ->first();
        self::assertSame('40050.000000000000', $preservedCandle->close);

        // Conflict table should record the evidence
        if (Schema::hasTable('market_candle_conflicts')) {
            self::assertGreaterThanOrEqual(1, DB::table('market_candle_conflicts')->count());
        }
    }

    public function test_gap_and_coverage_reporting(): void
    {
        $plan = $this->service->plan(
            exchange: 'binance',
            marketType: 'linear',
            symbol: 'BTCUSDT',
            interval: '5m',
            startDate: '2024-01-01T00:00:00Z',
            endDate: '2024-01-01T00:30:00Z', // 6 candles expected
            categories: ['candles']
        );

        // Ingest candles with a deliberate gap at 00:15:00
        $this->service->setChunkFetcher(function (): array {
            return [
                'candles' => [
                    ['open_time' => '2024-01-01T00:00:00Z', 'open' => '40000', 'high' => '40100', 'low' => '39900', 'close' => '40050', 'volume' => '1', 'turnover' => '40000', 'is_closed' => true],
                    ['open_time' => '2024-01-01T00:05:00Z', 'open' => '40050', 'high' => '40150', 'low' => '40000', 'close' => '40100', 'volume' => '1', 'turnover' => '40000', 'is_closed' => true],
                    ['open_time' => '2024-01-01T00:10:00Z', 'open' => '40100', 'high' => '40200', 'low' => '40050', 'close' => '40150', 'volume' => '1', 'turnover' => '40000', 'is_closed' => true],
                    // GAP: 00:15:00 missing!
                    ['open_time' => '2024-01-01T00:20:00Z', 'open' => '40150', 'high' => '40250', 'low' => '40100', 'close' => '40200', 'volume' => '1', 'turnover' => '40000', 'is_closed' => true],
                    ['open_time' => '2024-01-01T00:25:00Z', 'open' => '40200', 'high' => '40300', 'low' => '40150', 'close' => '40250', 'volume' => '1', 'turnover' => '40000', 'is_closed' => true],
                ],
            ];
        });

        $result = $this->service->acquire($plan);

        self::assertSame(5, $result['acquired_candles']);
        self::assertSame(6, $result['coverage']['expected_candles']);
        self::assertSame(5, $result['coverage']['actual_candles']);
        self::assertTrue($result['coverage']['has_gaps']);
        self::assertSame(1, $result['coverage']['gaps_count']);
        self::assertSame(0.8333, $result['coverage']['coverage_ratio']);

        $gap = $result['coverage']['gaps'][0];
        self::assertSame('2024-01-01T00:15:00.000000Z', $gap['gap_start']);
        self::assertSame('2024-01-01T00:20:00.000000Z', $gap['gap_end']);
        self::assertSame(1, $gap['missing_bars']);
    }

    public function test_artisan_command_end_to_end_acquisition(): void
    {
        $this->service->setChunkFetcher(function (array $chunk): array {
            $candles = [];
            $from = CarbonImmutable::parse($chunk['from']);
            for ($i = 0; $i < 6; $i++) {
                $time = $from->addMinutes($i * 5);
                $candles[] = [
                    'open_time' => $time->toISOString(),
                    'open' => '42000.00', 'high' => '42100.00', 'low' => '41900.00', 'close' => '42050.00',
                    'volume' => '10.0', 'turnover' => '420500.0', 'is_closed' => true,
                ];
            }

            return ['candles' => $candles];
        });

        $exitCode = Artisan::call('historical:universal-acquire', [
            '--exchange' => 'binance',
            '--market-type' => 'linear',
            '--symbol' => 'BTCUSDT',
            '--interval' => '5m',
            '--from' => '2024-01-01T00:00:00Z',
            '--to' => '2024-01-01T00:30:00Z',
        ]);

        self::assertSame(0, $exitCode);
        $output = Artisan::output();
        self::assertStringContainsString('Acquisition completed with status: SUCCESS', $output);
        self::assertStringContainsString('Coverage Ratio', $output);
        self::assertStringContainsString('100.00%', $output);
        self::assertStringContainsString('Dataset Pin ID', $output);

        self::assertSame(6, DB::table('market_candles')->count());
        self::assertSame(1, DB::table('market_dataset_pins')->count());
    }
}
