<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Security\ExchangeUrlSecurityValidator;
use App\Strategy\Application\StrategyExchangeAssignmentService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class StrategyTopologyIsolationAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private StrategyRegistry $strategies;

    private ExchangeRegistry $exchanges;

    private PaperTopologySynchronizer $synchronizer;

    private StrategyExchangeAssignmentService $assignments;

    private ExchangeCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategies = $this->app->make(StrategyRegistry::class);
        $this->exchanges = $this->app->make(ExchangeRegistry::class);
        $this->synchronizer = $this->app->make(PaperTopologySynchronizer::class);
        $this->assignments = $this->app->make(StrategyExchangeAssignmentService::class);
        $this->catalog = $this->app->make(ExchangeCatalogService::class);
    }

    public function test_initial_sync_preserves_canonical_assignments_and_creates_no_unassigned_accounts(): void
    {
        // 1. Initial baseline topology sync
        $res = $this->synchronizer->sync(true);
        self::assertSame('APPLIED', $res['mode']);

        $canonicalCount = count(config('exchanges.enabled'));
        $strategyCount = count(config('paper.primary_strategies'));
        self::assertSame($canonicalCount * $strategyCount, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Idempotency: re-running topology sync creates zero new accounts
        $res2 = $this->synchronizer->sync(true);
        self::assertSame(0, $res2['created_bybit']);
        self::assertSame(0, $res2['created_binance']);
        self::assertSame(0, $res2['created_other']);
        self::assertSame($canonicalCount * $strategyCount, PaperAccountRecord::query()->where('is_primary', true)->count());
    }

    public function test_adding_new_exchange_without_assigned_strategies_provisions_zero_accounts(): void
    {
        $this->synchronizer->sync(true);
        $baselineCount = PaperAccountRecord::query()->where('is_primary', true)->count();

        // Onboard new exchange: mexc without assigning strategies
        $this->catalog->onboardManifest([
            'id' => 'mexc',
            'name' => 'MEXC Global',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            'cost_model' => [
                'version' => 'mexc-2026.1',
                'market' => 'Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
            // assigned_strategies omitted!
        ]);

        // Invariant: Registering a new exchange must NOT automatically assign all existing strategies to it.
        $mexcAccounts = PaperAccountRecord::query()->where('exchange_id', 'mexc')->count();
        self::assertSame(0, $mexcAccounts, 'New exchange with no assigned strategies must have zero accounts provisioned.');
        self::assertSame($baselineCount, PaperAccountRecord::query()->where('is_primary', true)->count());
    }

    public function test_enabling_strategy_on_selected_exchange_provisions_only_that_single_account(): void
    {
        $this->synchronizer->sync(true);

        // Onboard isolated venue: kraken_isolated
        $this->catalog->onboardManifest([
            'id' => 'kraken_iso',
            'name' => 'Kraken Isolated',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            'cost_model' => [
                'version' => 'kraken-iso-2026.1',
                'market' => 'Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
        ]);

        self::assertSame(0, PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')->count());

        // Explicitly assign ONLY ONE strategy: 'trend_pullback' to 'kraken_iso'
        $this->assignments->assign('trend_pullback', 'kraken_iso', 'test_operator');
        self::assertTrue($this->assignments->hasAssignment('trend_pullback', 'kraken_iso'));
        self::assertFalse($this->assignments->hasAssignment('momentum', 'kraken_iso'));

        // Re-run topology synchronization
        $this->synchronizer->sync(true);

        // Exactly ONE account created for kraken_iso
        $krakenAccounts = PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')->get();
        self::assertCount(1, $krakenAccounts);
        self::assertSame('trend_pullback', $krakenAccounts->first()->primary_strategy_id);
        self::assertSame('BLOCKED', $krakenAccounts->first()->status);

        // Verify other strategies do NOT have accounts on kraken_iso
        self::assertNull(PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')->where('primary_strategy_id', 'momentum')->first());
    }

    public function test_disabling_strategy_on_exchange_blocks_execution_and_preserves_historical_records(): void
    {
        $this->synchronizer->sync(true);

        // Explicitly assign 'momentum' to 'bybit'
        self::assertTrue($this->assignments->hasAssignment('momentum', 'bybit'));
        $bybitMomentum = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'momentum')->firstOrFail();

        // Operator unassigns/disables momentum on Bybit
        $this->assignments->unassign('momentum', 'bybit');
        self::assertFalse($this->assignments->hasAssignment('momentum', 'bybit'));

        // Topology sync re-run must NOT delete the account or its ledger
        $this->synchronizer->sync(true);
        $preserved = PaperAccountRecord::query()->find($bybitMomentum->id);
        self::assertNotNull($preserved, 'Historical account must never be deleted upon unassignment.');

        // Eligibility check rejects entries with explicit reason
        $eligibilityService = app(PaperExecutionEligibilityService::class);
        $rejection = $eligibilityService->accountEntryRejectionReason($preserved);
        self::assertSame('strategy_not_assigned_to_exchange', $rejection);
    }

    public function test_registering_new_strategy_does_not_automatically_provision_unassigned_exchanges(): void
    {
        $this->synchronizer->sync(true);
        $baselineAccounts = PaperAccountRecord::query()->where('is_primary', true)->count();

        // Register custom isolated strategy
        $strat = new class implements StrategyInterface
        {
            public function id(): string
            {
                return 'isolated_alpha_1';
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'Isolated Alpha 1';
            }

            public function parameters(): array
            {
                return ['alpha' => 1];
            }

            public function evaluate(StrategyInput $input): SignalDecision
            {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                return new SignalDecision(
                    id: 'iso-sig-'.Uuid::uuid4()->toString(),
                    exchange: $input->exchange,
                    category: $input->category,
                    symbol: $input->symbol,
                    timeframe: '15m',
                    decisionTime: $now,
                    candleCloseTime: $now,
                    side: SignalSide::Skip,
                    qualityClass: null,
                    score: 0,
                    pricePlan: null,
                    modelRiskPercent: null,
                    expiresAt: $now->modify('+30 minutes'),
                    maximumHoldingMinutes: 60,
                    reasons: ['test_skip'],
                    risks: [],
                    indicatorSnapshot: [],
                    strategyVersion: '1.0.0',
                    schemaVersion: '1.0.0',
                );
            }

            public function targetExchanges(): array
            {
                return [];
            }
        };

        // Register in registry without assigning to any exchanges
        $this->strategies->register($strat);

        // Invariant: Registering a new strategy must NOT automatically activate it on every existing exchange.
        $this->synchronizer->sync(true);
        self::assertSame($baselineAccounts, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Now explicitly assign ONLY to Bybit
        $this->assignments->assign('isolated_alpha_1', 'bybit', 'operator');
        $this->synchronizer->sync(true);

        // Exactly one new account on Bybit, zero on all other 9 exchanges
        self::assertSame($baselineAccounts + 1, PaperAccountRecord::query()->where('is_primary', true)->count());
        self::assertNotNull(PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'isolated_alpha_1')->first());
        self::assertNull(PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'isolated_alpha_1')->first());
        self::assertNull(PaperAccountRecord::query()->where('exchange_id', 'coinbase')->where('primary_strategy_id', 'isolated_alpha_1')->first());
    }

    public function test_ssrf_validator_strictly_rejects_internal_private_and_metadata_addresses(): void
    {
        $validator = app(ExchangeUrlSecurityValidator::class);

        // 1. Loopback addresses
        $resLoopback = $validator->validateUrl('http://127.0.0.1/api');
        self::assertFalse($resLoopback['safe']);
        self::assertStringContainsString('loopback', $resLoopback['reason']);

        // 2. Cloud metadata service (169.254.169.254)
        $resMetadata = $validator->validateUrl('http://169.254.169.254/latest/meta-data/');
        self::assertFalse($resMetadata['safe']);
        self::assertStringContainsString('cloud metadata', $resMetadata['reason']);

        // 3. Private RFC1918 range
        $resPrivate = $validator->validateUrl('http://10.0.0.1/status');
        self::assertFalse($resPrivate['safe']);
        self::assertStringContainsString('private network', $resPrivate['reason']);

        // 4. Prohibited port (Redis 6379)
        $resPort = $validator->validateUrl('https://api.bybit.com:6379/test');
        self::assertFalse($resPort['safe']);
        self::assertStringContainsString('Prohibited destination port', $resPort['reason']);

        // 5. Valid public URL passes
        $resPublic = $validator->validateUrl('https://api.bybit.com');
        self::assertTrue($resPublic['safe']);
    }
}
