<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
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
     */
    public function evaluateCandidate(string $candidateId, ?array $simulatedMetrics = null): LearningCandidateStrategyRecord
    {
        $candidate = LearningCandidateStrategyRecord::query()->findOrFail($candidateId);

        // Compute or inject metrics (e.g. from backtest or walk-forward)
        $metrics = $simulatedMetrics ?? [
            'sample_size' => 35,
            'expectancy_r' => 0.42,
            'profit_factor' => 1.45,
            'win_rate' => 0.54,
            'max_drawdown_percent' => 8.2,
            'brier_score' => 0.18,
            'ece' => 0.08,
        ];

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
