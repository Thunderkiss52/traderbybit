<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BinanceDeploymentInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_binance_deployment_initializer_is_idempotent_and_preserves_bybit_history(): void
    {
        // 1. Setup representative Bybit primary accounts with financial history
        $accountService = $this->app->make(PaperAccountService::class);
        $bybitAccounts = $accountService->allPrimaryAccounts('bybit');
        self::assertCount(13, $bybitAccounts);

        // Add dummy positions and executions for a Bybit account to prove preservation
        $trendBybit = $bybitAccounts->firstWhere('primary_strategy_id', 'trend_pullback');
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => now(),
            'candle_close_time' => now(),
            'entry_low' => '100',
            'entry_high' => '101',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => now()->addMinutes(30),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.3.0',
            'schema_version' => '1.0.0',
            'strategy_id' => 'trend_pullback',
        ]);
        $positionId = (string) Uuid::uuid4();
        VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $trendBybit->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '100',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'initial_quantity' => '1',
            'remaining_quantity' => '1',
            'qty_step' => '0.001',
            'risk_capital' => '50',
            'gross_pnl' => '0',
            'net_pnl' => '0',
            'fees' => '0',
            'funding' => '0',
            'unrealized_pnl' => '0',
            'opened_at' => now(),
            'maximum_holding_until' => now()->addHours(4),
            'execution_model' => 'close_5m',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $positionId,
            'exchange_id' => 'bybit',
            'type' => 'ENTRY',
            'side' => 'BUY',
            'price' => '100',
            'quantity' => '1',
            'gross_pnl' => '0',
            'commission' => '0',
            'slippage' => '0',
            'market_event_at' => now(),
            'registered_at' => now(),
            'price_source' => 'close_5m',
            'ambiguous_execution' => false,
            'idempotency_key' => 'test-exec-'.Uuid::uuid4()->toString(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        RiskDecisionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $trendBybit->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'allowed' => true,
            'reasons' => ['test'],
            'snapshot' => ['risk' => 'passed'],
            'risk_version' => '1.0.0',
            'decided_at' => now(),
        ]);
        JournalEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $trendBybit->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'event_type' => 'RISK_ACCEPTED',
            'occurred_at' => now(),
            'payload' => ['test' => true],
            'idempotency_key' => 'test-journal-'.Uuid::uuid4()->toString(),
        ]);

        // Capture exact Bybit state before initialization
        $bybitBefore = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();

        // 2. Run initializer
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $result = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        self::assertSame('INITIALIZED_SUCCESSFULLY', $result['status']);
        self::assertTrue($result['bybit_history_preserved']);
        self::assertSame(26, $result['canonical_accounts_count']);
        self::assertSame(13, $result['provisioned']['created']);
        self::assertSame(13, $result['capital']['accounts_allocated']);
        self::assertSame('LEDGER_PASS_ONLY', $result['reconciliation']['status']);

        // Assert Bybit accounts are byte-for-byte unchanged
        $bybitAfter = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();
        self::assertEquals($bybitBefore, $bybitAfter);

        // Assert exactly 26 canonical accounts (13 Bybit, 13 Binance)
        self::assertSame(13, DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('is_primary', true)->count());
        self::assertSame(13, DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->count());
        self::assertSame(26, DB::table('paper_accounts')->where('is_primary', true)->count());

        // Assert all 13 Binance accounts remain in BLOCKED status (execution not activated)
        foreach (DB::table('paper_accounts')->where('exchange_id', 'binance')->get() as $acc) {
            self::assertSame('BLOCKED', $acc->status);
            self::assertTrue(BigDecimal::of((string) $acc->starting_balance)->isEqualTo(10000));
            self::assertTrue(BigDecimal::of((string) $acc->current_equity)->isEqualTo(10000));
            self::assertNotNull($acc->configuration_hash);
        }

        // 3. Repeat initialization (idempotency check)
        $repeatResult = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');
        self::assertSame('INITIALIZED_SUCCESSFULLY', $repeatResult['status']);
        self::assertSame(0, $repeatResult['provisioned']['created']);
        self::assertSame(13, $repeatResult['provisioned']['already_provisioned']);
        self::assertSame(13, $repeatResult['capital']['idempotent_repeats']);
        self::assertSame(26, DB::table('paper_accounts')->where('is_primary', true)->count());

        // 4. Test Artisan command
        $this->artisan('binance:initialize-paper', ['--force' => true])->assertSuccessful();
    }

    public function test_deployment_initialization_is_idempotent_after_binance_activation_and_trading_history(): void
    {
        // 1. Initial provisioning
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $result = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');
        self::assertSame('INITIALIZED_SUCCESSFULLY', $result['status']);
        self::assertSame(13, $result['provisioned']['created']);

        // 2. Simulate owner activation: Binance accounts become ACTIVE
        DB::table('paper_accounts')->where('exchange_id', 'binance')
            ->whereNotIn('strategy_id', ['btc_eth_lead_lag', 'funding_open_interest_squeeze'])
            ->update(['status' => 'ACTIVE']);

        // 3. Simulate active trading history on Binance trend_pullback account
        $binanceTrend = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => now(),
            'candle_close_time' => now(),
            'entry_low' => '100',
            'entry_high' => '101',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => now()->addMinutes(30),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.3.0',
            'schema_version' => '1.0.0',
            'strategy_id' => 'trend_pullback',
        ]);
        $positionId = (string) Uuid::uuid4();
        VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $binanceTrend->id,
            'exchange_id' => 'binance',
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '100',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'initial_quantity' => '1',
            'remaining_quantity' => '1',
            'qty_step' => '0.001',
            'risk_capital' => '50',
            'gross_pnl' => '0',
            'net_pnl' => '-0.06',
            'fees' => '0.06',
            'funding' => '0',
            'unrealized_pnl' => '0',
            'opened_at' => now(),
            'maximum_holding_until' => now()->addHours(4),
            'execution_model' => 'close_5m',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $positionId,
            'exchange_id' => 'binance',
            'type' => 'ENTRY',
            'side' => 'BUY',
            'price' => '100',
            'quantity' => '1',
            'gross_pnl' => '0',
            'commission' => '0.06',
            'slippage' => '0',
            'market_event_at' => now(),
            'registered_at' => now(),
            'price_source' => 'close_5m',
            'ambiguous_execution' => false,
            'idempotency_key' => 'test-exec-binance-'.Uuid::uuid4()->toString(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $binanceTrend->update([
            'current_equity' => '9999.94',
            'realized_pnl' => '-0.06',
        ]);

        $binanceBefore = DB::table('paper_accounts')->where('exchange_id', 'binance')->orderBy('id')->get();
        $positionsBefore = DB::table('virtual_positions')->where('exchange_id', 'binance')->orderBy('id')->get();

        // 4. Run initializer again as part of a redeploy
        $repeatResult = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        // 5. Assert deployment succeeds idempotently without error or capital resetting
        self::assertSame('INITIALIZED_SUCCESSFULLY', $repeatResult['status']);
        self::assertSame(0, $repeatResult['provisioned']['created']);
        self::assertSame(13, $repeatResult['provisioned']['already_provisioned']);
        self::assertSame(0, $repeatResult['capital']['accounts_allocated']);
        self::assertSame(13, $repeatResult['capital']['idempotent_repeats']);
        self::assertSame('CAPITAL_ALREADY_ALLOCATED', $repeatResult['capital']['status']);
        self::assertTrue($repeatResult['bybit_history_preserved']);
        self::assertSame(26, $repeatResult['canonical_accounts_count']);
        self::assertSame('LEDGER_PASS_ONLY', $repeatResult['reconciliation']['status']);

        // Assert Binance accounts and positions are identical to pre-deploy state
        $binanceAfter = DB::table('paper_accounts')->where('exchange_id', 'binance')->orderBy('id')->get();
        $positionsAfter = DB::table('virtual_positions')->where('exchange_id', 'binance')->orderBy('id')->get();
        self::assertEquals($binanceBefore, $binanceAfter);
        self::assertEquals($positionsBefore, $positionsAfter);
    }

    public function test_deployment_initializer_succeeds_with_historical_bybit_strategy_namespaced_account_ids(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $bybitAccounts = $accountService->allPrimaryAccounts('bybit');

        // Mutate original 7 non-baseline accounts to their exact historical traderbybit:paper:strategy IDs
        $originalStrategies = [
            'support_resistance_breakout',
            'regime_switching',
            'bollinger_mean_reversion',
            'support_resistance_bounce',
            'trend_pullback',
            'momentum',
            'donchian_atr_breakout',
        ];
        foreach ($originalStrategies as $strategy) {
            $historicalId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:strategy:{$strategy}")->toString();
            $account = DB::table('paper_accounts')->where('exchange_id', 'bybit')->where('primary_strategy_id', $strategy)->first();
            DB::table('equity_snapshots')->where('paper_account_id', $account->id)->delete();
            DB::table('paper_accounts')->where('id', $account->id)->update(['id' => $historicalId]);
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
        }

        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $result = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        self::assertSame('INITIALIZED_SUCCESSFULLY', $result['status']);
        self::assertSame(13, $result['provisioned']['created']);
        self::assertSame(13, $result['capital']['accounts_allocated']);
        self::assertSame('LEDGER_PASS_ONLY', $result['reconciliation']['status']);
        self::assertTrue($result['bybit_history_preserved']);
        self::assertSame(26, $result['canonical_accounts_count']);
    }
}
