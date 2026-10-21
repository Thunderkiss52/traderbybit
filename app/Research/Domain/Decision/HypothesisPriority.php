<?php

declare(strict_types=1);

namespace App\Research\Domain\Decision;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * PROFIT-1 / P-6 — economic prioritisation of registered hypotheses (§14.4).
 *
 * The class only formats and ranks. It never invents a recovery estimate for
 * an untested idea, never collapses the decision dimensions into a composite
 * score and never turns a historical counterfactual into a promise.
 */
final class HypothesisPriority
{
    public const HISTORICAL_STATUS_HYPOTHETICAL = 'HYPOTHETICAL';

    public const HISTORICAL_STATUS_NOT_ESTIMATED = 'NOT_ESTIMATED';

    /**
     * @param  list<array<string, mixed>>  $hypotheses
     * @return list<array<string, mixed>>
     */
    public function build(array $hypotheses): array
    {
        $rows = [];

        foreach ($hypotheses as $hypothesis) {
            $rows[] = $this->buildRow($hypothesis);
        }

        usort($rows, static function (array $a, array $b): int {
            $left = $a['lower_confidence_bound'];
            $right = $b['lower_confidence_bound'];

            if ($left === null && $right === null) {
                return strcmp((string) $a['hypothesis_id'], (string) $b['hypothesis_id']);
            }
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }

            $comparison = BigDecimal::of((string) $left)->compareTo(BigDecimal::of((string) $right));
            if ($comparison !== 0) {
                return $comparison > 0 ? -1 : 1;
            }

            return strcmp((string) $a['hypothesis_id'], (string) $b['hypothesis_id']);
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $hypothesis
     * @return array<string, mixed>
     */
    private function buildRow(array $hypothesis): array
    {
        $deltas = $hypothesis['delta_net_pnl'] ?? [];
        $deltas = is_array($deltas) ? array_values($deltas) : [];

        $sampleSize = count($deltas);
        $historicalPointEstimate = null;
        $historicalReason = null;

        if ($sampleSize === 0) {
            $historicalReason = 'NO_MATCHED_POPULATION';
        } else {
            $sum = BigDecimal::zero();
            foreach ($deltas as $delta) {
                $sum = $sum->plus(BigDecimal::of((string) $delta));
            }
            $mean = $sum->dividedBy(BigDecimal::of((string) $sampleSize), 12, RoundingMode::HalfEven);
            $historicalPointEstimate = $mean->multipliedBy(100)->toScale(12, RoundingMode::HalfEven)->__toString();
        }

        $oos = is_array($hypothesis['oos_evaluation'] ?? null) ? $hypothesis['oos_evaluation'] : null;
        $forwardEstimate = null;
        $forwardReason = null;
        $lowerBound = null;
        $upperBound = null;

        if ($oos === null) {
            $forwardReason = 'NO_ELIGIBLE_OOS_SAMPLE';
        } elseif (($oos['sample_sufficiency'] ?? null) !== 'SUFFICIENT') {
            $forwardReason = 'INSUFFICIENT_DATA';
        } elseif (! isset($oos['expected_capital_recovery_usdt_per_100_trades'])) {
            $forwardReason = 'NO_ELIGIBLE_OOS_SAMPLE';
        } else {
            $forwardEstimate = (string) $oos['expected_capital_recovery_usdt_per_100_trades'];
            $lowerBound = isset($oos['lower_confidence_bound']) ? (string) $oos['lower_confidence_bound'] : null;
            $upperBound = isset($oos['upper_confidence_bound']) ? (string) $oos['upper_confidence_bound'] : null;
        }

        return [
            'rank' => null,
            'hypothesis_id' => (string) ($hypothesis['hypothesis_id'] ?? 'UNREGISTERED'),
            'assumption_version' => (string) ($hypothesis['assumption_version'] ?? 'UNKNOWN'),
            'status' => (string) ($hypothesis['status'] ?? 'REGISTERED'),
            'source_generation_ids' => array_values((array) ($hypothesis['source_generation_ids'] ?? [])),
            'sample_size' => $sampleSize,
            'historical_counterfactual_recovery_usdt_per_100_trades' => $historicalPointEstimate,
            'historical_estimate_status' => $historicalPointEstimate === null
                ? self::HISTORICAL_STATUS_NOT_ESTIMATED
                : self::HISTORICAL_STATUS_HYPOTHETICAL,
            'historical_estimate_reason' => $historicalReason,
            'expected_capital_recovery_usdt_per_100_trades' => $forwardEstimate,
            'forward_estimate_status' => $forwardEstimate === null
                ? self::HISTORICAL_STATUS_NOT_ESTIMATED
                : 'FORWARD_OOS_ESTIMATED',
            'forward_estimate_reason' => $forwardReason,
            'lower_confidence_bound' => $lowerBound,
            'upper_confidence_bound' => $upperBound,
            'drawdown_impact' => $hypothesis['drawdown_impact'] ?? null,
            'cost_assumptions' => $hypothesis['cost_assumptions'] ?? null,
            'ess' => $hypothesis['ess'] ?? null,
            'sample_sufficiency' => $hypothesis['sample_sufficiency'] ?? null,
            'concentration' => $hypothesis['concentration'] ?? null,
            'block_method' => $oos['block_method'] ?? null,
            'seed' => $oos['seed'] ?? null,
            'note' => 'Scaled historical estimate is not a promise of future recovery; no composite score is used.',
        ];
    }
}
