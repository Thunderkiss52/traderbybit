<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Tests\TestCase;

final class BinancePaperIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_six_accounts_are_isolated_and_owner_allocation_cannot_change_bybit(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        $bybit = app(PaperAccountService::class)->allPrimaryAccounts();
        self::assertCount(13, $bybit);
        // Compare persisted bytes on both sides. PostgreSQL normalizes NUMERIC
        // scale on read, while freshly created models retain input strings.
        $bybit->each->refresh();
        $before = $bybit->mapWithKeys(fn ($a) => [$a->id => [(string) $a->starting_balance, (string) $a->current_equity]])->all();
        $provisioned = app(BinancePaperProvisioner::class)->provision();
        self::assertSame(13, $provisioned['created']);
        self::assertSame(0, $provisioned['already_provisioned']);
        self::assertSame(0, app(BinancePaperProvisioner::class)->provision()['created']);
        foreach (DB::table('paper_accounts')->where('exchange_id', 'binance')->get() as $account) {
            self::assertSame('BLOCKED', $account->status);
            self::assertEquals(0, (float) $account->starting_balance);
            self::assertEquals(0, (float) $account->current_equity);
        }
        $capital = app(BinancePaperCapital::class)->allocate('10000', 'OWNER-VECTOR-20261023');
        self::assertSame(13, $capital['accounts_allocated']);
        self::assertSame(13, app(BinancePaperCapital::class)->allocate('10000', 'OWNER-VECTOR-20261023')['idempotent_repeats']);
        self::assertSame(13, DB::table('paper_capital_events')->where('exchange_id', 'binance')->count());
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);
        foreach ($bybit as $account) {
            $fresh = $account->fresh();
            self::assertSame($before[$fresh->id][0], (string) $fresh->starting_balance);
            self::assertSame($before[$fresh->id][1], (string) $fresh->current_equity);
        }
        self::assertSame(26, DB::table('paper_accounts')->where('is_primary', true)->count());
        self::assertSame(0, DB::table('virtual_positions')->where('exchange_id', 'binance')->count());
    }

    public function test_funded_binance_account_cannot_be_activated_by_an_equity_recalculation(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-VECTOR-20261023');

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        self::assertSame('BLOCKED', $account->status);

        // Market/funding jobs call this method; their writes cannot implicitly
        // grant the separate, explicit owner activation required for Binance.
        $recalculate = new ReflectionMethod(PositionLifecycleService::class, 'refreshAccountMetrics');
        $recalculate->invoke(app(PositionLifecycleService::class), $account, BigDecimal::zero(), BigDecimal::zero());

        self::assertSame('BLOCKED', $account->refresh()->status);
        self::assertTrue(BigDecimal::of($account->current_equity)->isEqualTo('10000'));
    }

    public function test_m9_rejects_primary_accounts_with_unknown_exchange_even_if_all_26_pairs_exist(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        $bybit = app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);

        // A previously unseen exchange is outside the 26 canonical pairs.
        // A WHERE IN census used to ignore this extra PRIMARY account.
        $rogue = $bybit->first()->replicate();
        $rogue->id = (string) Str::uuid();
        $rogue->exchange_id = 'unexpected';
        $rogue->save();

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertSame(26, $report['verified_pairs']);
        self::assertContains('TOTAL_PRIMARY_ACCOUNT_COUNT_MISMATCH', array_column($report['issues'], 'code'));
        self::assertContains('UNRECOGNIZED_PRIMARY_EXCHANGE', array_column($report['issues'], 'code'));
    }

    public function test_m9_rejects_foreign_capital_events_on_an_otherwise_valid_binance_account(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-VECTOR-20261023');
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);

        $account = DB::table('paper_accounts')->where('exchange_id', 'binance')->first();
        $now = now('UTC');
        DB::table('paper_capital_events')->insert([
            'id' => (string) Str::uuid(), 'paper_account_id' => $account->id,
            'exchange_id' => 'bybit', 'amount' => '10000',
            'approval_reference' => 'CROSS-EXCHANGE-TEST',
            'reason' => 'OWNER_APPROVED_PAPER_ALLOCATION', 'occurred_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('CROSS_EXCHANGE_PAPER_CAPITAL_EVENT', array_column($report['issues'], 'code'));
    }

    public function test_m9_rejects_capital_events_even_on_zero_balance_binance_accounts(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        $account = DB::table('paper_accounts')->where('exchange_id', 'binance')->first();
        $now = now('UTC');
        DB::table('paper_capital_events')->insert([
            'id' => (string) Str::uuid(), 'paper_account_id' => $account->id,
            'exchange_id' => 'binance', 'amount' => '10000',
            'approval_reference' => 'UNAUTHORIZED-TEST',
            'reason' => 'OWNER_APPROVED_PAPER_ALLOCATION', 'occurred_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('PAPER_CAPITAL_PROVENANCE_MISMATCH', array_column($report['issues'], 'code'));
    }

    public function test_m9_reconciles_execution_quantity_and_gross_pnl_against_position(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        app(BinancePaperCapital::class)->allocate('10000', 'OWNER-VECTOR-20261023');
        $account = DB::table('paper_accounts')->where('exchange_id', 'binance')
            ->where('primary_strategy_id', 'baseline')->first();
        $now = now('UTC');
        $signal = SignalRecord::query()->create([
            'id' => (string) Str::uuid(), 'exchange' => 'binance', 'category' => 'linear',
            'symbol' => 'BTCUSDT', 'timeframe' => '15', 'side' => 'BUY', 'score' => 80,
            'decision_time' => $now, 'candle_close_time' => $now,
            'expires_at' => $now->copy()->addMinutes(15), 'maximum_holding_minutes' => 240,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [],
            'strategy_version' => '1.0.0', 'schema_version' => '1.0.0',
            'strategy_id' => 'baseline',
        ]);
        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(), 'paper_account_id' => $account->id,
            'exchange_id' => 'binance', 'signal_id' => $signal->id, 'symbol' => 'BTCUSDT',
            'side' => 'BUY', 'quality_class' => 'A', 'status' => 'OPEN',
            'entry_price' => '100', 'stop_loss' => '95', 'take_profit_1' => '110',
            'take_profit_2' => '115', 'initial_quantity' => '1', 'remaining_quantity' => '1',
            'qty_step' => '0.001', 'risk_capital' => '5', 'gross_pnl' => '0',
            'net_pnl' => '0', 'unrealized_pnl' => '0', 'fees' => '0', 'funding' => '0',
            'opened_at' => $now, 'maximum_holding_until' => $now->copy()->addHours(4),
            'execution_model' => 'TEST_FIXTURE', 'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);
        VirtualExecutionRecord::query()->create([
            'id' => (string) Str::uuid(), 'position_id' => $position->id,
            'exchange_id' => 'binance', 'type' => 'ENTRY', 'side' => 'BUY',
            'quantity' => '1', 'price' => '100', 'gross_pnl' => '0',
            'commission' => '0', 'slippage' => '0', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TEST_FIXTURE',
            'idempotency_key' => 'test-m9-entry-'.$position->id,
            'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);

        // A Binance trade cannot be reconciled as valid zero-capital history,
        // even if every other amount in its position and execution matches.
        DB::table('paper_accounts')->where('id', $account->id)->update([
            'starting_balance' => '0', 'current_equity' => '0', 'peak_equity' => '0',
        ]);
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('UNFUNDED_BINANCE_TRADING_HISTORY', array_column($report['issues'], 'code'));
        DB::table('paper_accounts')->where('id', $account->id)->update([
            'starting_balance' => '10000', 'current_equity' => '10000', 'peak_equity' => '10000',
        ]);
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);

        DB::table('virtual_positions')->where('id', $position->id)->update(['remaining_quantity' => '0.75']);
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('EXECUTION_REMAINING_QUANTITY_MISMATCH', array_column($report['issues'], 'code'));

        DB::table('virtual_positions')->where('id', $position->id)->update(['remaining_quantity' => '1']);
        DB::table('virtual_executions')->where('position_id', $position->id)->update(['quantity' => '0.5']);
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('EXECUTION_ENTRY_QUANTITY_MISMATCH', array_column($report['issues'], 'code'));

        DB::table('virtual_executions')->where('position_id', $position->id)->update([
            'quantity' => '1', 'gross_pnl' => '1',
        ]);
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('EXECUTION_GROSS_PNL_MISMATCH', array_column($report['issues'], 'code'));

        // After repairing the deliberately corrupted execution, demonstrate
        // that a Binance-labelled funding row cannot launder a Bybit source.
        DB::table('virtual_executions')->where('position_id', $position->id)->update(['gross_pnl' => '0']);
        self::assertSame('LEDGER_PASS_ONLY', app(ExchangePaperReconciliation::class)->inspect()['status']);
        $fundingId = DB::table('market_funding_events')->insertGetId([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'funding_at' => $now, 'rate' => '0', 'mark_price' => '100',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('position_funding_events')->insert([
            'id' => (string) Str::uuid(), 'position_id' => $position->id,
            'exchange_id' => 'binance', 'market_funding_event_id' => $fundingId,
            'quantity' => '1', 'mark_price' => '100', 'rate' => '0',
            'amount' => '0', 'funding_at' => $now,
        ]);
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertContains('FUNDING_SOURCE_PROVENANCE_MISMATCH', array_column($report['issues'], 'code'));
    }

    public function test_missing_accounts_must_make_m9_reconciliation_fail(): void
    {
        app(PaperAccountService::class)->allPrimaryAccounts();
        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertSame(13, $report['verified_pairs']);
        self::assertSame(26, $report['expected_pairs']);
        self::assertCount(14, $report['issues']);
    }

    public function test_provisioner_accepts_historical_strategy_namespaced_bybit_account_ids(): void
    {
        config()->set('exchanges.binance_paper_provisioning_approved', true);
        // Seed Bybit primary accounts
        app(PaperAccountService::class)->allPrimaryAccounts();

        // Mutate one Bybit primary account ID to historical traderbybit:paper:strategy namespace
        $strategy = 'momentum';
        $historicalId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:strategy:{$strategy}")->toString();
        $current = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('primary_strategy_id', $strategy)->first();
        DB::table('equity_snapshots')->where('paper_account_id', $current->id)->delete();
        DB::table('paper_accounts')->where('id', $current->id)->update(['id' => $historicalId]);
        DB::table('equity_snapshots')->insert([
            'paper_account_id' => $historicalId,
            'exchange_id' => 'bybit',
            'recorded_at' => now('UTC'),
            'equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'account:initial:bybit',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // Provisioning must succeed and recognize historical provenance
        $result = app(BinancePaperProvisioner::class)->provision();
        self::assertSame(13, $result['created']);
        self::assertSame('BLOCKED_UNTIL_VERIFIED', $result['paper_execution']);
    }
}
