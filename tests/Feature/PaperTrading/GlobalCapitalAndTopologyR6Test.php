<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePublicAdapter;
use App\Exchanges\Bybit\BybitAdapter;
use App\Exchanges\Contracts\ExchangeAdapter;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalCapitalAndTopologyR6Test extends TestCase
{
    use RefreshDatabase;

    public function test_topology_sync_is_safe_idempotent_and_approval_gated(): void
    {
        $sync = app(PaperTopologySynchronizer::class);
        $dry = $sync->sync();
        self::assertSame('DRY_RUN', $dry['mode']);
        self::assertCount(26, $dry['missing_pairs']);
        self::assertSame(0, PaperAccountRecord::query()->count());

        config(['exchanges.binance_paper_provisioning_approved' => false]);
        $first = $sync->sync(true);
        self::assertSame(13, $first['created_bybit']);
        self::assertTrue($first['binance_pending_approval']);
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'bybit')->count());
        self::assertSame(0, PaperAccountRecord::query()->where('exchange_id', 'binance')->count());

        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $second = $sync->sync(true);
        self::assertSame(13, $second['created_binance_blocked_unfunded']);
        $third = $sync->sync(true);
        self::assertSame(0, $third['created_bybit']);
        self::assertSame(0, $third['created_binance_blocked_unfunded']);
        self::assertSame(26, PaperAccountRecord::query()->where('is_primary', true)->count());
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'BLOCKED')->count());
    }

    public function test_new_strategy_is_added_to_both_exchanges_without_activating_it_or_mutating_existing_accounts(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $sync = app(PaperTopologySynchronizer::class);
        $sync->sync(true);
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-INITIAL-20261103');
        $oldAccounts = PaperAccountRecord::query()->where('is_primary', true)->get()
            ->mapWithKeys(fn ($account) => [$account->id => [$account->status, (string) $account->starting_balance, (string) $account->current_equity]])->all();

        $this->registerAdditionalStrategy();
        $result = app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(1, $result['created_bybit']);
        self::assertSame(1, $result['created_binance_blocked_unfunded']);
        self::assertSame(28, PaperAccountRecord::query()->where('is_primary', true)->count());
        $bybit = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'sample_new_strategy')->firstOrFail();
        $binance = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'sample_new_strategy')->firstOrFail();
        self::assertSame('BLOCKED', $bybit->status);
        self::assertSame('BLOCKED', $binance->status);
        self::assertDecimalSame('0', $binance->starting_balance);
        foreach ($oldAccounts as $id => $expected) {
            $original = PaperAccountRecord::query()->findOrFail($id);
            self::assertSame($expected, [$original->status, (string) $original->starting_balance, (string) $original->current_equity]);
        }
        $funding = app(BinancePaperCapital::class)->allocate('10000', 'OWNER-NEW-STRATEGY-20261103');
        self::assertSame(1, $funding['accounts_allocated']);
        self::assertSame(13, $funding['idempotent_repeats']);
        self::assertSame('BLOCKED', $binance->refresh()->status);
        self::assertDecimalSame('10000', $binance->starting_balance);
    }

    public function test_new_public_exchange_gets_only_blocked_unfunded_placeholders(): void
    {
        config(['exchanges.enabled' => ['bybit', 'binance', 'new_public_exchange'],
            'exchanges.binance_paper_provisioning_approved' => true]);
        $registry = new ExchangeRegistry([
            'bybit' => app(BybitAdapter::class),
            'binance' => app(BinancePublicAdapter::class),
            'new_public_exchange' => new class implements ExchangeAdapter
            {
                public function id(): string
                {
                    return 'new_public_exchange';
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
        app()->instance(ExchangeRegistry::class, $registry);
        $result = app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(13, $result['created_other_blocked_unfunded']);
        self::assertSame(39, PaperAccountRecord::query()->where('is_primary', true)->count());
        $accounts = PaperAccountRecord::query()->where('exchange_id', 'new_public_exchange')->get();
        foreach ($accounts as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertDecimalSame('0', $account->starting_balance);
        }
    }

    public function test_global_five_percent_drawdown_and_reserved_risk_budget_are_independent_fail_closed_gates(): void
    {
        $sync = app(PaperTopologySynchronizer::class);
        $sync->sync(true);
        $guard = app(GlobalPaperCapitalGuard::class);
        self::assertNull($guard->entryRejectionReason());
        // 13 x 10,000 initial -> 130,000. Each account falls by 499.99; aggregate below 5%.
        PaperAccountRecord::query()->where('exchange_id', 'bybit')->update(['current_equity' => '9500.01']);
        self::assertNull($guard->entryRejectionReason());
        PaperAccountRecord::query()->where('exchange_id', 'bybit')->update(['current_equity' => '9500']);
        self::assertSame('global_capital_loss_limit_exceeded', $guard->entryRejectionReason());
        PaperAccountRecord::query()->where('exchange_id', 'bybit')->update(['current_equity' => '10000', 'reserved_risk' => '499']);
        self::assertNull($guard->entryRejectionReason('13'));
        self::assertSame('global_risk_budget_exceeded', $guard->entryRejectionReason('14'));
        self::assertSame('global_risk_budget_unverified', $guard->entryRejectionReason('-1'));
    }

    private function registerAdditionalStrategy(): void
    {
        $registered = app(StrategyRegistry::class);
        $strategies = $registered->all();
        $strategies[] = new class implements StrategyInterface
        {
            public function id(): string
            {
                return 'sample_new_strategy';
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'Test-only registered strategy';
            }

            public function parameters(): array
            {
                return ['version' => '1.0.0', 'model_risk_percent' => '0.1'];
            }

            public function evaluate(StrategyInput $input): SignalDecision
            {
                throw new \LogicException('No trading in topology test.');
            }
        };
        app()->instance(StrategyRegistry::class, new StrategyRegistry(
            $strategies, app(LevelInteractionAnalyzer::class), app(LevelProbabilityService::class)
        ));
        app()->forgetInstance(PaperTopologySynchronizer::class);
        app()->forgetInstance(PaperAccountService::class);
        config(['paper.primary_strategies' => [...(array) config('paper.primary_strategies'), 'sample_new_strategy']]);
    }
}
