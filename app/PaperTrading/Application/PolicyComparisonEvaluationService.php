<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\TradeRiskClass;
use App\Strategy\Domain\SignalQuality;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Evaluates counterfactual strategy performance between:
 * Policy A (Baseline without quality/risk filter) vs
 * Policy B (Filtered with Signal Quality + Trade Risk + Validated Net-Edge gate).
 *
 * Descriptive analysis of caller-supplied outcomes. This interface cannot verify
 * chronological splits, decision-time feature availability or independent evidence.
 */
final readonly class PolicyComparisonEvaluationService
{
    public function __construct(
        private PreExecutionQualityRiskGate $gate = new PreExecutionQualityRiskGate,
    ) {}

    /**
     * @param list<array{
     *     signal_id: string,
     *     strategy_id: string,
     *     exchange_id: string,
     *     side: string,
     *     signal_quality: string|SignalQuality,
     *     trade_risk: string|TradeRiskClass,
     *     entry_price: string|float,
     *     stop_loss: string|float,
     *     take_profit_1: string|float,
     *     gross_pnl: string|float,
     *     net_pnl: string|float,
     *     fees: string|float,
     *     slippage: string|float,
     *     r_multiple?: float|null,
     *     drawdown_at_entry?: float|null,
     *     expected_net_edge?: float|null,
     *     sample_size?: int|null,
     *     evidence_status?: string|null,
     *     cost_drag_percent?: float|null
     * }> $signals
     * @return array<string, mixed>
     */
    public function compare(array $signals): array
    {
        $policyATrades = [];
        $policyBTrades = [];
        $policyBRejections = 0;
        $rejectionReasons = [];

        foreach ($signals as $trade) {
            // Policy A: baseline policy includes all generated signals
            $policyATrades[] = $trade;

            // Policy B: evaluate PreExecutionQualityRiskGate
            $quality = $trade['signal_quality'] instanceof SignalQuality
                ? $trade['signal_quality']
                : SignalQuality::fromString((string) $trade['signal_quality']);

            $risk = $trade['trade_risk'] instanceof TradeRiskClass
                ? $trade['trade_risk']
                : (TradeRiskClass::tryFrom((string) $trade['trade_risk']) ?? TradeRiskClass::Unknown);

            $eval = $this->gate->evaluate([
                'signal_quality' => $quality,
                'trade_risk' => $risk,
                'drawdown_percent' => $trade['drawdown_at_entry'] ?? 0.0,
                'expected_net_edge' => $trade['expected_net_edge'] ?? null,
                'sample_size' => $trade['sample_size'] ?? 0,
                'evidence_status' => $trade['evidence_status'] ?? 'UNKNOWN',
                'cost_drag_percent' => $trade['cost_drag_percent'] ?? null,
                'side' => $trade['side'],
            ]);

            if ($eval['decision']->isPermitted()) {
                $policyBTrades[] = $trade;
            } else {
                $policyBRejections++;
                foreach ($eval['rejection_reasons'] as $reason) {
                    $rejectionReasons[$reason] = ($rejectionReasons[$reason] ?? 0) + 1;
                }
            }
        }

        $metricsA = $this->calculateMetrics($policyATrades);
        $metricsB = $this->calculateMetrics($policyBTrades);

        $deltaNetPnl = BigDecimal::of((string) $metricsB['net_pnl'])->minus((string) $metricsA['net_pnl']);
        $deltaDrawdown = BigDecimal::of((string) $metricsB['max_drawdown'])->minus((string) $metricsA['max_drawdown']);

        // A difference on supplied rows is descriptive, not statistical validation.
        $totalSample = count($signals);
        if ($totalSample < 10) {
            $verdict = 'INSUFFICIENT_EVIDENCE_TO_DETERMINE_IMPROVEMENT';
            $verdictExplanation = 'Fewer than ten supplied observations; independence and out-of-sample validity are unverified.';
        } elseif ($deltaNetPnl->isPositive() && $deltaDrawdown->isLessThanOrEqualTo(0)) {
            $verdict = 'DESCRIPTIVE_IMPROVEMENT_REQUIRES_VALIDATION';
            $verdictExplanation = 'Supplied rows show higher net PnL and no greater drawdown; independent research and forward validation remain required.';
        } elseif ($deltaNetPnl->isPositive()) {
            $verdict = 'DESCRIPTIVE_IMPROVEMENT_REQUIRES_VALIDATION';
            $verdictExplanation = 'Supplied rows show higher net PnL with increased drawdown; risk-constrained improvement is unverified.';
        } else {
            $verdict = 'GATE_CORRECT_BUT_PROFITABILITY_UNPROVEN';
            $verdictExplanation = 'Policy B filtered low quality and high risk trades correctly, but net profitability was neutral or unproven on this sample.';
        }

        return [
            'total_signals_evaluated' => $totalSample,
            'unique_signal_count' => count(array_unique(array_column($signals, 'signal_id'))),
            'validation_status' => 'DESCRIPTIVE_ONLY',
            'causality_verified' => false,
            'independence_verified' => false,
            'entry_authority' => false,
            'policy_a_baseline' => $metricsA,
            'policy_b_filtered' => array_merge($metricsB, [
                'rejections_count' => $policyBRejections,
                'rejection_reasons' => $rejectionReasons,
            ]),
            'comparison' => [
                'delta_net_pnl' => (string) $deltaNetPnl,
                'delta_max_drawdown' => (string) $deltaDrawdown,
                'delta_win_rate' => $metricsA['win_rate'] !== null && $metricsB['win_rate'] !== null
                    ? round($metricsB['win_rate'] - $metricsA['win_rate'], 2)
                    : null,
                'delta_profit_factor' => $metricsA['profit_factor'] !== null && $metricsB['profit_factor'] !== null
                    ? round($metricsB['profit_factor'] - $metricsA['profit_factor'], 2)
                    : null,
            ],
            'verdict' => $verdict,
            'verdict_explanation' => $verdictExplanation,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     * @return array<string, mixed>
     */
    private function calculateMetrics(array $trades): array
    {
        $count = count($trades);
        if ($count === 0) {
            return [
                'trades_count' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => null,
                'gross_pnl' => '0',
                'fees' => '0',
                'slippage' => '0',
                'net_pnl' => '0',
                'profit_factor' => null,
                'average_r' => null,
                'max_drawdown' => '0',
                'expectancy' => '0',
            ];
        }

        $gross = BigDecimal::zero();
        $fees = BigDecimal::zero();
        $slippage = BigDecimal::zero();
        $net = BigDecimal::zero();
        $grossWins = BigDecimal::zero();
        $grossLosses = BigDecimal::zero();
        $rTotal = BigDecimal::zero();
        $rCount = 0;
        $wins = 0;
        $losses = 0;

        $runningEquity = BigDecimal::zero();
        $peakEquity = BigDecimal::zero();
        $maxDd = BigDecimal::zero();

        foreach ($trades as $t) {
            $tNet = BigDecimal::of((string) ($t['net_pnl'] ?? '0'));
            $tGross = BigDecimal::of((string) ($t['gross_pnl'] ?? '0'));
            $tFees = BigDecimal::of((string) ($t['fees'] ?? '0'));
            $tSlip = BigDecimal::of((string) ($t['slippage'] ?? '0'));

            $gross = $gross->plus($tGross);
            $fees = $fees->plus($tFees);
            $slippage = $slippage->plus($tSlip);
            $net = $net->plus($tNet);

            if ($tNet->isPositive()) {
                $wins++;
                $grossWins = $grossWins->plus($tNet);
            } elseif ($tNet->isNegative()) {
                $losses++;
                $grossLosses = $grossLosses->plus($tNet->abs());
            }

            if (isset($t['r_multiple']) && is_numeric($t['r_multiple'])) {
                $rTotal = $rTotal->plus((string) $t['r_multiple']);
                $rCount++;
            }

            // Track drawdown on cumulative equity
            $runningEquity = $runningEquity->plus($tNet);
            if ($runningEquity->isGreaterThan($peakEquity)) {
                $peakEquity = $runningEquity;
            }
            $currentDd = $peakEquity->minus($runningEquity);
            if ($currentDd->isGreaterThan($maxDd)) {
                $maxDd = $currentDd;
            }
        }

        $winRate = $count > 0 ? (float) round(($wins / $count) * 100, 2) : null;
        $profitFactor = $grossLosses->isPositive()
            ? (float) round($grossWins->dividedBy($grossLosses, 4, RoundingMode::HalfEven)->toFloat(), 2)
            : null;
        $avgR = $rCount > 0 ? (float) round($rTotal->dividedBy($rCount, 4, RoundingMode::HalfEven)->toFloat(), 2) : null;
        $expectancy = (string) $net->dividedBy($count, 4, RoundingMode::HalfEven);

        return [
            'trades_count' => $count,
            'winning_trades' => $wins,
            'losing_trades' => $losses,
            'win_rate' => $winRate,
            'gross_pnl' => (string) $gross,
            'fees' => (string) $fees,
            'slippage' => (string) $slippage,
            'net_pnl' => (string) $net,
            'profit_factor' => $profitFactor,
            'average_r' => $avgR,
            'max_drawdown' => (string) $maxDd,
            'expectancy' => $expectancy,
        ];
    }
}
