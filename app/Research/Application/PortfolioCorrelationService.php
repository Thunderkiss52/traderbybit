<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Infrastructure\Persistence\PortfolioCorrelationSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class PortfolioCorrelationService
{
    /**
     * Compute portfolio correlations, concurrent overlap, and concentration.
     */
    public function evaluate(string $horizon = '14d', bool $persist = true): PortfolioCorrelationSnapshotRecord
    {
        $method = $persist ? 'create' : 'make';
        $days = match ($horizon) {
            '7d' => 7,
            '30d' => 30,
            default => 14,
        };

        $cutoff = CarbonImmutable::now()->subDays($days);

        // Fetch closed and open positions
        $positions = VirtualPositionRecord::query()
            ->join('paper_accounts', 'virtual_positions.paper_account_id', '=', 'paper_accounts.id')
            ->where('virtual_positions.opened_at', '>=', $cutoff)
            ->select('virtual_positions.*', 'paper_accounts.strategy_id as account_strategy_id')
            ->get();

        // 1. Group positions by strategy and symbol
        $byStrategy = [];
        $byAssetDirection = [];
        $strategyDailyPnls = [];

        foreach ($positions as $pos) {
            $strat = (string) ($pos->account_strategy_id ?? 'unknown');
            $sym = (string) $pos->symbol;
            $side = (string) $pos->side;

            $byStrategy[$strat] = ($byStrategy[$strat] ?? 0) + 1;
            $assetKey = "{$sym}:{$side}";
            $byAssetDirection[$assetKey] = ($byAssetDirection[$assetKey] ?? 0) + 1;

            $isClosed = $pos->status === 'CLOSED' || str_starts_with((string) $pos->status, 'CLOSED_');
            if ($isClosed) {
                $timeBucket = CarbonImmutable::parse($pos->opened_at)->format('Y-m-d');
                $strategyDailyPnls[$strat][$timeBucket] = ($strategyDailyPnls[$strat][$timeBucket] ?? 0.0) + (float) $pos->net_pnl;
            }
        }

        // 2. Compute pairwise Pearson correlation between strategies aligned by common time bucket
        $strategies = array_keys($byStrategy);
        $pairMatrix = [];
        $alerts = [];

        foreach ($strategies as $s1) {
            $pairMatrix[$s1] = [];
            foreach ($strategies as $s2) {
                if ($s1 === $s2) {
                    $pairMatrix[$s1][$s2] = 1.0;

                    continue;
                }

                $series1 = $strategyDailyPnls[$s1] ?? [];
                $series2 = $strategyDailyPnls[$s2] ?? [];

                $commonBuckets = array_values(array_intersect(array_keys($series1), array_keys($series2)));
                sort($commonBuckets);

                if (count($commonBuckets) >= 3) {
                    $aligned1 = array_map(static fn (string $bucket): float => (float) $series1[$bucket], $commonBuckets);
                    $aligned2 = array_map(static fn (string $bucket): float => (float) $series2[$bucket], $commonBuckets);

                    $corr = $this->pearsonCorrelation($aligned1, $aligned2);
                    $pairMatrix[$s1][$s2] = round($corr, 4);

                    if ($corr > 0.80 && ! in_array("High correlation between {$s1} and {$s2} ({$corr})", $alerts, true)) {
                        $alerts[] = "High correlation between {$s1} and {$s2} ({$corr})";
                    }
                } else {
                    $pairMatrix[$s1][$s2] = null;
                }
            }
        }

        // 3. Concurrent open position overlap
        $openPositions = VirtualPositionRecord::query()
            ->whereIn('status', ['OPEN', 'PARTIALLY_CLOSED'])
            ->get();

        $concurrentOverlap = [];
        $totalOpen = $openPositions->count();

        foreach ($openPositions as $p) {
            $key = "{$p->symbol}_{$p->side}";
            $concurrentOverlap[$key] = ($concurrentOverlap[$key] ?? 0) + 1;
        }

        foreach ($concurrentOverlap as $k => $cnt) {
            if ($cnt >= 2) {
                $alerts[] = "Concurrent position overlap: {$cnt} strategies holding {$k} simultaneously";
            }
        }

        // 4. Herfindahl-Hirschman Index (HHI) Concentration Score
        $totalPositions = array_sum($byStrategy);
        $concentrationScore = 0.0;

        if ($totalPositions > 0) {
            $hhiSum = 0.0;
            foreach ($byStrategy as $count) {
                $share = $count / $totalPositions;
                $hhiSum += ($share * $share);
            }
            // HHI normalized: 1.0 is extreme concentration (single strategy), 1/N is perfectly diversified
            $concentrationScore = round($hhiSum, 4);
        }

        if ($concentrationScore > 0.60) {
            $alerts[] = "High portfolio concentration: single strategy dominates {$concentrationScore} of trade allocation";
        }

        return PortfolioCorrelationSnapshotRecord::query()->{$method}([
            'id' => (string) Str::uuid(),
            'horizon' => $horizon,
            'pair_matrix' => $pairMatrix,
            'concurrent_overlap' => $concurrentOverlap,
            'concentration_score' => $concentrationScore,
            'alerts' => $alerts,
            'evaluated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Compute Pearson correlation coefficient.
     */
    private function pearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = 0.0;
        $sumSqX = 0.0;
        $sumSqY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $diffX = $x[$i] - $meanX;
            $diffY = $y[$i] - $meanY;
            $numerator += ($diffX * $diffY);
            $sumSqX += ($diffX * $diffX);
            $sumSqY += ($diffY * $diffY);
        }

        $denominator = sqrt($sumSqX * $sumSqY);
        if ($denominator == 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $numerator / $denominator));
    }
}
