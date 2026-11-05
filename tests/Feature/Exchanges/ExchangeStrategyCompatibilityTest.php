<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\Exchanges\Infrastructure\Persistence\ExchangeStrategyCompatibilityRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\StrategyBuilder\DefinitionStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\StrategyBuilder\DefinitionTest;
use Tests\TestCase;

final class ExchangeStrategyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_all_evaluates_all_canonical_strategies(): void
    {
        $service = app(ExchangeStrategyCompatibilityService::class);
        $result = $service->reconcileAll();

        self::assertGreaterThanOrEqual(2, $result['exchanges_evaluated']);
        self::assertGreaterThanOrEqual(13, $result['strategies_evaluated']);
        self::assertSame($result['exchanges_evaluated'] * $result['strategies_evaluated'], $result['evaluations_count']);

        // Assert records exist in DB
        $bybitBaseline = ExchangeStrategyCompatibilityRecord::query()
            ->where('exchange_id', 'bybit')
            ->where('strategy_id', 'baseline')
            ->first();

        self::assertNotNull($bybitBaseline);
        self::assertSame('INSUFFICIENT_DATA', $bybitBaseline->status);
        self::assertFalse($bybitBaseline->research_eligible);
        self::assertArrayHasKey('missing_requirements', $bybitBaseline->diagnostics);
    }

    public function test_offline_historical_exchange_without_live_adapter_is_research_eligible_when_data_exists(): void
    {
        $catalog = app(ExchangeCatalogService::class);
        $service = app(ExchangeStrategyCompatibilityService::class);

        // 1. Register offline historical exchange
        $exchange = $catalog->register([
            'id' => 'okx_historical',
            'name' => 'OKX Historical Research Dataset',
            'market_type' => 'linear_perpetual',
            'capabilities' => [
                'has_candles' => true,
                'has_funding' => true,
                'has_live_adapter' => false,
                'supported_timeframes' => ['5', '15', '30', '60'],
                'price_precision' => 2,
                'qty_precision' => 3,
            ],
            'cost_model' => [
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
        ]);

        self::assertFalse($exchange->paper_eligible);

        // 2. Insert 250 bars (>= 220 required) for BTCUSDT
        $now = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $t = $now->addMinutes($i * 5)->format('Y-m-d H:i:s.uP');
            $rows[] = [
                'exchange' => 'okx_historical',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'open_time' => $t,
                'open' => '100',
                'high' => '105',
                'low' => '95',
                'close' => '102',
                'volume' => '10',
                'turnover' => '1000',
                'is_closed' => true,
                'created_at' => $t,
                'updated_at' => $t,
            ];
        }
        CandleRecord::query()->insert($rows);

        // 3. Reconcile
        $service->reconcileExchange('okx_historical');

        $compat = ExchangeStrategyCompatibilityRecord::query()
            ->where('exchange_id', 'okx_historical')
            ->where('strategy_id', 'baseline')
            ->first();

        self::assertNotNull($compat);
        self::assertTrue($compat->research_eligible, 'Offline historical exchange must be research eligible when data exists.');
        self::assertFalse($compat->shadow_eligible, 'Offline exchange without live adapter cannot be shadow eligible.');
        self::assertFalse($compat->paper_eligible, 'Offline exchange without live adapter cannot be paper eligible.');
        self::assertSame('RESEARCH_ELIGIBLE', $compat->status);
    }

    public function test_registering_exchange_automatically_reconciles_compatibility(): void
    {
        $catalog = app(ExchangeCatalogService::class);

        $exchange = $catalog->register([
            'id' => 'bitget_test',
            'name' => 'Bitget Test',
            'market_type' => 'linear_perpetual',
        ]);

        $count = ExchangeStrategyCompatibilityRecord::query()
            ->where('exchange_id', 'bitget_test')
            ->count();

        self::assertGreaterThanOrEqual(13, $count, 'Registering exchange must auto-discover and evaluate all strategies.');
    }

    public function test_saving_custom_strategy_automatically_reconciles_against_all_exchanges(): void
    {
        $store = app(DefinitionStore::class);
        $d = DefinitionTest::example();
        $d['id'] = 'user_auto_compat_test';
        $d['schema_version'] = 2;

        $store->save($d, 'test-owner');

        $count = ExchangeStrategyCompatibilityRecord::query()
            ->where('strategy_id', 'user_auto_compat_test')
            ->count();

        $exchangeCount = ExchangeRecord::query()->count();
        self::assertSame($exchangeCount, $count, 'Saving custom strategy must automatically evaluate compatibility across all exchanges.');
    }

    public function test_api_endpoints_for_compatibility_matrix_and_exchange_strategies(): void
    {
        config(['services.research_api_token' => 'owner-token']);

        // Matrix endpoint
        $matrixResp = $this->getJson('/api/v1/exchanges/matrix');
        $matrixResp->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'matrix',
                    'total_evaluations',
                    'updated_at',
                ],
            ]);

        // Exchange strategies endpoint
        $stratResp = $this->getJson('/api/v1/exchanges/bybit/strategies');
        $stratResp->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'exchange_id',
                    'strategies_evaluated',
                    'results',
                ],
            ]);

        // Manual reconcile endpoint
        $recResp = $this->postJson('/api/v1/exchanges/bybit/reconcile');
        $recResp->assertOk()
            ->assertJsonPath('data.exchange_id', 'bybit');
    }

    public function test_cli_compatibility_reconcile_command_runs_successfully(): void
    {
        $this->artisan('compatibility:reconcile')->assertSuccessful();
        $this->artisan('compatibility:reconcile', ['--exchange' => 'bybit'])->assertSuccessful();
        $this->artisan('compatibility:reconcile', ['--strategy' => 'baseline'])->assertSuccessful();
    }
}
