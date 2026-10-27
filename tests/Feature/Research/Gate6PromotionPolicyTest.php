<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class Gate6PromotionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private PromotionPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PaperAccountService::class)->default();
        $this->policy = $this->app->make(PromotionPolicyService::class);
    }

    public function test_gate6_rejects_absent_paired_observations_even_with_optimistic_standard_error(): void
    {
        $candidate = $this->fullEvidence();
        unset($candidate['r_differences']);
        $candidate['standard_error'] = 0.0000001;

        $verdict = $this->policy->evaluateModelPromotion($candidate, $this->champion(), ['is_test_fixture' => true]);

        self::assertFalse($verdict['all_gates_passed']);
        self::assertFalse($verdict['gates']['multiplicity_adjusted_lcb95']['pass']);
        self::assertSame('missing_paired_r_differences', $verdict['gates']['multiplicity_adjusted_lcb95']['detail']['failure_reason']);

        $candidate['r_differences'] = array_fill(0, 2, 0.30);
        $verdict = $this->policy->evaluateModelPromotion($candidate, $this->champion(), ['is_test_fixture' => true]);
        self::assertFalse($verdict['all_gates_passed']);
        self::assertSame('insufficient_paired_r_differences', $verdict['gates']['multiplicity_adjusted_lcb95']['detail']['failure_reason']);
    }

    public function test_gate6_rejects_missing_or_non_finite_financial_risk_evidence(): void
    {
        foreach ([null, 'not-a-number', INF, NAN, true, -1] as $invalid) {
            $candidate = $this->fullEvidence();
            $candidate['max_drawdown_percent'] = $invalid;
            $verdict = $this->policy->evaluateModelPromotion($candidate, $this->champion(), ['is_test_fixture' => true]);
            self::assertFalse($verdict['all_gates_passed']);
            self::assertSame('invalid_or_missing_gate6_evidence', $verdict['failure_reason']);
        }
    }

    public function test_gate6_rejects_bad_paired_values_and_missing_champion(): void
    {
        $candidate = $this->fullEvidence();
        $candidate['r_differences'][0] = INF;
        $verdict = $this->policy->evaluateModelPromotion($candidate, $this->champion(), ['is_test_fixture' => true]);
        self::assertFalse($verdict['all_gates_passed']);
        self::assertSame('invalid_paired_observations', $verdict['gates']['multiplicity_adjusted_lcb95']['detail']['failure_reason']);

        $verdict = $this->policy->evaluateModelPromotion($this->fullEvidence(), null, ['is_test_fixture' => true]);
        self::assertFalse($verdict['all_gates_passed']);
        self::assertSame('invalid_or_missing_gate6_evidence', $verdict['failure_reason']);
    }

    public function test_gate6_reports_actual_oos_sample_count(): void
    {
        $candidate = $this->fullEvidence();
        $candidate['oos_samples'] = 135;
        $verdict = $this->policy->evaluateModelPromotion($candidate, $this->champion(), ['is_test_fixture' => true]);
        self::assertSame(135, $verdict['gates']['min_oos_samples']['actual']);
    }

    private function fullEvidence(): array
    {
        return [
            'training_samples' => 400,
            'oos_samples' => 140,
            'shadow_samples' => 120,
            'challenger_duration_days' => 20,
            'challenger_samples' => 75,
            'average_r' => 0.55,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 75, 0.30),
        ];
    }

    private function champion(): array
    {
        return ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];
    }

    public function test_multiplicity_adjusted_lcb_rejects_insignificant_improvement_under_multiple_trials(): void
    {
        // 50 observations where mean delta = +0.04 and standard error = 0.0173
        // Under single trial (M=1, z=1.96): LCB = +0.0061 > 0 (naively passes)
        // Under multiple candidate trials (M=5, z=2.576): LCB = -0.0045 <= 0 (correctly rejected)
        $differences = [];
        for ($i = 0; $i < 50; $i++) {
            $differences[] = 0.04 + ((($i % 5) - 2) * 0.10);
        }

        $singleTrial = $this->policy->computeMultiplicityAdjustedLcb($differences, trialCount: 1);
        self::assertTrue($singleTrial['pass']);
        self::assertGreaterThan(0.0, $singleTrial['lcb95']);
        self::assertEqualsWithDelta(1.96, $singleTrial['critical_z'], 0.01);

        $multiTrial = $this->policy->computeMultiplicityAdjustedLcb($differences, trialCount: 5);
        self::assertFalse($multiTrial['pass']);
        self::assertLessThanOrEqual(0.0, $multiTrial['lcb95']);
        self::assertGreaterThanOrEqual(2.57, $multiTrial['critical_z']);
    }

    public function test_multiplicity_adjusted_lcb_passes_for_strong_and_consistent_challenger(): void
    {
        // 60 observations with strong mean difference +0.40 and low variance
        $differences = [];
        for ($i = 0; $i < 60; $i++) {
            $differences[] = 0.35 + (($i % 5) * 0.02); // Mean ~ 0.39, small variance
        }

        $res = $this->policy->computeMultiplicityAdjustedLcb($differences, trialCount: 5);
        self::assertTrue($res['pass']);
        self::assertGreaterThan(0.0, $res['lcb95']);
        self::assertGreaterThanOrEqual(2.5, $res['critical_z']);
    }

    public function test_candidate_rejected_on_insufficient_training_samples(): void
    {
        $candidate = [
            'training_samples' => 250, // Required >= 300
            'oos_samples' => 120,
            'shadow_samples' => 110,
            'challenger_duration_days' => 16,
            'challenger_samples' => 60,
            'average_r' => 0.50,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 60, 0.30),
        ];

        $champion = [
            'average_r' => 0.20,
            'max_drawdown_percent' => 4.5,
        ];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('insufficient_training_samples', $result['failure_reason']);
        self::assertFalse($result['gates']['min_training_samples']['pass']);
    }

    public function test_candidate_rejected_on_insufficient_oos_samples(): void
    {
        $candidate = [
            'training_samples' => 350,
            'oos_samples' => 85, // Required >= 100
            'shadow_samples' => 110,
            'challenger_duration_days' => 16,
            'challenger_samples' => 60,
            'average_r' => 0.50,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 60, 0.30),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('insufficient_oos_samples', $result['failure_reason']);
    }

    public function test_candidate_rejected_on_insufficient_shadow_samples(): void
    {
        $candidate = [
            'training_samples' => 350,
            'oos_samples' => 120,
            'shadow_samples' => 40, // Required >= 100
            'challenger_duration_days' => 16,
            'challenger_samples' => 60,
            'average_r' => 0.50,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 60, 0.30),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('insufficient_shadow_samples', $result['failure_reason']);
    }

    public function test_candidate_rejected_on_premature_challenger_duration(): void
    {
        $candidate = [
            'training_samples' => 350,
            'oos_samples' => 120,
            'shadow_samples' => 110,
            'challenger_duration_days' => 10, // Required >= 14
            'challenger_samples' => 60,
            'average_r' => 0.50,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 60, 0.30),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('premature_challenger_duration', $result['failure_reason']);
    }

    public function test_candidate_rejected_on_insufficient_challenger_samples(): void
    {
        $candidate = [
            'training_samples' => 350,
            'oos_samples' => 120,
            'shadow_samples' => 110,
            'challenger_duration_days' => 16,
            'challenger_samples' => 30, // Required >= 50
            'average_r' => 0.50,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 30, 0.30),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('insufficient_challenger_samples', $result['failure_reason']);
    }

    public function test_candidate_rejected_on_drawdown_breach(): void
    {
        $candidate = [
            'training_samples' => 350,
            'oos_samples' => 120,
            'shadow_samples' => 110,
            'challenger_duration_days' => 16,
            'challenger_samples' => 60,
            'average_r' => 0.50,
            'max_drawdown_percent' => 8.0, // Champion is 4.0, margin is 2.0 -> max allowed 6.0
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 60, 0.30),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.0];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('drawdown_exceeds_threshold', $result['failure_reason']);
    }

    public function test_candidate_rejected_in_forward_production_without_genuine_forward_evidence(): void
    {
        $candidate = [
            'training_samples' => 400,
            'oos_samples' => 150,
            'shadow_samples' => 120,
            'challenger_duration_days' => 20,
            'challenger_samples' => 80,
            'average_r' => 0.45,
            'max_drawdown_percent' => 5.0,
            'brier_score' => 0.18,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 80, 0.25),
        ];

        $champion = ['average_r' => 0.20, 'max_drawdown_percent' => 4.5];

        // In forward production mode without genuine forward observations
        $result = $this->policy->evaluateModelPromotion($candidate, $champion, [
            'is_forward_production' => true,
            'is_synthetic_fixture' => false,
            'genuine_forward_observations' => 10, // < 50
        ]);

        self::assertFalse($result['all_gates_passed']);
        self::assertSame('insufficient_genuine_forward_evidence', $result['failure_reason']);
    }

    public function test_candidate_passes_all_gate6_requirements_with_immutable_hash(): void
    {
        $candidate = [
            'training_samples' => 450,
            'oos_samples' => 150,
            'shadow_samples' => 130,
            'challenger_duration_days' => 20,
            'challenger_samples' => 70,
            'average_r' => 0.55,
            'max_drawdown_percent' => 5.2,
            'brier_score' => 0.16,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 70, 0.30),
        ];

        $champion = [
            'average_r' => 0.20,
            'max_drawdown_percent' => 4.5,
            'artifact_id' => 'champ-uuid-001',
        ];

        $result = $this->policy->evaluateModelPromotion($candidate, $champion, ['is_test_fixture' => true]);

        self::assertTrue($result['all_gates_passed']);
        self::assertNull($result['failure_reason']);
        self::assertSame('4.0.0', $result['policy_version']);
        self::assertNotEmpty($result['policy_hash']);
        self::assertSame(64, strlen($result['policy_hash']));
        self::assertTrue($result['gates']['min_training_samples']['pass']);
        self::assertTrue($result['gates']['min_oos_samples']['pass']);
        self::assertTrue($result['gates']['min_shadow_samples']['pass']);
        self::assertTrue($result['gates']['challenger_duration']['pass']);
        self::assertTrue($result['gates']['challenger_samples']['pass']);
        self::assertTrue($result['gates']['risk_adjusted_improvement']['pass']);
        self::assertTrue($result['gates']['multiplicity_adjusted_lcb95']['pass']);
        self::assertTrue($result['gates']['drawdown_ceiling']['pass']);
        self::assertTrue($result['gates']['calibration']['pass']);
    }

    public function test_hac_variance_inflates_under_positively_autocorrelated_overlapping_returns(): void
    {
        // Persistent overlapping holding periods where trades stay correlated for consecutive bars
        $n = 60;
        $differences = [];
        for ($i = 0; $i < $n; $i++) {
            $block = (int) floor($i / 6); // 10 blocks of 6 overlapping bars each
            $differences[] = 0.10 + (($block % 2 === 0 ? 1 : -1) * 0.05) + (($i % 6) * 0.005);
        }

        $res = $this->policy->computeMultiplicityAdjustedLcb($differences, trialCount: 5, maxLag: 4);

        // Compute naive sample variance (gamma_0 / n)
        $mean = array_sum($differences) / $n;
        $gamma0 = 0.0;
        foreach ($differences as $d) {
            $gamma0 += ($d - $mean) ** 2;
        }
        $gamma0 /= $n;
        $naiveVariance = $gamma0;

        // Positive autocorrelation causes HAC variance to exceed sample variance
        self::assertGreaterThan($naiveVariance, $res['hac_variance']);
        // Verify lag sensitivity is reported for lags 1, 2, 4, 8
        self::assertArrayHasKey('lag_1', $res['lag_sensitivity']);
        self::assertArrayHasKey('lag_2', $res['lag_sensitivity']);
        self::assertArrayHasKey('lag_4', $res['lag_sensitivity']);
        self::assertArrayHasKey('lag_8', $res['lag_sensitivity']);
    }

    public function test_authoritative_candidate_pool_prevents_caller_underestimation(): void
    {
        $candidate = [
            'training_samples' => 450,
            'oos_samples' => 150,
            'shadow_samples' => 130,
            'challenger_duration_days' => 20,
            'challenger_samples' => 70,
            'average_r' => 0.55,
            'max_drawdown_percent' => 5.2,
            'brier_score' => 0.16,
            'ece' => 0.04,
            'r_differences' => array_fill(0, 70, 0.30),
            // Caller attempts to claim only 1 candidate was tested to bypass multiple comparison penalty
            'attempted_candidates' => 1,
        ];

        $champion = [
            'average_r' => 0.20,
            'max_drawdown_percent' => 4.5,
            'artifact_id' => 'champ-uuid-001',
        ];

        // Evaluate with caller attempted_candidates = 1
        $result = $this->policy->evaluateModelPromotion($candidate, $champion, [
            'is_test_fixture' => true,
            'attempted_candidates' => 1,
        ]);

        // Must enforce at least the precommitted pool size (>= 5 trials)
        $lcbGate = $result['gates']['multiplicity_adjusted_lcb95'];
        self::assertGreaterThanOrEqual(5, $lcbGate['trial_count']);
        self::assertGreaterThanOrEqual(2.57, $lcbGate['critical_z']);
    }

    public function test_f26_promotion_rejection_records_audit_trail_event(): void
    {
        $candidate = LearningCandidateStrategyRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Test Candidate',
            'parent_strategy_id' => 'trend_pullback',
            'base_version' => '1.0.0',
            'candidate_version' => '1.1.0',
            'configuration_hash' => hash('sha256', 'test'),
            'hypothesis' => 'Test hypothesis',
            'status' => 'GENERATED',
            'parameters' => ['test' => true],
            'parameter_diff' => ['test' => true],
            'search_metadata' => ['seed' => 1],
            'validation_gates' => ['all_gates_passed' => false],
        ]);

        try {
            $this->policy->promoteCandidate($candidate->id, 'TEST_OPERATOR');
            self::fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            // Expected
        }

        // F26 Invariant: Rejection event must be recorded in autonomy_approval_events
        $event = AutonomyApprovalEventRecord::query()
            ->where('target_id', $candidate->id)
            ->where('action', 'CANDIDATE_PROMOTION_REJECTED')
            ->first();

        self::assertNotNull($event, 'F26: CANDIDATE_PROMOTION_REJECTED audit event must be persisted');
        self::assertSame('TEST_OPERATOR', $event->actor);
        self::assertStringContainsString('Cannot promote candidate', $event->reason);
    }
}
