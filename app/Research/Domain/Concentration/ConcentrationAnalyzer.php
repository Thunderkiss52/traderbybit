<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — PnL concentration metrics (§10.4).
 *
 * All money arithmetic uses exact decimal strings; a share of a non-positive
 * total is reported as NULL with an explicit reason instead of a misleading
 * ratio. Realized net PnL of still-open positions (zero while open) and their
 * unrealized PnL are never mixed.
 */
final class ConcentrationAnalyzer
{
    public const TOP_K = [1, 3, 5];

    public const SCALE = 12;

    /**
     * @param  list<OverlapCluster>  $clusters
     * @param  list<PositionInterval>  $population
     * @return array<string, mixed>
     */
    public function summarize(array $clusters, array $population): array
    {
        $totalNetPnl = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $openUnrealized = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $openCount = 0;
        $dayBlocks = [];
        foreach ($population as $interval) {
            $totalNetPnl = $totalNetPnl->plus($interval->netPnl);
            if ($interval->openAtCutoff) {
                $openCount++;
                $openUnrealized = $openUnrealized->plus($interval->unrealizedPnl);
            }
            $day = $interval->openedAt->utc()->format('Y-m-d');
            $dayBlocks[$day] = ($dayBlocks[$day] ?? 0) + 1;
        }

        $interpretable = $totalNetPnl->isPositive();
        $totalReason = $interpretable ? null : 'TOTAL_NET_PNL_NOT_POSITIVE';

        $ranked = $clusters;
        usort($ranked, static function (OverlapCluster $a, OverlapCluster $b): int {
            $cmp = BigDecimal::of($b->netPnl)->compareTo(BigDecimal::of($a->netPnl));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a->clusterId, $b->clusterId);
        });

        $clusterSummaries = [];
        foreach ($ranked as $cluster) {
            $clusterSummaries[] = [
                'cluster_id' => $cluster->clusterId,
                'view_code' => $cluster->viewCode,
                'symbol' => $cluster->symbol,
                'side_family' => $cluster->sideFamily,
                'member_count' => $cluster->memberCount(),
                'member_position_ids' => $cluster->memberIds(),
                'net_pnl' => $cluster->netPnl,
                'start_at' => $cluster->startAt->toIso8601String(),
                'end_at' => $cluster->endAt->toIso8601String(),
            ];
        }

        $topK = [];
        foreach (self::TOP_K as $k) {
            $slice = array_slice($ranked, 0, $k);
            $sum = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
            $members = [];
            foreach ($slice as $cluster) {
                $sum = $sum->plus($cluster->netPnl);
                $members[] = [
                    'cluster_id' => $cluster->clusterId,
                    'net_pnl' => $cluster->netPnl,
                    'member_position_ids' => $cluster->memberIds(),
                ];
            }
            $share = null;
            if ($interpretable) {
                $share = $sum->dividedBy($totalNetPnl, self::SCALE, RoundingMode::HalfEven)->toScale(self::SCALE, RoundingMode::HalfEven)->__toString();
            }
            $topK[(string) $k] = [
                'k' => $k,
                'cluster_count' => count($slice),
                'net_pnl' => $sum->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                'share_of_total_net_pnl' => $share,
                'share_reason' => $share === null ? $totalReason : null,
                'clusters' => $members,
            ];
        }

        ksort($dayBlocks);
        $clustersPerDay = [];
        foreach ($ranked as $cluster) {
            $day = $cluster->startAt->utc()->format('Y-m-d');
            $clustersPerDay[$day] = ($clustersPerDay[$day] ?? 0) + 1;
        }
        ksort($clustersPerDay);

        return [
            'cluster_count' => count($clusters),
            'cluster_member_count' => array_sum(array_map(static fn (OverlapCluster $c): int => $c->memberCount(), $clusters)),
            'population_size' => count($population),
            'total_net_pnl' => $totalNetPnl->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'total_net_pnl_interpretable' => $interpretable,
            'total_net_pnl_reason' => $totalReason,
            'open_position_count' => $openCount,
            'open_unrealized_pnl' => $openUnrealized->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'top_k' => $topK,
            'clusters' => $clusterSummaries,
            'day_block_count' => count($dayBlocks),
            'positions_per_day_block' => $dayBlocks,
            'clusters_per_day_block' => $clustersPerDay,
            'computed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }
}
