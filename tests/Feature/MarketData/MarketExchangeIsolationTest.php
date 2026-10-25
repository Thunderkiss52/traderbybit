<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
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
        $accounts = app(PaperAccountService::class)->allPrimaryAccounts('bybit');

        // Without evaluations: AWAITING_FIRST_CYCLE
        $res = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('AWAITING_FIRST_CYCLE', $res['system_status']);

        $now = now('UTC');
        $primaryStrategies = (array) config('paper.primary_strategies', []);

        // 2. Seed evaluations for all 13 strategies, but ONLY seed BTCUSDT candle (ETHUSDT candle missing)
        foreach ($primaryStrategies as $stratId) {
            StrategyObservationRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => $stratId,
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 90,
                'decision_time' => $now->copy()->subMinutes(5),
                'reasons' => ['test'],
                'analysis' => [],
                'strategy_version' => '1.0.0',
                'configuration_hash' => hash('sha256', "test-{$stratId}"),
            ]);
        }
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => $now->copy()->subMinutes(5),
            'open' => '65000', 'high' => '65100', 'low' => '64900', 'close' => '65050',
            'volume' => '10', 'turnover' => '650000', 'is_closed' => true,
        ]);

        Cache::flush();
        $resMissingSymbol = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('MARKET_DATA_STALLED', $resMissingSymbol['system_status']);
        self::assertContains('ETHUSDT', $resMissingSymbol['stalled_symbols']);
        self::assertNotContains('BTCUSDT', $resMissingSymbol['stalled_symbols']);

        // 3. Seed fresh ETHUSDT candle too, but make 1 strategy (trend_pullback) stalled by deleting its fresh evaluation and creating an old one (>45m)
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'ETHUSDT', 'interval' => '5',
            'open_time' => $now->copy()->subMinutes(5),
            'open' => '3500', 'high' => '3510', 'low' => '3490', 'close' => '3505',
            'volume' => '100', 'turnover' => '350000', 'is_closed' => true,
        ]);
        StrategyObservationRecord::query()
            ->where('strategy_id', 'trend_pullback')->delete();
        StrategyObservationRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
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
            'configuration_hash' => hash('sha256', 'test-trend_pullback-stale'),
        ]);

        Cache::flush();
        $resStalledStrategy = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('SCHEDULER_STALLED', $resStalledStrategy['system_status']);
        self::assertContains('trend_pullback', $resStalledStrategy['stalled_strategies']);
        self::assertEmpty($resStalledStrategy['stalled_symbols']);

        // 4. Update trend_pullback with a fresh evaluation (<45m): status must now be HEALTHY
        StrategyObservationRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
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
            'configuration_hash' => hash('sha256', 'test-trend_pullback-fresh'),
        ]);

        Cache::flush();
        $resFresh = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('HEALTHY', $resFresh['system_status']);
        self::assertSame(13, $resFresh['provisioned_accounts']);
        self::assertSame(13, $resFresh['active_accounts']);
        self::assertEmpty($resFresh['stalled_symbols']);
        self::assertEmpty($resFresh['stalled_strategies']);

        // 5. Inject an EXECUTION_FAILED journal event within 24h: status must become DEGRADED
        $account = $accounts->first();
        JournalEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'event_type' => 'EXECUTION_FAILED',
            'occurred_at' => $now->copy()->subHours(2),
            'payload' => ['reason' => 'margin_exhausted'],
            'idempotency_key' => 'test-exec-failed-1',
        ]);

        Cache::flush();
        $resDegraded = $this->withHeader('X-Exchange', 'bybit')->getJson('/api/v1/dashboard')->assertOk()->json('data.paper_execution_health');
        self::assertSame('DEGRADED', $resDegraded['system_status']);
        self::assertSame(1, $resDegraded['execution_failures_24h']);
    }
}
