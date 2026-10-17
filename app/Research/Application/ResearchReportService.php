<?php

declare(strict_types=1);

namespace App\Research\Application;

use Carbon\CarbonImmutable;

final class ResearchReportService
{
    public function __construct(
        private readonly SystemFreshnessService $freshness,
        private readonly StrategyHealthService $health,
        private readonly StrategyDegradationService $degradation,
        private readonly CounterfactualAnalyzerService $counterfactual,
        private readonly ProbabilityCalibrationService $calibration,
        private readonly PortfolioCorrelationService $correlation,
        private readonly ResearchRecommendationService $recommendations,
    ) {}

    /**
     * Build an end-to-end research intelligence audit report.
     */
    public function generateReport(string $period = 'daily'): array
    {
        $horizon = $period === 'weekly' ? '7d' : '24h';
        $corrHorizon = $period === 'weekly' ? '7d' : '14d';
        $now = CarbonImmutable::now();

        // 1. Freshness & Health
        $freshnessData = $this->freshness->check();
        $healthOverview = $this->health->overview($horizon === '24h' ? '24h' : '7d');

        // 2. Degradation on primary strategies
        $strategies = ['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout', 'regime_switching'];
        $degradationReports = [];
        foreach ($strategies as $strat) {
            $snap = $this->degradation->evaluate($strat, $period === 'weekly' ? '7d' : '14d');
            $degradationReports[] = [
                'strategy_id' => $snap->strategy_id,
                'status' => $snap->status,
                'degradation_type' => $snap->degradation_type,
                'sample_size' => $snap->sample_size,
                'win_rate' => $snap->win_rate,
                'expectancy_r' => $snap->expectancy_r,
                'profit_factor' => $snap->profit_factor,
                'max_drawdown_percent' => $snap->max_drawdown_percent,
            ];
        }

        // 3. Counterfactual Filter Effectiveness
        $filterReport = $this->counterfactual->filterEffectivenessReport();

        // 4. Calibration
        $calibReport = $this->calibration->evaluateCalibration();

        // 5. Portfolio Correlation & Overlap
        $corrSnap = $this->correlation->evaluate($corrHorizon);

        // 6. Generated Recommendations
        $activeRecs = $this->recommendations->generateRecommendations();
        $recommendationsSummary = array_map(fn ($r) => [
            'id' => $r->id,
            'category' => $r->category,
            'title' => $r->title,
            'description' => $r->description,
            'proposed_changes' => $r->proposed_changes,
        ], $activeRecs);

        $payload = [
            'report_type' => strtoupper($period).'_QUANT_RESEARCH_AUDIT',
            'generated_at' => $now->toIso8601String(),
            'period' => $period,
            'system_freshness' => [
                'status' => $freshnessData['status'],
                'candle_lag_minutes' => $freshnessData['candle_freshness']['linear']['BTCUSDT']['lag_minutes'] ?? 0,
                'redis_queue_depth' => $freshnessData['queues']['redis_queue_depth'] ?? 0,
                'active_alerts' => $freshnessData['alerts'],
            ],
            'strategy_funnel_health' => $healthOverview,
            'strategy_degradation_watchlist' => $degradationReports,
            'counterfactual_filter_effectiveness' => $filterReport,
            'probability_calibration' => [
                'status' => $calibReport['status'],
                'brier_score' => $calibReport['brier_score'],
                'expected_calibration_error' => $calibReport['ece'],
                'total_samples' => $calibReport['total_samples'],
            ],
            'portfolio_correlation_and_risk' => [
                'concentration_score' => $corrSnap->concentration_score,
                'concurrent_overlap' => $corrSnap->concurrent_overlap,
                'alerts' => $corrSnap->alerts,
            ],
            'actionable_recommendations' => $recommendationsSummary,
        ];

        $jsonEncoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payload['manifest'] = [
            'content_sha256' => hash('sha256', $jsonEncoded),
            'schema_version' => '1.0.0',
            'operating_mode' => 'PAPER_TRADING_RESEARCH_ONLY',
        ];

        return $payload;
    }
}
