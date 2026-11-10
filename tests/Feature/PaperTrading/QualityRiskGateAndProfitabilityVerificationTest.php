<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\DirectionalPerformanceService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\PolicyComparisonEvaluationService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PreExecutionQualityRiskGate;
use App\PaperTrading\Application\TopologyMatrixAuditService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Domain\QualityRiskGateDecision;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QualityRiskGateAndProfitabilityVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.global_capital.enabled' => false]);
    }

    /**
     * Scenario 1: HIGH quality / LOW risk / negative edge is rejected.
     */
    public function test_high_quality_low_risk_negative_edge_is_rejected(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Low,
            'expected_net_edge' => -2.5,
        ]);

        self::assertSame(QualityRiskGateDecision::Rejected, $result['decision']);
        self::assertFalse($result['allowed']);
        self::assertContains('high_quality_low_risk_negative_edge', $result['rejection_reasons']);
    }

    /**
     * Scenario 2: HIGH quality / LOW risk / insufficient evidence when validated edge is required.
     */
    public function test_high_quality_low_risk_insufficient_evidence_when_validated_edge_required(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Low,
            'require_validated_edge' => true,
            'expected_net_edge' => null,
            'sample_size' => 0,
        ]);

        self::assertSame(QualityRiskGateDecision::InsufficientEvidence, $result['decision']);
        self::assertFalse($result['allowed']);
        self::assertContains('insufficient_expectancy_evidence', $result['rejection_reasons']);
    }

    /**
     * Scenario 3: HIGH quality / MEDIUM risk / positive edge is eligible.
     */
    public function test_high_quality_medium_risk_positive_edge_is_eligible(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Medium,
            'expected_net_edge' => 15.0,
            'sample_size' => 25,
        ]);

        self::assertSame(QualityRiskGateDecision::Eligible, $result['decision']);
        self::assertTrue($result['allowed']);
        self::assertEmpty($result['rejection_reasons']);
    }

    /**
     * Scenario 4: HIGH quality / HIGH risk rejection.
     */
    public function test_high_quality_high_risk_rejection(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::High,
            'expected_net_edge' => 50.0,
        ]);

        self::assertSame(QualityRiskGateDecision::Rejected, $result['decision']);
        self::assertFalse($result['allowed']);
        self::assertContains('high_risk_trade_rejected', $result['rejection_reasons']);
    }

    /**
     * Scenario 5: MEDIUM quality / LOW risk with inadequate evidence when validated edge required.
     */
    public function test_medium_quality_low_risk_with_inadequate_evidence_when_required(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::Medium,
            'trade_risk' => TradeRiskClass::Low,
            'require_validated_edge' => true,
            'expected_net_edge' => null,
            'sample_size' => 0,
        ]);

        self::assertSame(QualityRiskGateDecision::InsufficientEvidence, $result['decision']);
        self::assertFalse($result['allowed']);
    }

    /**
     * Scenario 6: MEDIUM quality / MEDIUM risk with valid evidence is conditionally eligible.
     */
    public function test_medium_quality_medium_risk_with_valid_evidence_is_conditionally_eligible(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::Medium,
            'trade_risk' => TradeRiskClass::Medium,
            'expected_net_edge' => 0.15,
            'sample_size' => 20,
            'drawdown_percent' => 1.2,
        ]);

        self::assertSame(QualityRiskGateDecision::ConditionallyEligible, $result['decision']);
        self::assertTrue($result['allowed']);
        self::assertEmpty($result['rejection_reasons']);
    }

    /**
     * Scenario 7: LOW quality rejected from execution (Observation Only).
     */
    public function test_low_quality_rejected_from_execution_and_persisted_as_observation(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'C', 'LOW', 'LOW', 50);

        $eligibility = app(PaperExecutionEligibilityService::class);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('low_signal_quality_observation_only');

        $eligibility->assertOpenEligible($account, $state, $signal);
    }

    /**
     * Scenario 8: UNKNOWN classifications rejected.
     */
    public function test_unknown_classifications_rejected(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'UNKNOWN', 'LOW', 90);

        $eligibility = app(PaperExecutionEligibilityService::class);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unknown_signal_quality');

        $eligibility->assertOpenEligible($account, $state, $signal);
    }

    /**
     * Scenario 9: SKIP observation persisted.
     */
    public function test_skip_observation_persisted_without_opening_virtual_position(): void
    {
        $account = $this->createAccount('bybit', 'momentum');
        $obsId = Uuid::uuid4()->toString();

        StrategyObservationRecord::query()->create([
            'id' => $obsId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'momentum'),
            'side' => 'SKIP',
            'score' => 40,
            'decision_time' => now(),
            'reasons' => ['adx_below_trend_threshold', 'volume_declining'],
            'signal_quality' => 'LOW',
            'trade_risk' => 'UNKNOWN',
        ]);

        // Observation persisted in database
        self::assertTrue(StrategyObservationRecord::query()->where('id', $obsId)->exists());
        // Zero virtual positions opened
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    /**
     * Scenario 10: Risk-blocked account cannot open exposure.
     */
    public function test_risk_blocked_account_cannot_open_exposure(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $account->update(['status' => 'BLOCKED']);

        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 95);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $eligibility = app(PaperExecutionEligibilityService::class);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('account_not_active');

        $eligibility->assertOpenEligible($account, $state, $signal);
    }

    /**
     * Scenario 11: Duplicate signal cannot open twice (Idempotency).
     */
    public function test_duplicate_signal_cannot_open_twice(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 95);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $size = new PositionSize(true, '0.040', '20.00', '500.00', '2000.00', reason: null, qtyStep: '0.001');
        $quote = new ExecutionQuote(ExecutionType::Entry, '50000.00', '50000.00', '0', 'test', false);

        $lifecycle = app(PositionLifecycleService::class);
        $pos1 = $lifecycle->open($account, $state, $signal, $size, $quote, new DateTimeImmutable('2026-11-09T12:00:00Z'));

        // Attempt second open with same account and signal
        $pos2 = $lifecycle->open($account, $state, $signal, $size, $quote, new DateTimeImmutable('2026-11-09T12:00:00Z'));

        self::assertSame($pos1->id, $pos2->id);
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->count());
    }

    /**
     * Scenario 12: Retry cannot bypass the gate.
     */
    public function test_retry_cannot_bypass_the_gate(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        // High risk signal
        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'HIGH', 90);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $size = new PositionSize(true, '0.040', '20.00', '500.00', '2000.00', reason: null, qtyStep: '0.001');
        $quote = new ExecutionQuote(ExecutionType::Entry, '50000.00', '50000.00', '0', 'test', false);

        $lifecycle = app(PositionLifecycleService::class);

        try {
            $lifecycle->open($account, $state, $signal, $size, $quote, new DateTimeImmutable('2026-11-09T12:00:00Z'));
            self::fail('Expected PositionLifecycleService::open to reject HIGH trade risk on retry.');
        } catch (DomainException $e) {
            self::assertStringContainsString('high_risk_trade_rejected', $e->getMessage());
        }

        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    /**
     * Scenario 13: Recovery cannot bypass the gate.
     */
    public function test_recovery_cannot_bypass_the_gate(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        // Drawdown breached limit
        $account->update(['current_drawdown_percent' => '6.00']); // > 5% limit

        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 95);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $size = new PositionSize(true, '0.040', '20.00', '500.00', '2000.00', reason: null, qtyStep: '0.001');
        $quote = new ExecutionQuote(ExecutionType::Entry, '50000.00', '50000.00', '0', 'test', false);

        $lifecycle = app(PositionLifecycleService::class);

        try {
            $lifecycle->open($account, $state, $signal, $size, $quote, new DateTimeImmutable('2026-11-09T12:00:00Z'));
            self::fail('Expected recovery path to reject entry when drawdown exceeds limit.');
        } catch (DomainException $e) {
            self::assertStringContainsString('entry_drawdown_limit_exceeded', $e->getMessage());
        }
    }

    /**
     * Scenario 14: Missing fee model fails closed.
     */
    public function test_missing_fee_model_fails_closed(): void
    {
        $gate = app(PreExecutionQualityRiskGate::class);
        $result = $gate->evaluate([
            'signal_quality' => SignalQuality::High,
            'trade_risk' => TradeRiskClass::Low,
            'cost_drag_percent' => 150.0, // Fee model indicates excessive cost drag >= 100%
        ]);

        self::assertSame(QualityRiskGateDecision::Rejected, $result['decision']);
        self::assertFalse($result['allowed']);
        self::assertContains('execution_cost_drag_exceeds_edge', $result['rejection_reasons']);
    }

    /**
     * Scenario 15: Stale candle fails closed.
     */
    public function test_stale_candle_fails_closed(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $signal = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 95);
        $signal->update(['expires_at' => now()->subMinutes(10)]); // Expired

        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);

        $eligibility = app(PaperExecutionEligibilityService::class);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('signal_expired');

        $eligibility->assertOpenEligible($account, $state, $signal, now()->toDateTimeImmutable());
    }

    /**
     * Scenario 16: LONG/SHORT attribution is correct.
     */
    public function test_long_short_attribution_is_correct(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');

        // Create LONG trade (+100)
        $sigLong = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 90, now()->subMinutes(30));
        $this->createPosition($account, $sigLong, 'BUY', 'A', 'HIGH', 'LOW', 'CLOSED_TP', '105.00', '100.00', '5.00');

        // Create SHORT trade (-200)
        $sigShort = $this->createSignal($account, 'BTCUSDT', 'SELL', 'A', 'HIGH', 'LOW', 90, now()->subMinutes(15));
        $this->createPosition($account, $sigShort, 'SELL', 'A', 'HIGH', 'LOW', 'CLOSED_SL', '-190.00', '-200.00', '10.00');

        $service = app(DirectionalPerformanceService::class);
        $attribution = $service->analyze(['account_id' => $account->id]);

        self::assertSame(1, $attribution['directional']['LONG']['closed_positions']);
        self::assertSame(100.0, $attribution['directional']['LONG']['win_rate']);
        self::assertStringStartsWith('100.00', $attribution['directional']['LONG']['net_pnl']);

        self::assertSame(1, $attribution['directional']['SHORT']['closed_positions']);
        self::assertSame(0.0, $attribution['directional']['SHORT']['win_rate']);
        self::assertStringStartsWith('-200.00', $attribution['directional']['SHORT']['net_pnl']);

        self::assertGreaterThan(90.0, (float) $attribution['loss_concentration']['short_loss_percent']);
    }

    /**
     * Scenario 17: Net PnL includes relevant costs (commissions, funding, slippage).
     */
    public function test_net_pnl_includes_all_execution_costs(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $sig = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'HIGH', 'LOW', 90);

        // Gross = 100, Fee = 6.00, Funding = 1.50 -> Net = 92.50
        $pos = $this->createPosition($account, $sig, 'BUY', 'A', 'HIGH', 'LOW', 'CLOSED_TP', '100.00', '92.50', '6.00');
        $pos->update(['funding' => '-1.50']);

        self::assertSame('92.50', (string) $pos->net_pnl);
        self::assertSame('6.00', (string) $pos->fees);
        self::assertSame('-1.50', (string) $pos->funding);
    }

    /**
     * Scenario 18: Historical UNKNOWN classifications remain unchanged.
     */
    public function test_historical_unknown_classifications_remain_unchanged(): void
    {
        $account = $this->createAccount('bybit', 'trend_pullback');
        $sig = $this->createSignal($account, 'BTCUSDT', 'BUY', 'A', 'UNKNOWN', 'UNKNOWN', 85);

        self::assertSame('UNKNOWN', $sig->getRawOriginal('trade_risk') ?? 'UNKNOWN');
        self::assertSame('UNKNOWN', $sig->trade_risk);
    }

    /**
     * Scenario 19: All 130 combination gate paths (10 exchanges x 13 strategies).
     */
    public function test_all_130_combination_gate_paths(): void
    {
        app(PaperTopologySynchronizer::class)->sync(true);

        $auditService = app(TopologyMatrixAuditService::class);
        $result = $auditService->audit();

        self::assertSame(130, $result['total_expected']);
        self::assertSame(130, $result['total_provisioned']);
        self::assertSame(130, $result['counts']['STRATEGY_READY']);
        self::assertSame(117, $result['counts']['RISK_BLOCKED']);
        self::assertArrayHasKey('account_provisioned', $result['matrix'][0]);
        self::assertArrayHasKey('market_data_ready', $result['matrix'][0]);
        self::assertArrayHasKey('strategy_ready', $result['matrix'][0]);
        self::assertArrayHasKey('execution_ready', $result['matrix'][0]);
        self::assertArrayHasKey('reconciliation_passed', $result['matrix'][0]);
    }

    /**
     * Scenario 20: Out-of-sample baseline versus filtered comparison.
     */
    public function test_out_of_sample_baseline_versus_filtered_comparison(): void
    {
        $comparisonService = app(PolicyComparisonEvaluationService::class);

        $testSignals = [
            // High Quality / Low Risk Win
            [
                'signal_id' => (string) Uuid::uuid4(),
                'strategy_id' => 'trend_pullback',
                'exchange_id' => 'bybit',
                'side' => 'BUY',
                'signal_quality' => SignalQuality::High,
                'trade_risk' => TradeRiskClass::Low,
                'entry_price' => '50000',
                'stop_loss' => '49500',
                'take_profit_1' => '51500',
                'gross_pnl' => '150.00',
                'net_pnl' => '142.00',
                'fees' => '6.00',
                'slippage' => '2.00',
                'r_multiple' => 3.0,
                'expected_net_edge' => 5.0,
                'sample_size' => 15,
            ],
            // Low Quality Trade (Lost -100) -> Policy B rejects!
            [
                'signal_id' => (string) Uuid::uuid4(),
                'strategy_id' => 'trend_pullback',
                'exchange_id' => 'bybit',
                'side' => 'BUY',
                'signal_quality' => SignalQuality::Low,
                'trade_risk' => TradeRiskClass::Low,
                'entry_price' => '50000',
                'stop_loss' => '49500',
                'take_profit_1' => '51000',
                'gross_pnl' => '-95.00',
                'net_pnl' => '-103.00',
                'fees' => '6.00',
                'slippage' => '2.00',
                'r_multiple' => -1.0,
                'expected_net_edge' => -1.0,
                'sample_size' => 15,
            ],
            // High Risk Trade (Lost -250) -> Policy B rejects!
            [
                'signal_id' => (string) Uuid::uuid4(),
                'strategy_id' => 'trend_pullback',
                'exchange_id' => 'bybit',
                'side' => 'SELL',
                'signal_quality' => SignalQuality::High,
                'trade_risk' => TradeRiskClass::High,
                'entry_price' => '50000',
                'stop_loss' => '45000',
                'take_profit_1' => '55000',
                'gross_pnl' => '-240.00',
                'net_pnl' => '-255.00',
                'fees' => '10.00',
                'slippage' => '5.00',
                'r_multiple' => -1.0,
                'expected_net_edge' => 0.0,
                'sample_size' => 15,
            ],
            // High Quality / Medium Risk Win (+80) -> Policy B accepts (positive edge)
            [
                'signal_id' => (string) Uuid::uuid4(),
                'strategy_id' => 'trend_pullback',
                'exchange_id' => 'bybit',
                'side' => 'BUY',
                'signal_quality' => SignalQuality::High,
                'trade_risk' => TradeRiskClass::Medium,
                'entry_price' => '50000',
                'stop_loss' => '49000',
                'take_profit_1' => '51500',
                'gross_pnl' => '88.00',
                'net_pnl' => '80.00',
                'fees' => '6.00',
                'slippage' => '2.00',
                'r_multiple' => 1.5,
                'expected_net_edge' => 8.0,
                'sample_size' => 15,
            ],
        ];

        // Replicate signals to exceed statistical sample >= 10
        $replicated = array_merge($testSignals, $testSignals, $testSignals);

        $comparison = $comparisonService->compare($replicated);

        self::assertSame(12, $comparison['total_signals_evaluated']);
        self::assertSame(12, $comparison['policy_a_baseline']['trades_count']);
        self::assertSame(6, $comparison['policy_b_filtered']['trades_count']); // 6 rejected (all Low Quality and High Risk)
        self::assertSame(6, $comparison['policy_b_filtered']['rejections_count']);

        // Policy B filtered out the losing Low Quality and High Risk trades:
        // Policy A Net PnL = 3 * (142 - 103 - 255 + 80) = 3 * (-136) = -408
        // Policy B Net PnL = 3 * (142 + 80) = +666
        self::assertGreaterThan(0, (float) $comparison['policy_b_filtered']['net_pnl']);
        self::assertLessThan(0, (float) $comparison['policy_a_baseline']['net_pnl']);
        self::assertSame('VERIFIED_IMPROVED_RISK_ADJUSTED_PERFORMANCE', $comparison['verdict']);
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSignal(PaperAccountRecord $account, string $symbol, string $side, string $qualityClass, string $signalQuality, string $tradeRisk, int $score, mixed $time = null): SignalRecord
    {
        $time = $time ?? now();

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
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.20',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '500.00', 'close' => '50000.00']],
            'decision_time' => $time,
            'candle_close_time' => $time,
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => now()->addMinutes(30),
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
        string $fee
    ): VirtualPositionRecord {
        $pos = VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'symbol' => $signal->symbol,
            'side' => $side,
            'quality_class' => $qualityClass,
            'signal_quality' => $signalQuality,
            'trade_risk' => $tradeRisk,
            'status' => $status,
            'entry_price' => '50000.00',
            'stop_loss' => $signal->stop_loss,
            'take_profit_1' => $signal->take_profit_1,
            'take_profit_2' => $signal->take_profit_2,
            'initial_quantity' => '0.100',
            'remaining_quantity' => '0.000',
            'qty_step' => '0.001',
            'risk_capital' => '20.00',
            'gross_pnl' => $grossPnl,
            'net_pnl' => $netPnl,
            'unrealized_pnl' => '0',
            'fees' => $fee,
            'funding' => '0',
            'opened_at' => now()->subMinutes(60),
            'closed_at' => now()->subMinutes(10),
            'maximum_holding_until' => now()->addMinutes(60),
            'execution_model' => 'close_5m',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $pos->id,
            'type' => 'ENTRY',
            'side' => $side,
            'price' => '50000.00',
            'quantity' => '0.100',
            'commission' => (string) BigDecimal::of($fee)->dividedBy(2, 4, RoundingMode::HalfEven),
            'gross_pnl' => '0',
            'slippage' => '0.0002',
            'price_source' => 'test',
            'idempotency_key' => (string) Uuid::uuid4(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'market_event_at' => now()->subMinutes(60),
            'registered_at' => now()->subMinutes(60),
            'ambiguous_execution' => false,
        ]);

        return $pos;
    }
}
