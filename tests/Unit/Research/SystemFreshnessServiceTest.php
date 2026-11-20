<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\Research\Application\SystemFreshnessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class SystemFreshnessServiceTest extends TestCase
{
    use CleansCandleConflictEvidence;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerConflictCleanup();
    }

    public function test_freshness_check_detects_lag_and_generates_alerts(): void
    {
        $service = new SystemFreshnessService;

        // Seed an old closed candle for BTCUSDT 15m (lagging > 30 minutes)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => now('UTC')->subHours(3),
            'open' => '65000',
            'high' => '65500',
            'low' => '64900',
            'close' => '65200',
            'volume' => '100',
            'turnover' => '6500000',
            'is_closed' => true,
        ]);

        $check = $service->check();

        self::assertIsArray($check);
        self::assertArrayHasKey('status', $check);
        self::assertArrayHasKey('candle_freshness', $check);
        self::assertArrayHasKey('alerts', $check);

        // Expect warning because 3 hours lag is >= 2 consecutive 15m intervals
        self::assertSame('WARNING', $check['status']);
        self::assertNotEmpty($check['alerts']);
        self::assertSame('MISSING_CANDLE_INTERVALS', $check['alerts'][0]['code']);
    }

    public function test_frozen_clock_timestamp_and_checked_at_represent_actual_current_utc_time_and_are_not_mutated(): void
    {
        $frozenTime = CarbonImmutable::parse('2026-10-29T21:00:00Z');
        Carbon::setTestNow($frozenTime);

        $service = new SystemFreshnessService;
        $check = $service->check();

        self::assertSame('2026-10-29T21:00:00+00:00', $check['timestamp']);
        self::assertSame('2026-10-29T21:00:00+00:00', $check['checked_at']);
        self::assertNotSame('2026-10-28T21:00:00+00:00', $check['timestamp'], 'Timestamp was mutated to yesterday');
    }

    public function test_execution_failures_24h_window_boundary_exactness_without_timestamp_mutation(): void
    {
        $account = app(PaperAccountService::class)->default();
        $frozenTime = CarbonImmutable::parse('2026-10-29T12:00:00Z');
        Carbon::setTestNow($frozenTime);

        // 1. Failure at 24h 1 minute ago (outside 24h window: 2026-10-28 11:59:00) -> should NOT be counted
        JournalEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'idempotency_key' => 'fail-1-'.Str::uuid(),
            'event_type' => 'EXECUTION_FAILED',
            'paper_account_id' => $account->id,
            'occurred_at' => $frozenTime->subHours(24)->subMinute(),
            'payload' => ['reason' => 'Too old'],
        ]);

        // 2. Failure at 23h 59m ago (inside 24h window: 2026-10-28 12:01:00) -> counted
        JournalEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'idempotency_key' => 'fail-2-'.Str::uuid(),
            'event_type' => 'EXECUTION_FAILED',
            'paper_account_id' => $account->id,
            'occurred_at' => $frozenTime->subHours(23)->subMinutes(59),
            'payload' => ['reason' => 'Recent failure 1'],
        ]);

        // 3. Failure 1 hour ago -> counted
        JournalEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'idempotency_key' => 'fail-3-'.Str::uuid(),
            'event_type' => 'EXECUTION_FAILED',
            'paper_account_id' => $account->id,
            'occurred_at' => $frozenTime->subHour(),
            'payload' => ['reason' => 'Recent failure 2'],
        ]);

        // 4. Future event (anomaly) -> should NOT be counted
        JournalEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'idempotency_key' => 'fail-4-'.Str::uuid(),
            'event_type' => 'EXECUTION_FAILED',
            'paper_account_id' => $account->id,
            'occurred_at' => $frozenTime->addHour(),
            'payload' => ['reason' => 'Future event'],
        ]);

        $service = new SystemFreshnessService;
        $check = $service->check();

        self::assertSame(2, $check['execution_failures_24h']);
        self::assertSame('2026-10-29T12:00:00+00:00', $check['timestamp']);
    }

    public function test_bybit_and_binance_freshness_isolation(): void
    {
        $frozenTime = CarbonImmutable::parse('2026-10-29T12:00:00Z');
        Carbon::setTestNow($frozenTime);

        // Seed fresh Bybit BTCUSDT 15m candle (10 minutes ago, close was 5m ago -> lag = 300s, threshold = 1800s -> FRESH)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $frozenTime->subMinutes(20),
            'open' => '70000',
            'high' => '70500',
            'low' => '69800',
            'close' => '70200',
            'volume' => '50',
            'turnover' => '3500000',
            'is_closed' => true,
        ]);

        // Seed STALE Binance BTCUSDT 15m candle (5 hours ago -> lag > 2 intervals -> STALE)
        CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $frozenTime->subHours(5),
            'open' => '70000',
            'high' => '70500',
            'low' => '69800',
            'close' => '70200',
            'volume' => '50',
            'turnover' => '3500000',
            'is_closed' => true,
        ]);

        $service = new SystemFreshnessService;

        // 1. Bybit-isolated check
        $bybitCheck = $service->check('bybit');
        $bybitBtc15 = collect($bybitCheck['candle_freshness'])->first(fn ($c) => $c['symbol'] === 'BTCUSDT' && $c['interval'] === '15');
        self::assertSame('bybit', $bybitBtc15['exchange']);
        self::assertSame('FRESH', $bybitBtc15['status']);
        self::assertFalse($bybitBtc15['is_stale']);
        // No binance records in bybit check
        self::assertEmpty(collect($bybitCheck['candle_freshness'])->filter(fn ($c) => $c['exchange'] === 'binance'));

        // 2. Binance-isolated check
        $binanceCheck = $service->check('binance');
        $binanceBtc15 = collect($binanceCheck['candle_freshness'])->first(fn ($c) => $c['symbol'] === 'BTCUSDT' && $c['interval'] === '15');
        self::assertSame('binance', $binanceBtc15['exchange']);
        self::assertSame('STALE', $binanceBtc15['status']);
        self::assertTrue($binanceBtc15['is_stale']);
        self::assertNotEmpty($binanceCheck['alerts']);
        self::assertSame('binance', $binanceCheck['alerts'][0]['exchange']);
    }

    public function test_missing_stale_conflicted_and_healthy_intervals_produce_correct_statuses_and_alerts(): void
    {
        $frozenTime = CarbonImmutable::parse('2026-10-29T12:00:00Z');
        Carbon::setTestNow($frozenTime);

        $conflictService = app(MarketCandleConflictService::class);

        // 1. FRESH interval: BTCUSDT 15m on Bybit
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $frozenTime->subMinutes(20),
            'open' => '70000', 'high' => '70500', 'low' => '69800', 'close' => '70200',
            'volume' => '50', 'turnover' => '3500000', 'is_closed' => true,
        ]);

        // 2. STALE interval: ETHUSDT 15m on Bybit (lagging 4 hours)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'interval' => '15',
            'open_time' => $frozenTime->subHours(4),
            'open' => '2500', 'high' => '2550', 'low' => '2480', 'close' => '2520',
            'volume' => '500', 'turnover' => '1250000', 'is_closed' => true,
        ]);

        // 3. CONFLICTED interval: BTCUSDT 30m on Binance
        CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '30',
            'open_time' => $frozenTime->subHours(1),
            'open' => '70000', 'high' => '70500', 'low' => '69800', 'close' => '70200',
            'volume' => '50', 'turnover' => '3500000', 'is_closed' => true,
        ]);
        $conflictService->recordConflict(
            'binance',
            'linear',
            'BTCUSDT',
            '30',
            '2026-10-27T00:00:00Z',
            ['close' => '80100'],
            ['close' => '80241.30'],
            'close',
            'Conflicting immutable Binance close observation.'
        );

        $service = new SystemFreshnessService($conflictService);

        // Check with alertOnMissing = true to verify missing intervals as well
        $check = $service->check(alertOnMissing: true);

        $items = collect($check['candle_freshness']);

        // Assert FRESH
        $fresh = $items->first(fn ($c) => $c['exchange'] === 'bybit' && $c['symbol'] === 'BTCUSDT' && $c['interval'] === '15');
        self::assertSame('FRESH', $fresh['status']);

        // Assert STALE
        $stale = $items->first(fn ($c) => $c['exchange'] === 'bybit' && $c['symbol'] === 'ETHUSDT' && $c['interval'] === '15');
        self::assertSame('STALE', $stale['status']);

        // Assert CONFLICTED
        $conflicted = $items->first(fn ($c) => $c['exchange'] === 'binance' && $c['symbol'] === 'BTCUSDT' && $c['interval'] === '30');
        self::assertSame('CONFLICTED', $conflicted['status']);

        // Assert MISSING
        $missing = $items->first(fn ($c) => $c['exchange'] === 'binance' && $c['symbol'] === 'ETHUSDT' && $c['interval'] === '60');
        self::assertSame('MISSING', $missing['status']);

        // Assert Alerts
        $alerts = collect($check['alerts']);
        self::assertTrue($alerts->contains(fn ($a) => $a['code'] === 'CANDLE_INTEGRITY_CONFLICT' && $a['exchange'] === 'binance' && $a['interval'] === '30'));
        self::assertTrue($alerts->contains(fn ($a) => $a['code'] === 'MISSING_CANDLE_INTERVALS' && $a['exchange'] === 'bybit' && $a['symbol'] === 'ETHUSDT'));
        self::assertTrue($alerts->contains(fn ($a) => $a['code'] === 'MISSING_CANDLE_INTERVALS' && $a['exchange'] === 'binance' && $a['interval'] === '60'));

        // Status is CRITICAL because of the conflict
        self::assertSame('CRITICAL', $check['status']);
    }

    public function test_api_contract_backward_compatibility(): void
    {
        $service = new SystemFreshnessService;
        $check = $service->check();

        // Exact keys required by clients and previous tests
        self::assertArrayHasKey('status', $check);
        self::assertArrayHasKey('timestamp', $check);
        self::assertArrayHasKey('checked_at', $check);
        self::assertArrayHasKey('database', $check);
        self::assertArrayHasKey('candle_freshness', $check);
        self::assertArrayHasKey('queues', $check);
        self::assertArrayHasKey('execution_failures_24h', $check);
        self::assertArrayHasKey('alerts', $check);
        self::assertArrayHasKey('conflicts', $check);
        self::assertArrayHasKey('unresolved_conflicts_count', $check);

        self::assertArrayHasKey('status', $check['database']);
        self::assertArrayHasKey('latency_ms', $check['database']);

        foreach ($check['candle_freshness'] as $item) {
            self::assertArrayHasKey('symbol', $item);
            self::assertArrayHasKey('interval', $item);
            self::assertArrayHasKey('status', $item);
            self::assertArrayHasKey('last_closed_open_time', $item);
            self::assertArrayHasKey('expected_close_time', $item);
            self::assertArrayHasKey('lag_seconds', $item);
            self::assertArrayHasKey('is_stale', $item);
        }
    }
}
