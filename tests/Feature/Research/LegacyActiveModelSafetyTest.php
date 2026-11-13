<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class LegacyActiveModelSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function createLegacyActiveModel(string $symbol = 'BTCUSDT', string $targetEvent = 'TP_BEFORE_SL', bool $scoped = false): LearningModelArtifactRecord
    {
        return LearningModelArtifactRecord::query()->create([
            'id' => '2fcc2ec5-5446-4187-8e56-cacb66ba7fcd',
            'name' => $scoped ? LearningPredictorService::scopedModelName($targetEvent, $symbol, 'bybit', strategyVersion: '1.3.0') : "Logistic_{$targetEvent}_{$symbol}",
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'version' => 'v20261018.145406',
            'parameters' => [
                'target_event' => $targetEvent,
                'symbol' => $symbol,
                'total_sample_size' => 37,
                'train_sample_size' => 27,
                'validation_sample_size' => 10,
                'features_used' => ['rsi_14', 'adx_14', 'relative_volume'],
                'algorithm' => 'BATCH_GRADIENT_DESCENT_L2',
            ],
            'weights' => [
                'intercept' => -3.5, // Strongly negative -> probability ~ 0.029, would veto if active
                'coefficients' => [
                    'rsi_14' => -0.05,
                    'adx_14' => -0.02,
                    'relative_volume' => -0.10,
                ],
                'means' => ['rsi_14' => 50, 'adx_14' => 25, 'relative_volume' => 1.0],
                'stds' => ['rsi_14' => 10, 'adx_14' => 5, 'relative_volume' => 0.5],
            ],
            'metrics' => [
                'brier_score' => 0.22,
                'ece' => 0.05,
                'accuracy' => 0.70,
            ],
            'artifact_hash' => '297cab4738ab7e7b752065f39ca387ab5ee14986d961ff85a27298991f6c56de',
            'status' => 'ACTIVE',
            'created_at' => CarbonImmutable::parse('2026-10-18 14:54:06+00'),
        ]);
    }

    private function createSampleBuyDecision(string $symbol = 'BTCUSDT'): SignalDecision
    {
        return new SignalDecision(
            id: (string) Uuid::uuid4(),
            exchange: 'bybit',
            category: 'linear',
            symbol: $symbol,
            timeframe: '15',
            decisionTime: CarbonImmutable::now(),
            candleCloseTime: CarbonImmutable::now(),
            side: SignalSide::Buy,
            qualityClass: QualityClass::A,
            score: 85,
            pricePlan: new PricePlan('82000.00', '82050.00', '81000.00', '84000.00', '85000.00', '2.0', '3.0'),
            modelRiskPercent: '0.50',
            expiresAt: CarbonImmutable::now()->addMinutes(30),
            maximumHoldingMinutes: 240,
            reasons: ['trend_bullish', 'ema_cross'],
            risks: ['volatility_high'],
            indicatorSnapshot: ['15m' => ['close' => '82000.00']],
            strategyVersion: '1.3.0',
            schemaVersion: '1.0.0'
        );
    }

    public function test_ineligible_legacy_active_model_under_fail_closed_skip_converts_to_skip(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // 1. Seed historical paper account and existing open position
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'trend_pullback',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'status' => 'ACTIVE',
        ]);

        $histSignalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $histSignalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => CarbonImmutable::now()->subHours(2),
            'candle_close_time' => CarbonImmutable::now()->subHours(2),
            'expires_at' => CarbonImmutable::now()->subHours(1),
            'maximum_holding_minutes' => 240,
            'reasons' => ['trend_bullish'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $existingPosition = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $histSignalId,
            'symbol' => $symbol,
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '82000.00',
            'stop_loss' => '81000.00',
            'take_profit_1' => '84000.00',
            'take_profit_2' => '85000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.1',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'opened_at' => CarbonImmutable::now()->subHours(2),
            'maximum_holding_until' => CarbonImmutable::now()->addHours(2),
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'execution_version' => '1.0.0',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // 2. Seed legacy ACTIVE model
        $legacyModel = $this->createLegacyActiveModel($symbol, $targetEvent, scoped: true);

        // 3. Configure ModelAdvisoryService in ACTIVE mode with FAIL_CLOSED_SKIP
        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'minimum_calibrated_probability' => 0.40,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, ['rsi_14' => 60.0, 'adx_14' => 28.0, 'relative_volume' => 1.2]);

        // 4. Assert: Ineligible model MUST fail-closed to SKIP
        self::assertSame(SignalSide::Skip, $advised->side, 'Ineligible legacy model under FAIL_CLOSED_SKIP must convert to SKIP.');
        self::assertNull($advised->qualityClass);
        self::assertContains('ml_advisory_no_eligible_model_fail_closed', $advised->reasons);

        $snapshot = $advised->indicatorSnapshot['model_advisory'];
        self::assertSame('INELIGIBLE_LEGACY_MODEL', $snapshot['status']);
        self::assertSame('FAIL_CLOSED_SKIP', $snapshot['fallback_policy']);
        self::assertSame('BUY', $snapshot['original_decision_side']);
        self::assertTrue($snapshot['altered_decision']);
        self::assertFalse($snapshot['eligible_for_advisory']);

        // 5. Assert autonomy event MODEL_ADVISORY_FAIL_CLOSED recorded
        $event = AutonomyApprovalEventRecord::query()
            ->where('action', 'MODEL_ADVISORY_FAIL_CLOSED')
            ->where('target_id', $legacyModel->id)
            ->first();
        self::assertNotNull($event);
        self::assertSame('BUY', $event->previous_state);
        self::assertSame('SKIP', $event->new_state);

        // 6. Assert historical artifact & existing positions preserved
        self::assertSame('ACTIVE', $legacyModel->fresh()->status);
        self::assertSame('OPEN', $existingPosition->fresh()->status);
    }

    public function test_ineligible_legacy_active_model_under_allow_unfiltered_proceeds_to_risk_gate(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        $legacyModel = $this->createLegacyActiveModel($symbol, $targetEvent, scoped: true);

        // Configure ModelAdvisoryService with ALLOW_UNFILTERED
        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'minimum_calibrated_probability' => 0.40,
                'fallback_policy' => 'ALLOW_UNFILTERED',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, ['rsi_14' => 60.0, 'adx_14' => 28.0, 'relative_volume' => 1.2]);

        // Assert: Original BUY proposal proceeds unaltered through Risk Gate
        self::assertSame(SignalSide::Buy, $advised->side);
        self::assertSame(QualityClass::A, $advised->qualityClass);
        self::assertFalse($advised->indicatorSnapshot['model_advisory']['altered_decision']);
        self::assertFalse($advised->indicatorSnapshot['model_advisory']['eligible_for_advisory']);
        self::assertSame('ALLOW_UNFILTERED', $advised->indicatorSnapshot['model_advisory']['recommendation']);

        // Autonomy event logged as MODEL_ADVISORY_LEGACY_BYPASS
        $event = AutonomyApprovalEventRecord::query()
            ->where('action', 'MODEL_ADVISORY_LEGACY_BYPASS')
            ->where('target_id', $legacyModel->id)
            ->first();
        self::assertNotNull($event);
        self::assertSame('BUY', $event->previous_state);
        self::assertSame('BUY', $event->new_state);
    }

    public function test_unscoped_legacy_model_never_enters_inference_or_changes_history(): void
    {
        $legacy = $this->createLegacyActiveModel();
        $predictor = $this->createMock(LearningPredictorService::class);
        $predictor->expects(self::never())->method('predictCalibrated');
        $advisor = new ModelAdvisoryService(
            predictor: $predictor, autonomy: app(AutonomyPermissionService::class),
            config: ['enabled' => true, 'mode' => 'ACTIVE', 'fallback_policy' => 'FAIL_CLOSED_SKIP']
        );
        $decision = $advisor->advise($this->createSampleBuyDecision(), []);
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertContains('ml_advisory_no_active_model_fail_closed', $decision->reasons);
        self::assertNull($decision->indicatorSnapshot['model_advisory']['calibrated_probability']);
        self::assertSame('ACTIVE', $legacy->fresh()->status);
        self::assertSame('Logistic_TP_BEFORE_SL_BTCUSDT', $legacy->fresh()->name);
    }

    public function test_missing_model_under_mandatory_active_ml_converts_to_skip(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Ensure no active model exists
        LearningModelArtifactRecord::query()->delete();

        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, []);

        self::assertSame(SignalSide::Skip, $advised->side);
        self::assertContains('ml_advisory_no_active_model_fail_closed', $advised->reasons);
        self::assertTrue($advised->indicatorSnapshot['model_advisory']['altered_decision']);
    }

    public function test_missing_model_under_deterministic_only_strategy_proceeds_normally(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        LearningModelArtifactRecord::query()->delete();

        // When advisory is disabled (deterministic-only strategy)
        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => false,
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, []);

        self::assertSame(SignalSide::Buy, $advised->side);
        self::assertSame(QualityClass::A, $advised->qualityClass);
    }

    public function test_inference_exception_under_fail_closed_skip_converts_to_skip(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Create an eligible model
        LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => LearningPredictorService::scopedModelName($targetEvent, $symbol, 'bybit', strategyVersion: '1.3.0'),
            'version' => 'v2.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => [
                'exchange' => 'bybit', 'category' => 'linear', 'strategy_id' => 'baseline',
                'strategy_version' => '1.3.0', 'timeframe' => '15', 'side' => 'BUY',
                'approved_for_advisory' => true,
                'gate6_validation' => ['all_gates_passed' => true, 'policy_version' => '4.0.0'],
            ],
            'weights' => ['intercept' => 0.0],
            'metrics' => ['brier_score' => 0.20, 'ece' => 0.05],
            'artifact_hash' => hash('sha256', 'mock'),
            'status' => 'ACTIVE',
        ]);

        $mockPredictor = $this->createMock(LearningPredictorService::class);
        $mockPredictor->expects(self::once())
            ->method('predictCalibrated')
            ->willThrowException(new \RuntimeException('Feature schema corruption or matrix singularity'));

        $advisory = new ModelAdvisoryService(
            predictor: $mockPredictor,
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, []);

        self::assertSame(SignalSide::Skip, $advised->side);
        self::assertContains('ml_advisory_inference_error_fail_closed', $advised->reasons);
        self::assertTrue($advised->indicatorSnapshot['model_advisory']['altered_decision']);
        self::assertSame('INFERENCE_ERROR', $advised->indicatorSnapshot['model_advisory']['status']);
        self::assertNull($advised->indicatorSnapshot['model_advisory']['calibrated_probability']);
    }

    public function test_invalid_model_artifact_gates_breached_under_fail_closed_skip(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Create an approved model whose metrics breached validation gates (Brier 0.35 > 0.28)
        LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => LearningPredictorService::scopedModelName($targetEvent, $symbol, 'bybit', strategyVersion: '1.3.0'),
            'version' => 'v2.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => [
                'exchange' => 'bybit', 'category' => 'linear', 'strategy_id' => 'baseline',
                'strategy_version' => '1.3.0', 'timeframe' => '15', 'side' => 'BUY',
                'approved_for_advisory' => true,
                'gate6_validation' => ['all_gates_passed' => true, 'policy_version' => '4.0.0'],
            ],
            'weights' => ['intercept' => 0.0],
            'metrics' => ['brier_score' => 0.35, 'ece' => 0.20], // Breached!
            'artifact_hash' => hash('sha256', 'breached'),
            'status' => 'ACTIVE',
        ]);

        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'maximum_allowed_brier' => 0.28,
                'maximum_allowed_ece' => 0.15,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, []);

        self::assertSame(SignalSide::Skip, $advised->side);
        self::assertContains('ml_advisory_corrupt_model_fail_closed', $advised->reasons);
        self::assertFalse($advised->indicatorSnapshot['model_advisory']['validation_gates_passed']);
    }

    public function test_rolled_back_model_cannot_influence_new_decisions(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Seed model in RETIRED or SUPERSEDED status
        LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => LearningPredictorService::scopedModelName($targetEvent, $symbol, 'bybit', strategyVersion: '1.3.0'),
            'version' => 'v1.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => ['approved_for_advisory' => true],
            'weights' => ['intercept' => -3.0],
            'metrics' => ['brier_score' => 0.18, 'ece' => 0.04],
            'artifact_hash' => hash('sha256', 'retired'),
            'status' => 'RETIRED',
        ]);

        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $decision = $this->createSampleBuyDecision($symbol);
        $advised = $advisory->advise($decision, []);

        // Since no ACTIVE model exists, fails closed to SKIP
        self::assertSame(SignalSide::Skip, $advised->side);
        self::assertContains('ml_advisory_no_active_model_fail_closed', $advised->reasons);
    }

    public function test_existing_position_management_continues_when_model_is_missing(): void
    {
        // 1. Ensure no model exists
        LearningModelArtifactRecord::query()->delete();

        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'trend_pullback',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'status' => 'ACTIVE',
        ]);

        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => CarbonImmutable::now()->subHours(2),
            'candle_close_time' => CarbonImmutable::now()->subHours(2),
            'expires_at' => CarbonImmutable::now()->subHours(1),
            'maximum_holding_minutes' => 240,
            'reasons' => ['trend_bullish'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::Open->value,
            'entry_price' => '80000.00',
            'stop_loss' => '79000.00',
            'take_profit_1' => '82000.00',
            'take_profit_2' => '83000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.1',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'opened_at' => CarbonImmutable::now()->subHours(2),
            'maximum_holding_until' => CarbonImmutable::now()->addHours(2),
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'execution_version' => '1.0.0',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // 2. Feed execution quote hitting TakeProfit 1
        $quote = new ExecutionQuote(
            type: ExecutionType::TakeProfit1,
            price: '82000.00',
            referencePrice: '82000.00',
            slippage: '0.00',
            priceSource: 'LIMIT_ORDER',
            ambiguous: false,
        );

        $lifecycle = app(PositionLifecycleService::class);
        $lifecycle->executeExit($position, $quote, new DateTimeImmutable('2026-10-18T12:00:00Z'));

        // Position was managed and partially exited on TP1 without requiring any ML model
        $position->refresh();
        self::assertSame(PositionStatus::PartiallyClosed->value, $position->status);
        self::assertEquals(0.05, (float) $position->remaining_quantity);
    }

    public function test_disallowed_trade_under_risk_gate_remains_rejected_even_with_allow_unfiltered(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        $decision = $this->createSampleBuyDecision($symbol);

        // 1. Create cooled-down paper account (Risk Gate will reject)
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'trend_pullback',
            'strategy_id' => 'trend_pullback',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'status' => 'ACTIVE',
            'cooldown_until' => CarbonImmutable::parse($decision->decisionTime)->addHours(2), // In cooldown relative to decision_time!
        ]);

        // 2. Legacy model under ALLOW_UNFILTERED emits BUY
        $this->createLegacyActiveModel($symbol, $targetEvent);
        $advisory = new ModelAdvisoryService(
            predictor: app(LearningPredictorService::class),
            autonomy: app(AutonomyPermissionService::class),
            promotionPolicy: app(PromotionPolicyService::class),
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'fallback_policy' => 'ALLOW_UNFILTERED',
            ]
        );

        $advised = $advisory->advise($decision, []);
        self::assertSame(SignalSide::Buy, $advised->side);

        // 3. Persist signal into database for PaperProcessingService
        $signalRecord = SignalRecord::query()->create([
            'id' => $advised->id,
            'exchange' => $advised->exchange,
            'category' => $advised->category,
            'symbol' => $advised->symbol,
            'strategy_id' => 'trend_pullback',
            'strategy_version' => $advised->strategyVersion,
            'timeframe' => $advised->timeframe,
            'side' => $advised->side->value,
            'quality_class' => $advised->qualityClass?->value,
            'score' => $advised->score,
            'entry_low' => $advised->pricePlan->entryLow,
            'entry_high' => $advised->pricePlan->entryHigh,
            'stop_loss' => $advised->pricePlan->stopLoss,
            'take_profit_1' => $advised->pricePlan->takeProfit1,
            'take_profit_2' => $advised->pricePlan->takeProfit2,
            'risk_reward_1' => $advised->pricePlan->riskReward1,
            'risk_reward_2' => $advised->pricePlan->riskReward2,
            'model_risk_percent' => $advised->modelRiskPercent,
            'decision_time' => $advised->decisionTime,
            'candle_close_time' => $advised->candleCloseTime,
            'expires_at' => $advised->expiresAt,
            'maximum_holding_minutes' => $advised->maximumHoldingMinutes,
            'reasons' => $advised->reasons,
            'risks' => $advised->risks,
            'indicator_snapshot' => $advised->indicatorSnapshot,
            'contract' => [],
            'schema_version' => $advised->schemaVersion,
        ]);

        // 4. Process through PaperProcessingService
        $processor = app(PaperProcessingService::class);
        $processor->process(
            accountId: $account->id,
            symbol: $symbol,
            at: CarbonImmutable::parse($advised->decisionTime)->addMinutes(1)->toDateTimeImmutable()
        );

        // 5. Assert: Trade was REJECTED by Risk Gate
        $riskDecision = RiskDecisionRecord::query()->where('signal_id', $signalRecord->id)->first();
        self::assertNotNull($riskDecision);
        self::assertFalse($riskDecision->allowed, 'Risk Gate must reject trade during account cooldown.');

        $signalState = PaperSignalStateRecord::query()->where('signal_id', $signalRecord->id)->first();
        self::assertNotNull($signalState);
        self::assertSame(SignalLifecycle::Rejected->value, $signalState->status);

        // No position opened
        self::assertFalse(VirtualPositionRecord::query()->where('signal_id', $signalRecord->id)->exists());
    }

    public function test_duplicate_paper_processing_event_does_not_mutate_ledger_or_create_duplicate_positions(): void
    {
        $symbol = 'BTCUSDT';
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'trend_pullback',
            'strategy_id' => 'trend_pullback',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'status' => 'ACTIVE',
        ]);

        $decisionTime = CarbonImmutable::now()->subMinutes(10);
        // Create SKIP signal
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'SKIP',
            'quality_class' => null,
            'score' => 50,
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'expires_at' => CarbonImmutable::now()->addMinutes(20),
            'maximum_holding_minutes' => 240,
            'reasons' => ['ml_advisory_no_eligible_model_fail_closed'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $processor = app(PaperProcessingService::class);
        $processTime = CarbonImmutable::now()->toDateTimeImmutable();

        // First execution
        $processor->process(accountId: $account->id, symbol: $symbol, at: $processTime);
        self::assertSame(1, PaperSignalStateRecord::query()->where('signal_id', $signal->id)->count());

        // Duplicate execution / retry
        $processor->process(accountId: $account->id, symbol: $symbol, at: $processTime);
        self::assertSame(1, PaperSignalStateRecord::query()->where('signal_id', $signal->id)->count(), 'Retry must not duplicate signal state.');
        self::assertSame(0, VirtualPositionRecord::query()->where('signal_id', $signal->id)->count());
    }
}
