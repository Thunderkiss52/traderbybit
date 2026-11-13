<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Security\ExchangeUrlSecurityValidator;
use App\Strategy\Application\StrategyExchangeAssignmentService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Manifest costs and instrument limits are synthetic isolated test assumptions. */
final class StrategyTopologyIsolationAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // Synthetic fixture limits, not verified specifications for a public venue.
    private const SYNTHETIC_INSTRUMENT_LIMITS = [
        'price_precision' => 2, 'qty_precision' => 4,
        'min_qty' => '0.0001', 'min_notional' => '5.0',
    ];

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

    public function test_new_exchange_automatically_provisions_all_registered_strategies_without_authorizing_execution(): void
    {
        $this->synchronizer->sync(true);
        $baselineCount = PaperAccountRecord::query()->where('is_primary', true)->count();
        $strategyCount = count($this->strategies->ids());

        $this->catalog->onboardManifest([
            'id' => 'mexc',
            'name' => 'MEXC Global',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            ...self::SYNTHETIC_INSTRUMENT_LIMITS,
            'cost_model' => [
                'version' => 'SYNTHETIC-mexc-2026.1',
                'market' => 'Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
        ]);

        $accounts = PaperAccountRecord::query()->where('exchange_id', 'mexc')->get();
        self::assertCount($strategyCount, $accounts);
        self::assertSame($baselineCount + $strategyCount, PaperAccountRecord::query()->where('is_primary', true)->count());
        foreach ($accounts as $account) {
            self::assertSame('BLOCKED', $account->status, 'Unapproved new venue must never enter PAPER execution.');
            self::assertSame('0', (string) BigDecimal::of((string) $account->starting_balance)->toScale(0));
        }
        self::assertSame($strategyCount, count($this->assignments->assignedStrategiesForExchange('mexc')));
        self::assertSame(0, $this->synchronizer->sync(true)['created_other'], 'Automatic onboarding must be idempotent.');
    }

    public function test_explicit_operator_veto_blocks_execution_without_erasing_provisioned_history(): void
    {
        $this->synchronizer->sync(true);
        $this->catalog->onboardManifest([
            'id' => 'kraken_iso',
            'name' => 'Kraken Isolated',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            ...self::SYNTHETIC_INSTRUMENT_LIMITS,
            'cost_model' => [
                'version' => 'SYNTHETIC-kraken-iso-2026.1',
                'market' => 'Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
        ]);

        $initial = PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')->count();
        self::assertSame(count($this->strategies->ids()), $initial);
        $momentum = PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')
            ->where('primary_strategy_id', 'momentum')->firstOrFail();

        $this->assignments->unassign('momentum', 'kraken_iso');
        self::assertFalse($this->assignments->hasAssignment('momentum', 'kraken_iso'));
        $this->synchronizer->sync(true);
        self::assertSame($initial, PaperAccountRecord::query()->where('exchange_id', 'kraken_iso')->count());
        self::assertNotNull(PaperAccountRecord::query()->find($momentum->id));
        // Venue approval is checked before the independent assignment veto.
        self::assertSame('exchange_not_paper_approved',
            app(PaperExecutionEligibilityService::class)->accountEntryRejectionReason($momentum->fresh()));
    }

    public function test_preexisting_operator_veto_never_suppresses_new_venues_historical_account_topology(): void
    {
        $this->synchronizer->sync(true);
        $this->assignments->unassign('momentum', 'mexc_disabled');
        $this->catalog->onboardManifest([
            'id' => 'mexc_disabled',
            'name' => 'MEXC With Disabled Strategy',
            'market_type' => 'linear_perpetual',
            'supported_timeframes' => ['5m', '15m', '30m', '1h'],
            ...self::SYNTHETIC_INSTRUMENT_LIMITS,
            'cost_model' => [
                'version' => 'SYNTHETIC-mexc-disabled-2026.1',
                'market' => 'Perpetuals',
                'fee_tier' => 'Standard',
                'maker_rate' => '0.0002',
                'taker_rate' => '0.0005',
                'slippage_rate' => '0.0002',
            ],
        ]);

        self::assertSame(count($this->strategies->ids()),
            PaperAccountRecord::query()->where('exchange_id', 'mexc_disabled')->count());
        $account = PaperAccountRecord::query()->where('exchange_id', 'mexc_disabled')
            ->where('primary_strategy_id', 'momentum')->firstOrFail();
        self::assertSame('BLOCKED', $account->status);
        self::assertFalse($this->assignments->hasAssignment('momentum', 'mexc_disabled'));
    }

    public function test_canonical_assignment_reseeding_preserves_explicit_operator_unassignment(): void
    {
        $this->assignments->seedCanonicalAssignments();
        $this->assignments->unassign('momentum', 'bybit');
        self::assertFalse($this->assignments->hasAssignment('momentum', 'bybit'));

        // Redeploy/reconciliation must not bypass the operator's explicit veto.
        $this->assignments->seedCanonicalAssignments();
        self::assertFalse($this->assignments->hasAssignment('momentum', 'bybit'));

        $matrix = $this->assignments->assignmentMatrix();
        self::assertFalse($matrix['bybit']['momentum']['assigned']);
        self::assertNotContains('bybit', $this->assignments->assignedExchangesForStrategy('momentum'));
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

    public function test_registering_new_strategy_provisions_all_exchanges_but_respects_explicit_execution_compatibility(): void
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

        // Registration creates one isolated PAPER account for every registered venue.
        // The strategy explicitly supports no venues, so trade eligibility stays false.
        $exchangeIds = $this->exchanges->registeredIds();
        $this->strategies->register($strat);
        self::assertSame($baselineAccounts + count($exchangeIds),
            PaperAccountRecord::query()->where('is_primary', true)->count());
        foreach ($exchangeIds as $exchangeId) {
            $account = PaperAccountRecord::query()->where('exchange_id', $exchangeId)
                ->where('primary_strategy_id', 'isolated_alpha_1')->firstOrFail();
            self::assertNotNull($account->id);
            self::assertFalse($this->assignments->hasAssignment('isolated_alpha_1', $exchangeId),
                'Explicit targetExchanges=[] must deny execution without preventing account provisioning.');
        }
        self::assertSame(0, $this->synchronizer->sync(true)['created_other']);

        // The owner may explicitly override the compatibility restriction on a
        // selected exchange without creating a second account or resetting data.
        $this->assignments->assign('isolated_alpha_1', 'bybit', 'operator');
        self::assertTrue($this->assignments->hasAssignment('isolated_alpha_1', 'bybit'));
        $this->synchronizer->sync(true);
        self::assertSame($baselineAccounts + count($exchangeIds),
            PaperAccountRecord::query()->where('is_primary', true)->count());
    }

    public function test_sync_does_not_invent_historical_opening_snapshot_for_existing_accounts(): void
    {
        $this->synchronizer->sync(true);
        $existing = PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        EquitySnapshotRecord::query()->where('paper_account_id', $existing->id)->delete();

        $this->synchronizer->sync(true);
        self::assertSame(0, EquitySnapshotRecord::query()->where('paper_account_id', $existing->id)->count(),
            'Never manufacture a backdated initial equity snapshot for an existing historical account.');
    }

    public function test_ssrf_validator_strictly_rejects_internal_private_and_metadata_addresses(): void
    {
        $validator = app(ExchangeUrlSecurityValidator::class);

        // 1. Loopback addresses
        $resLoopback = $validator->validateUrl('http://127.0.0.1/api');
        self::assertFalse($resLoopback['safe']);
        self::assertSame('127.0.0.1', $resLoopback['ip']);
        self::assertStringContainsString('private or reserved address', $resLoopback['reason']);

        // 2. Cloud metadata service (169.254.169.254)
        $resMetadata = $validator->validateUrl('http://169.254.169.254/latest/meta-data/');
        self::assertFalse($resMetadata['safe']);
        self::assertSame('169.254.169.254', $resMetadata['ip']);
        self::assertStringContainsString('private or reserved address', $resMetadata['reason']);

        // 3. Private RFC1918 range
        $resPrivate = $validator->validateUrl('http://10.0.0.1/status');
        self::assertFalse($resPrivate['safe']);
        self::assertSame('10.0.0.1', $resPrivate['ip']);
        self::assertStringContainsString('private or reserved address', $resPrivate['reason']);

        // 4. Prohibited port (Redis 6379)
        $resPort = $validator->validateUrl('https://api.bybit.com:6379/test');
        self::assertFalse($resPort['safe']);
        self::assertStringContainsString('Prohibited destination port', $resPort['reason']);

        // 5. Valid public URL passes
        $resPublic = $validator->validateUrl('https://api.bybit.com');
        self::assertTrue($resPublic['safe']);
    }
}
