<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\DirectionalPerformanceService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class DirectionalPerformanceAndRiskGateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.global_capital.enabled' => false]);
    }

    public function test_directional_performance_calculation_and_loss_concentration(): void
    {
        $account = $this->createAccount('bybit', 'trend_following');

        // Create LONG winning position (Net PnL +100)
        $sig1 = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 90, 50);
        $this->createPosition($account, $sig1, 'BUY', 'A', 'HIGH', 'LOW', 'CLOSED_TP', '105.00', '100.00', '5.00');

        // Create LONG losing position (Net PnL -50)
        $sig2 = $this->createSignal($account, 'BTCUSDT', 'BUY', 'B', 'MEDIUM', 'MEDIUM', 75, 40);
        $this->createPosition($account, $sig2, 'BUY', 'B', 'MEDIUM', 'MEDIUM', 'CLOSED_SL', '-45.00', '-50.00', '5.00');

        // Create SHORT losing position 1 (Net PnL -150)
        $sig3 = $this->createSignal($account, 'ETHUSDT', 'SELL', 'A', 'HIGH', 'MEDIUM', 88, 30);
        $this->createPosition($account, $sig3, 'SELL', 'A', 'HIGH', 'MEDIUM', 'CLOSED_SL', '-140.00', '-150.00', '10.00');

        // Create SHORT losing position 2 (Net PnL -100)
        $sig4 = $this->createSignal($account, 'ETHUSDT', 'SELL', 'C', 'LOW', 'HIGH', 55, 20);
        $this->createPosition($account, $sig4, 'SELL', 'C', 'LOW', 'HIGH', 'CLOSED_SL', '-90.00', '-100.00', '10.00');

        $service = app(DirectionalPerformanceService::class);
        $analysis = $service->analyze(['account_id' => $account->id, 'exchange_id' => 'bybit']);

        // LONG checks: 2 positions, 1 win, 1 loss -> 50% win rate
        $long = $analysis['directional']['LONG'];
        $this->assertSame(2, $long['closed_positions']);
        $this->assertSame(1, $long['winning_trades']);
        $this->assertSame(1, $long['losing_trades']);
        $this->assertSame(50.0, $long['win_rate']);
        $this->assertStringStartsWith('50.00', $long['net_pnl']);

        // SHORT checks: 2 positions, 0 wins, 2 losses -> 0% win rate
        $short = $analysis['directional']['SHORT'];
        $this->assertSame(2, $short['closed_positions']);
        $this->assertSame(0, $short['winning_trades']);
        $this->assertSame(2, $short['losing_trades']);
        $this->assertSame(0.0, $short['win_rate']);
        $this->assertStringStartsWith('-250.00', $short['net_pnl']);

        // Loss concentration: Total losses = 50 (long) + 250 (short) = 300. SHORT is 83.33% of losses
        $lossConc = $analysis['loss_concentration'];
        $this->assertGreaterThan(80.0, (float) $lossConc['short_loss_percent']);
        $this->assertLessThan(20.0, (float) $lossConc['long_loss_percent']);
    }

    public function test_risk_and_strategy_monitor_api_endpoints_expose_quality_risk_metrics(): void
    {
        $account = $this->createAccount('bybit', 'breakout_momentum');

        // Create a signal
        $sig = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 92);

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'signal_id' => $sig->id,
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'allowed' => true,
            'reasons' => ['all_checks_passed'],
            'snapshot' => [
                'signal_quality' => 'HIGH',
                'trade_risk' => 'LOW',
                'gate_decision' => 'ELIGIBLE',
                'risk_capital' => '150.00',
                'notional_after' => '5000.00',
            ],
            'risk_version' => '1.0.0',
            'decided_at' => now()->subMinutes(10),
        ]);

        // Test GET /api/v1/risk
        $response = $this->getJson('/api/v1/risk?exchange=bybit&account='.$account->id.'&quality=HIGH&risk=LOW');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'account',
                'accounts',
                'decisions' => ['data'],
                'gate_policy' => ['matrix'],
                'directional_performance' => ['directional', 'loss_concentration'],
                'rejection_reasons',
            ],
        ]);
        $this->assertCount(1, $response->json('data.decisions.data'));
        $this->assertSame('HIGH', $response->json('data.decisions.data.0.signal_quality'));
        $this->assertSame('LOW', $response->json('data.decisions.data.0.trade_risk'));

        // Test GET /api/v1/strategy-monitor
        $monResponse = $this->getJson('/api/v1/strategy-monitor?exchange=bybit&quality=HIGH&risk=LOW');
        $monResponse->assertStatus(200);
        $monResponse->assertJsonStructure([
            'data' => [
                'exchanges',
                'strategies',
                'metrics' => [
                    'long_signals',
                    'short_signals',
                    'quality_counts',
                    'risk_counts',
                ],
                'signals' => ['data'],
            ],
        ]);
        $firstSig = $monResponse->json('data.signals.data.0');
        $this->assertSame('HIGH', $firstSig['signal_quality']);
        $this->assertSame('LOW', $firstSig['trade_risk']);
        $this->assertNotNull($firstSig['planned_stop_loss']);
        $this->assertNull($firstSig['margin'], 'Margin must not be guessed as 10x of notional.');
        $this->assertStringStartsWith('49500', (string) $firstSig['planned_stop_loss']['price']);
    }

    public function test_strategy_monitor_preserves_every_accounts_decision_and_position_for_one_signal(): void
    {
        $firstAccount = $this->createAccount('bybit', 'trend_pullback');
        $secondAccount = $this->createAccount('bybit', 'trend_pullback');
        // A secondary independent PAPER account can receive the same strategy signal.
        $secondAccount->update(['is_primary' => false]);
        $otherStrategyAccount = $this->createAccount('bybit', 'momentum');
        $signal = $this->createSignal($firstAccount, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 91);
        $otherSignal = $this->createSignal($otherStrategyAccount, 'BTCUSDT', 'SELL', 'B', 'MEDIUM', 'MEDIUM', 70);

        foreach ([$firstAccount, $secondAccount] as $index => $account) {
            RiskDecisionRecord::query()->create([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit',
                'signal_id' => $signal->id,
                'allowed' => $index === 0,
                'risk_version' => '1.0.0',
                'reasons' => [$index === 0 ? 'approved' : 'blocked'],
                'snapshot' => ['gate_decision' => $index === 0 ? 'ELIGIBLE' : 'REJECTED'],
                'decided_at' => now()->subMinute(),
            ]);
            $this->createPosition($account, $signal, 'BUY', 'A', 'HIGH', 'LOW', 'CLOSED_TP', '20.00', '18.00', '2.00');
        }
        $this->createPosition($otherStrategyAccount, $otherSignal, 'SELL', 'B', 'MEDIUM', 'MEDIUM', 'CLOSED_SL', '-10.00', '-12.00', '2.00');

        $response = $this->getJson('/api/v1/strategy-monitor?exchange=bybit&strategy_id=trend_pullback&quality=HIGH&risk=LOW');
        $response->assertOk();
        $response->assertJsonPath('data.metrics.total_signals', 1);
        $response->assertJsonPath('data.metrics.risk_approved', 1);
        $response->assertJsonPath('data.metrics.closed_positions', 2);
        $response->assertJsonPath('data.signals.data.0.risk_decision_count', 2);
        $response->assertJsonPath('data.signals.data.0.position_count', 2);
        $response->assertJsonPath('data.signals.data.0.gate_decision', 'MULTI_ACCOUNT');
        $this->assertNull($response->json('data.signals.data.0.position'));
        $this->assertNull($response->json('data.signals.data.0.risk_decision'));
        $this->assertCount(2, $response->json('data.signals.data.0.positions'));
        $this->assertCount(2, $response->json('data.signals.data.0.risk_decisions'));
        $this->assertEqualsCanonicalizing(
            [$firstAccount->id, $secondAccount->id],
            array_column($response->json('data.signals.data.0.positions'), 'paper_account_id'),
        );
        $this->assertNotContains(
            $otherStrategyAccount->id,
            array_column($response->json('data.signals.data.0.positions'), 'paper_account_id'),
        );
        $timeline = $this->getJson('/api/v1/strategy-monitor/timeline?signal_id='.$signal->id);
        $timeline->assertOk();
        $riskEvents = collect($timeline->json('data.timeline'))->where('stage', 'RISK_DECISION');
        $this->assertCount(2, $riskEvents);
    }

    public function test_end_to_end_paper_processing_respects_quality_risk_gate(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $account->update(['created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);

        // Create market context
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => now()->subMinutes(2),
            'bid' => '49995.00',
            'ask' => '50005.00',
            'turnover_24h' => '100000000.00',
            'tick_size' => '0.01',
            'funding_rate' => '0.0001',
            'open_interest' => '1000',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'min_notional_value' => '5.0',
        ]);

        // 1. High Quality, Low Risk Signal (Stop loss 1.0% away, score 90, class A)
        $eligibleSignal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'entry_low' => '50000.00',
            'entry_high' => '50000.00',
            'stop_loss' => '49500.00', // 1% stop loss (LOW risk)
            'take_profit_1' => '52000.00',
            'take_profit_2' => '53000.00',
            'risk_reward_1' => '4.0',
            'risk_reward_2' => '6.0',
            'model_risk_percent' => '0.20', // 0.20% risk capital (LOW risk)
            'reasons' => ['trend_confirmed'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '500.00', 'close' => '50000.00']],
            'decision_time' => now()->subMinute(),
            'candle_close_time' => now()->subMinute(),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => now()->addMinutes(30),
        ]);
        $this->seedValidatedPaperEvidence($eligibleSignal);

        $service = app(PaperProcessingService::class);
        $service->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable(), true, true);

        // Verify eligible signal was approved
        $decision = RiskDecisionRecord::query()->where('signal_id', $eligibleSignal->id)->first();
        $this->assertNotNull($decision);
        $this->assertTrue($decision->allowed);
        $this->assertSame(SignalQuality::High->value, $decision->signal_quality);
        $this->assertSame(TradeRiskClass::Low->value, $decision->trade_risk);

        // 2. Low Quality Signal (Class C, score 55) -> must be observation only / rejected from paper execution
        $lowSignal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'C',
            'score' => 55,
            'entry_low' => '50000.00',
            'entry_high' => '50000.00',
            'stop_loss' => '49500.00',
            'take_profit_1' => '51000.00',
            'take_profit_2' => '52000.00',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.10',
            'reasons' => ['weak_signal'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '500.00', 'close' => '50000.00']],
            'decision_time' => now()->subSeconds(30),
            'candle_close_time' => now()->subSeconds(30),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => now()->addMinutes(30),
        ]);

        $service->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable(), true, true);

        $lowDecision = RiskDecisionRecord::query()->where('signal_id', $lowSignal->id)->first();
        $this->assertNotNull($lowDecision);
        $this->assertFalse($lowDecision->allowed);
        $this->assertSame(SignalQuality::Low->value, $lowDecision->signal_quality);
        $this->assertContains('low_signal_quality_observation_only', $lowDecision->reasons);

        // 3. High Risk Signal (Class A, score 90, but stop loss 6.0% away -> High Risk) -> rejected
        $highRiskSignal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'entry_low' => '50000.00',
            'entry_high' => '50000.00',
            'stop_loss' => '45000.00', // 10% stop loss -> HIGH risk
            'take_profit_1' => '56000.00',
            'take_profit_2' => '58000.00',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '2.6',
            'model_risk_percent' => '0.80', // 0.80% of equity -> HIGH factor (>0.55%)
            'reasons' => ['wide_swing'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '3000.00', 'close' => '50000.00']],
            'decision_time' => now()->subSeconds(10),
            'candle_close_time' => now()->subSeconds(10),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => now()->addMinutes(30),
        ]);

        $account->update(['current_drawdown_percent' => '4.0']);
        $service->process($account->id, 'BTCUSDT', now()->toDateTimeImmutable(), true, true);

        $highRiskDecision = RiskDecisionRecord::query()->where('signal_id', $highRiskSignal->id)->first();
        $this->assertNotNull($highRiskDecision);
        $this->assertFalse($highRiskDecision->allowed);
        $this->assertSame(TradeRiskClass::High->value, $highRiskDecision->trade_risk);
        $this->assertContains('high_risk_trade_rejected', $highRiskDecision->reasons);
    }

    private function createAccount(string $exchange, string $strategy): PaperAccountRecord
    {
        return PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => "{$exchange}_{$strategy}",
            'exchange_id' => $exchange,
            'strategy_id' => $strategy,
            'is_primary' => true,
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'reserved_risk' => '0.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'current_drawdown_percent' => '0.00',
            'sizing_settings' => ['mode' => 'risk_percent'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSignal(PaperAccountRecord $account, string $symbol, string $side, string $qualityClass, string $signalQuality, string $tradeRisk, int $score, int $minutesAgo = 10): SignalRecord
    {
        return SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => $account->exchange_id,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15m',
            'strategy_id' => $account->strategy_id,
            'strategy_version' => '1.0.0',
            'side' => $side,
            'quality_class' => $qualityClass,
            'signal_quality' => $signalQuality,
            'trade_risk' => $tradeRisk,
            'score' => $score,
            'entry_low' => '50000.00',
            'entry_high' => '50100.00',
            'stop_loss' => '49500.00',
            'take_profit_1' => '51500.00',
            'take_profit_2' => '53000.00',
            'risk_reward_1' => '2.5',
            'risk_reward_2' => '5.0',
            'model_risk_percent' => '0.015',
            'reasons' => ['trend_confirmed'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => [],
            'decision_time' => now()->subMinutes($minutesAgo),
            'candle_close_time' => now()->subMinutes($minutesAgo),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function createPosition(
        PaperAccountRecord $account,
        SignalRecord $signal,
        string $side,
        string $qualityClass,
        string $signalQuality,
        string $tradeRisk,
        string $status,
        string $grossPnl,
        string $netPnl,
        string $fees
    ): VirtualPositionRecord {
        return VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'symbol' => $signal->symbol,
            'side' => $side,
            'quality_class' => $qualityClass,
            'signal_quality' => $signalQuality,
            'trade_risk' => $tradeRisk,
            'status' => $status,
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit_1' => '51500',
            'take_profit_2' => '52000',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0',
            'qty_step' => '0.001',
            'risk_capital' => '100',
            'execution_model' => 'SIMULATED',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'gross_pnl' => $grossPnl,
            'net_pnl' => $netPnl,
            'fees' => $fees,
            'funding' => '0',
            'opened_at' => now()->subHours(2),
            'maximum_holding_until' => now()->addHours(4),
            'closed_at' => now()->subHour(),
        ]);
    }
}
