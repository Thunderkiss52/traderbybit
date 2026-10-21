<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — deterministic overlap cluster algorithm (§10.2).
 *
 * SAME_SIDE:
 *   edges exist only between overlapping positions of the same symbol and the
 *   same side; connected components are transitive within that side.
 *
 * OPPOSITE_SIDE:
 *   a bipartite overlap graph per symbol; the partitions are BUY and SELL and
 *   an edge is only allowed between opposite-side positions whose intervals
 *   overlap. A same-side edge is never created through a shared opposite-side
 *   neighbour. A reported connected component may contain several BUY and
 *   SELL nodes because the graph is bipartite.
 *
 * Cluster identifiers are content addressed (definition version + view + symbol
 * + side family + sorted member position ids), so re-running the same inputs
 * yields identical cluster ids and membership (§10.5).
 */
final class OverlapClusterBuilder
{
    public const VIEW_SAME_SIDE = 'SAME_SIDE';

    public const VIEW_OPPOSITE_SIDE = 'OPPOSITE_SIDE';

    public const CLUSTER_DEFINITION_VERSION = 'p5-overlap-v1';

    /**
     * @param  list<PositionInterval>  $intervals
     * @return list<OverlapCluster>
     */
    public function buildSameSide(array $intervals, string $scopeCode = 'UNSCOPED'): array
    {
        $groups = [];
        foreach ($intervals as $interval) {
            $groups[$interval->symbol.'|'.$interval->side][] = $interval;
        }
        ksort($groups);

        $clusters = [];
        foreach ($groups as $groupKey => $groupIntervals) {
            [$symbol, $side] = explode('|', $groupKey, 2);
            $components = $this->connectedComponents($groupIntervals);
            foreach ($components as $component) {
                $clusters[] = $this->makeCluster(self::VIEW_SAME_SIDE, $scopeCode, $symbol, $side, $component);
            }
        }

        return $this->sortClusters($clusters);
    }

    /**
     * @param  list<PositionInterval>  $intervals
     * @return array{clusters: list<OverlapCluster>, edges: list<array<string, mixed>>}
     */
    public function buildOppositeSide(array $intervals, string $scopeCode = 'UNSCOPED'): array
    {
        $bySymbol = [];
        foreach ($intervals as $interval) {
            $bySymbol[$interval->symbol][] = $interval;
        }
        ksort($bySymbol);

        $clusters = [];
        $edges = [];
        foreach ($bySymbol as $symbol => $symbolIntervals) {
            $buys = array_values(array_filter($symbolIntervals, static fn (PositionInterval $i): bool => $i->isBuy()));
            $sells = array_values(array_filter($symbolIntervals, static fn (PositionInterval $i): bool => ! $i->isBuy()));

            $symbolEdges = [];
            foreach ($buys as $buy) {
                foreach ($sells as $sell) {
                    if (! $buy->overlaps($sell)) {
                        continue;
                    }
                    $overlapStart = $buy->openedAt->greaterThan($sell->openedAt) ? $buy->openedAt : $sell->openedAt;
                    $overlapEnd = $buy->endAt->lessThan($sell->endAt) ? $buy->endAt : $sell->endAt;
                    $symbolEdges[] = [
                        'symbol' => $symbol,
                        'buy_position_id' => $buy->positionId,
                        'sell_position_id' => $sell->positionId,
                        'overlap_start' => $overlapStart->toIso8601String(),
                        'overlap_end' => $overlapEnd->toIso8601String(),
                    ];
                }
            }
            usort($symbolEdges, static fn (array $a, array $b): int => [$a['buy_position_id'], $a['sell_position_id']] <=> [$b['buy_position_id'], $b['sell_position_id']]);
            $edges = array_merge($edges, $symbolEdges);

            // Connected components of the bipartite graph: union BUY and SELL
            // nodes through opposite-side edges only.
            $parent = [];
            foreach ($buys as $buy) {
                $parent[$buy->positionId] = $buy->positionId;
            }
            foreach ($sells as $sell) {
                $parent[$sell->positionId] = $sell->positionId;
            }
            foreach ($symbolEdges as $edge) {
                $this->union($parent, $edge['buy_position_id'], $edge['sell_position_id']);
            }

            $components = [];
            foreach ($parent as $node => $_) {
                $root = $this->find($parent, $node);
                $components[$root][] = $node;
            }
            ksort($components);

            $lookup = [];
            foreach ($symbolIntervals as $interval) {
                $lookup[$interval->positionId] = $interval;
            }

            foreach ($components as $nodeIds) {
                sort($nodeIds);
                $members = [];
                foreach ($nodeIds as $nodeId) {
                    $members[] = $lookup[$nodeId];
                }
                usort($members, static fn (PositionInterval $a, PositionInterval $b): int => [$a->openedAt->getTimestamp(), $a->positionId] <=> [$b->openedAt->getTimestamp(), $b->positionId]);
                $clusters[] = $this->makeCluster(self::VIEW_OPPOSITE_SIDE, $scopeCode, (string) $symbol, null, $members);
            }
        }

        return [
            'clusters' => $this->sortClusters($clusters),
            'edges' => $edges,
        ];
    }

