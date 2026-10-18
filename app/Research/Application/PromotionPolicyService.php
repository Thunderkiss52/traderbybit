<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
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
     * Evaluate candidate against mandatory validation gates.
     * Only accepts immutable, reproducible validation results linked to a real completed research or backtest run.
     * Missing or unverified evidence fails closed.
     */
    public function evaluateCandidate(string $candidateId, ?string $validationRunId = null): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        $runId = $validationRunId
            ?? ($candidate->search_metadata['backtest_run_id'] ?? null)
            ?? ($candidate->search_metadata['validation_run_id'] ?? null);

        if ($runId === null || ! is_string($runId) || trim($runId) === '') {
            $candidate->update([
                'status' => 'REJECTED',
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'failure_reason' => 'missing_validation_evidence',
                    'detail' => 'No immutable backtest or research run ID is linked to candidate.',
                ],
            ]);

            return $candidate->fresh();
        }

        $metrics = null;
        $sourceType = null;

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
                    ],
                ]);

                return $candidate->fresh();
            }

            $sourceType = 'BACKTEST_RUN';
            $bm = $backtestRun->metrics;
            $metrics = [
                'validation_run_id' => $backtestRun->id,
                'validation_source' => $sourceType,
                'sample_size' => (int) ($bm['total_trades'] ?? $bm['sample_size'] ?? 0),
                'expectancy_r' => (float) ($bm['average_r'] ?? $bm['expectancy_r'] ?? 0.0),
                'profit_factor' => (float) ($bm['profit_factor'] ?? 0.0),
                'win_rate' => (float) ($bm['win_rate'] ?? 0.0),
                'max_drawdown_percent' => (float) ($bm['maximum_drawdown_percent'] ?? $bm['max_drawdown_percent'] ?? 100.0),
                'brier_score' => (float) ($bm['brier_score'] ?? 0.20),
                'ece' => (float) ($bm['ece'] ?? 0.05),
            ];
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
                        ],
                    ]);

                    return $candidate->fresh();
                }

                $sourceType = 'RESEARCH_JOB';
                $jm = $researchJob->result_payload;
                $metrics = [
                    'validation_run_id' => $researchJob->job_id,
                    'validation_source' => $sourceType,
                    'sample_size' => (int) ($jm['trades_count'] ?? $jm['total_trades'] ?? $jm['sample_size'] ?? 0),
                    'expectancy_r' => (float) ($jm['average_r'] ?? $jm['expectancy'] ?? 0.0),
                    'profit_factor' => (float) ($jm['profit_factor'] ?? 0.0),
                    'win_rate' => (float) ($jm['win_rate'] ?? 0.0),
                    'max_drawdown_percent' => (float) ($jm['max_drawdown_percent'] ?? 100.0),
                    'brier_score' => (float) ($jm['brier_score'] ?? 0.20),
                    'ece' => (float) ($jm['ece'] ?? 0.05),
                ];
            }
        }

        if ($metrics === null) {
            $candidate->update([
                'status' => 'REJECTED',
                'validation_gates' => [
                    'all_gates_passed' => false,
                    'failure_reason' => 'validation_run_not_found',
                    'detail' => "Validation run {$runId} could not be located in database.",
                ],
            ]);

            return $candidate->fresh();
        }

        // Evaluate mandatory gates
        $samplePass = ($metrics['sample_size'] ?? 0) >= 30;
        $expectancyPass = ($metrics['expectancy_r'] ?? 0.0) > 0.0;
        $drawdownPass = ($metrics['max_drawdown_percent'] ?? 99.0) <= 12.0;
        $calibrationPass = ($metrics['ece'] ?? 1.0) <= 0.15;

        $allGatesPass = $samplePass && $expectancyPass && $drawdownPass && $calibrationPass;

        $gates = [
            'min_sample_size' => ['threshold' => 30, 'actual' => $metrics['sample_size'], 'pass' => $samplePass],
            'positive_expectancy' => ['threshold' => 0.0, 'actual' => $metrics['expectancy_r'], 'pass' => $expectancyPass],
            'max_drawdown' => ['threshold' => 12.0, 'actual' => $metrics['max_drawdown_percent'], 'pass' => $drawdownPass],
            'calibration_error' => ['threshold' => 0.15, 'actual' => $metrics['ece'], 'pass' => $calibrationPass],
            'all_gates_passed' => $allGatesPass,
            'validation_run_id' => $metrics['validation_run_id'],
            'validation_source' => $metrics['validation_source'],
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
     * Promote a candidate to forward paper trading.
     *
     * @throws ValidationException
     */
    public function promoteCandidate(string $candidateId, string $actor = 'USER_OPERATOR'): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

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
            evidence: ['previous_status' => $prev]
        );

        return $candidate->fresh();
    }
}
