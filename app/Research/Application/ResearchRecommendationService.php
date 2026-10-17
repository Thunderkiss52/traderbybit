<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Research\Infrastructure\Persistence\PortfolioCorrelationSnapshotRecord;
use App\Research\Infrastructure\Persistence\ResearchRecommendationRecord;
use App\Research\Infrastructure\Persistence\StrategyDegradationSnapshotRecord;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ResearchRecommendationService
{
    /**
     * Generate research recommendations from deterministic evidence.
     *
     * @return ResearchRecommendationRecord[]
     */
    public function generateRecommendations(): array
    {
        $recommendations = [];

        // 1. Check for restrictive filters from counterfactual evaluations
        $counterfactuals = CounterfactualEvaluationRecord::query()
            ->where('status', 'RESOLVED_TP')
            ->where('decision_time', '>=', CarbonImmutable::now()->subDays(14))
            ->get();

        $byFilter = [];
        foreach ($counterfactuals as $cf) {
            $key = "{$cf->strategy_id}:{$cf->filter_family}";
            $byFilter[$key]['count'] = ($byFilter[$key]['count'] ?? 0) + 1;
            $byFilter[$key]['missed_r'] = ($byFilter[$key]['missed_r'] ?? 0.0) + (float) $cf->r_multiple;
            $byFilter[$key]['strategy_id'] = $cf->strategy_id;
            $byFilter[$key]['filter'] = $cf->filter_family;
        }

        foreach ($byFilter as $item) {
            if ($item['count'] >= 3 && $item['missed_r'] >= 4.0) {
                $rec = ResearchRecommendationRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'category' => 'ALPHA_IMPROVEMENT',
                    'strategy_id' => $item['strategy_id'],
                    'title' => "Relax restrictive {$item['filter']} filter threshold for {$item['strategy_id']}",
                    'description' => "Counterfactual analyzer detected that {$item['count']} skipped candidates resolved at TP (+{$item['missed_r']} R missed). Relaxing this filter may capture profitable trades without excessive risk.",
                    'evidence' => [
                        'evaluated_filter' => $item['filter'],
                        'missed_tp_trades' => $item['count'],
                        'missed_r_multiple' => round($item['missed_r'], 2),
                    ],
                    'proposed_changes' => [
                        'filter' => $item['filter'],
                        'action' => 'REDUCE_THRESHOLD_BY_10_PERCENT',
                    ],
                    'status' => 'OPEN',
                ]);
                $recommendations[] = $rec;
            }
        }

        // 2. Check for strategy degradation alerts
        $degradations = StrategyDegradationSnapshotRecord::query()
            ->whereIn('status', ['DEGRADING', 'CRITICAL_DRAWDOWN'])
            ->where('as_of_time', '>=', CarbonImmutable::now()->subHours(24))
            ->get();

        foreach ($degradations as $deg) {
            $title = $deg->degradation_type === 'REGIME_MISMATCH'
                ? "Pause or adapt {$deg->strategy_id} due to market regime mismatch"
                : "Reduce risk allocation for {$deg->strategy_id} due to alpha degradation";

            $rec = ResearchRecommendationRecord::query()->create([
                'id' => (string) Str::uuid(),
                'category' => 'RISK_MITIGATION',
                'strategy_id' => $deg->strategy_id,
                'title' => $title,
                'description' => "Strategy {$deg->strategy_id} shows status {$deg->status} over horizon {$deg->horizon} (drawdown {$deg->max_drawdown_percent}%, PF {$deg->profit_factor}).",
                'evidence' => [
                    'status' => $deg->status,
                    'drawdown_percent' => $deg->max_drawdown_percent,
                    'profit_factor' => $deg->profit_factor,
                    'win_rate' => $deg->win_rate,
                    'sample_size' => $deg->sample_size,
                    'degradation_type' => $deg->degradation_type,
                ],
                'proposed_changes' => [
                    'risk_per_trade_scale' => 0.50,
                    'regime_filter_enabled' => true,
                ],
                'status' => 'OPEN',
            ]);
            $recommendations[] = $rec;
        }

        // 3. Check for high portfolio concentration & correlation
        $latestCorr = PortfolioCorrelationSnapshotRecord::query()
            ->latest('evaluated_at')
            ->first();

        if ($latestCorr && ! empty($latestCorr->alerts)) {
            foreach ($latestCorr->alerts as $alert) {
                $rec = ResearchRecommendationRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'category' => 'PORTFOLIO_DIVERSIFICATION',
                    'strategy_id' => null,
                    'title' => "Address portfolio concentration / correlation: {$alert}",
                    'description' => "Portfolio correlation engine raised alert: {$alert}. Consider enabling anti-correlation risk limits or hedging.",
                    'evidence' => [
                        'concentration_score' => $latestCorr->concentration_score,
                        'alert' => $alert,
                    ],
                    'proposed_changes' => [
                        'max_concurrent_same_direction_signals' => 1,
                    ],
                    'status' => 'OPEN',
                ]);
                $recommendations[] = $rec;
            }
        }

        // 4. Check for mature shadow experiments eligible for promotion
        $shadows = ResearchExperimentRecord::query()
            ->where('is_shadow', true)
            ->where('shadow_status', 'RUNNING')
            ->where('sample_size', '>=', 30)
            ->get();

        foreach ($shadows as $exp) {
            $metrics = $exp->metrics ?? [];
            $sharpe = (float) ($metrics['sharpe_ratio'] ?? 0.0);
            if ($sharpe >= 1.5) {
                $rec = ResearchRecommendationRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'category' => 'EXPERIMENT_PROMOTION',
                    'strategy_id' => $exp->strategy_id,
                    'title' => "Promote shadow experiment {$exp->name} to forward candidate",
                    'description' => "Shadow variant for {$exp->strategy_id} reached {$exp->sample_size} evaluations with strong Sharpe ratio ({$sharpe}).",
                    'evidence' => [
                        'sample_size' => $exp->sample_size,
                        'metrics' => $metrics,
                    ],
                    'proposed_changes' => $exp->parameters,
                    'status' => 'OPEN',
                ]);
                $recommendations[] = $rec;
            }
        }

        return $recommendations;
    }
}
