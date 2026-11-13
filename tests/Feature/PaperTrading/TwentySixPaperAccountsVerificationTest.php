<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePublicAdapter;
use App\Exchanges\Bybit\BybitAdapter;
use App\Exchanges\Contracts\ExchangeAdapter;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperSizingSettings;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TwentySixPaperAccountsVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Dedicated preservation fixture for the original 26-account registry.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
    }

    public function test_scenario_a_twenty_six_primary_accounts_isolated_and_funded(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $synchronizer = app(PaperTopologySynchronizer::class);
        $syncResult = $synchronizer->sync(true);

        self::assertSame(13, $syncResult['created_bybit']);
        self::assertSame(13, $syncResult['created_binance_blocked_unfunded']);

        // Allocate canonical virtual capital to Binance accounts
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-ONBOARDING-26-ACCOUNTS');

        $bybitAccounts = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('is_primary', true)->get();
        $binanceAccounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->get();

        self::assertCount(13, $bybitAccounts);
        self::assertCount(13, $binanceAccounts);
        self::assertSame(26, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Verify Bybit accounts are ACTIVE and funded with 10,000 USDT
        foreach ($bybitAccounts as $account) {
            self::assertSame('ACTIVE', $account->status);
            self::assertDecimalSame('10000', (string) $account->starting_balance);
            self::assertDecimalSame('10000', (string) $account->current_equity);
            self::assertDecimalSame('0', (string) $account->realized_pnl);
            self::assertDecimalSame('0', (string) $account->unrealized_pnl);
        }

        // Verify Binance accounts are funded with 10,000 USDT (status is BLOCKED pending activation gate)
        foreach ($binanceAccounts as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertDecimalSame('10000', (string) $account->starting_balance);
            self::assertDecimalSame('10000', (string) $account->current_equity);
        }

        // Verify account uniqueness: all 26 IDs are distinct
        $allIds = PaperAccountRecord::query()->where('is_primary', true)->pluck('id');
        self::assertCount(26, $allIds->unique());
    }

    public function test_scenario_b_register_additional_strategy_onboards_compatibly_without_mutating_existing(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $synchronizer = app(PaperTopologySynchronizer::class);
        $synchronizer->sync(true);
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-INITIAL-26');

        $existingSnapshot = PaperAccountRecord::query()->where('is_primary', true)->get()->mapWithKeys(
            fn ($a) => [$a->id => [(string) $a->starting_balance, (string) $a->current_equity, $a->status]]
        )->all();

        // Register 14th strategy
        $this->registerMockStrategy('volatility_arbitrage_v1');

        $syncResult = app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(1, $syncResult['created_bybit']);
        self::assertSame(1, $syncResult['created_binance_blocked_unfunded']);
        self::assertSame(28, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Existing accounts are completely untouched
        foreach ($existingSnapshot as $id => $expected) {
            $current = PaperAccountRecord::query()->findOrFail($id);
            self::assertSame($expected, [(string) $current->starting_balance, (string) $current->current_equity, $current->status]);
        }

        // Venue approval cannot fund or activate an unvalidated new strategy.
        $newBybit = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'volatility_arbitrage_v1')->firstOrFail();
        $newBinance = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'volatility_arbitrage_v1')->firstOrFail();
        self::assertSame('BLOCKED', $newBybit->status);
        self::assertSame('BLOCKED', $newBinance->status);
        self::assertDecimalSame('0', (string) $newBinance->starting_balance);
        self::assertDecimalSame('0', (string) $newBybit->starting_balance);
        self::assertSame('READINESS_PENDING', $newBybit->execution_block_origin);
    }

    public function test_scenario_c_research_only_exchange_onboards_with_zero_capital_and_blocked_status(): void
    {
        config([
            'exchanges.enabled' => ['bybit', 'binance', 'okx_research'],
            'exchanges.binance_paper_provisioning_approved' => true,
        ]);

        $customRegistry = new ExchangeRegistry([
            'bybit' => app(BybitAdapter::class),
            'binance' => app(BinancePublicAdapter::class),
            'okx_research' => new class implements ExchangeAdapter
            {
                public function id(): string
                {
                    return 'okx_research';
                }

                public function name(): string
                {
                    return 'OKX Research';
                }

                public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
                {
                    return [];
                }

                public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
                {
                    return [];
                }

                public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
                {
                    return [];
                }

                public function fetchSymbols(): array
                {
                    return [];
                }
            },
        ]);
        app()->instance(ExchangeRegistry::class, $customRegistry);

        $result = app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(13, $result['created_other_blocked_unfunded']);

        $okxAccounts = PaperAccountRecord::query()->where('exchange_id', 'okx_research')->get();
        self::assertCount(13, $okxAccounts);

        $eligibility = app(PaperExecutionEligibilityService::class);
        foreach ($okxAccounts as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertDecimalSame('0', (string) $account->starting_balance);
            self::assertDecimalSame('0', (string) $account->current_equity);
            self::assertFalse($eligibility->isAccountEntryEligible($account));
        }
    }

    public function test_scenario_d_repeated_synchronization_requests_are_strictly_idempotent(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $synchronizer = app(PaperTopologySynchronizer::class);

        $first = $synchronizer->sync(true);
        self::assertSame(13, $first['created_bybit']);
        self::assertSame(13, $first['created_binance_blocked_unfunded']);

        $second = $synchronizer->sync(true);
        self::assertSame(0, $second['created_bybit']);
        self::assertSame(0, $second['created_binance_blocked_unfunded']);

        $third = $synchronizer->sync(true);
        self::assertSame(0, $third['created_bybit']);
        self::assertSame(0, $third['created_binance_blocked_unfunded']);

        self::assertSame(26, PaperAccountRecord::query()->where('is_primary', true)->count());
    }

    public function test_scenario_e_custom_opening_balance_policy_for_new_primary_account(): void
    {
        config([
            'exchanges.binance_paper_provisioning_approved' => true,
            'paper.new_primary_initial_balances.bybit.trend_pullback' => '25000',
        ]);

        $accountService = app(PaperAccountService::class);
        $account = $accountService->primaryAccount('trend_pullback', 'bybit');

        self::assertDecimalSame('25000', (string) $account->starting_balance);
        self::assertDecimalSame('25000', (string) $account->current_equity);
        self::assertDecimalSame('25000', (string) $account->peak_equity);
    }

    public function test_sizing_options_and_limits_across_accounts(): void
    {
        $config = require dirname(__DIR__, 3).'/config/paper.php';
        $sizing = new PositionSizingService($config);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');

        // 1. Fixed notional sizing
        $fixedNotional = $sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '2000',
            stop: '1980',
            limits: $limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'fixed_notional', 'amount' => '1000']
        );
        self::assertTrue($fixedNotional->allowed);
        self::assertTrue(BigDecimal::of($fixedNotional->quantity)->isEqualTo('0.5'));

        // 2. Fixed margin with 2x leverage
        $fixedMargin = $sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '2000',
            stop: '1980',
            limits: $limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'fixed_margin', 'amount' => '500', 'leverage' => '2']
        );
        self::assertTrue($fixedMargin->allowed);
        self::assertTrue(BigDecimal::of($fixedMargin->quantity)->isEqualTo('0.5'));

        // 3. Leverage limit enforcement: > 2x leverage rejected
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('PAPER leverage requires verified 1x–2x setting.');
        PaperSizingSettings::normalize(['mode' => 'fixed_margin', 'amount' => '500', 'leverage' => '3']);
    }

    private function registerMockStrategy(string $id): void
    {
        config(['paper.primary_strategies' => array_merge((array) config('paper.primary_strategies'), [$id])]);
        $mock = new class($id) implements StrategyInterface
        {
            public function __construct(private string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'Mock Strategy';
            }

            public function parameters(): array
            {
                return ['param' => 1];
            }

            public function evaluate(StrategyInput $input): SignalDecision
            {
                throw new \RuntimeException('Not implemented in mock.');
            }
        };

        $registry = new StrategyRegistry(
            array_merge(app(StrategyRegistry::class)->all(), [$mock]),
            app(LevelInteractionAnalyzer::class),
            app(LevelProbabilityService::class)
        );
        app()->instance(StrategyRegistry::class, $registry);
        app()->forgetInstance(PaperTopologySynchronizer::class);
        app()->forgetInstance(PaperAccountService::class);
    }
}
