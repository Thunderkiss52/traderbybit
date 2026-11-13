<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePublicAdapter;
use App\Exchanges\Binance\ExchangePaperReconciliation;
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
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class GlobalCapitalAndTopologyR6Test extends TestCase
{
    use RefreshDatabase;

    public function test_topology_sync_provisions_all_registered_pairs_without_execution_approval(): void
    {
        $sync = app(PaperTopologySynchronizer::class);
        $dry = $sync->sync();
        self::assertSame('DRY_RUN', $dry['mode']);
        self::assertCount(130, $dry['missing_pairs']);
        self::assertSame(0, PaperAccountRecord::query()->count());

        config(['exchanges.binance_paper_provisioning_approved' => false]);
        $first = $sync->sync(true);
        self::assertSame(13, $first['created_bybit']);
        self::assertSame(13, $first['created_binance_blocked_unfunded']);
        self::assertSame(104, $first['created_other_blocked_unfunded']);
        self::assertTrue($first['binance_pending_approval']);
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'bybit')->count());
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'BLOCKED')->where('starting_balance', '0')->count());
        self::assertSame(130, PaperAccountRecord::query()->where('is_primary', true)->count());

        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $second = $sync->sync(true);
        self::assertSame(0, $second['created_binance_blocked_unfunded']);
        $third = $sync->sync(true);
        self::assertSame(0, $third['created_bybit']);
        self::assertSame(0, $third['created_binance_blocked_unfunded']);
        self::assertSame(130, PaperAccountRecord::query()->where('is_primary', true)->count());
        self::assertSame(13, PaperAccountRecord::query()->where('exchange_id', 'binance')->where('status', 'BLOCKED')->count());
    }

    public function test_fourteenth_strategy_is_added_to_all_ten_exchanges_without_mutating_existing_accounts(): void
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
        self::assertSame(140, PaperAccountRecord::query()->where('is_primary', true)->count());
        self::assertSame(8, $result['created_other_blocked_unfunded']);
        $bybit = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'sample_new_strategy')->firstOrFail();
        $binance = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'sample_new_strategy')->firstOrFail();
        self::assertSame('BLOCKED', $bybit->status);
        self::assertDecimalSame('0', $bybit->starting_balance);
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

                public function name(): string
                {
                    return 'New Public Exchange';
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
        self::assertSame(117, $result['created_other_blocked_unfunded']);
        self::assertSame(143, PaperAccountRecord::query()->where('is_primary', true)->count());
        $accounts = PaperAccountRecord::query()->where('exchange_id', 'new_public_exchange')->get();
        foreach ($accounts as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertDecimalSame('0', $account->starting_balance);
        }
    }

    public function test_registration_expands_to_143_then_154_accounts_without_capital_or_history_changes(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperTopologySynchronizer::class)->sync(true);
        app(BinancePaperCapital::class)->allocate('10000', 'TEST-LEGACY-CAPITAL');
        $legacy = DB::table('paper_accounts')->whereIn('exchange_id', ['bybit', 'binance'])->orderBy('id')->get();
        self::assertCount(26, $legacy);
        self::assertSame(26, $legacy->where('starting_balance', '>', 0)->count());
        self::assertSame(13, DB::table('paper_accounts')->where('starting_balance', '10000')->where('status', 'BLOCKED')->count());
        self::assertSame(104, DB::table('paper_accounts')->where('starting_balance', '0')->where('status', 'BLOCKED')->count());
        $blocked = $legacy->where('exchange_id', 'bybit')->take(5)->pluck('id');
        DB::table('paper_accounts')->whereIn('id', $blocked)->update(['status' => 'BLOCKED', 'current_drawdown_percent' => '5']);
        $legacy = DB::table('paper_accounts')->whereIn('exchange_id', ['bybit', 'binance'])->orderBy('id')->get();
        $legacyAccountIds = $legacy->pluck('id');
        $events = DB::table('paper_capital_events')->whereIn('paper_account_id', $legacyAccountIds)->orderBy('id')->get()->toJson();

        app(ExchangeCatalogService::class)->register(['id' => 'new_venue', 'name' => 'New Venue', 'market_type' => 'spot']);
        self::assertSame(143, DB::table('paper_accounts')->where('is_primary', true)->count());
        $this->registerAdditionalStrategy();
        app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame(154, DB::table('paper_accounts')->where('is_primary', true)->count());
        self::assertSame([], app(PaperTopologySynchronizer::class)->sync()['missing_pairs']);
        app(PaperTopologySynchronizer::class)->sync(true);
        self::assertSame($legacy->toJson(), DB::table('paper_accounts')->whereIn('id', $legacyAccountIds)->orderBy('id')->get()->toJson());
        self::assertSame($events, DB::table('paper_capital_events')->whereIn('paper_account_id', $legacyAccountIds)->orderBy('id')->get()->toJson());
        self::assertSame(5, DB::table('paper_accounts')->whereIn('id', $blocked)->where('status', 'BLOCKED')->count());
        $pairs = DB::table('paper_accounts')->select(['exchange_id', 'primary_strategy_id'])->get();
        self::assertSame(154, $pairs->unique(fn ($row) => $row->exchange_id.'|'.$row->primary_strategy_id)->count());
        $new = PaperAccountRecord::query()->where('exchange_id', 'new_venue')->where('primary_strategy_id', 'sample_new_strategy')->firstOrFail();
        self::assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:primary:new_venue:sample_new_strategy')->toString(), $new->id);
        self::assertDecimalSame('0', $new->starting_balance);
        self::assertSame('BLOCKED', $new->status);
        $m9 = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status'], json_encode($m9['issues']));
        self::assertSame(154, $m9['expected_pairs']);
        self::assertSame(154, $m9['verified_pairs']);
    }

    public function test_m9_reconciles_130_and_rejects_foreign_financial_provenance(): void
    {
        app(PaperTopologySynchronizer::class)->sync(true);
        $m9 = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status'], json_encode($m9['issues']));
        self::assertSame(130, $m9['expected_pairs']);
        self::assertSame(130, $m9['verified_pairs']);
        $account = PaperAccountRecord::query()->where('exchange_id', 'kraken')->firstOrFail();
        self::assertSame($account->id, app(PaperAccountService::class)->primaryAccount($account->strategy_id, 'kraken')->id);
        DB::table('paper_capital_events')->insert([
            'id' => Uuid::uuid4()->toString(), 'paper_account_id' => $account->id,
            'exchange_id' => 'bybit', 'amount' => '100', 'approval_reference' => 'TEST-FOREIGN',
            'reason' => 'OWNER_APPROVED_PAPER_ALLOCATION', 'occurred_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $m9 = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $m9['status']);
        self::assertContains('CROSS_EXCHANGE_PAPER_CAPITAL_EVENT', array_column($m9['issues'], 'code'));
    }

    public function test_global_five_percent_drawdown_and_reserved_risk_budget_are_independent_fail_closed_gates(): void
    {
        foreach ((array) config('exchanges.enabled', []) as $ex) {
            config(["exchanges.{$ex}.paper_execution_approved" => true]);
        }
        $sync = app(PaperTopologySynchronizer::class);
        $sync->sync(true);
        $funded = PaperAccountRecord::query()->where('is_primary', true)->where('starting_balance', '>', 0);
        self::assertSame(13, (clone $funded)->count());
        $guard = app(GlobalPaperCapitalGuard::class);
        self::assertNull($guard->entryRejectionReason());
        // Only 13 funded Bybit accounts bear risk; unfunded discovery does not
        // inflate either the protected capital or the owner's five-percent cap.
        (clone $funded)->update(['current_equity' => '9500.01']);
        self::assertNull($guard->entryRejectionReason());
        (clone $funded)->update(['current_equity' => '9500']);
        self::assertSame('global_capital_loss_limit_exceeded', $guard->entryRejectionReason());
        (clone $funded)->update(['current_equity' => '10000', 'reserved_risk' => '499']);
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
    }
}
