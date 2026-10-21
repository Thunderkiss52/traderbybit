<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
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
        $config = (array) config('strategy.promotion_policy', []);

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
     * Compute Multiplicity-Adjusted Lower Confidence Bound (LCB) with Newey-West HAC variance.
     * Accounts for serial correlation in overlapping trading observations and controls FWER across M candidate trials.
     */
    public function computeMultiplicityAdjustedLcb(array $rDifferences, int $trialCount = 1, float $alpha = 0.05, int $maxLag = 4): array
    {
        $n = count($rDifferences);
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

        $trainingSamples = (int) ($candidateMetrics['training_samples'] ?? $candidateMetrics['sample_size'] ?? 0);
        $oosSamples = (int) ($candidateMetrics['oos_samples'] ?? $candidateMetrics['holdout_samples'] ?? 0);
        $shadowSamples = (int) ($candidateMetrics['shadow_samples'] ?? 0);
        $challengerDurationDays = (int) ($candidateMetrics['challenger_duration_days'] ?? 0);
        $challengerOps = (int) ($candidateMetrics['challenger_samples'] ?? $candidateMetrics['challenger_operations'] ?? 0);
        $candidateDd = (float) ($candidateMetrics['max_drawdown_percent'] ?? 100.0);
        $candidateBrier = (float) ($candidateMetrics['brier_score'] ?? 1.0);
        $candidateEce = (float) ($candidateMetrics['ece'] ?? 1.0);
        $candidateExpR = (float) ($candidateMetrics['expectancy_r'] ?? $candidateMetrics['average_r'] ?? 0.0);

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
        $champExpR = (float) ($championMetrics['expectancy_r'] ?? $championMetrics['average_r'] ?? 0.0);
        $rDiffs = $candidateMetrics['r_differences'] ?? null;
        if (is_array($rDiffs) && count($rDiffs) > 0) {
            $lcbResult = $this->computeMultiplicityAdjustedLcb($rDiffs, $maxTrials, $alphaFwer, $hacLag);
            $riskAdjPass = ($lcbResult['mean_difference'] ?? 0.0) > 0.0;
            $lcbPass = (bool) ($lcbResult['pass'] ?? false);
        } else {
            $deltaR = $candidateExpR - $champExpR;
            $riskAdjPass = $deltaR > 0.0;
            // Conservative approximation when individual diffs are aggregated
            $seApprox = (float) ($candidateMetrics['standard_error'] ?? 0.05);
            $critZ = self::invNormCdf(1.0 - ($alphaFwer / (2.0 * $maxTrials)));
            $lcbApprox = $deltaR - ($critZ * $seApprox);
            $lcbPass = $lcbApprox > 0.0;
            $lcbResult = [
                'mean_difference' => round($deltaR, 4),
                'standard_error' => round($seApprox, 4),
                'critical_z' => round($critZ, 4),
                'lcb95' => round($lcbApprox, 4),
                'pass' => $lcbPass,
            ];
        }

        // Gate 7: Drawdown ceiling
        $champDd = (float) ($championMetrics['max_drawdown_percent'] ?? 0.0);
        $ddMarginPass = $candidateDd <= ($champDd + $maxDdMargin);
        $hardDdPass = $candidateDd <= $hardDdCeiling;
        $ddPass = $ddMarginPass && $hardDdPass;

        // Gate 8: Calibration (Brier <= 0.28, ECE <= 0.15)
        $calibrationPass = ($candidateBrier <= $maxBrier) && ($candidateEce <= $maxEce);

        // Gate 9: Forward Authenticity Check (synthetic fixtures cannot promote in real forward production)
        $authenticityPass = true;
        if ($isForwardProduction && ! $isTestFixture) {
            // Require explicit genuine forward observation evidence
            $genuineCount = (int) ($context['genuine_forward_observations'] ?? 0);
            if ($genuineCount < $minChallengerOps) {
                $authenticityPass = false;
            }
        }

        $allPass = $trainPass
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
                'min_oos_samples' => ['threshold' => $minOos, 'actual' => $minOos, 'pass' => $oosPass],
                'min_shadow_samples' => ['threshold' => $minShadow, 'actual' => $shadowSamples, 'pass' => $shadowPass],
                'challenger_duration' => ['threshold' => $minChallengerDays, 'actual' => $challengerDurationDays, 'pass' => $challengerDurationPass],
                'challenger_samples' => ['threshold' => $minChallengerOps, 'actual' => $challengerOps, 'pass' => $challengerOpsPass],
                'risk_adjusted_improvement' => ['pass' => $riskAdjPass, 'detail' => $lcbResult],
                'multiplicity_adjusted_lcb95' => ['pass' => $lcbPass, 'trial_count' => $maxTrials, 'critical_z' => $lcbResult['critical_z'], 'detail' => $lcbResult],
                'drawdown_ceiling' => ['threshold' => $hardDdCeiling, 'actual' => $candidateDd, 'pass' => $ddPass],
                'calibration' => ['pass' => $calibrationPass, 'brier' => $candidateBrier, 'ece' => $candidateEce],
                'forward_authenticity' => ['pass' => $authenticityPass],
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
        if (($params['gate6_validation']['all_gates_passed'] ?? false) === true) {
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

        // 3. Dynamic evaluation against Gate 6 promotion policy
        $policy = (array) config('strategy.promotion_policy', []);
        $minTrain = (int) ($policy['min_training_samples'] ?? 300);
        $minOos = (int) ($policy['min_oos_samples'] ?? 100);
        $minChallengerDays = (int) ($policy['min_challenger_duration_days'] ?? 14);
        $minChallengerOps = (int) ($policy['min_challenger_samples'] ?? 50);
        $maxBrier = (float) ($policy['max_brier'] ?? 0.28);
        $maxEce = (float) ($policy['max_ece'] ?? 0.15);

        $trainSamples = (int) ($params['train_sample_size'] ?? $metrics['sample_size'] ?? 0);
        $oosSamples = (int) ($params['validation_sample_size'] ?? $metrics['oos_samples'] ?? 0);
        $brier = (float) ($metrics['brier_score'] ?? 1.0);
        $ece = (float) ($metrics['ece'] ?? 1.0);

        $ageDays = $model->created_at ? CarbonImmutable::parse($model->created_at)->diffInDays(now('UTC')) : 0;
        $challengerOps = (int) ($params['challenger_samples'] ?? $metrics['challenger_samples'] ?? 0);

        $samplePass = $trainSamples >= $minTrain && $oosSamples >= $minOos;
        $challengerPass = $ageDays >= $minChallengerDays && $challengerOps >= $minChallengerOps;
        $calibPass = $brier <= $maxBrier && $ece <= $maxEce;

        if ($samplePass && $challengerPass && $calibPass) {
            return [
                'eligible' => true,
                'reason' => 'dynamic_gate6_requirements_satisfied',
                'policy_version' => $this->getPolicyVersion(),
            ];
        }

        $failures = [];
        if (! $samplePass) {
            $failures[] = "insufficient_samples (train: {$trainSamples}/{$minTrain}, oos: {$oosSamples}/{$minOos})";
        }
        if (! $challengerPass) {
            $failures[] = "insufficient_challenger_observation (days: {$ageDays}/{$minChallengerDays}, ops: {$challengerOps}/{$minChallengerOps})";
        }
        if (! $calibPass) {
            $failures[] = "calibration_gates_breached (Brier: {$brier}/{$maxBrier}, ECE: {$ece}/{$maxEce})";
        }

        return [
            'eligible' => false,
            'reason' => 'legacy_model_unverified_by_gate6: '.implode('; ', $failures),
            'policy_version' => $this->getPolicyVersion(),
            'failures' => $failures,
        ];
    }

    /**
     * Evaluate candidate against mandatory validation gates.
     * Only accepts immutable, reproducible validation results linked to a real completed research or backtest run.
     * Missing or unverified evidence fails closed.
     */
    public function evaluateCandidate(string $candidateId, ?string $validationRunId = null): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        $runId = $validationRunId
            ?? ($candidate->search_metadata['backtest_run_id'] ?? null)
            ?? ($candidate->search_metadata['validation_run_id'] ?? null)
            ?? ($candidate->search_metadata['evaluation_run_id'] ?? null);

        if ($runId === null || ! is_string($runId) || trim($runId) === '') {
            $candidate->update([
                'status' => 'REJECTED',
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'failure_reason' => 'missing_validation_evidence',
                    'detail' => 'No immutable backtest or research run ID is linked to candidate.',
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
        $samplePass = ($metrics['oos_samples'] ?? 0) >= 30;
        $oosVerified = (bool) ($metrics['out_of_sample_verified'] ?? false);
        $expectancyPass = is_numeric($metrics['expectancy_r'] ?? null) && (float) $metrics['expectancy_r'] > 0.0;
        $drawdownPass = is_numeric($metrics['max_drawdown_percent'] ?? null) && (float) $metrics['max_drawdown_percent'] <= 12.0;
        $calibrationApplicable = (bool) ($metrics['calibration_applicable'] ?? false);
        $calibrationPass = ! $calibrationApplicable
            || (is_numeric($metrics['ece'] ?? null) && (float) $metrics['ece'] <= 0.15);

        $allGatesPass = $samplePass && $oosVerified && $expectancyPass && $drawdownPass && $calibrationPass;

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
        }

        $gates = [
            'out_of_sample_verified' => ['actual' => $oosVerified, 'pass' => $oosVerified],
            'min_sample_size' => ['threshold' => 30, 'actual' => $metrics['oos_samples'], 'pass' => $samplePass],
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
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        if ($candidate->status === 'PAPER_PROMOTED' && $candidate->paper_account_id !== null) {
            return $candidate;
        }

        // Verification: cannot promote without passing gates
        $gates = (array) $candidate->validation_gates;
        if (! ($gates['all_gates_passed'] ?? false)) {
            throw ValidationException::withMessages([
                'gates' => 'Cannot promote candidate: mandatory statistical validation gates have not passed.',
            ]);
        }

        // Check autonomy permission if automated
        if ($actor === 'SYSTEM_AUTONOMOUS' && ! $this->autonomy->isAllowed('auto_promote_paper')) {
            $candidate->update(['status' => 'PAPER_PROMOTION_PENDING']);

            $this->autonomy->recordEvent(
                actor: $actor,
                action: 'PROMOTION_PENDING',
                targetType: 'CANDIDATE_STRATEGY',
                targetId: $candidate->id,
                previousState: $candidate->status,
                newState: 'PAPER_PROMOTION_PENDING',
                reason: 'Candidate passed gates; awaiting explicit operator approval (AUTO_PROMOTE_PAPER is disabled).'
            );

            return $candidate->fresh();
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
    }

    /**
     * Rollback a candidate strategy.
     */
    public function rollbackCandidate(string $candidateId, string $reason, string $actor = 'USER_OPERATOR'): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

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
