<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Exchanges\ExchangeRegistry;
use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ResearchJobService;
use App\Research\Application\StrategyDegradationService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Strategy\Application\StrategyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessAutonomousLearningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('research');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research:autonomous-learning'))->expireAfter(600)];
    }

    public function handle(
        LearningFeatureStoreService $featureStore,
        LearningPredictorService $predictor,
        StrategyEvolutionService $evolution,
        StrategyDegradationService $degradation,
        StrategyRegistry $strategyRegistry,
        AutonomyPermissionService $autonomy,
        ResearchJobService $researchJobs
    ): void {
        Log::info('[AutonomousLearning] Starting autonomous research & quantitative learning cycle.');

        // 0. Recover leased jobs whose worker disappeared before the retry budget expired.
        $reconciled = $researchJobs->reconcileStaleLeases();
        if ($reconciled > 0) {
            Log::warning("[AutonomousLearning] Requeued or expired {$reconciled} stale research job leases.");
        }

        // 1. Mature pending feature labels using post-facto closed candles
        $matured = $featureStore->maturePendingLabels(100);
        Log::info("[AutonomousLearning] Matured {$matured} pending feature snapshots.");

        // 2. Continuous machine learning model training & champion/challenger lifecycle
        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $baselineVersion = $strategyRegistry->get('baseline')->version();
        $venues = app(ExchangeRegistry::class);
        foreach ($venues->activeIds() as $exchange) {
            if (! $venues->hasAdapter($exchange)) {
                Log::warning('[AutonomousLearning] Skipping exchange without a registered public-data adapter.', ['exchange' => $exchange]);

                continue;
            }
            foreach ($symbols as $symbol) {
                foreach (['BUY', 'SELL'] as $side) {
                    $maturedCount = LearningFeatureSnapshotRecord::query()
                        ->where('symbol', $symbol)
                        ->where('strategy_id', 'baseline')
                        ->where('timeframe', '15')
                        ->where('data_quality->exchange', $exchange)
                        ->where('data_quality->category', 'linear')
                        ->where('data_quality->side', $side)
                        ->where('data_quality->label_policy_version', 'SIGNAL_PROXY_V2')
                        ->where('data_quality->strategy_version', $baselineVersion)
                        ->where('label_status', 'MATURED')
                        ->count();
                    if ($maturedCount < 12) {
                        continue;
                    }
                    $drift = $predictor->detectConceptDrift($symbol, 'TP_BEFORE_SL', $exchange, 'baseline', '15', $side, 'linear', $baselineVersion);
                    if ($autonomy->isAllowed('retrain_models') && ($drift['drift_detected'] || $drift['recommendation'] === 'TRAIN_INITIAL')) {
                        Log::info('[AutonomousLearning] Training segregated proxy cohort.', ['exchange' => $exchange, 'symbol' => $symbol, 'side' => $side]);
                        $predictor->trainModel('TP_BEFORE_SL', $symbol, $exchange, 'baseline', '15', $side, 'linear', $baselineVersion);
                    }
                }
            }
        }

        // 3. Fair, round-robin candidate generation across every strategy family.
        $maxTotalActive = max(1, (int) config('research.evolution.max_total_active', 8));
        $maxPerFamily = max(1, (int) config('research.evolution.max_per_family', 2));
        $perCycle = max(1, (int) config('research.evolution.max_evaluations_per_cycle', 2));
        $activeTotal = LearningCandidateStrategyRecord::query()
            ->where('status', 'RESEARCH_ONLY')
            ->where(function ($q): void {
                $q->whereNull('search_metadata->evaluation_status')
                    ->orWhereIn('search_metadata->evaluation_status', ['PENDING', 'RUNNING']);
            })
            ->count();

        if ($activeTotal < $maxTotalActive && $autonomy->isAllowed('discover_hypotheses') && $autonomy->isAllowed('tune_parameters')) {
            $eligible = [];
            foreach ($evolution->families() as $family) {
                $activeForFamily = LearningCandidateStrategyRecord::query()
                    ->where('parent_strategy_id', $family)
                    ->where('status', 'RESEARCH_ONLY')
                    ->where(function ($q): void {
                        $q->whereNull('search_metadata->evaluation_status')
                            ->orWhereIn('search_metadata->evaluation_status', ['PENDING', 'RUNNING']);
                    })
                    ->count();
                if ($activeForFamily >= $maxPerFamily) {
                    continue;
                }
                $last = LearningCandidateStrategyRecord::query()
                    ->where('parent_strategy_id', $family)
                    ->latest('created_at')
                    ->value('created_at');
                $eligible[] = ['family' => $family, 'last' => $last === null ? null : CarbonImmutable::parse($last)->getTimestamp()];
            }

            usort($eligible, static fn (array $a, array $b): int => ($a['last'] ?? 0) <=> ($b['last'] ?? 0));
            $slots = min($perCycle, $maxTotalActive - $activeTotal, count($eligible));
            foreach (array_slice($eligible, 0, $slots) as $row) {
                try {
                    $retry = $this->retryableCandidate((string) $row['family']);
                    $candidate = $retry ?? $evolution->generateCandidate((string) $row['family']);
                    EvaluateStrategyCandidateJob::dispatch($candidate->id);
                    Log::info('[AutonomousLearning] Queued candidate evaluation.', [
                        'candidate_id' => $candidate->id,
                        'family' => $candidate->parent_strategy_id,
                        'retry' => $retry !== null,
                    ]);
                } catch (\Throwable $error) {
                    Log::warning('[AutonomousLearning] Candidate generation failed.', [
                        'family' => $row['family'],
                        'exception_type' => $error::class,
                    ]);
                }
            }
        }

        // 4. Autonomous strategy degradation & circuit-breaker protection
        foreach ($strategyRegistry->all() as $strategy) {
            $snapshot = $degradation->evaluate($strategy->id(), '14d');
            if (in_array($snapshot->status, ['CRITICAL_DRAWDOWN', 'DEGRADING'], true)) {
                Log::warning("[AutonomousLearning] Strategy {$strategy->id()} degradation detected: {$snapshot->status} (max DD: {$snapshot->max_drawdown_percent}%).");
            }
        }

        Log::info('[AutonomousLearning] Completed autonomous research & learning cycle successfully.');
    }

    private function retryableCandidate(string $family): ?LearningCandidateStrategyRecord
    {
        $maxAttempts = max(1, (int) config('research.evolution.max_evaluation_attempts', 3));
        $staleBefore = CarbonImmutable::now('UTC')->subMinutes(10);

        return LearningCandidateStrategyRecord::query()
            ->where('parent_strategy_id', $family)
            ->where('status', 'RESEARCH_ONLY')
            ->where('search_metadata->evaluation_status', 'FAILED')
            ->oldest('created_at')
            ->get()
            ->first(function (LearningCandidateStrategyRecord $candidate) use ($maxAttempts, $staleBefore): bool {
                $metadata = (array) $candidate->search_metadata;
                $failedAt = $metadata['evaluation_failed_at'] ?? null;

                return (int) ($metadata['evaluation_attempts'] ?? 0) < $maxAttempts
                    && is_string($failedAt)
                    && CarbonImmutable::parse($failedAt)->lt($staleBefore);
            });
    }
}
