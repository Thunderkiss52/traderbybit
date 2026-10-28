<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromotionPolicyService
{
    public function __construct(
        private readonly AutonomyPermissionService $autonomy,
        private readonly PaperAccountService $paperAccounts,
    ) {}

    /**
     * Return immutable configuration hash of current promotion policy.
     */
    public function getPolicyHash(): string
    {
        $config = ['model' => (array) config('strategy.promotion_policy', []), 'candidate' => (array) config('research.autonomy.gates', [])];

        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * Return current policy version string.
     */
    public function getPolicyVersion(): string
    {
        return (string) config('strategy.promotion_policy.version', '4.0.0');
    }

    /**
     * Inverse Standard Normal Cumulative Distribution Function (Abramowitz & Stegun 26.2.23).
     * Delivers absolute precision <= 4.5e-4 across (0, 1).
     */
    public static function invNormCdf(float $p): float
    {
        if ($p <= 0.0 || $p >= 1.0) {
            throw new \InvalidArgumentException("Probability p must be in (0, 1), got {$p}");
        }

        if ($p < 0.5) {
            return -self::invNormCdf(1.0 - $p);
        }

        $t = sqrt(-2.0 * log(1.0 - $p));
        $c0 = 2.515517;
        $c1 = 0.802853;
        $c2 = 0.010328;
        $d1 = 1.432788;
        $d2 = 0.189269;
        $d3 = 0.001308;

        $num = $c0 + ($c1 * $t) + ($c2 * $t * $t);
        $den = 1.0 + ($d1 * $t) + ($d2 * $t * $t) + ($d3 * $t * $t * $t);

        return $t - ($num / $den);
    }

    /**
     * Parse a genuine finite observation, never an implicit PHP cast.
     * Numeric strings remain supported for metrics serialized by a DB driver.
     */
    private static function observedNumber(mixed $value): ?float
    {
        if (! (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '' && is_numeric($value)))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private static function observedCount(mixed $value): ?int
    {
        $number = self::observedNumber($value);
        if ($number === null || $number < 0 || $number > PHP_INT_MAX || floor($number) !== $number) {
            return null;
        }

        return (int) $number;
    }

    /**
     * Compute Multiplicity-Adjusted Lower Confidence Bound (LCB) with Newey-West HAC variance.
     * Accounts for serial correlation in overlapping trading observations and controls FWER across M candidate trials.
     */
    public function computeMultiplicityAdjustedLcb(array $rDifferences, int $trialCount = 1, float $alpha = 0.05, int $maxLag = 4): array
    {
        $n = count($rDifferences);
        foreach ($rDifferences as $difference) {
            if (self::observedNumber($difference) === null) {
                return [
                    'mean_difference' => 0.0,
                    'standard_error' => 0.0,
                    'critical_z' => null,
                    'lcb95' => 0.0,
                    'pass' => false,
                    'effective_sample_size' => $n,
                    'failure_reason' => 'invalid_paired_observations',
                ];
            }
        }
        if ($trialCount < 1 || $alpha <= 0.0 || $alpha >= 1.0 || ! is_finite($alpha) || $maxLag < 0) {
            return [
                'mean_difference' => 0.0,
                'standard_error' => 0.0,
                'critical_z' => null,
                'lcb95' => 0.0,
                'pass' => false,
                'effective_sample_size' => $n,
                'failure_reason' => 'invalid_statistical_policy',
            ];
        }
        if ($n < 2) {
            return [
                'mean_difference' => $n === 1 ? (float) $rDifferences[0] : 0.0,
                'standard_error' => 0.0,
                'critical_z' => 1.96,
                'lcb95' => 0.0,
                'pass' => false,
                'effective_sample_size' => $n,
                'failure_reason' => 'insufficient_observations_for_lcb',
            ];
        }

        $mean = array_sum($rDifferences) / $n;

        // 1. Newey-West HAC Variance
        // gamma_0
        $gamma0 = 0.0;
        foreach ($rDifferences as $diff) {
            $gamma0 += ($diff - $mean) ** 2;
        }
        $gamma0 /= $n;

        $calcHacForLag = static function (int $lag) use ($rDifferences, $mean, $gamma0, $n): float {
            $hacSum = 0.0;
            for ($l = 1; $l <= $lag; $l++) {
                $gammaL = 0.0;
                for ($t = $l; $t < $n; $t++) {
                    $gammaL += ($rDifferences[$t] - $mean) * ($rDifferences[$t - $l] - $mean);
                }
                $gammaL /= $n;
                $weight = 1.0 - ($l / ($lag + 1.0));
                $hacSum += 2.0 * $weight * $gammaL;
            }

            return max(1e-8, $gamma0 + $hacSum);
        };

        // Truncation lag L = min(maxLag, floor(N^(1/4)))
        $lagTrunc = min($maxLag, (int) floor($n ** 0.25));
        $lagTrunc = max(1, $lagTrunc);

        $hacVariance = $calcHacForLag($lagTrunc);
        $se = sqrt($hacVariance / $n);

        // 2. Multiplicity Correction: Bonferroni-adjusted quantile for M concurrent trials
        $m = max(1, $trialCount);
        $alphaTrial = $alpha / $m;
        $quantileP = 1.0 - ($alphaTrial / 2.0);
        $critZ = self::invNormCdf($quantileP);

        // 3. Multiplicity-Adjusted Lower Confidence Bound
        $lcb = $mean - ($critZ * $se);

        // 4. Sensitivity analysis across candidate HAC truncation lags (1, 2, 4, 8)
        $lagSensitivity = [];
        foreach ([1, 2, 4, 8] as $sensLag) {
            $sensVar = $calcHacForLag($sensLag);
            $sensSe = sqrt($sensVar / $n);
            $sensLcb = $mean - ($critZ * $sensSe);
            $lagSensitivity["lag_{$sensLag}"] = [
                'hac_variance' => round($sensVar, 6),
                'standard_error' => round($sensSe, 4),
                'lcb95' => round($sensLcb, 4),
                'pass' => $sensLcb > 0.0,
            ];
        }

        return [
            'sample_size' => $n,
            'mean_difference' => round($mean, 4),
            'hac_variance' => round($hacVariance, 6),
            'standard_error' => round($se, 4),
            'trial_count' => $m,
            'adjusted_alpha' => round($alphaTrial, 6),
            'critical_z' => round($critZ, 4),
            'lcb95' => round($lcb, 4),
            'pass' => $lcb > 0.0,
            'lag_truncation' => $lagTrunc,
            'lag_sensitivity' => $lagSensitivity,
        ];
    }

    /**
     * Evaluate a candidate model or parameter policy against the complete Gate 6 requirements.
     */
    public function evaluateModelPromotion(array $candidateMetrics, ?array $championMetrics = null, array $context = []): array
    {
        $policy = (array) config('strategy.promotion_policy', []);
        $policyVersion = $this->getPolicyVersion();
        $policyHash = $this->getPolicyHash();

        $minTrain = (int) ($policy['min_training_samples'] ?? 300);
        $minOos = (int) ($policy['min_oos_samples'] ?? 100);
        $minShadow = (int) ($policy['min_shadow_samples'] ?? 100);
        $minChallengerDays = (int) ($policy['min_challenger_duration_days'] ?? 14);
        $minChallengerOps = (int) ($policy['min_challenger_samples'] ?? 50);
        $maxDdMargin = (float) ($policy['max_challenger_drawdown_margin'] ?? 2.0);
        $hardDdCeiling = (float) ($policy['max_hard_drawdown'] ?? 12.0);
        $maxBrier = (float) ($policy['max_brier'] ?? 0.28);
        $maxEce = (float) ($policy['max_ece'] ?? 0.15);
        $alphaFwer = (float) ($policy['alpha_fwer'] ?? 0.05);
        $precommittedCandidates = (int) ($policy['max_concurrent_candidates'] ?? 5);
        $registryCandidates = 0;
        if (! empty($context['hypothesis_family'])) {
            $registryCandidates = ResearchJobRecord::query()->where('hypothesis_family', (string) $context['hypothesis_family'])->count();
        } elseif (! empty($context['job_id'])) {
            $job = ResearchJobRecord::query()->find($context['job_id']);
            if ($job && ! empty($job->hypothesis_family)) {
                $registryCandidates = ResearchJobRecord::query()->where('hypothesis_family', (string) $job->hypothesis_family)->count();
            }
        }
        $callerTrials = (int) ($candidateMetrics['attempted_candidates'] ?? $context['attempted_candidates'] ?? 0);
        // Authoritative candidate count is the maximum of registry candidates, precommitted pool, and caller count.
        // Never allows downscaling below the precommitted pool or registry family size.
        $maxTrials = max(1, $precommittedCandidates, $registryCandidates, $callerTrials);
        $hacLag = (int) ($policy['hac_lag_truncation'] ?? 4);

        // Reject missing/non-finite values before evaluating Gate 6. Casting null,
        // booleans or malformed strings to zero can fabricate passing risk data.
        $observed = [
            'training_samples' => self::observedCount($candidateMetrics['training_samples'] ?? $candidateMetrics['sample_size'] ?? null),
            'oos_samples' => self::observedCount($candidateMetrics['oos_samples'] ?? $candidateMetrics['holdout_samples'] ?? null),
            'shadow_samples' => self::observedCount($candidateMetrics['shadow_samples'] ?? null),
            'challenger_duration_days' => self::observedCount($candidateMetrics['challenger_duration_days'] ?? null),
            'challenger_samples' => self::observedCount($candidateMetrics['challenger_samples'] ?? $candidateMetrics['challenger_operations'] ?? null),
            'max_drawdown_percent' => self::observedNumber($candidateMetrics['max_drawdown_percent'] ?? null),
            'brier_score' => self::observedNumber($candidateMetrics['brier_score'] ?? null),
            'ece' => self::observedNumber($candidateMetrics['ece'] ?? null),
            'candidate_expectancy_r' => self::observedNumber($candidateMetrics['expectancy_r'] ?? $candidateMetrics['average_r'] ?? null),
            'champion_expectancy_r' => self::observedNumber($championMetrics['expectancy_r'] ?? $championMetrics['average_r'] ?? null),
            'champion_drawdown_percent' => self::observedNumber($championMetrics['max_drawdown_percent'] ?? null),
        ];
        $invalidEvidenceFields = array_keys(array_filter($observed, static fn (mixed $v): bool => $v === null));
        foreach (['max_drawdown_percent', 'champion_drawdown_percent', 'brier_score', 'ece'] as $nonnegativeField) {
            if ($observed[$nonnegativeField] !== null && $observed[$nonnegativeField] < 0.0) {
                $invalidEvidenceFields[] = $nonnegativeField;
            }
        }
        foreach (['brier_score', 'ece'] as $probabilityField) {
            if ($observed[$probabilityField] !== null && $observed[$probabilityField] > 1.0) {
                $invalidEvidenceFields[] = $probabilityField;
            }
        }
        $trainingSamples = $observed['training_samples'] ?? 0;
        $oosSamples = $observed['oos_samples'] ?? 0;
        $shadowSamples = $observed['shadow_samples'] ?? 0;
        $challengerDurationDays = $observed['challenger_duration_days'] ?? 0;
        $challengerOps = $observed['challenger_samples'] ?? 0;
        $candidateDd = $observed['max_drawdown_percent'] ?? 0.0;
        $candidateBrier = $observed['brier_score'] ?? 0.0;
        $candidateEce = $observed['ece'] ?? 0.0;
        $candidateExpR = $observed['candidate_expectancy_r'] ?? 0.0;

        $isTestFixture = (bool) ($context['is_synthetic_fixture'] ?? $context['is_test_fixture'] ?? false);
        $isForwardProduction = (bool) ($context['is_forward_production'] ?? (! app()->runningUnitTests()));

        // Gate 1: Training samples >= 300
        $trainPass = $trainingSamples >= $minTrain;

        // Gate 2: Non-overlapping OOS samples >= 100
        $oosPass = $oosSamples >= $minOos;

        // Gate 3: Prospective SHADOW opportunities >= 100
        $shadowPass = $shadowSamples >= $minShadow;

        // Gate 4: Challenger duration >= 14 days AND operations >= 50
        $challengerDurationPass = $challengerDurationDays >= $minChallengerDays;
        $challengerOpsPass = $challengerOps >= $minChallengerOps;

        // Gate 5 & 6: Paired risk-adjusted improvement and Multiplicity-adjusted 95% LCB
        $champExpR = $observed['champion_expectancy_r'] ?? 0.0;
        $rDiffs = $candidateMetrics['r_differences'] ?? null;
        if (is_array($rDiffs) && count($rDiffs) >= max(2, $minChallengerOps, $challengerOps)) {
            $lcbResult = $this->computeMultiplicityAdjustedLcb($rDiffs, $maxTrials, $alphaFwer, $hacLag);
            $riskAdjPass = ! isset($lcbResult['failure_reason']) && ($lcbResult['mean_difference'] ?? 0.0) > 0.0;
            $lcbPass = (bool) ($lcbResult['pass'] ?? false);
        } else {
            // Aggregate expectancy and a caller supplied/assumed standard error
            // do not establish paired prospective evidence or a valid HAC LCB.
            $riskAdjPass = false;
            $lcbPass = false;
            $lcbResult = [
                'mean_difference' => null,
                'standard_error' => null,
                'critical_z' => null,
                'lcb95' => null,
                'pass' => false,
                'failure_reason' => is_array($rDiffs) && $rDiffs !== [] ? 'insufficient_paired_r_differences' : 'missing_paired_r_differences',
            ];
        }

        // Gate 7: Drawdown ceiling
        $champDd = $observed['champion_drawdown_percent'] ?? 0.0;
        $ddMarginPass = $candidateDd <= ($champDd + $maxDdMargin);
        $hardDdPass = $candidateDd <= $hardDdCeiling;
        $ddPass = $ddMarginPass && $hardDdPass;

        // Gate 8: Calibration (Brier <= 0.28, ECE <= 0.15)
        $calibrationPass = ($candidateBrier <= $maxBrier) && ($candidateEce <= $maxEce);

        // Gate 9: Forward Authenticity Check (synthetic fixtures cannot promote in real forward production)
        $authenticityPass = true;
        if ($isForwardProduction && ! $isTestFixture) {
            // Require explicit genuine forward observation evidence
            $genuineCount = self::observedCount($context['genuine_forward_observations'] ?? null) ?? 0;
            if ($genuineCount < $minChallengerOps) {
                $authenticityPass = false;
            }
        }

        $allPass = $invalidEvidenceFields === []
            && $trainPass
            && $oosPass
            && $shadowPass
            && $challengerDurationPass
            && $challengerOpsPass
            && $riskAdjPass
            && $lcbPass
            && $ddPass
            && $calibrationPass
            && $authenticityPass;

        $failureReason = null;
        $failureDetail = null;
        if (! $trainPass) {
            $failureReason = 'insufficient_training_samples';
            $failureDetail = "Training samples {$trainingSamples} < required {$minTrain}.";
        } elseif (! $oosPass) {
            $failureReason = 'insufficient_oos_samples';
            $failureDetail = "Out-of-sample observations {$oosSamples} < required {$minOos}.";
        } elseif (! $shadowPass) {
            $failureReason = 'insufficient_shadow_samples';
            $failureDetail = "Prospective shadow observations {$shadowSamples} < required {$minShadow}.";
        } elseif (! $challengerDurationPass) {
            $failureReason = 'premature_challenger_duration';
            $failureDetail = "Challenger duration {$challengerDurationDays} days < required {$minChallengerDays} days.";
        } elseif (! $challengerOpsPass) {
            $failureReason = 'insufficient_challenger_samples';
            $failureDetail = "Challenger operations {$challengerOps} < required {$minChallengerOps}.";
        } elseif ($observed['candidate_expectancy_r'] !== null && $observed['candidate_expectancy_r'] <= 0.0) {
            $failureReason = 'inferior_risk_adjusted_expectancy';
            $failureDetail = 'Challenger net expectancy is not positive.';
        } elseif ($invalidEvidenceFields !== []) {
            $failureReason = 'invalid_or_missing_gate6_evidence';
            $failureDetail = 'Invalid or missing required metrics: '.implode(', ', array_unique($invalidEvidenceFields));
        } elseif (! $riskAdjPass) {
            $failureReason = 'inferior_risk_adjusted_expectancy';
            $failureDetail = 'Challenger net expectancy does not exceed champion.';
        } elseif (! $lcbPass) {
            $failureReason = 'negative_lcb95_confidence_bound';
            $failureDetail = "Multiplicity-adjusted 95% LCB {$lcbResult['lcb95']} <= 0 (not statistically distinguishable after multiple testing correction).";
        } elseif (! $ddPass) {
            $failureReason = 'drawdown_exceeds_threshold';
            $failureDetail = "Challenger max drawdown {$candidateDd}% breaches champion margin or hard ceiling {$hardDdCeiling}%.";
        } elseif (! $calibrationPass) {
            $failureReason = 'calibration_failed';
            $failureDetail = "Calibration error (Brier: {$candidateBrier}, ECE: {$candidateEce}) exceeds thresholds.";
        } elseif (! $authenticityPass) {
            $failureReason = 'insufficient_genuine_forward_evidence';
            $failureDetail = 'Forward market promotion requires genuine prospective observation history.';
        }

        return [
            'all_gates_passed' => $allPass,
            'failure_reason' => $failureReason,
            'failure_detail' => $failureDetail,
            'policy_version' => $policyVersion,
            'policy_hash' => $policyHash,
            'gates' => [
                'min_training_samples' => ['threshold' => $minTrain, 'actual' => $trainingSamples, 'pass' => $trainPass],
                'min_oos_samples' => ['threshold' => $minOos, 'actual' => $oosSamples, 'pass' => $oosPass],
                'min_shadow_samples' => ['threshold' => $minShadow, 'actual' => $shadowSamples, 'pass' => $shadowPass],
                'challenger_duration' => ['threshold' => $minChallengerDays, 'actual' => $challengerDurationDays, 'pass' => $challengerDurationPass],
                'challenger_samples' => ['threshold' => $minChallengerOps, 'actual' => $challengerOps, 'pass' => $challengerOpsPass],
                'risk_adjusted_improvement' => ['pass' => $riskAdjPass, 'detail' => $lcbResult],
                'multiplicity_adjusted_lcb95' => ['pass' => $lcbPass, 'trial_count' => $maxTrials, 'critical_z' => $lcbResult['critical_z'], 'detail' => $lcbResult],
                'drawdown_ceiling' => ['threshold' => $hardDdCeiling, 'actual' => $candidateDd, 'pass' => $ddPass],
                'calibration' => ['pass' => $calibrationPass, 'brier' => $candidateBrier, 'ece' => $candidateEce],
                'forward_authenticity' => ['pass' => $authenticityPass],
                'evidence_integrity' => ['pass' => $invalidEvidenceFields === [], 'invalid_fields' => array_values(array_unique($invalidEvidenceFields))],
            ],
            'metrics' => $candidateMetrics,
            'evidence_identifiers' => [
                'validation_run_id' => $candidateMetrics['validation_run_id'] ?? null,
                'challenger_account_id' => $candidateMetrics['challenger_account_id'] ?? null,
                'champion_artifact_id' => $championMetrics['artifact_id'] ?? null,
                'policy_hash' => $policyHash,
            ],
        ];
    }

    /**
     * Evaluate whether an active ML model artifact is eligible to influence live/paper execution.
     * Legacy active models without Gate 6 promotion or explicit approved eligibility are strictly ineligible.
     *
     * @return array{eligible: bool, reason: string, policy_version: string, failures?: list<string>}
     */
    public function evaluateAdvisoryEligibility(LearningModelArtifactRecord $model): array
    {
        $params = (array) ($model->parameters ?? []);
        $metrics = (array) ($model->metrics ?? []);

        // 1. Explicit Gate 6 promotion validation approval
        if (($params['gate6_validation']['all_gates_passed'] ?? false) === true
            && ($params['gate6_validation']['policy_hash'] ?? null) === $this->getPolicyHash()
            && ! empty($params['gate6_validation']['evidence_identifiers']['validation_run_id'])
            && ! empty($params['gate6_validation']['evidence_identifiers']['challenger_account_id'])) {
            return [
                'eligible' => true,
                'reason' => 'gate6_promotion_verified',
                'policy_version' => (string) ($params['gate6_validation']['policy_version'] ?? $this->getPolicyVersion()),
            ];
        }

        // 2. Explicitly approved operator eligibility policy
        if (($params['approved_for_advisory'] ?? false) === true) {
            return [
                'eligible' => true,
                'reason' => 'explicitly_approved_advisory_policy',
                'policy_version' => $this->getPolicyVersion(),
            ];
        }

        // 3. Legacy model statistics are NOT a substitute for prospective
        // paired evidence, candidate-bound immutable history or all Gate 6
        // conditions. Never dynamically approve from sample counts alone.
        return [
            'eligible' => false,
            'reason' => 'legacy_model_unverified_by_gate6: full prospective evidence required',
            'policy_version' => $this->getPolicyVersion(),
            'failures' => ['missing_verified_gate6_promotion'],
        ];
    }

    /**
     * Evaluate candidate against mandatory validation gates.
     * Only accepts immutable, reproducible validation results linked to a real completed research or backtest run.
     * Missing or unverified evidence fails closed.
     */
    public function evaluateCandidate(string $candidateId, ?string $validationRunId = null): LearningCandidateStrategyRecord
    {
        return DB::transaction(function () use ($candidateId, $validationRunId): LearningCandidateStrategyRecord {
            $before = LearningCandidateStrategyRecord::query()->lockForUpdate()->findOrFail($candidateId);
            $result = $this->evaluateCandidateEvidence($candidateId, $validationRunId);
            $this->autonomy->recordEvent(
                actor: 'SYSTEM_VALIDATION', action: 'CANDIDATE_EVALUATION', targetType: 'CANDIDATE_STRATEGY',
                targetId: $candidateId, previousState: $before->status, newState: $result->status,
                reason: 'Current authoritative candidate eligibility evaluation.',
                evidence: ['previous_gates' => $before->validation_gates, 'validation_gates' => $result->validation_gates]
            );

            return $result;
        });
    }

    private function evaluateCandidateEvidence(string $candidateId, ?string $validationRunId): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        $runId = $validationRunId
            ?? ($candidate->search_metadata['backtest_run_id'] ?? null)
            ?? ($candidate->search_metadata['validation_run_id'] ?? null)
            ?? ($candidate->search_metadata['evaluation_run_id'] ?? null);

        if ($runId === null || ! is_string($runId) || trim($runId) === '') {
            $metadata = (array) $candidate->search_metadata;
            $evalStatus = $metadata['evaluation_status'] ?? null;
            if (in_array($evalStatus, ['QUEUED', 'RUNNING'], true)) {
                $candidate->update([
                    'validation_gates' => [
                        'all_gates_passed' => false,
                        'status' => 'VALIDATION_IN_PROGRESS',
                        'failure_reason' => 'validation_in_progress',
                        'detail' => 'Validation job is currently '.$evalStatus.' on the research queue.',
                        'policy_version' => $this->getPolicyVersion(),
                        'policy_hash' => $this->getPolicyHash(),
                    ],
                ]);

                return $candidate->fresh();
            }

            $candidate->update([
                'status' => 'REJECTED',
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'status' => 'MISSING_EVIDENCE',
                    'failure_reason' => 'missing_validation_evidence',
                    'detail' => 'No immutable backtest or research run ID is linked to candidate. Schedule validation job to evaluate.',
                    'policy_version' => $this->getPolicyVersion(),
                    'policy_hash' => $this->getPolicyHash(),
                ],
            ]);

            return $candidate->fresh();
        }

        $metrics = null;
        $sourceType = null;
        $gate6Eval = null;

        // 1. Check BacktestRunRecord
        $backtestRun = BacktestRunRecord::query()->find($runId);
        if ($backtestRun !== null) {
            if ($backtestRun->status !== 'COMPLETED' || ! is_array($backtestRun->metrics)) {
                $candidate->update([
                    'status' => 'REJECTED',
                    'validation_gates' => [
                        'all_gates_passed' => false,
                        'status' => 'FAILED',
                        'failure_reason' => 'incomplete_backtest_run',
                        'detail' => "Linked backtest run {$runId} status is {$backtestRun->status}, must be COMPLETED.",
                        'policy_version' => $this->getPolicyVersion(),
                        'policy_hash' => $this->getPolicyHash(),
                    ],
                ]);

                return $candidate->fresh();
            }

            $sourceType = 'BACKTEST_RUN';
            $metrics = $this->parseBacktestMetrics((array) $backtestRun->metrics);
        } else {
            // 2. Check ResearchJobRecord
            $researchJob = ResearchJobRecord::query()->where('job_id', $runId)->first();
            if ($researchJob !== null) {
                if ($researchJob->status !== 'SUCCEEDED' || ! is_array($researchJob->result_payload)) {
                    $candidate->update([
                        'status' => 'REJECTED',
                        'validation_gates' => [
                            'all_gates_passed' => false,
                            'status' => 'FAILED',
                            'failure_reason' => 'incomplete_research_job',
                            'detail' => "Linked research job {$runId} status is {$researchJob->status}, must be SUCCEEDED.",
                            'policy_version' => $this->getPolicyVersion(),
                            'policy_hash' => $this->getPolicyHash(),
                        ],
                    ]);

                    return $candidate->fresh();
                }

                $sourceType = 'RESEARCH_JOB';
                $metrics = $this->parseResearchJobMetrics((array) $researchJob->result_payload);
            }
        }

        if ($metrics === null) {
            $candidate->update([
                'status' => 'REJECTED',
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'failure_reason' => 'validation_run_not_found',
                    'detail' => "Validation run {$runId} could not be located in database.",
                    'policy_version' => $this->getPolicyVersion(),
                    'policy_hash' => $this->getPolicyHash(),
                ],
            ]);

            return $candidate->fresh();
        }

        $metrics['validation_run_id'] = $runId;
        $metrics['validation_source'] = $sourceType;

        // Evaluate candidate parameter gates against out-of-sample evidence only.
        $minimumOos = max(100, (int) config('strategy.promotion_policy.min_oos_samples', 100), (int) config('research.autonomy.gates.min_oos_observations', 100));
        $samplePass = ($metrics['oos_samples'] ?? 0) >= $minimumOos;
        $oosVerified = (bool) ($metrics['out_of_sample_verified'] ?? false);
        $expectancyPass = is_numeric($metrics['expectancy_r'] ?? null) && (float) $metrics['expectancy_r'] > 0.0;
        $drawdownPass = is_numeric($metrics['max_drawdown_percent'] ?? null) && (float) $metrics['max_drawdown_percent'] <= 12.0;
        $calibrationApplicable = (bool) ($metrics['calibration_applicable'] ?? false);
        $calibrationPass = ! $calibrationApplicable
            || (is_numeric($metrics['ece'] ?? null) && (float) $metrics['ece'] <= 0.15);

        // Backtests/research jobs do not establish prospective candidate evidence.
        // Do not accept caller metadata or a stored verdict as a substitute for
        // a candidate-bound, immutable forward evidence source.
        $prospective = (new PaperPromotionPolicy)->evaluate([
            'train_observations' => $metrics['training_samples'] ?? null,
            'oos_observations' => $metrics['oos_samples'] ?? null,
        ], (array) config('research.autonomy.gates'), true);
        $prospectivePass = $prospective['verdict'] === PaperPromotionPolicy::VERDICT_ELIGIBLE;
        $allGatesPass = $samplePass && $oosVerified && $expectancyPass && $drawdownPass && $calibrationPass && $prospectivePass;

        $failureReason = null;
        if (! $oosVerified) {
            $failureReason = 'missing_out_of_sample_evidence';
        } elseif (! $samplePass) {
            $failureReason = 'insufficient_sample_size';
        } elseif (! $expectancyPass) {
            $failureReason = 'negative_expectancy';
        } elseif (! $drawdownPass) {
            $failureReason = 'excessive_drawdown';
        } elseif (! $calibrationPass) {
            $failureReason = 'excessive_calibration_error';
        } elseif (! $prospectivePass) {
            $failureReason = 'missing_verified_prospective_evidence';
        }

        $gates = [
            'out_of_sample_verified' => ['actual' => $oosVerified, 'pass' => $oosVerified],
            'prospective_gate' => $prospective,
            'min_sample_size' => ['threshold' => $minimumOos, 'actual' => $metrics['oos_samples'], 'pass' => $samplePass],
            'positive_expectancy' => ['threshold' => 0.0, 'actual' => $metrics['expectancy_r'], 'pass' => $expectancyPass],
            'max_drawdown' => ['threshold' => 12.0, 'actual' => $metrics['max_drawdown_percent'], 'pass' => $drawdownPass],
            'calibration_error' => ['threshold' => 0.15, 'actual' => $metrics['ece'] ?? null, 'applicable' => $calibrationApplicable, 'pass' => $calibrationPass],
            'all_gates_passed' => $allGatesPass,
            'failure_reason' => $failureReason,
            'validation_run_id' => $metrics['validation_run_id'],
            'validation_source' => $metrics['validation_source'],
            'policy_version' => $this->getPolicyVersion(),
            'policy_hash' => $this->getPolicyHash(),
            'evidence_identifiers' => [
                'validation_run_id' => $metrics['validation_run_id'],
                'validation_source' => $metrics['validation_source'],
            ],
        ];

        $newStatus = $allGatesPass ? 'PAPER_ELIGIBLE' : 'REJECTED';

        $candidate->update([
            'metrics' => $metrics,
            'validation_gates' => $gates,
            'status' => $newStatus,
        ]);

        return $candidate->fresh();
    }

    /**
     * Normalise the immutable BacktestRunService metrics shape into candidate gates.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function parseBacktestMetrics(array $metrics): array
    {
        $hasSegments = isset($metrics['test']) || isset($metrics['out_of_sample']);
        $oos = (array) ($metrics['test'] ?? $metrics['out_of_sample'] ?? []);
        $all = (array) ($metrics['all_segments'] ?? $oos);
        $oosVerified = $hasSegments
            ? (bool) ($metrics['test_is_out_of_sample'] ?? $metrics['out_of_sample_only'] ?? true)
            : (bool) ($metrics['out_of_sample_verified'] ?? false);

        return [
            'validation_source' => 'BACKTEST_RUN',
            'out_of_sample_verified' => $oosVerified,
            'sample_size' => (int) ($oos['total_trades'] ?? 0),
            'oos_samples' => (int) ($oos['total_trades'] ?? 0),
            'training_samples' => (int) ($all['total_trades'] ?? 0),
            'expectancy_r' => $oos['average_r'] ?? null,
            'expectancy' => $oos['expectancy'] ?? null,
            'profit_factor' => $oos['profit_factor'] ?? null,
            'win_rate' => $oos['win_rate'] ?? null,
            'max_drawdown_percent' => $oos['maximum_drawdown_percent'] ?? null,
            'fees' => $oos['fees'] ?? null,
            'funding' => $oos['funding'] ?? null,
            'net_pnl' => $oos['net_pnl'] ?? null,
            'calibration_applicable' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function parseResearchJobMetrics(array $payload): array
    {
        $hasSegments = isset($payload['test']) || isset($payload['out_of_sample']);
        $oos = (array) ($payload['test'] ?? $payload['out_of_sample'] ?? $payload);
        $all = (array) ($payload['all_segments'] ?? $oos);

        return [
            'validation_source' => 'RESEARCH_JOB',
            'out_of_sample_verified' => $hasSegments || (bool) ($payload['out_of_sample_verified'] ?? false),
            'sample_size' => (int) ($oos['total_trades'] ?? $oos['trades_count'] ?? 0),
            'oos_samples' => (int) ($oos['total_trades'] ?? $oos['trades_count'] ?? 0),
            'training_samples' => (int) ($all['total_trades'] ?? $all['trades_count'] ?? 0),
            'expectancy_r' => $oos['average_r'] ?? $oos['expectancy'] ?? null,
            'expectancy' => $oos['expectancy'] ?? null,
            'profit_factor' => $oos['profit_factor'] ?? null,
            'win_rate' => $oos['win_rate'] ?? null,
            'max_drawdown_percent' => $oos['maximum_drawdown_percent'] ?? $oos['max_drawdown_percent'] ?? null,
            'fees' => $oos['fees'] ?? null,
            'funding' => $oos['funding'] ?? null,
            'net_pnl' => $oos['net_pnl'] ?? null,
            'calibration_applicable' => (bool) ($payload['calibration_applicable'] ?? false),
            'ece' => $payload['ece'] ?? null,
            'brier_score' => $payload['brier_score'] ?? null,
        ];
    }

    /**
     * Promote a candidate to forward paper trading.
     *
     * @throws ValidationException
     */
    public function promoteCandidate(string $candidateId, string $actor = 'USER_OPERATOR'): LearningCandidateStrategyRecord
    {
        try {
            return DB::transaction(function () use ($candidateId, $actor): LearningCandidateStrategyRecord {
                // Serialize authorization with permission changes and competing promotions.
                $settings = $this->autonomy->getSettings()->newQuery()->lockForUpdate()->findOrFail('default');
                $candidate = LearningCandidateStrategyRecord::query()->lockForUpdate()->findOrFail($candidateId);
                if ($settings->kill_switch_active || app(AutonomyResearchService::class)->killSwitchActive()) {
                    throw ValidationException::withMessages(['gates' => 'Paper promotion is blocked by the kill switch.']);
                }
                if ($actor === 'SYSTEM_AUTONOMOUS' && (! config('research.autonomy.paper_promotion_enabled', false)
                    || ! $this->autonomy->isAllowed('auto_promote_paper'))) {
                    throw ValidationException::withMessages(['gates' => 'Automatic paper promotion is disabled.']);
                }
                if ($actor === 'SYSTEM_AUTONOMOUS') {
                    // No operational reversal of a promoted account/version exists.
                    // Permissions cannot make an irreversible autonomous change safe.
                    throw ValidationException::withMessages(['gates' => 'Automatic paper promotion requires a verified operational rollback implementation.']);
                }
                if ($candidate->status === 'PAPER_PROMOTED' && $candidate->paper_account_id !== null) {
                    return $candidate;
                }
                if (($candidate->search_metadata['exchange'] ?? 'bybit') !== 'bybit') {
                    throw ValidationException::withMessages(['gates' => 'Candidate exchange is unsupported by forward account provisioning.']);
                }
                $previousGates = (array) $candidate->validation_gates;
                $candidate = $this->evaluateCandidate($candidateId, $previousGates['validation_run_id'] ?? null);
                if (! ($candidate->validation_gates['all_gates_passed'] ?? false)) {
                    throw ValidationException::withMessages(['gates' => 'Cannot promote candidate: current authoritative evidence does not pass all applicable gates.']);
                }

                // Provision an isolated forward paper trading account for this candidate version
                $account = $this->paperAccounts->strategyForward(
                    $candidate->parent_strategy_id,
                    $candidate->candidate_version,
                    (array) $candidate->parameters
                );

                $prev = $candidate->status;
                $candidate->update([
                    'status' => 'PAPER_PROMOTED',
                    'paper_account_id' => $account->id,
                    'promoted_at' => CarbonImmutable::now(),
                ]);

                $this->autonomy->recordEvent(
                    actor: $actor,
                    action: 'CANDIDATE_PROMOTION',
                    targetType: 'CANDIDATE_STRATEGY',
                    targetId: $candidate->id,
                    previousState: $prev,
                    newState: 'PAPER_PROMOTED',
                    reason: "Promoted to forward paper trading on dedicated account {$account->id}.",
                    evidence: [
                        'parameters' => $candidate->parameters,
                        'validation_gates' => $candidate->validation_gates,
                        'policy_version' => $this->getPolicyVersion(),
                        'policy_hash' => $this->getPolicyHash(),
                    ]
                );

                return $candidate->fresh();
            });
        } catch (ValidationException $e) {
            $candidate = LearningCandidateStrategyRecord::query()->find($candidateId);
            $reason = collect($e->errors())->flatten()->first() ?? 'Promotion validation rejected.';
            $this->autonomy->recordEvent(
                actor: $actor,
                action: 'CANDIDATE_PROMOTION_REJECTED',
                targetType: 'CANDIDATE_STRATEGY',
                targetId: $candidateId,
                previousState: $candidate?->status,
                newState: $candidate?->status ?? 'REJECTED',
                reason: $reason,
                evidence: [
                    'validation_errors' => $e->errors(),
                    'policy_version' => $this->getPolicyVersion(),
                    'policy_hash' => $this->getPolicyHash(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Rollback a candidate strategy.
     */
    public function rollbackCandidate(string $candidateId, string $reason, string $actor = 'USER_OPERATOR'): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        if ($candidate->status === 'PAPER_PROMOTED' || $candidate->paper_account_id !== null) {
            throw ValidationException::withMessages([
                'gates' => 'Operational rollback is unsupported for a promoted paper account; no state was changed.',
            ]);
        }

        $prev = $candidate->status;
        $candidate->update([
            'status' => 'ROLLED_BACK',
            'rolled_back_at' => CarbonImmutable::now(),
            'rollback_reason' => $reason,
        ]);

        $this->autonomy->recordEvent(
            actor: $actor,
            action: 'CANDIDATE_ROLLBACK',
            targetType: 'CANDIDATE_STRATEGY',
            targetId: $candidate->id,
            previousState: $prev,
            newState: 'ROLLED_BACK',
            reason: $reason,
            evidence: [
                'previous_status' => $prev,
                'policy_version' => $this->getPolicyVersion(),
                'policy_hash' => $this->getPolicyHash(),
            ]
        );

        return $candidate->fresh();
    }
}
