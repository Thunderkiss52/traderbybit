<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Research\Application\AutonomyPermissionService;
use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\PromotionPolicyService;
use App\Research\Application\StrategyDegradationService;
use App\Research\Application\StrategyEvolutionService;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Application\StrategyRegistry;
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
        PromotionPolicyService $promotion,
        StrategyDegradationService $degradation,
        StrategyRegistry $strategyRegistry,
        AutonomyPermissionService $autonomy
    ): void {
        Log::info('[AutonomousLearning] Starting autonomous research & quantitative learning cycle.');

        // 1. Mature pending feature labels using post-facto closed candles
        $matured = $featureStore->maturePendingLabels(100);
        Log::info("[AutonomousLearning] Matured {$matured} pending feature snapshots.");

        // 2. Continuous machine learning model training & champion/challenger lifecycle
        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        foreach ($symbols as $symbol) {
            // Check concept drift
            $drift = $predictor->detectConceptDrift($symbol, 'TP_BEFORE_SL');
            if ($drift['drift_detected'] || $drift['recommendation'] === 'TRAIN_INITIAL') {
                Log::info("[AutonomousLearning] Initiating model training for {$symbol} (trigger: {$drift['recommendation']}).");
                $artifact = $predictor->trainModel('TP_BEFORE_SL', $symbol);
                if ($artifact) {
                    Log::info("[AutonomousLearning] Trained model {$artifact->name} {$artifact->version} status: {$artifact->status}.");
                }
            }
        }

        // 3. Strategy hypothesis generation & candidate validation
        $activeCandidates = LearningCandidateStrategyRecord::query()
            ->whereIn('status', ['RESEARCH_ONLY', 'PAPER_ELIGIBLE', 'PAPER_PROMOTION_PENDING', 'PAPER_PROMOTED'])
            ->count();

        if ($activeCandidates < 4 && $autonomy->isAllowed('discover_hypotheses')) {
            $parentStrategies = ['trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout'];
            $selectedParent = $parentStrategies[array_rand($parentStrategies)];
            $candidate = $evolution->generateCandidate($selectedParent);
            Log::info("[AutonomousLearning] Generated new strategy candidate {$candidate->name} ({$candidate->id}).");

            // Evaluate candidate against mandatory validation gates
            $evaluated = $promotion->evaluateCandidate($candidate->id);
            Log::info("[AutonomousLearning] Candidate {$evaluated->name} validation status: {$evaluated->status}.");

            // If eligible and autonomous promotion is permitted, promote to paper trading
            if ($evaluated->status === 'PAPER_ELIGIBLE' && $autonomy->isAllowed('auto_promote_paper')) {
                $promoted = $promotion->promoteCandidate($evaluated->id, 'SYSTEM_AUTONOMOUS');
                Log::info("[AutonomousLearning] Promoted candidate {$promoted->name} to forward paper trading.");
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
}
