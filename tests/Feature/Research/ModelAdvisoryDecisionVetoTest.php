<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ModelAdvisoryDecisionVetoTest extends TestCase
{
    use RefreshDatabase;

    private LearningPredictorService $predictor;

    private AutonomyPermissionService $autonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PaperAccountService::class)->default();
        $this->predictor = $this->app->make(LearningPredictorService::class);
        $this->autonomy = $this->app->make(AutonomyPermissionService::class);
    }

    private function createBaselineBuyDecision(string $symbol = 'BTCUSDT'): SignalDecision
    {
        $now = CarbonImmutable::now();

        return new SignalDecision(
            id: (string) Uuid::uuid4(),
            exchange: 'bybit',
            category: 'linear',
            symbol: $symbol,
            timeframe: '15',
            decisionTime: $now,
            candleCloseTime: $now->subSeconds(1),
            side: SignalSide::Buy,
            qualityClass: QualityClass::B,
            score: 75,
            pricePlan: new PricePlan(
                entryLow: '50000.0',
                entryHigh: '50100.0',
                stopLoss: '49500.0',
                takeProfit1: '51000.0',
                takeProfit2: '51500.0',
                riskReward1: '1.80',
                riskReward2: '2.80'
            ),
            modelRiskPercent: '0.25',
            expiresAt: $now->addMinutes(30),
            maximumHoldingMinutes: 240,
            reasons: ['ema_bullish_pullback', 'relative_volume_expansion'],
            risks: ['market_volatility'],
            indicatorSnapshot: ['rsi_14' => 55.0, 'adx_14' => 28.0],
            strategyVersion: '1.2.0',
            schemaVersion: '1.0.0'
        );
    }

    public function test_active_model_vetoes_buy_to_skip_and_respects_fail_closed_and_authorized_fallback(): void
    {
        $symbol = 'BTCUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // 1. Create an active champion model with negative weights (will predict low probability < 0.40)
        $model = LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => "Logistic_{$targetEvent}_{$symbol}",
            'version' => 'v1.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => [
                'features' => ['rsi_14', 'adx_14', 'relative_volume'],
                'scaling' => ['mean' => ['rsi_14' => 50, 'adx_14' => 25, 'relative_volume' => 1.0], 'std' => ['rsi_14' => 10, 'adx_14' => 5, 'relative_volume' => 0.5]],
                'dataset_id' => 'ds-001',
                'training_run_id' => 'run-001',
            ],
            'weights' => [
                'intercept' => -3.0, // Strong negative bias -> P(TP_BEFORE_SL) ~ 0.047
                'rsi_14' => -0.05,
                'adx_14' => -0.02,
                'relative_volume' => -0.10,
            ],
            'metrics' => [
                'brier_score' => 0.18,
                'ece' => 0.04,
                'log_loss' => 0.48,
                'accuracy' => 0.72,
            ],
            'artifact_hash' => hash('sha256', 'mock_model_1'),
            'status' => 'ACTIVE',
        ]);

        $decision = $this->createBaselineBuyDecision($symbol);
        self::assertSame(SignalSide::Buy, $decision->side);

        // 2. Evaluate through ModelAdvisoryService in ACTIVE mode
        $activeAdvisor = new ModelAdvisoryService(
            predictor: $this->predictor,
            autonomy: $this->autonomy,
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'minimum_calibrated_probability' => 0.40,
                'fallback_policy' => 'FAIL_CLOSED_SKIP',
            ]
        );

        $vetoedDecision = $activeAdvisor->advise($decision, [
            'rsi_14' => 55.0,
            'adx_14' => 28.0,
            'relative_volume' => 1.2,
        ]);

        // Verify decision was altered from BUY to SKIP with explicit veto reason
        self::assertSame(SignalSide::Skip, $vetoedDecision->side);
        self::assertNull($vetoedDecision->qualityClass);
        self::assertNull($vetoedDecision->pricePlan);
        self::assertContains('ml_advisory_low_probability', $vetoedDecision->reasons);
        self::assertTrue((bool) $vetoedDecision->indicatorSnapshot['model_advisory']['altered_decision']);
        self::assertSame('VETO_LOW_PROBABILITY', $vetoedDecision->indicatorSnapshot['model_advisory']['recommendation']);

        // Verify audit log event was written
        $vetoEvent = AutonomyApprovalEventRecord::query()
            ->where('action', 'MODEL_ADVISORY_VETO')
            ->where('target_id', $model->id)
            ->latest('created_at')
            ->first();
        self::assertNotNull($vetoEvent);
        self::assertSame('BUY', $vetoEvent->previous_state);
        self::assertSame('SKIP', $vetoEvent->new_state);

        // 3. Rollback model: status transitions to ROLLED_BACK
        $rolledBack = $this->predictor->rollbackModel($targetEvent, $symbol, 'Concept drift detected in forward monitoring');
        self::assertNull($rolledBack); // No prior champion exists

        $model->refresh();
        self::assertSame('ROLLED_BACK', $model->status);

        // 4. Test Fail-Closed Policy when active model is missing/rolled-back
        $failClosedDecision = $activeAdvisor->advise($decision, []);
        self::assertSame(SignalSide::Skip, $failClosedDecision->side);
        self::assertContains('ml_advisory_no_active_model_fail_closed', $failClosedDecision->reasons);
        self::assertTrue((bool) $failClosedDecision->indicatorSnapshot['model_advisory']['altered_decision']);

        // 5. Test Explicitly Authorized Fallback (ALLOW_UNFILTERED) restores baseline BUY
        $fallbackAdvisor = new ModelAdvisoryService(
            predictor: $this->predictor,
            autonomy: $this->autonomy,
            config: [
                'enabled' => true,
                'mode' => 'ACTIVE',
                'target_event' => $targetEvent,
                'fallback_policy' => 'ALLOW_UNFILTERED',
            ]
        );

        $restoredDecision = $fallbackAdvisor->advise($decision, []);
        self::assertSame(SignalSide::Buy, $restoredDecision->side);
        self::assertSame(QualityClass::B, $restoredDecision->qualityClass);
        self::assertNotNull($restoredDecision->pricePlan);
        self::assertFalse((bool) $restoredDecision->indicatorSnapshot['model_advisory']['altered_decision']);
        self::assertSame('NO_ACTIVE_MODEL', $restoredDecision->indicatorSnapshot['model_advisory']['status']);
    }

    public function test_champion_rollback_restores_previous_champion_if_available(): void
    {
        $symbol = 'ETHUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Incumbent champion v1.0.0 in SUPERSEDED
        $champV1 = LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => "Logistic_{$targetEvent}_{$symbol}",
            'version' => 'v1.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => ['features' => ['rsi_14']],
            'weights' => ['intercept' => 0.5, 'rsi_14' => 0.01],
            'metrics' => ['brier_score' => 0.20, 'ece' => 0.05],
            'status' => 'SUPERSEDED',
            'created_at' => CarbonImmutable::now()->subDays(5),
            'artifact_hash' => hash('sha256', 'v1'),
        ]);

        // Challenger v2.0.0 currently ACTIVE
        $champV2 = LearningModelArtifactRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => "Logistic_{$targetEvent}_{$symbol}",
            'version' => 'v2.0.0',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'parameters' => ['features' => ['rsi_14']],
            'weights' => ['intercept' => -2.0, 'rsi_14' => -0.05],
            'metrics' => ['brier_score' => 0.22, 'ece' => 0.06],
            'status' => 'ACTIVE',
            'created_at' => CarbonImmutable::now()->subDays(1),
            'artifact_hash' => hash('sha256', 'v2'),
        ]);

        // Execute rollback of v2
        $restored = $this->predictor->rollbackModel($targetEvent, $symbol, 'Performance deterioration in v2.0.0');
        self::assertNotNull($restored);
        self::assertSame($champV1->id, $restored->id);

        $champV2->refresh();
        $champV1->refresh();

        self::assertSame('ROLLED_BACK', $champV2->status);
        self::assertSame('ACTIVE', $champV1->status);
    }
}
