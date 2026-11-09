<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\QualityRiskGateDecision;
use App\PaperTrading\Domain\TradeRiskClass;
use App\Strategy\Domain\SignalQuality;

/**
 * Pre-Execution Quality and Risk Gate.
 * Enforces systematic matrix evaluation of Signal Quality vs Trade Risk before virtual PAPER execution.
 */
final readonly class PreExecutionQualityRiskGate
{
    public const VERSION = '1.0.0';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param array{
     *     signal_quality: SignalQuality,
     *     trade_risk: TradeRiskClass,
     *     expected_net_edge?: float|null,
     *     sample_size?: int|null,
     *     drawdown_percent?: float|string|null,
     *     directional_win_rate?: float|null,
     *     directional_profit_factor?: float|null,
     *     directional_sample_size?: int|null,
     *     side?: string|null
     * } $context
     * @return array{
     *     decision: QualityRiskGateDecision,
     *     allowed: bool,
     *     quality_risk_pair: string,
     *     expected_net_edge: float|null,
     *     sample_size: int,
     *     rejection_reasons: list<string>,
     *     diagnostics: array<string, mixed>
     * }
     */
    public function evaluate(array $context): array
    {
        $quality = $context['signal_quality'] ?? SignalQuality::Unknown;
        $risk = $context['trade_risk'] ?? TradeRiskClass::Unknown;
        $expectedNetEdge = isset($context['expected_net_edge']) ? (float) $context['expected_net_edge'] : null;
        $sampleSize = (int) ($context['sample_size'] ?? 0);
        $drawdown = (float) ($context['drawdown_percent'] ?? 0.0);
        $side = strtoupper((string) ($context['side'] ?? 'BUY'));

        $pair = "{$quality->value}_{$risk->value}";
        $reasons = [];
        $diagnostics = [
            'gate_version' => $this->version(),
            'signal_quality' => $quality->value,
            'trade_risk' => $risk->value,
            'expected_net_edge' => $expectedNetEdge,
            'sample_size' => $sampleSize,
            'drawdown_percent' => $drawdown,
            'side' => $side,
        ];

        // 1. Unknown Quality or Risk: Never allow automatic entry
        if ($quality === SignalQuality::Unknown || $risk === TradeRiskClass::Unknown) {
            return [
                'decision' => QualityRiskGateDecision::Rejected,
                'allowed' => false,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => ['unknown_quality_or_risk'],
                'diagnostics' => $diagnostics,
            ];
        }

        // 2. Low Signal Quality: Observation only by default
        if ($quality === SignalQuality::Low) {
            return [
                'decision' => QualityRiskGateDecision::ObservationOnly,
                'allowed' => false,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => ['low_signal_quality_observation_only'],
                'diagnostics' => $diagnostics,
            ];
        }

        // 3. High Trade Risk: Rejected by default
        if ($risk === TradeRiskClass::High) {
            return [
                'decision' => QualityRiskGateDecision::Rejected,
                'allowed' => false,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => ['high_risk_trade_rejected'],
                'diagnostics' => $diagnostics,
            ];
        }

        // 4. Directional Expectancy Check (if sufficient historical directional sample exists)
        $dirSample = (int) ($context['directional_sample_size'] ?? 0);
        if ($dirSample >= 10) {
            $dirWinRate = isset($context['directional_win_rate']) ? (float) $context['directional_win_rate'] : null;
            $dirPf = isset($context['directional_profit_factor']) ? (float) $context['directional_profit_factor'] : null;
            if ($dirWinRate !== null && $dirWinRate < 0.30 && $dirPf !== null && $dirPf < 0.60) {
                return [
                    'decision' => QualityRiskGateDecision::Rejected,
                    'allowed' => false,
                    'quality_risk_pair' => $pair,
                    'expected_net_edge' => $expectedNetEdge,
                    'sample_size' => $sampleSize,
                    'rejection_reasons' => ["directional_negative_expectancy_{$side}"],
                    'diagnostics' => array_merge($diagnostics, [
                        'directional_sample_size' => $dirSample,
                        'directional_win_rate' => $dirWinRate,
                        'directional_profit_factor' => $dirPf,
                    ]),
                ];
            }
        }

        // 5. Matrix Evaluation
        if ($quality === SignalQuality::High && $risk === TradeRiskClass::Low) {
            // HIGH Quality / LOW Risk: Eligible after general risk validation
            return [
                'decision' => QualityRiskGateDecision::Eligible,
                'allowed' => true,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        if ($quality === SignalQuality::High && $risk === TradeRiskClass::Medium) {
            // HIGH Quality / MEDIUM Risk: Eligible if validated expected net edge is positive
            if ($expectedNetEdge !== null && $expectedNetEdge <= 0.0) {
                return [
                    'decision' => QualityRiskGateDecision::Rejected,
                    'allowed' => false,
                    'quality_risk_pair' => $pair,
                    'expected_net_edge' => $expectedNetEdge,
                    'sample_size' => $sampleSize,
                    'rejection_reasons' => ['high_quality_medium_risk_negative_edge'],
                    'diagnostics' => $diagnostics,
                ];
            }

            return [
                'decision' => QualityRiskGateDecision::Eligible,
                'allowed' => true,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        if ($quality === SignalQuality::Medium && $risk === TradeRiskClass::Low) {
            // MEDIUM Quality / LOW Risk: Conditionally eligible with stricter validation
            if ($drawdown >= 2.50) {
                $reasons[] = 'medium_quality_drawdown_limit';
            }
            if ($expectedNetEdge !== null && $expectedNetEdge < 0.0) {
                $reasons[] = 'medium_quality_low_risk_negative_edge';
            }

            $allowed = $reasons === [];

            return [
                'decision' => $allowed ? QualityRiskGateDecision::ConditionallyEligible : QualityRiskGateDecision::Rejected,
                'allowed' => $allowed,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => $reasons,
                'diagnostics' => $diagnostics,
            ];
        }

        if ($quality === SignalQuality::Medium && $risk === TradeRiskClass::Medium) {
            // MEDIUM Quality / MEDIUM Risk: Conditionally eligible with stronger evidence
            if ($drawdown >= 2.00) {
                $reasons[] = 'medium_risk_drawdown_limit';
            }
            if ($sampleSize > 0 && $sampleSize < 10) {
                $reasons[] = 'insufficient_expectancy_evidence';
            }
            if ($expectedNetEdge !== null && $expectedNetEdge <= 0.05) {
                $reasons[] = 'medium_quality_medium_risk_insufficient_edge';
            }

            $allowed = $reasons === [];

            return [
                'decision' => $allowed ? QualityRiskGateDecision::ConditionallyEligible : QualityRiskGateDecision::Rejected,
                'allowed' => $allowed,
                'quality_risk_pair' => $pair,
                'expected_net_edge' => $expectedNetEdge,
                'sample_size' => $sampleSize,
                'rejection_reasons' => $reasons,
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'decision' => QualityRiskGateDecision::Rejected,
            'allowed' => false,
            'quality_risk_pair' => $pair,
            'expected_net_edge' => $expectedNetEdge,
            'sample_size' => $sampleSize,
            'rejection_reasons' => ['unhandled_quality_risk_pair'],
            'diagnostics' => $diagnostics,
        ];
    }
}
