<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\Domain\ExchangeManifest;
use App\Exchanges\Domain\ExchangeManifestValidator;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class DynamicExchangeOnboardingAndUnlimitedArchitectureTest extends TestCase
{
    use CleansCandleConflictEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerConflictCleanup();
        $this->seedBaselineExchanges();
    }

    private function seedBaselineExchanges(): void
    {
        ExchangeRecord::query()->firstOrCreate(
            ['id' => 'bybit'],
            [
                'name' => 'Bybit Linear Perpetual',
                'market_type' => 'linear_perpetual',
                'description' => 'Bybit USDT-perpetual linear contracts with 13 canonical PRIMARY forward paper accounts.',
                'lifecycle_status' => 'ACTIVE_PAPER',
                'capabilities' => [
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\Exchanges\Bybit\BybitAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60'],
                    'price_precision' => 2,
                    'qty_precision' => 3,
                    'min_qty' => '0.001',
                    'min_notional' => '5.0',
                ],
                'cost_model' => [
                    'version' => 'bybit-linear-perpetual-2026.1',
                    'market' => 'USDT Perpetual (Linear)',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.00055',
                    'slippage_rate' => '0.0002',
                ],
                'paper_execution_assumptions' => [
                    'execution_model' => 'NEXT_CANDLE_OPEN',
                    'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
                ],
                'paper_eligible' => true,
                'paper_approved' => true,
                'paper_approved_at' => now('UTC'),
            ]
        );

        ExchangeRecord::query()->firstOrCreate(
            ['id' => 'binance'],
            [
                'name' => 'Binance USD(S)-M Futures',
                'market_type' => 'linear_perpetual',
                'description' => 'Binance USD-M linear futures public market data and 13 provisioned BLOCKED paper accounts.',
                'lifecycle_status' => 'ACTIVE_PAPER',
                'capabilities' => [
                    'has_candles' => true,
                    'has_funding' => true,
                    'has_open_interest' => true,
                    'has_live_adapter' => true,
                    'adapter_class' => 'App\Exchanges\Binance\BinancePublicAdapter',
                    'supported_timeframes' => ['5', '15', '30', '60'],
                    'price_precision' => 2,
                    'qty_precision' => 3,
                    'min_qty' => '0.001',
                    'min_notional' => '5.0',
                ],
                'cost_model' => [
                    'version' => 'binance-usdm-perpetual-2026.1',
                    'market' => 'USD(S)-M Futures (Linear Perpetuals)',
                    'fee_tier' => 'VIP 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'paper_execution_assumptions' => [
                    'execution_model' => 'NEXT_CANDLE_OPEN',
                    'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
                ],
                'paper_eligible' => true,
                'paper_approved' => true,
                'paper_approved_at' => now('UTC'),
            ]
        );
    }

    public function test_manifest_validation_contract_strictly_rejects_malformed_inputs(): void
    {
        // 1. Missing timeframes
        $this->expectException(InvalidArgumentException::class);
        $manifest = ExchangeManifest::fromArray([
            'id' => 'bad_venue',
            'name' => 'Bad Venue',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['1m'], // missing 5m, 15m, 30m, 1h
        ]);
        (new ExchangeManifestValidator)->assertValid($manifest);
    }

    public function test_manifest_validation_contract_rejects_invalid_fee_rates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $manifest = ExchangeManifest::fromArray([
            'id' => 'negative_fee_venue',
            'name' => 'Negative Fee Venue',
            'market_type' => 'linear_perpetual',
            'cost_model' => [
                'taker_rate' => '-0.05', // invalid negative
                'slippage_rate' => '0.0002',
            ],
        ]);
        (new ExchangeManifestValidator)->assertValid($manifest);
    }

    public function test_declarative_onboarding_automatically_provisions_independent_strategy_accounts(): void
    {
        $registry = app(ExchangeRegistry::class);
        $catalog = app(ExchangeCatalogService::class);
        $synchronizer = app(PaperTopologySynchronizer::class);

        // Synchronize initial baseline topology
        $synchronizer->sync(true);
        $strategyCount = count(PrimaryStrategies::ids());
        $initialExchangesCount = count($registry->registeredIds());
        $initialAccountsCount = PaperAccountRecord::query()->where('is_primary', true)->count();
        self::assertSame($initialExchangesCount * $strategyCount, $initialAccountsCount);

        // Onboard new exchange: hyperliquid
        $manifestData = [
            'manifest_version' => '1.0',
            'id' => 'hyperliquid',
            'name' => 'Hyperliquid L1 Perps',
            'market_type' => 'linear_perpetual',
            'description' => 'Decentralized high-throughput L1 perpetuals order book venue.',
            'base_url' => 'https://api.hyperliquid.xyz',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            'price_precision' => 2,
            'qty_precision' => 4,
            'min_qty' => '0.0001',
            'min_notional' => '5.0',
            'cost_model' => [
                'version' => 'hyperliquid-perps-2026.1',
                'market' => 'USDC Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0001',
                'taker_rate' => '0.00035',
                'slippage_rate' => '0.00015',
            ],
            'paper_execution_assumptions' => [
                'execution_model' => 'NEXT_CANDLE_OPEN',
                'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
            ],
            'assigned_strategies' => PrimaryStrategies::ids(),
        ];

        $res = $this->postJson('/api/v1/exchanges/onboard', ['manifest' => $manifestData]);
        $res->assertStatus(201);
        self::assertSame('hyperliquid', $res->json('data.id'));

        // Topology invariant: Accounts = Strategies x Exchanges
        $totalPrimary = PaperAccountRecord::query()->where('is_primary', true)->count();
        self::assertSame(($initialExchangesCount + 1) * $strategyCount, $totalPrimary);

        $hyperAccounts = PaperAccountRecord::query()->where('exchange_id', 'hyperliquid')->get();
        self::assertCount($strategyCount, $hyperAccounts);

        // Invariant: Unapproved venue accounts start in BLOCKED status
        foreach ($hyperAccounts as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertTrue(BigDecimal::of((string) $account->realized_pnl)->isZero());
            self::assertTrue(BigDecimal::of((string) $account->unrealized_pnl)->isZero());

            // Verify initial equity snapshot exists
            $snapshot = EquitySnapshotRecord::query()
                ->where('paper_account_id', $account->id)
                ->where('source_event_key', 'account:initial')
                ->first();
            self::assertNotNull($snapshot, "Initial equity snapshot missing for {$account->id}");
        }

        // Onboard another new exchange: dydx_v4
        $dydxManifest = [
            'manifest_version' => '2.0',
            'id' => 'dydx_v4',
            'name' => 'dYdX Chain v4',
            'market_type' => 'linear_perpetual',
            'description' => 'dYdX Cosmos app-chain orderbook.',
            'base_url' => 'https://indexer.dydx.trade',
            'supported_timeframes' => ['5', '15', '30', '60'],
            'cost_model' => [
                'version' => 'dydx-v4-2026.1',
                'market' => 'USDC Perpetuals',
                'fee_tier' => 'Tier 1',
                'maker_rate' => '0.00015',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
            'assigned_strategies' => PrimaryStrategies::ids(),
        ];

        $res2 = $this->postJson('/api/v1/exchanges/onboard', ['manifest' => $dydxManifest]);
        $res2->assertStatus(201);
        self::assertSame('dydx_v4', $res2->json('data.id'));

        // Topology invariant: (Initial + 2) * Strategies
        self::assertSame(($initialExchangesCount + 2) * $strategyCount, PaperAccountRecord::query()->where('is_primary', true)->count());
        self::assertCount($strategyCount, PaperAccountRecord::query()->where('exchange_id', 'dydx_v4')->get());

        // Verify dynamic ExchangeRegistry reflects 4 registered venues
        $registered = $registry->registeredIds();
        self::assertContains('bybit', $registered);
        self::assertContains('binance', $registered);
        self::assertContains('hyperliquid', $registered);
        self::assertContains('dydx_v4', $registered);
    }

    public function test_connectivity_check_endpoint(): void
    {
        // Onboard test venue
        $this->postJson('/api/v1/exchanges/onboard', [
            'manifest' => [
                'id' => 'test_conn_venue',
                'name' => 'Test Connectivity Venue',
                'market_type' => 'linear_perpetual',
                'base_url' => 'https://api.bybit.com',
                'supported_timeframes' => ['5m', '15m', '30m', '1h'],
                'cost_model' => [
                    'version' => 'test-2026.1',
                    'market' => 'Linear',
                    'fee_tier' => 'Tier 0',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'assigned_strategies' => PrimaryStrategies::ids(),
            ],
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/exchanges/test_conn_venue/connectivity');
        $res->assertStatus(200);
        self::assertSame('test_conn_venue', $res->json('data.exchange_id'));
        self::assertArrayHasKey('reachable', $res->json('data'));
        self::assertArrayHasKey('probe_status', $res->json('data'));
    }

    public function test_premature_paper_approval_is_strictly_rejected_when_requirements_missing(): void
    {
        $this->postJson('/api/v1/exchanges/onboard', [
            'manifest' => [
                'id' => 'incomplete_venue',
                'name' => 'Incomplete Venue',
                'market_type' => 'linear_perpetual',
                'supported_timeframes' => ['5m', '15m', '30m', '1h'],
                'cost_model' => [
                    'version' => 'inc-2026.1',
                    'market' => 'Linear',
                    'fee_tier' => 'Standard',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'assigned_strategies' => PrimaryStrategies::ids(),
            ],
        ])->assertStatus(201);

        // Attempt paper approval without candles or market context
        $res = $this->postJson('/api/v1/exchanges/incomplete_venue/approve-paper', [
            'approved' => true,
        ]);

        $res->assertStatus(422);
        self::assertSame('PAPER_ACTIVATION_REJECTED', $res->json('error.code'));
        self::assertStringContainsString('fails technical eligibility', $res->json('message'));

        $this->assertDatabaseHas('exchanges', [
            'id' => 'incomplete_venue',
            'paper_approved' => false,
        ]);
    }

    public function test_exchange_deactivation_lifecycle_blocks_paper_and_preserves_historical_records(): void
    {
        $synchronizer = app(PaperTopologySynchronizer::class);
        $synchronizer->sync(true);

        $this->postJson('/api/v1/exchanges/onboard', [
            'manifest' => [
                'id' => 'deact_venue',
                'name' => 'Deactivate Test Venue',
                'market_type' => 'linear_perpetual',
                'supported_timeframes' => ['5m', '15m', '30m', '1h'],
                'cost_model' => [
                    'version' => 'deact-2026.1',
                    'market' => 'Linear',
                    'fee_tier' => 'Standard',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'assigned_strategies' => PrimaryStrategies::ids(),
            ],
        ])->assertStatus(201);

        $accounts = PaperAccountRecord::query()->where('exchange_id', 'deact_venue')->get();
        self::assertCount(13, $accounts);

        // Operator deactivates the exchange
        $res = $this->postJson('/api/v1/exchanges/deact_venue/deactivate', [
            'reason' => 'OPERATOR_SCHEDULED_RETIREMENT',
        ]);

        $res->assertStatus(200);
        self::assertSame('DEACTIVATED', $res->json('data.lifecycle_status'));
        self::assertFalse($res->json('data.paper_approved'));

        // All 13 accounts are preserved and blocked
        $postDeactAccounts = PaperAccountRecord::query()->where('exchange_id', 'deact_venue')->get();
        self::assertCount(13, $postDeactAccounts);
        foreach ($postDeactAccounts as $acc) {
            self::assertSame('BLOCKED', $acc->status);
        }

        // Verify PaperExecutionEligibilityService rejects new entries on deactivated venue
        $eligibilityService = app(PaperExecutionEligibilityService::class);
        $rejection = $eligibilityService->accountEntryRejectionReason($postDeactAccounts->first());
        self::assertSame('exchange_deactivated', $rejection);

        // Verify activeIds() excludes deactivated exchange while registeredIds() preserves it for audit
        $registry = app(ExchangeRegistry::class);
        self::assertContains('deact_venue', $registry->registeredIds());
        self::assertNotContains('deact_venue', $registry->activeIds());
    }

    public function test_exchange_pause_lifecycle_blocks_new_entries(): void
    {
        $this->postJson('/api/v1/exchanges/onboard', [
            'manifest' => [
                'id' => 'pause_venue',
                'name' => 'Pause Test Venue',
                'market_type' => 'linear_perpetual',
                'supported_timeframes' => ['5m', '15m', '30m', '1h'],
                'cost_model' => [
                    'version' => 'pause-2026.1',
                    'market' => 'Linear',
                    'fee_tier' => 'Standard',
                    'maker_rate' => '0.0002',
                    'taker_rate' => '0.0005',
                    'slippage_rate' => '0.0002',
                ],
                'assigned_strategies' => PrimaryStrategies::ids(),
            ],
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/exchanges/pause_venue/pause', [
            'reason' => 'HIGH_VOLATILITY_COOLOFF',
        ]);

        $res->assertStatus(200);
        self::assertSame('PAUSED', $res->json('data.lifecycle_status'));

        $account = PaperAccountRecord::query()->where('exchange_id', 'pause_venue')->first();
        self::assertSame('BLOCKED', $account->status);

        $eligibilityService = app(PaperExecutionEligibilityService::class);
        $rejection = $eligibilityService->accountEntryRejectionReason($account);
        self::assertSame('exchange_paused', $rejection);
    }
}
