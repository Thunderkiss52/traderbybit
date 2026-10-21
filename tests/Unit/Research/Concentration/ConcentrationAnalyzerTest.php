<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Concentration;

use App\Research\Domain\Concentration\ConcentrationAnalyzer;
use App\Research\Domain\Concentration\OverlapCluster;
use App\Research\Domain\Concentration\OverlapClusterBuilder;
use App\Research\Domain\Concentration\PositionInterval;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * PROFIT-1 / P-5 — PnL concentration metrics (§10.4).
 */
final class ConcentrationAnalyzerTest extends TestCase
{
    public function test_share_of_total_is_null_with_a_reason_when_total_net_pnl_is_not_positive(): void
    {
        $analyzer = new ConcentrationAnalyzer;

        $summary = $analyzer->summarize(
            [$this->cluster('c1', ['-5.000000000000'])],
            [$this->interval('p1', '-5.000000000000')]
        );

        self::assertFalse($summary['total_net_pnl_interpretable']);
        self::assertSame('TOTAL_NET_PNL_NOT_POSITIVE', $summary['total_net_pnl_reason']);
        self::assertNull($summary['top_k']['1']['share_of_total_net_pnl']);
        self::assertSame('TOTAL_NET_PNL_NOT_POSITIVE', $summary['top_k']['1']['share_reason']);
        self::assertSame('-5.000000000000', $summary['top_k']['1']['net_pnl']);
    }

    public function test_top_k_ranks_clusters_by_net_pnl_and_traces_members_to_position_ids(): void
    {
        $analyzer = new ConcentrationAnalyzer;

        $summary = $analyzer->summarize(
            [
                $this->cluster('c1', ['10.000000000000'], ['p1']),
                $this->cluster('c2', ['30.000000000000'], ['p2']),
                $this->cluster('c3', ['20.000000000000'], ['p3']),
                $this->cluster('c4', ['40.000000000000'], ['p4']),
            ],
            [
                $this->interval('p1', '10.000000000000'),
                $this->interval('p2', '30.000000000000'),
                $this->interval('p3', '20.000000000000'),
                $this->interval('p4', '40.000000000000'),
            ]
        );

        self::assertTrue($summary['total_net_pnl_interpretable']);
        self::assertSame('100.000000000000', $summary['total_net_pnl']);

        $top1 = $summary['top_k']['1'];
        self::assertSame('c4', $top1['clusters'][0]['cluster_id']);
        self::assertSame(['p4'], $top1['clusters'][0]['member_position_ids']);
        self::assertSame('40.000000000000', $top1['net_pnl']);
        self::assertSame('0.400000000000', $top1['share_of_total_net_pnl']);

        $top3 = $summary['top_k']['3'];
        self::assertSame('90.000000000000', $top3['net_pnl']);
        self::assertSame('0.900000000000', $top3['share_of_total_net_pnl']);
        self::assertSame(3, $top3['cluster_count']);

        $top5 = $summary['top_k']['5'];
        self::assertSame(4, $top5['cluster_count'], 'Top-K never invents clusters beyond the population.');
    }

    public function test_open_positions_contribute_no_realized_pnl_but_report_unrealized_separately(): void
    {
        $analyzer = new ConcentrationAnalyzer;

        $open = new PositionInterval(
            positionId: 'p-open',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            openedAt: CarbonImmutable::parse('2026-01-01 00:00', 'UTC'),
            endAt: CarbonImmutable::parse('2026-01-01 04:00', 'UTC'),
            openAtCutoff: true,
            partialCloseNotEnding: false,
            netPnl: '0.000000000000',
            unrealizedPnl: '12.500000000000',
            entryNotional: '100',
        );

        $summary = $analyzer->summarize(
            [$this->cluster('c1', ['0.000000000000'], ['p-open'])],
            [$open]
        );

        self::assertSame('0.000000000000', $summary['total_net_pnl']);
        self::assertSame(1, $summary['open_position_count']);
        self::assertSame('12.500000000000', $summary['open_unrealized_pnl']);
        self::assertFalse($summary['total_net_pnl_interpretable']);
    }

    public function test_day_block_counts_are_reported_for_positions_and_clusters(): void
    {
        $analyzer = new ConcentrationAnalyzer;

        $summary = $analyzer->summarize(
            [$this->cluster('c1', ['5.000000000000'], ['p1'])],
            [
                $this->interval('p1', '5.000000000000', '2026-01-01 01:00'),
                $this->interval('p2', '5.000000000000', '2026-01-02 01:00'),
            ]
        );

        self::assertSame(2, $summary['day_block_count']);
        self::assertSame(['2026-01-01' => 1, '2026-01-02' => 1], $summary['positions_per_day_block']);
        self::assertSame(1, $summary['clusters_per_day_block']['2026-01-01']);
    }

    private function interval(string $id, string $netPnl, string $openedAt = '2026-01-01 00:00'): PositionInterval
    {
        return new PositionInterval(
            positionId: $id,
            accountId: 'account-'.$id,
            symbol: 'BTCUSDT',
            side: 'BUY',
            openedAt: CarbonImmutable::parse($openedAt, 'UTC'),
            endAt: CarbonImmutable::parse($openedAt, 'UTC')->addHours(2),
            openAtCutoff: false,
            partialCloseNotEnding: false,
            netPnl: $netPnl,
            unrealizedPnl: '0.000000000000',
            entryNotional: '100.000000000000',
        );
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function cluster(string $clusterId, array $netPnl, array $memberIds = []): OverlapCluster
    {
        $members = [];
        foreach ($memberIds as $memberId) {
            $members[] = $this->interval($memberId, $netPnl[0]);
        }

        return new OverlapCluster(
            clusterId: $clusterId,
            viewCode: OverlapClusterBuilder::VIEW_SAME_SIDE,
            symbol: 'BTCUSDT',
            sideFamily: 'BUY',
            members: $members,
            startAt: CarbonImmutable::parse('2026-01-01 00:00', 'UTC'),
            endAt: CarbonImmutable::parse('2026-01-01 02:00', 'UTC'),
            netPnl: $netPnl[0],
        );
    }
}
