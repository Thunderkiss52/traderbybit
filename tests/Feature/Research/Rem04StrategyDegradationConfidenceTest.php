<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\StrategyDegradationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Rem04StrategyDegradationConfidenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Small sample sizes (N < 30) must be flagged with low confidence / insufficient sample size,
     * wide confidence intervals, and cannot declare definitive alpha decay.
     */
    public function test_small_sample_size_below_30_is_flagged_with_low_confidence(): void
    {
        $service = new StrategyDegradationService;
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['strategy_id' => 'sample_size_test']);
        $now = CarbonImmutable::now();

        // Seed 10 losing positions (N = 10 < 30)
        for ($i = 0; $i < 10; $i++) {
            $signalId = (string) Str::uuid();
            SignalRecord::query()->create([
                'id' => $signalId,
                'strategy_id' => 'sample_size_test',
                'strategy_version' => '1.0.0',
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 85,
                'decision_time' => $now->subDays(8)->addHours($i * 4),
                'candle_close_time' => $now->subDays(8)->addHours($i * 4),
                'entry_low' => '60000',
                'entry_high' => '60100',
                'stop_loss' => '59500',
                'take_profit_1' => '61000',
                'take_profit_2' => '62000',
                'risk_reward_1' => '2.0',
                'risk_reward_2' => '3.0',
                'model_risk_percent' => '1.0',
                'expires_at' => $now->subDays(8)->addHours($i * 4)->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['test_setup'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'schema_version' => '1.0.0',
            ]);

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $account->id,
                'signal_id' => $signalId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => 'CLOSED_SL',
                'entry_price' => '60000.0',
                'stop_loss' => '59500.0',
                'take_profit_1' => '61000.0',
                'take_profit_2' => '62000.0',
                'initial_quantity' => '0.2',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => '-100.0',
                'net_pnl' => '-102.5',
                'unrealized_pnl' => '0.0',
                'fees' => '2.5',
                'funding' => '0.0',
                'r_multiple' => -1.0,
                'opened_at' => $now->subDays(8)->addHours($i * 4),
                'closed_at' => $now->subDays(8)->addHours($i * 4)->addHours(2),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        $snap = $service->evaluate('sample_size_test', '14d');

        self::assertSame(10, $snap->sample_size);
        $diag = $snap->diagnostics;

        // Verify sample size check and low confidence flags
        self::assertTrue($diag['insufficient_sample_size']);
        self::assertFalse($diag['sample_size_sufficient']);
        self::assertSame(30, $diag['min_sample_size']);
        self::assertSame('LOW', $diag['confidence_rating']);
        self::assertFalse($diag['definitive_alpha_decay']);

        // Must not declare definitive ALPHA_DECAY; status is WATCH with POSSIBLE_ALPHA_DECAY
        self::assertSame('POSSIBLE_ALPHA_DECAY', $snap->degradation_type);
        self::assertSame('WATCH', $snap->status);

        // Standard error must be computed
        self::assertArrayHasKey('expectancy_confidence', $diag);
        self::assertArrayHasKey('profit_factor_confidence', $diag);
    }

    /**
     * When sample size reaches N >= 30 and structural alpha is negative,
     * the system declares definitive ALPHA_DECAY with HIGH confidence rating.
     */
    public function test_sample_size_gte_30_declares_definitive_alpha_decay_with_high_confidence(): void
    {
        $service = new StrategyDegradationService;
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['strategy_id' => 'decay_test']);
        $now = CarbonImmutable::now();

        // Seed 35 positions with negative gross PnL (structural alpha decay)
        for ($i = 0; $i < 35; $i++) {
            $signalId = (string) Str::uuid();
            SignalRecord::query()->create([
                'id' => $signalId,
                'strategy_id' => 'decay_test',
                'strategy_version' => '1.0.0',
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 85,
                'decision_time' => $now->subDays(12)->addHours($i * 6),
                'candle_close_time' => $now->subDays(12)->addHours($i * 6),
                'entry_low' => '60000',
                'entry_high' => '60100',
                'stop_loss' => '59500',
                'take_profit_1' => '61000',
                'take_profit_2' => '62000',
                'risk_reward_1' => '2.0',
                'risk_reward_2' => '3.0',
                'model_risk_percent' => '1.0',
                'expires_at' => $now->subDays(12)->addHours($i * 6)->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['test_setup'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'schema_version' => '1.0.0',
            ]);

            // 5 small wins, 30 losses -> structural alpha loss without exceeding 20% critical drawdown
            $isWin = ($i < 5);
            $gross = $isWin ? 20.0 : -45.0;
            $net = $isWin ? 17.5 : -47.5;
            $r = $isWin ? 0.4 : -0.9;

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $account->id,
                'signal_id' => $signalId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => $isWin ? 'CLOSED_TP' : 'CLOSED_SL',
                'entry_price' => '60000.0',
                'stop_loss' => '59500.0',
                'take_profit_1' => '61000.0',
                'take_profit_2' => '62000.0',
                'initial_quantity' => '0.2',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) $gross,
                'net_pnl' => (string) $net,
                'unrealized_pnl' => '0.0',
                'fees' => '2.5',
                'funding' => '0.0',
                'r_multiple' => $r,
                'opened_at' => $now->subDays(12)->addHours($i * 6),
                'closed_at' => $now->subDays(12)->addHours($i * 6)->addHours(2),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        $snap = $service->evaluate('decay_test', '14d');

        self::assertSame(35, $snap->sample_size);
        $diag = $snap->diagnostics;

        // Sample size sufficient -> HIGH confidence rating
        self::assertFalse($diag['insufficient_sample_size']);
        self::assertTrue($diag['sample_size_sufficient']);
        self::assertSame('HIGH', $diag['confidence_rating']);
        self::assertTrue($diag['definitive_alpha_decay']);
        self::assertTrue($diag['structural_negative_alpha']);
        self::assertFalse($diag['is_fee_drag']);

        // Declares definitive ALPHA_DECAY and DEGRADING status
        self::assertSame('ALPHA_DECAY', $snap->degradation_type);
        self::assertSame('DEGRADING', $snap->status);

        // 95% Confidence interval for expectancy: upper bound is negative
        $expConf = $diag['expectancy_confidence'];
        self::assertLessThan(0.0, $expConf['mean']);
        self::assertGreaterThan(0.0, $expConf['standard_error']);
        self::assertLessThan(0.0, $expConf['ci_upper'], '95% CI upper bound must be strictly negative for definitive decay.');
    }

    /**
     * Fee Drag Isolation: Distinguish strategies suffering from execution costs
     * (gross PnL >= 0, net PnL < 0) from structural negative alpha (gross PnL < 0).
     */
    public function test_fee_drag_isolation_distinguishes_execution_costs_from_structural_decay(): void
    {
        $service = new StrategyDegradationService;
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['strategy_id' => 'fee_drag_strategy']);
        $now = CarbonImmutable::now();

        // 32 trades: positive gross PnL (+10.0 per trade) but high fees (-15.0 per trade) -> negative net (-5.0)
        for ($i = 0; $i < 32; $i++) {
            $signalId = (string) Str::uuid();
            $posId = (string) Str::uuid();

            SignalRecord::query()->create([
                'id' => $signalId,
                'strategy_id' => 'fee_drag_strategy',
                'strategy_version' => '1.0.0',
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'ETHUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 88,
                'decision_time' => $now->subDays(10)->addHours($i * 4),
                'candle_close_time' => $now->subDays(10)->addHours($i * 4),
                'entry_low' => '2500',
                'entry_high' => '2510',
                'stop_loss' => '2450',
                'take_profit_1' => '2560',
                'take_profit_2' => '2600',
                'risk_reward_1' => '1.5',
                'risk_reward_2' => '2.5',
                'model_risk_percent' => '1.0',
                'expires_at' => $now->subDays(10)->addHours($i * 4)->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['fee_test'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'schema_version' => '1.0.0',
            ]);

            VirtualPositionRecord::query()->create([
                'id' => $posId,
                'paper_account_id' => $account->id,
                'signal_id' => $signalId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'ETHUSDT',
                'side' => 'BUY',
                'status' => 'CLOSED_TIMEOUT',
                'entry_price' => '2500.0',
                'stop_loss' => '2450.0',
                'take_profit_1' => '2560.0',
                'take_profit_2' => '2600.0',
                'initial_quantity' => '1.0',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.01',
                'risk_capital' => '50.0',
                'gross_pnl' => '10.0',
                'net_pnl' => '-5.0',
                'unrealized_pnl' => '0.0',
                'fees' => '12.0',
                'funding' => '0.0',
                'r_multiple' => -0.1,
                'opened_at' => $now->subDays(10)->addHours($i * 4),
                'closed_at' => $now->subDays(10)->addHours($i * 4)->addHours(4),
                'maximum_holding_until' => $now->addDay(),
            ]);

            // Add virtual execution with slippage
            VirtualExecutionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'position_id' => $posId,
                'type' => 'EXIT',
                'side' => 'SELL',
                'quantity' => '1.0',
                'price' => '2510.0',
                'gross_pnl' => '10.0',
                'commission' => '12.0',
                'slippage' => '3.0',
                'market_event_at' => $now->subDays(10)->addHours($i * 4)->addHours(4),
                'registered_at' => $now->subDays(10)->addHours($i * 4)->addHours(4),
                'price_source' => 'market',
                'ambiguous_execution' => false,
                'idempotency_key' => 'exec_fee_test_'.$i,
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
            ]);
        }

        $snap = $service->evaluate('fee_drag_strategy', '14d');

        self::assertSame(32, $snap->sample_size);
        $diag = $snap->diagnostics;

        // Fee drag isolation invariants
        self::assertTrue($diag['is_fee_drag'], 'Must identify strategy as suffering from fee drag.');
        self::assertFalse($diag['structural_negative_alpha'], 'Gross PnL is positive; not structural alpha decay.');
        self::assertSame('FEE_DRAG', $snap->degradation_type);
        self::assertSame('DEGRADING', $snap->status);

        // Financial breakdown verification
        $fin = $diag['financial_breakdown'];
        self::assertSame(320.0, (float) $fin['gross_pnl'], 'Total gross PnL must be 32 * 10 = +320.');
        self::assertSame(-160.0, (float) $fin['net_pnl'], 'Total net PnL must be 32 * -5 = -160.');
        self::assertSame(384.0, (float) $fin['total_fees'], 'Total fees must be 32 * 12 = 384.');
        self::assertSame(96.0, (float) $fin['total_slippage'], 'Total slippage must be 32 * 3 = 96.');
        self::assertSame(480.0, (float) $fin['total_execution_costs'], 'Total execution friction = fees + slippage = 480.');
        self::assertGreaterThan(0.0, (float) $fin['gross_expectancy_usd']);
        self::assertLessThan(0.0, (float) $fin['net_expectancy_usd']);
    }

    /**
     * Statistical confidence bounds for expectancy and profit factor
     * must calculate valid standard errors, confidence intervals, and respect boundary constraints.
     */
    public function test_confidence_bounds_calculation_for_expectancy_and_profit_factor(): void
    {
        $service = new StrategyDegradationService;
        $account = $this->app->make(PaperAccountService::class)->default();
        $account->update(['strategy_id' => 'bounds_test']);
        $now = CarbonImmutable::now();

        // Seed 30 varied trades: 20 wins, 10 losses
        for ($i = 0; $i < 30; $i++) {
            $signalId = (string) Str::uuid();
            SignalRecord::query()->create([
                'id' => $signalId,
                'strategy_id' => 'bounds_test',
                'strategy_version' => '1.0.0',
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 85,
                'decision_time' => $now->subDays(10)->addHours($i * 4),
                'candle_close_time' => $now->subDays(10)->addHours($i * 4),
                'entry_low' => '60000',
                'entry_high' => '60100',
                'stop_loss' => '59500',
                'take_profit_1' => '61000',
                'take_profit_2' => '62000',
                'risk_reward_1' => '2.0',
                'risk_reward_2' => '3.0',
                'model_risk_percent' => '1.0',
                'expires_at' => $now->subDays(10)->addHours($i * 4)->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['bounds_setup'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'schema_version' => '1.0.0',
            ]);

            $isWin = ($i < 20);
            $gross = $isWin ? 150.0 : -100.0;
            $net = $isWin ? 146.0 : -104.0;
            $r = $isWin ? 1.5 : -1.0;

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $account->id,
                'signal_id' => $signalId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => $isWin ? 'CLOSED_TP' : 'CLOSED_SL',
                'entry_price' => '60000.0',
                'stop_loss' => '59500.0',
                'take_profit_1' => '61000.0',
                'take_profit_2' => '62000.0',
                'initial_quantity' => '0.2',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) $gross,
                'net_pnl' => (string) $net,
                'unrealized_pnl' => '0.0',
                'fees' => '4.0',
                'funding' => '0.0',
                'r_multiple' => $r,
                'opened_at' => $now->subDays(10)->addHours($i * 4),
                'closed_at' => $now->subDays(10)->addHours($i * 4)->addHours(2),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        $snap = $service->evaluate('bounds_test', '14d');
        $diag = $snap->diagnostics;

        // Expectancy confidence bounds verification
        $exp = $diag['expectancy_confidence'];
        self::assertSame(0.95, $exp['confidence_level']);
        self::assertGreaterThan(0.0, $exp['mean']);
        self::assertGreaterThan(0.0, $exp['standard_error']);
        self::assertLessThan($exp['mean'], $exp['ci_lower']);
        self::assertGreaterThan($exp['mean'], $exp['ci_upper']);

        // Profit factor confidence bounds verification
        $pf = $diag['profit_factor_confidence'];
        self::assertSame(0.95, $pf['confidence_level']);
        self::assertGreaterThan(1.0, $pf['value']);
        self::assertGreaterThan(0.0, $pf['standard_error']);
        self::assertLessThan($pf['value'], $pf['ci_lower']);
        self::assertGreaterThan($pf['value'], $pf['ci_upper']);

        // Win rate Wilson interval verification
        self::assertGreaterThan(0.5, (float) $snap->win_rate);
        self::assertLessThan((float) $snap->win_rate, (float) $snap->win_rate_ci_lower);
        self::assertGreaterThan((float) $snap->win_rate, (float) $snap->win_rate_ci_upper);
    }
}
