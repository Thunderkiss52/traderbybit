<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A3 — research-only allocation and risk-budget proposals.
 *
 * The proposal is a *research artefact*: it never writes a risk budget, never
 * merges the primary ledgers and never increases the total risk. The
 * total allocated risk is always less than or equal to the total before, and
 * a per-strategy cap is never exceeded.
 */
final class AllocationProposal
{
    private const SCALE = 12;

    /**
     * @param  array<string, string>  $currentRiskCapital  strategy => risk capital
     * @param  array<string, float>  $volatility  strategy => annualised volatility used as the risk proxy
     * @param  array<string, float>  $correlation  "A|B" => correlation
     * @param  array<string, float>  $caps  strategy => maximum share of the total
     * @return array<string, mixed>
     */
    public function propose(
        array $currentRiskCapital,
        array $volatility,
        array $correlation,
        array $caps,
        string $totalRiskBudget,
        float $clusterCorrelationThreshold = 0.8,
        float $maxClusterShare = 0.6,
    ): array {
        $strategies = array_keys($currentRiskCapital);
        sort($strategies);

        $currentTotal = BigDecimal::zero();
        foreach ($currentRiskCapital as $value) {
            $currentTotal = $currentTotal->plus(BigDecimal::of((string) $value));
        }

        $budget = BigDecimal::of($totalRiskBudget);
        $neverIncrease = $budget->compareTo($currentTotal) > 0 ? $currentTotal : $budget;

        $weights = [];
        $weightTotal = BigDecimal::zero();
        foreach ($strategies as $strategy) {
            $vol = (float) ($volatility[$strategy] ?? 0.0);
            if ($vol <= 0) {
                $weights[$strategy] = BigDecimal::zero();

                continue;
            }
            $weight = BigDecimal::of('1')->dividedBy(BigDecimal::of((string) $vol), self::SCALE, RoundingMode::HalfEven);
            $weights[$strategy] = $weight;
            $weightTotal = $weightTotal->plus($weight);
        }

        $targets = [];
        $capBinding = [];
        $shares = [];

        foreach ($strategies as $strategy) {
            $weight = $weights[$strategy];
            $share = $weightTotal->isZero()
                ? BigDecimal::zero()
                : $weight->dividedBy($weightTotal, self::SCALE, RoundingMode::HalfEven);

            $target = $neverIncrease->multipliedBy($share)->toScale(self::SCALE, RoundingMode::HalfEven);

            $capShare = (float) ($caps[$strategy] ?? 1.0);
            $cap = $neverIncrease->multipliedBy(BigDecimal::of((string) $capShare))->toScale(self::SCALE, RoundingMode::HalfEven);
            $capped = $target->compareTo($cap) > 0;
            if ($capped) {
                $target = $cap;
            }

            $targets[$strategy] = $target;
            $capBinding[$strategy] = $capped;
            $shares[$strategy] = $share;
        }

        // Correlation-aware budgeting: the strategies whose correlation exceeds
        // the threshold form a cluster, and the total risk of a cluster is
        // capped. The excess is never reallocated, so the total risk still
        // cannot increase.
        $clusters = $this->correlationClusters($strategies, $correlation, $clusterCorrelationThreshold);
        $clusterCap = $neverIncrease->multipliedBy(BigDecimal::of((string) $maxClusterShare))->toScale(self::SCALE, RoundingMode::HalfEven);
        $clusterBinding = [];

        foreach ($clusters as $cluster) {
            if (count($cluster) < 2) {
                continue;
            }

            $clusterTotal = BigDecimal::zero();
            foreach ($cluster as $member) {
                $clusterTotal = $clusterTotal->plus($targets[$member]);
            }

            if ($clusterTotal->compareTo($clusterCap) <= 0) {
                continue;
            }

            $ratio = $clusterCap->dividedBy($clusterTotal, self::SCALE, RoundingMode::HalfEven);
            foreach ($cluster as $member) {
                $targets[$member] = $targets[$member]->multipliedBy($ratio)->toScale(self::SCALE, RoundingMode::HalfEven);
                $clusterBinding[$member] = true;
            }
        }

        $proposals = [];
        $allocated = BigDecimal::zero();

        foreach ($strategies as $strategy) {
            $target = $targets[$strategy];
            $allocated = $allocated->plus($target);

            $proposals[] = [
                'strategy' => $strategy,
                'current_risk_capital' => BigDecimal::of((string) $currentRiskCapital[$strategy])->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                'proposed_risk_capital' => $target->__toString(),
                'delta' => $target->minus(BigDecimal::of((string) $currentRiskCapital[$strategy]))->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                'cap_binding' => $capBinding[$strategy],
                'cluster_cap_binding' => $clusterBinding[$strategy] ?? false,
                'correlation_cluster' => $this->clusterOf($strategy, $clusters),
                'share_of_budget' => $shares[$strategy]->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                'average_correlation' => $this->averageCorrelation($strategy, $strategies, $correlation),
                'volatility' => (float) ($volatility[$strategy] ?? 0.0),
            ];
        }

        $unallocated = $neverIncrease->minus($allocated)->toScale(self::SCALE, RoundingMode::HalfEven);

        $volatilityBefore = $this->portfolioVolatility($currentRiskCapital, $volatility, $correlation, $strategies);
        $volatilityAfter = $this->portfolioVolatility(
            array_combine(
                $strategies,
                array_map(static fn (array $row): string => $row['proposed_risk_capital'], $proposals)
            ),
            $volatility,
            $correlation,
            $strategies
        );

        return [
            'research_only' => true,
            'applied' => false,
            'current_total_risk_capital' => $currentTotal->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'total_risk_budget' => $neverIncrease->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'allocated_risk_capital' => $allocated->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'unallocated_risk_capital' => $unallocated->__toString(),
            'total_risk_increased' => $neverIncrease->compareTo($currentTotal) > 0,
            'ledgers_merged' => false,
            'correlation_aware' => true,
            'correlation_cluster_threshold' => $clusterCorrelationThreshold,
            'max_cluster_share' => $maxClusterShare,
            'correlation_clusters' => $clusters,
            'portfolio_volatility_before' => $volatilityBefore,
            'portfolio_volatility_after' => $volatilityAfter,
            'diversification_improved' => $volatilityAfter <= $volatilityBefore,
            'proposals' => $proposals,
            'correlation' => $correlation,
            'note' => 'Research-only proposal. No risk budget was written, no ledger was merged and the total risk was not increased.',
        ];
    }

