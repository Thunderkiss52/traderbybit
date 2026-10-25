<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MarketExchangeIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T12:00:00Z'));
    }

    public function test_candles_endpoint_is_isolated_by_exchange_and_caches_are_distinct(): void
    {
        // Seed distinct Bybit and Binance candles
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
            'open_time' => now('UTC')->subMinutes(30),
            'open' => '65000', 'high' => '65100', 'low' => '64900', 'close' => '65050',
            'volume' => '10', 'turnover' => '650000', 'is_closed' => true,
        ]);

        CandleRecord::query()->create([
            'exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
            'open_time' => now('UTC')->subMinutes(30),
            'open' => '67000', 'high' => '67100', 'low' => '66900', 'close' => '67050',
            'volume' => '25', 'turnover' => '1675000', 'is_closed' => true,
        ]);

        // Request Bybit candles
        $bybitResponse = $this->withHeader('X-Exchange', 'bybit')
            ->getJson('/api/v1/market/BTCUSDT/candles?interval=15')
            ->assertOk()
            ->json('data');

        self::assertCount(1, $bybitResponse);
        self::assertSame('bybit', $bybitResponse[0]['exchange']);
        self::assertDecimalSame('65000', (string) $bybitResponse[0]['open']);

        // Request Binance candles (verify cache does not return cached Bybit candles)
        $binanceResponse = $this->withHeader('X-Exchange', 'binance')
            ->getJson('/api/v1/market/BTCUSDT/candles?interval=15')
            ->assertOk()
            ->json('data');

        self::assertCount(1, $binanceResponse);
        self::assertSame('binance', $binanceResponse[0]['exchange']);
        self::assertDecimalSame('67000', (string) $binanceResponse[0]['open']);

        // Repeat requests to test cache hit
        $bybitCached = $this->withHeader('X-Exchange', 'bybit')
            ->getJson('/api/v1/market/BTCUSDT/candles?interval=15')
            ->assertOk()
            ->json('data');
        self::assertSame('bybit', $bybitCached[0]['exchange']);

        $binanceCached = $this->withHeader('X-Exchange', 'binance')
            ->getJson('/api/v1/market/BTCUSDT/candles?interval=15')
            ->assertOk()
            ->json('data');
        self::assertSame('binance', $binanceCached[0]['exchange']);
    }

    public function test_context_endpoint_scopes_by_exchange(): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'observed_at' => new DateTimeImmutable('2026-10-25T11:59:00Z'),
            'bid' => '65000', 'ask' => '65001', 'turnover_24h' => '1000000',
            'tick_size' => '0.1', 'funding_rate' => '0.0001', 'open_interest' => '10000',
        ]);

        MarketContextRecord::query()->create([
            'exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'observed_at' => new DateTimeImmutable('2026-10-25T11:59:00Z'),
            'bid' => '67000', 'ask' => '67001', 'turnover_24h' => '2000000',
            'tick_size' => '0.1', 'funding_rate' => '0.0002', 'open_interest' => '20000',
        ]);

        $bybitContext = $this->withHeader('X-Exchange', 'bybit')
            ->getJson('/api/v1/market/BTCUSDT/context')
            ->assertOk()
            ->json('data');

        self::assertSame('bybit', $bybitContext['exchange']);
        self::assertDecimalSame('65000', (string) $bybitContext['bid']);

        $binanceContext = $this->withHeader('X-Exchange', 'binance')
            ->getJson('/api/v1/market/BTCUSDT/context')
            ->assertOk()
            ->json('data');

        self::assertSame('binance', $binanceContext['exchange']);
        self::assertDecimalSame('67000', (string) $binanceContext['bid']);
    }

    public function test_dashboard_does_not_fabricate_active_or_equity_for_unprovisioned_accounts(): void
    {
        // Only provision Bybit accounts
        app(PaperAccountService::class)->allPrimaryAccounts();

        $binanceDashboard = $this->withHeader('X-Exchange', 'binance')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        self::assertSame('binance', $binanceDashboard['exchange_scope']);
        self::assertSame('TOPOLOGY_INCOMPLETE', $binanceDashboard['paper_execution_health']['system_status']);
        self::assertSame(0, $binanceDashboard['paper_execution_health']['provisioned_accounts']);

        // Check strategy portfolios: status must be UNPROVISIONED, equity must be null
        foreach ($binanceDashboard['strategy_portfolios'] as $portfolio) {
            self::assertSame('UNPROVISIONED', $portfolio['status']);
            self::assertSame('ACCOUNT_NOT_PROVISIONED', $portfolio['account_status']);
            self::assertNull($portfolio['current_equity']);
            self::assertNull($portfolio['realized_pnl']);
            self::assertNull($portfolio['unrealized_pnl']);
            self::assertNull($portfolio['id']);
        }
    }

    public function test_dashboard_system_status_reflects_actual_operational_health_dimensions(): void
    {
        // 1. Provision all 13 Bybit accounts
        app(PaperAccountService::class)->allPrimaryAccounts('bybit');

        // Without evaluations: AWAITING_FIRST_CYCLE
        $res = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('AWAITING_FIRST_CYCLE', $res['system_status']);

        // 2. Add an evaluation that is stale (> 45 minutes ago)
        \Illuminate\Support\Facades\Cache::flush();
        $now = now('UTC');
        \App\Strategy\Infrastructure\Persistence\StrategyObservationRecord::query()->create([
            'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $now->copy()->subMinutes(60),
            'reasons' => ['test'],
            'analysis' => [],
            'strategy_version' => '1.3.0',
            'configuration_hash' => hash('sha256', 'test1'),
        ]);
        $resStale = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('SCHEDULER_STALLED', $resStale['system_status']);

        // 3. Add fresh evaluation (< 45 minutes ago)
        \Illuminate\Support\Facades\Cache::flush();
        \App\Strategy\Infrastructure\Persistence\StrategyObservationRecord::query()->create([
            'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $now->copy()->subMinutes(5),
            'reasons' => ['test'],
            'analysis' => [],
            'strategy_version' => '1.3.0',
            'configuration_hash' => hash('sha256', 'test2'),
        ]);
        $resFresh = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('HEALTHY', $resFresh['system_status']);
        self::assertSame(13, $resFresh['provisioned_accounts']);
        self::assertSame(13, $resFresh['active_accounts']);
    }
}