    /**
     * Connected components of a same-symbol / same-side interval graph.
     *
     * @param  list<PositionInterval>  $intervals
     * @return list<list<PositionInterval>>
     */
    private function connectedComponents(array $intervals): array
    {
        usort($intervals, static fn (PositionInterval $a, PositionInterval $b): int => [$a->openedAt->getTimestamp(), $a->positionId] <=> [$b->openedAt->getTimestamp(), $b->positionId]);

        $parent = [];
        foreach ($intervals as $interval) {
            $parent[$interval->positionId] = $interval->positionId;
        }

        for ($i = 0; $i < count($intervals); $i++) {
            for ($j = $i + 1; $j < count($intervals); $j++) {
                $a = $intervals[$i];
                $b = $intervals[$j];
                if (! $b->openedAt->lessThan($a->endAt)) {
                    // Sorted by start: no later interval can overlap $a once
                    // $b starts at or after the end of $a.
                    break;
                }
                if ($a->overlaps($b)) {
                    $this->union($parent, $a->positionId, $b->positionId);
                }
            }
        }

        $components = [];
        foreach ($intervals as $interval) {
            $root = $this->find($parent, $interval->positionId);
            $components[$root][] = $interval;
        }
        ksort($components);

        $result = [];
        foreach ($components as $component) {
            usort($component, static fn (PositionInterval $a, PositionInterval $b): int => [$a->openedAt->getTimestamp(), $a->positionId] <=> [$b->openedAt->getTimestamp(), $b->positionId]);
            $result[] = $component;
        }

        return $result;
    }

    /**
     * @param  list<PositionInterval>  $members
     */
    private function makeCluster(string $viewCode, string $scopeCode, string $symbol, ?string $sideFamily, array $members): OverlapCluster
    {
        usort($members, static fn (PositionInterval $a, PositionInterval $b): int => [$a->openedAt->getTimestamp(), $a->positionId] <=> [$b->openedAt->getTimestamp(), $b->positionId]);

        $memberIds = array_map(static fn (PositionInterval $m): string => $m->positionId, $members);
        sort($memberIds);
        $clusterId = $this->clusterId($viewCode, $scopeCode, $symbol, $sideFamily, $memberIds);

        $start = null;
        $end = null;
        $netPnl = '0';
        foreach ($members as $member) {
            $start = $start === null ? $member->openedAt : ($member->openedAt->lessThan($start) ? $member->openedAt : $start);
            $end = $end === null ? $member->endAt : ($member->endAt->greaterThan($end) ? $member->endAt : $end);
            $netPnl = BigDecimal::of($netPnl)->plus($member->netPnl)->toScale(12, RoundingMode::HalfEven)->__toString();
        }

        return new OverlapCluster(
            clusterId: $clusterId,
            viewCode: $viewCode,
            symbol: $symbol,
            sideFamily: $sideFamily,
            members: $members,
            startAt: $start ?? CarbonImmutable::now('UTC'),
            endAt: $end ?? CarbonImmutable::now('UTC'),
            netPnl: $netPnl,
        );
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function clusterId(string $viewCode, string $scopeCode, string $symbol, ?string $sideFamily, array $memberIds): string
    {
        $prefix = $viewCode === self::VIEW_SAME_SIDE ? 'P5SS' : 'P5OS';
        $digest = hash('sha256', implode('|', [
            self::CLUSTER_DEFINITION_VERSION,
            $viewCode,
            $scopeCode,
            $symbol,
            (string) $sideFamily,
            implode(',', $memberIds),
        ]));

        return $prefix.'-'.strtoupper(substr($digest, 0, 16));
    }

    /**
     * @param  list<OverlapCluster>  $clusters
     * @return list<OverlapCluster>
     */
    private function sortClusters(array $clusters): array
    {
        usort($clusters, static fn (OverlapCluster $a, OverlapCluster $b): int => [$a->viewCode, $a->symbol, (string) $a->sideFamily, $a->clusterId] <=> [$b->viewCode, $b->symbol, (string) $b->sideFamily, $b->clusterId]);

        return $clusters;
    }

    /**
     * @param  array<string, string>  $parent
     */
    private function find(array &$parent, string $node): string
    {
        $root = $node;
        while ($parent[$root] !== $root) {
            $root = $parent[$root];
        }
        while ($parent[$node] !== $root) {
            $next = $parent[$node];
            $parent[$node] = $root;
            $node = $next;
        }

        return $root;
    }

    /**
     * @param  array<string, string>  $parent
     */
    private function union(array &$parent, string $a, string $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);
        if ($rootA === $rootB) {
            return;
        }
        // Deterministic merge: the lexicographically smaller root wins.
        if (strcmp($rootA, $rootB) <= 0) {
            $parent[$rootB] = $rootA;
        } else {
            $parent[$rootA] = $rootB;
        }
    }
}
