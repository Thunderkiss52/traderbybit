<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Backtest\Application\BacktestRunService;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EvaluateStrategyCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 3600;

    public function __construct(public readonly string $candidateId)
    {
        $this->tries = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research:candidate:'.$this->candidateId))->expireAfter(3700)->dontRelease()];
    }

    public function handle(
        StrategyEvolutionService $evolution,
        BacktestRunService $backtests,
        PromotionPolicyService $promotion,
        AutonomyPermissionService $autonomy,
    ): void {
        $candidate = LearningCandidateStrategyRecord::query()->find($this->candidateId);
        if ($candidate === null || $candidate->status !== 'RESEARCH_ONLY') {
            return;
        }

        $metadata = (array) $candidate->search_metadata;
        $attempts = (int) ($metadata['evaluation_attempts'] ?? 0) + 1;
        $metadata['evaluation_status'] = 'RUNNING';
        $metadata['evaluation_attempts'] = $attempts;
        $metadata['evaluation_started_at'] = CarbonImmutable::now('UTC')->toIso8601String();
        $candidate->update(['search_metadata' => $metadata]);

        $validationDays = max(30, (int) config('research.evolution.validation_days', 180));
        $endLag = max(1, (int) config('research.evolution.validation_end_lag_hours', 24));
        $to = CarbonImmutable::now('UTC')->subHours($endLag);
        $from = $to->subDays($validationDays);
        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);

        try {
            $config = $evolution->strategyConfig($candidate);
            $run = $backtests->run(
                $symbols,
                new DateTimeImmutable($from->toIso8601String()),
                new DateTimeImmutable($to->toIso8601String()),
                true,
                $config,
            );

            $metadata['evaluation_status'] = 'COMPLETED';
            $metadata['backtest_run_id'] = $run->id;
            $metadata['evaluated_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $candidate->update(['search_metadata' => $metadata]);

            $evaluated = $promotion->evaluateCandidate($candidate->id, $run->id);
            Log::info('[ResearchEvolution] Candidate evaluated.', [
                'candidate_id' => $candidate->id,
                'family' => $candidate->parent_strategy_id,
                'status' => $evaluated->status,
                'backtest_run_id' => $run->id,
            ]);

            if ($evaluated->status === 'PAPER_ELIGIBLE' && $autonomy->isAllowed('auto_promote_paper')) {
                $promoted = $promotion->promoteCandidate($evaluated->id, 'SYSTEM_AUTONOMOUS');
                Log::info('[ResearchEvolution] Candidate promoted to isolated forward paper.', [
                    'candidate_id' => $promoted->id,
                    'family' => $promoted->parent_strategy_id,
                    'paper_account_id' => $promoted->paper_account_id,
                ]);
            }
        } catch (Throwable $error) {
            $metadata['evaluation_status'] = 'FAILED';
            $metadata['evaluation_error'] = substr($error->getMessage(), 0, 1000);
            $metadata['evaluation_failed_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            $candidate->update(['search_metadata' => $metadata]);

            $maxAttempts = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
            if ($attempts >= $maxAttempts) {
                $candidate->update([
                    'status' => 'REJECTED',
                    'validation_gates' => [
                        'all_gates_passed' => false,
                        'failure_reason' => 'backtest_execution_failed',
                        'detail' => substr($error->getMessage(), 0, 1000),
                        'attempts' => $attempts,
                    ],
                ]);
            }

            Log::warning('[ResearchEvolution] Candidate evaluation failed.', [
                'candidate_id' => $candidate->id,
                'family' => $candidate->parent_strategy_id,
                'attempt' => $attempts,
                'error' => $error->getMessage(),
            ]);

            if ($attempts < $maxAttempts) {
                throw $error;
            }
        }
    }
}
