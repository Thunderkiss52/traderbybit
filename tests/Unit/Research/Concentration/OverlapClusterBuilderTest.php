<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Concentration;

use App\Research\Domain\Concentration\OverlapClusterBuilder;
use App\Research\Domain\Concentration\PositionInterval;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * PROFIT-1 / P-5 — deterministic overlap cluster algorithm (§10.2, §10.5).
 */
final class OverlapClusterBuilderTest extends TestCase
{
    public function test_same_side_clusters_merge_only_overlapping_positions_of_the_same_symbol_and_side(): void
    {
        $builder = new OverlapClusterBuilder;

        $clusters = $builder->buildSameSide([
            $this->interval('p1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 02:00'),
            $this->interval('p2', 'BTCUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 03:00'),
            $this->interval('p3', 'BTCUSDT', 'BUY', '2026-01-01 10:00', '2026-01-01 12:00'),
            $this->interval('p4', 'ETHUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 03:00'),
            $this->interval('p5', 'BTCUSDT', 'SELL', '2026-01-01 01:00', '2026-01-01 03:00'),
        ]);

        self::assertCount(4, $clusters, 'Two BTC BUY clusters (overlapping pair + separate), one ETH BUY cluster, one BTC SELL cluster.');

        $sizes = array_map(static fn ($c): int => $c->memberCount(), $clusters);
        sort($sizes);
        self::assertSame([1, 1, 1, 2], $sizes);

        $merged = null;
        foreach ($clusters as $cluster) {
            if (in_array('p1', $cluster->memberIds(), true)) {
                $merged = $cluster;
            }
        }
        self::assertNotNull($merged);
        self::assertSame(['p1', 'p2'], $merged->memberIds());
        self::assertSame('BTCUSDT', $merged->symbol);
        self::assertSame('BUY', $merged->sideFamily);
    }

    public function test_same_side_membership_is_transitive_within_a_side(): void
    {
        $builder = new OverlapClusterBuilder;

        $clusters = $builder->buildSameSide([
            $this->interval('p1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 01:00'),
            $this->interval('p2', 'BTCUSDT', 'BUY', '2026-01-01 00:30', '2026-01-01 02:00'),
            $this->interval('p3', 'BTCUSDT', 'BUY', '2026-01-01 01:30', '2026-01-01 03:00'),
        ]);

        self::assertCount(1, $clusters);
        self::assertSame(['p1', 'p2', 'p3'], $clusters[0]->memberIds());
    }

    public function test_opposite_side_view_is_a_bipartite_graph_without_same_side_edges(): void
    {
        $builder = new OverlapClusterBuilder;

        $result = $builder->buildOppositeSide([
            $this->interval('b1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 02:00'),
            $this->interval('b2', 'BTCUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 03:00'),
            $this->interval('s1', 'BTCUSDT', 'SELL', '2026-01-01 01:30', '2026-01-01 04:00'),
            $this->interval('s2', 'BTCUSDT', 'SELL', '2026-01-01 10:00', '2026-01-01 12:00'),
        ]);

        self::assertCount(2, $result['edges'], 'Only opposite-side overlaps create edges: b1-s1 and b2-s1. The overlapping BUY pair b1/b2 never creates an edge.');
        foreach ($result['edges'] as $edge) {
            self::assertNotSame($edge['buy_position_id'], $edge['sell_position_id']);
        }

        $clusterIds = array_map(static fn ($c): string => $c->clusterId, $result['clusters']);
        self::assertSame($clusterIds, array_values(array_unique($clusterIds)));

        $bipartite = null;
        foreach ($result['clusters'] as $cluster) {
            if (in_array('b1', $cluster->memberIds(), true)) {
                $bipartite = $cluster;
            }
        }
        self::assertNotNull($bipartite);
        self::assertCount(3, $bipartite->memberIds(), 'A bipartite component may contain several BUY and SELL nodes.');
        self::assertNull($bipartite->sideFamily, 'The opposite-side view has no single side family.');
    }

    public function test_half_open_intervals_that_only_touch_do_not_overlap(): void
    {
        $builder = new OverlapClusterBuilder;

        $clusters = $builder->buildSameSide([
            $this->interval('p1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 01:00'),
            $this->interval('p2', 'BTCUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 02:00'),
        ]);

        self::assertCount(2, $clusters, '[00:00,01:00) and [01:00,02:00) do not overlap.');
    }

    public function test_cluster_ids_are_content_addressed_and_stable_under_input_reordering(): void
    {
        $builder = new OverlapClusterBuilder;

        $intervals = [
            $this->interval('p1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 02:00', '10'),
            $this->interval('p2', 'BTCUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 03:00', '5'),
            $this->interval('p3', 'ETHUSDT', 'SELL', '2026-01-01 01:00', '2026-01-01 03:00', '-3'),
        ];

        $first = $builder->buildSameSide($intervals);
        $shuffled = array_reverse($intervals);
        $second = $builder->buildSameSide($shuffled);

        self::assertSame(
            array_map(static fn ($c): string => $c->clusterId, $first),
            array_map(static fn ($c): string => $c->clusterId, $second),
            'Cluster ids must be identical for identical inputs regardless of input order.'
        );

        $byId = [];
        foreach ($first as $cluster) {
            $byId[implode(',', $cluster->memberIds())] = $cluster;
        }
        self::assertSame('15.000000000000', $byId['p1,p2']->netPnl);
        self::assertSame('-3.000000000000', $byId['p3']->netPnl);
    }

    public function test_cluster_net_pnl_is_the_exact_decimal_sum_of_members(): void
    {
        $builder = new OverlapClusterBuilder;

        $clusters = $builder->buildSameSide([
            $this->interval('p1', 'BTCUSDT', 'BUY', '2026-01-01 00:00', '2026-01-01 02:00', '10.5'),
            $this->interval('p2', 'BTCUSDT', 'BUY', '2026-01-01 01:00', '2026-01-01 03:00', '4.25'),
        ]);

        self::assertCount(1, $clusters);
        self::assertSame('14.750000000000', $clusters[0]->netPnl);
    }

    private function interval(string $id, string $symbol, string $side, string $start, string $end, string $netPnl = '0'): PositionInterval
    {
        return new PositionInterval(
            positionId: $id,
            accountId: 'account-'.$id,
            symbol: $symbol,
            side: $side,
            openedAt: CarbonImmutable::parse($start, 'UTC'),
            endAt: CarbonImmutable::parse($end, 'UTC'),
            openAtCutoff: false,
            partialCloseNotEnding: false,
            netPnl: $netPnl,
            unrealizedPnl: '0',
            entryNotional: '100',
        );
    }
}