    /**
     * The correlation clusters of the book: the strategies whose pairwise
     * correlation reaches the threshold form one cluster.
     *
     * @param  list<string>  $strategies
     * @param  array<string, float>  $correlation
     * @return list<list<string>>
     */
    private function correlationClusters(array $strategies, array $correlation, float $threshold): array
    {
        $parent = [];
        foreach ($strategies as $strategy) {
            $parent[$strategy] = $strategy;
        }

        $find = function (string $node) use (&$parent): string {
            return $this->findRoot($node, $parent);
        };

        foreach ($strategies as $i => $a) {
            foreach (array_slice($strategies, $i + 1) as $b) {
                if ($this->pairCorrelation($a, $b, $correlation) < $threshold) {
                    continue;
                }
                $rootA = $find($a);
                $rootB = $find($b);
                if ($rootA !== $rootB) {
                    $parent[$rootB] = $rootA;
                }
            }
        }

        $clusters = [];
        foreach ($strategies as $strategy) {
            $clusters[$find($strategy)][] = $strategy;
        }

        return array_values($clusters);
    }

    /**
     * @param  array<string, string>  $parent
     */
    private function findRoot(string $node, array &$parent): string
    {
        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /**
     * @param  list<list<string>>  $clusters
     */
    private function clusterOf(string $strategy, array $clusters): string
    {
        foreach ($clusters as $index => $cluster) {
            if (in_array($strategy, $cluster, true)) {
                return 'cluster_'.$index;
            }
        }

        return 'cluster_none';
    }

    /**
     * The average correlation of a strategy with the other strategies of the
     * book. A missing pair is reported as zero correlation, never as a
     * measured value.
     *
     * @param  list<string>  $strategies
     * @param  array<string, float>  $correlation
     */
    private function averageCorrelation(string $strategy, array $strategies, array $correlation): float
    {
        $peers = array_values(array_filter($strategies, static fn (string $peer): bool => $peer !== $strategy));
        if ($peers === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($peers as $peer) {
            $sum += $this->pairCorrelation($strategy, $peer, $correlation);
        }

        return $sum / count($peers);
    }

    /**
     * @param  array<string, float>  $correlation
     */
    private function pairCorrelation(string $a, string $b, array $correlation): float
    {
        foreach ([$a.'|'.$b, $b.'|'.$a, $a.','.$b, $b.','.$a] as $key) {
            if (array_key_exists($key, $correlation)) {
                return (float) $correlation[$key];
            }
        }

        return 0.0;
    }

    /**
     * The correlation-aware portfolio volatility of a risk-capital vector.
     *
     * This is a research estimate, not an accounting metric: the financial
     * amounts stay in exact decimal arithmetic.
     *
     * @param  array<string, string|float>  $riskCapital
     * @param  array<string, float>  $volatility
     * @param  array<string, float>  $correlation
     * @param  list<string>  $strategies
     */
    private function portfolioVolatility(array $riskCapital, array $volatility, array $correlation, array $strategies): float
    {
        $total = 0.0;
        foreach ($strategies as $strategy) {
            $total += (float) ($riskCapital[$strategy] ?? 0.0);
        }

        if ($total <= 0) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($strategies as $i) {
            $wi = (float) ($riskCapital[$i] ?? 0.0) / $total;
            $si = (float) ($volatility[$i] ?? 0.0);
            foreach ($strategies as $j) {
                $wj = (float) ($riskCapital[$j] ?? 0.0) / $total;
                $sj = (float) ($volatility[$j] ?? 0.0);
                $rho = $i === $j ? 1.0 : $this->pairCorrelation($i, $j, $correlation);
                $variance += $wi * $wj * $si * $sj * $rho;
            }
        }

        return sqrt(max(0.0, $variance));
    }
}
