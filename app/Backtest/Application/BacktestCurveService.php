<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Infrastructure\Persistence\BacktestEquitySnapshotRecord;
use Brick\Math\BigDecimal;

final class BacktestCurveService
{
    /** @return array{points: array<int, array<string, mixed>>, original_points: int, returned_points: int, downsampled: bool} */
    public function get(string $runId, int $maximumPoints = 1000): array
    {
        $maximumPoints = max(3, min(1000, $maximumPoints));
        $query = BacktestEquitySnapshotRecord::query()->where('backtest_run_id', $runId);
        $total = (clone $query)->count();
        if ($total <= $maximumPoints) {
            $points = $query->oldest('recorded_at')->oldest('id')
                ->get(['id', 'recorded_at', 'equity', 'drawdown_percent'])->toArray();

            return ['points' => $points, 'original_points' => $total, 'returned_points' => count($points), 'downsampled' => false];
        }

        $bucketCount = max(1, intdiv($maximumPoints - 2, 3));
        $bucketSize = (int) ceil($total / $bucketCount);
        $points = [];
        $first = $last = null;
        $bucket = [];
        $flush = static function () use (&$bucket, &$points): void {
            if ($bucket === []) {
                return;
            }
            $selected = [];
            foreach (['minimum', 'maximum', 'drawdown'] as $key) {
                if (isset($bucket[$key])) {
                    $selected[$bucket[$key]['id']] = $bucket[$key];
                }
            }
            usort($selected, static fn (array $left, array $right): int => [$left['recorded_at'], $left['id']] <=> [$right['recorded_at'], $right['id']]);
            array_push($points, ...$selected);
            $bucket = [];
        };

        $index = 0;
        foreach ($query->oldest('recorded_at')->oldest('id')->cursor(['id', 'recorded_at', 'equity', 'drawdown_percent']) as $row) {
            $point = $row->toArray();
            $first ??= $point;
            $last = $point;
            if ($bucket === []) {
                $bucket = ['minimum' => $point, 'maximum' => $point, 'drawdown' => $point];
            } else {
                if (BigDecimal::of($point['equity'])->isLessThan(BigDecimal::of($bucket['minimum']['equity']))) {
                    $bucket['minimum'] = $point;
                }
                if (BigDecimal::of($point['equity'])->isGreaterThan(BigDecimal::of($bucket['maximum']['equity']))) {
                    $bucket['maximum'] = $point;
                }
                if (BigDecimal::of($point['drawdown_percent'])->isGreaterThan(BigDecimal::of($bucket['drawdown']['drawdown_percent']))) {
                    $bucket['drawdown'] = $point;
                }
            }
            if (++$index % $bucketSize === 0) {
                $flush();
            }
        }
        $flush();
        $points = collect([$first, ...$points, $last])->filter()->keyBy('id')->values()
            ->sortBy([['recorded_at', 'asc'], ['id', 'asc']])->take($maximumPoints)->values()->all();

        return ['points' => $points, 'original_points' => $total, 'returned_points' => count($points), 'downsampled' => true];
    }
}
