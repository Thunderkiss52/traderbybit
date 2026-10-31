<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ModelAdvisoryService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class MlAuthorityAndProperScoringPostgresIntegrationTest extends TestCase
{
    private array $cleanUpModelIds = [];

    private array $cleanUpCandidateIds = [];

    private array $cleanUpSnapshotIds = [];

    private array $cleanUpEventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('learning_model_artifacts');
    }

    protected function tearDown(): void
    {
        if (! empty($this->cleanUpModelIds)) {
            LearningModelArtifactRecord::query()->whereIn('id', $this->cleanUpModelIds)->delete();
        }
        if (! empty($this->cleanUpCandidateIds)) {
            LearningCandidateStrategyRecord::query()->whereIn('id', $this->cleanUpCandidateIds)->delete();
        }
        if (! empty($this->cleanUpSnapshotIds)) {
            LearningFeatureSnapshotRecord::query()->whereIn('id', $this->cleanUpSnapshotIds)->delete();
        }
        if (! empty($this->cleanUpEventIds)) {
            AutonomyApprovalEventRecord::query()->whereIn('id', $this->cleanUpEventIds)->delete();
        }

        parent::tearDown();
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

    /**
     * Test 1: Legacy ACTIVE model without Gate 6 eligibility fails closed or bypasses,
     * without altering normal paper account balances or granting unearned advisory authority.
     */
    public function test_legacy_active_model_lacks_authority_and_fails_closed_or_bypasses(): void
    {
        $legacyModelId = (string) Uuid::uuid4();
        $this->cleanUpModelIds[] = $legacyModelId;

        $legacyModel = LearningModelArtifactRecord::query()->create([
            'id' => $legacyModelId,
            'name' => 'Logistic_TP_BEFORE_SL_BTCUSDT',
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => 'TP_BEFORE_SL',
            'version' => 'v20261018.145406',
            'parameters' => [
                'target_event' => 'TP_BEFORE_SL',
                'symbol' => 'BTCUSDT',
                'total_sample_size' => 37,
                'train_sample_size' => 27,
                'validation_sample_size' => 10,
                'features_used' => ['rsi_14', 'adx_14', 'relative_volume'],
                'algorithm' => 'BATCH_GRADIENT_DESCENT_L2',
            ],
            'weights' => [
                'intercept' => -3.5,
                'coefficients' => ['rsi_14' => -0.05, 'adx_14' => -0.02, 'relative_volume' => -0.10],
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

        $promotionPolicy = app(PromotionPolicyService::class);
        $eligibility = $promotionPolicy->evaluateAdvisoryEligibility($legacyModel);

        self::assertFalse($eligibility['eligible'], 'Legacy active model must NOT be marked eligible without Gate 6 validation.');
        self::assertStringContainsString('legacy_model_unverified_by_gate6', $eligibility['reason']);

        // Snapshot initial accounts state
        $accountsBefore = PaperAccountRecord::query()->pluck('current_equity', 'id')->toArray();

        // Mode A: FAIL_CLOSED_SKIP
        config([
            'strategy.model_advisory.mode' => 'ACTIVE',
            'strategy.model_advisory.fallback_policy' => 'FAIL_CLOSED_SKIP',
        ]);

        $advisoryService = app(ModelAdvisoryService::class);
        $decision = $this->createSampleBuyDecision('BTCUSDT');
        $evaluatedDecision = $advisoryService->advise($decision, [
            'rsi_14' => 55.0,
            'adx_14' => 28.0,
            'relative_volume' => 1.2,
        ]);

        self::assertSame(SignalSide::Skip, $evaluatedDecision->side, 'Must fail-closed to SKIP when eligible ML is required.');
        self::assertContains('ml_advisory_no_eligible_model_fail_closed', $evaluatedDecision->reasons);
        self::assertSame(85, $evaluatedDecision->score, 'Original score must be preserved for audit.');

        // Mode B: ALLOW_UNFILTERED
        config([
            'strategy.model_advisory.mode' => 'ACTIVE',
            'strategy.model_advisory.fallback_policy' => 'ALLOW_UNFILTERED',
        ]);

        app()->forgetInstance(ModelAdvisoryService::class);
        $advisoryServiceB = app(ModelAdvisoryService::class);
        $evaluatedDecisionB = $advisoryServiceB->advise($decision, [
            'rsi_14' => 55.0,
            'adx_14' => 28.0,
            'relative_volume' => 1.2,
        ]);

        self::assertSame(SignalSide::Buy, $evaluatedDecisionB->side, 'Deterministic proposal must proceed unfiltered when policy authorizes.');
        self::assertNotContains('ml_advisory_no_eligible_model_fail_closed', $evaluatedDecisionB->reasons);

        // Verify paper accounts were not mutated by advisory evaluation
        $accountsAfter = PaperAccountRecord::query()->pluck('current_equity', 'id')->toArray();
        self::assertSame($accountsBefore, $accountsAfter, 'Advisory evaluation must never alter paper account equities.');
    }

    /**
     * Test 2: Proper scoring evaluation enforces training-only constant prevalence baseline forecast
     * on identical chronological OOS holdout and requires positive Brier Skill Score.
     */
    public function test_proper_scoring_rule_training_prevalence_baseline_and_bss(): void
    {
        $now = CarbonImmutable::now('UTC');
        $symbol = 'ETHUSDT';
        $targetEvent = 'TP_BEFORE_SL';

        // Clean any existing models for this symbol and target
        LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', "Logistic_{$targetEvent}_{$symbol}")
            ->delete();

        // Seed 14 synthetic matured observations (10 train, 4 holdout)
        // With informative features separating TP from SL
        for ($i = 0; $i < 14; $i++) {
            $snapId = (string) Uuid::uuid4();
            $this->cleanUpSnapshotIds[] = $snapId;
            $isTp = ($i % 2 === 0);

            LearningFeatureSnapshotRecord::query()->create([
                'id' => $snapId,
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $now->subDays(20 - $i),
                'available_at' => $now->subDays(20 - $i),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'trend_pullback',
                'regime' => 'TREND_BULLISH',
                'features' => [
                    'close' => 3000.0,
                    'hl_spread_pct' => $isTp ? 2.5 : 0.5,
                    'body_spread_pct' => $isTp ? 1.5 : 0.2,
                    'recent_volatility' => $isTp ? 1.8 : 0.4,
                    'rsi_14' => $isTp ? 62.0 : 38.0,
                    'volume_ratio' => $isTp ? 2.0 : 0.6,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $isTp ? 'TP' : 'SL',
                'label_r_multiple' => $isTp ? 2.0 : -1.0,
                'outcome_at' => $now->subDays(20 - $i)->addHours(2),
                'data_quality' => ['exchange' => 'bybit', 'verified' => true],
            ]);
        }

        $predictor = app(LearningPredictorService::class);
        $model = $predictor->trainModel($targetEvent, $symbol);

        self::assertNotNull($model, 'Model must be trained from matured snapshots.');
        $this->cleanUpModelIds[] = $model->id;

        self::assertArrayHasKey('baseline_brier_score', $model->metrics);
        self::assertArrayHasKey('brier_skill_score', $model->metrics);
        self::assertArrayHasKey('brier_reduction_vs_baseline', $model->metrics);
        self::assertArrayHasKey('training_prevalence', $model->metrics);

        // Training prevalence must be between 0 and 1
        self::assertGreaterThanOrEqual(0.0, $model->metrics['training_prevalence']);
        self::assertLessThanOrEqual(1.0, $model->metrics['training_prevalence']);

        // Model that beats baseline and passes gates is ACTIVE champion
        self::assertSame('ACTIVE', $model->status);
        self::assertGreaterThanOrEqual(0.0, $model->metrics['brier_skill_score']);
    }

    /**
     * Test 3: Gate 6 full validation funnel checks min samples, expectancy, drawdown and calibration.
     */
    public function test_gate6_full_funnel_validation(): void
    {
        $policyService = app(PromotionPolicyService::class);

        // Candidate with insufficient training samples (< 300)
        $underSampledMetrics = [
            'sample_size' => 50,
            'training_samples' => 40,
            'oos_samples' => 10,
            'shadow_samples' => 0,
            'challenger_duration_days' => 5,
            'challenger_samples' => 10,
            'expectancy_r' => 0.45,
            'max_drawdown_percent' => 1.5,
            'brier_score' => 0.18,
            'ece' => 0.04,
        ];

        $evalUnder = $policyService->evaluateModelPromotion($underSampledMetrics, null, ['is_test_fixture' => true]);
        self::assertFalse($evalUnder['all_gates_passed']);
        self::assertSame('insufficient_training_samples', $evalUnder['failure_reason']);

        // Candidate with negative expectancy
        $negExpMetrics = array_merge($underSampledMetrics, [
            'training_samples' => 350,
            'oos_samples' => 120,
            'shadow_samples' => 110,
            'challenger_duration_days' => 15,
            'challenger_samples' => 60,
            'expectancy_r' => -0.10,
        ]);
        $evalNeg = $policyService->evaluateModelPromotion($negExpMetrics, null, ['is_test_fixture' => true]);
        self::assertFalse($evalNeg['all_gates_passed']);
        self::assertSame('inferior_risk_adjusted_expectancy', $evalNeg['failure_reason']);

        // Candidate satisfying all Gate 6 criteria
        $qualifiedMetrics = array_merge($negExpMetrics, [
            'expectancy_r' => 0.35,
            'max_drawdown_percent' => 1.5,
            'r_differences' => array_fill(0, 60, 0.25),
        ]);
        $championMetrics = [
            'expectancy_r' => 0.15,
            'max_drawdown_percent' => 3.0,
        ];
        $evalQual = $policyService->evaluateModelPromotion($qualifiedMetrics, $championMetrics, ['is_test_fixture' => true]);
        self::assertTrue($evalQual['all_gates_passed'], 'Fully qualified candidate must pass Gate 6.');
    }

    /**
     * Test 4 (Negative & Mutation Control §15.3 #4):
     * Attempt unauthorized promotion of a Gate-6-ineligible candidate and verify denial,
     * failure exception, and unchanged paper accounts.
     */
    public function test_negative_mutation_unauthorized_promotion_rejected(): void
    {
        $candidateId = (string) Uuid::uuid4();
        $this->cleanUpCandidateIds[] = $candidateId;

        // Create an ineligible candidate record
        $candidate = LearningCandidateStrategyRecord::query()->create([
            'id' => $candidateId,
            'name' => 'trend_pullback_v150',
            'parent_strategy_id' => 'trend_pullback',
            'base_version' => '1.0.0',
            'candidate_version' => '1.5.0-unauthorized',
            'configuration_hash' => hash('sha256', 'unauthorized'),
            'hypothesis' => 'Unauthorized parameter shift',
            'parameters' => ['fast_period' => 8, 'slow_period' => 21],
            'parameter_diff' => ['fast_period' => [10, 8]],
            'status' => 'REJECTED',
            'search_metadata' => ['generation' => 1],
            'validation_gates' => [
                'all_gates_passed' => false,
                'failure_reason' => 'excessive_drawdown',
            ],
        ]);

        $accountsBefore = PaperAccountRecord::query()->pluck('current_equity', 'id')->toArray();

        $policyService = app(PromotionPolicyService::class);

        $denied = false;
        try {
            $policyService->promoteCandidate($candidateId, 'UNAUTHORIZED_ATTEMPT');
        } catch (ValidationException $e) {
            $denied = true;
            self::assertStringContainsString('Cannot promote candidate', $e->getMessage());
        }

        self::assertTrue($denied, 'Promotion of ineligible candidate MUST throw ValidationException.');

        // Recheck candidate status in database: must remain REJECTED
        $candidateFresh = $candidate->fresh();
        self::assertSame('REJECTED', $candidateFresh->status, 'Candidate status must not change from REJECTED.');

        // Paper accounts must remain untouched
        $accountsAfter = PaperAccountRecord::query()->pluck('current_equity', 'id')->toArray();
        self::assertSame($accountsBefore, $accountsAfter, 'Paper accounts must remain unchanged after rejected promotion attempt.');
    }
}
