<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Infrastructure\Persistence\StrategyDegradationSnapshotRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class StrategyDegradationService
{
    /**
     * Evaluate strategy degradation over a given lookback horizon.
     */
    public function evaluate(string $strategyId, string $horizon = '14d', ?string $symbol = null): StrategyDegradationSnapshotRecord
    {
        $days = match ($horizon) {
            '7d' => 7,
            '30d' => 30,
            default => 14,
        };

        $cutoff = CarbonImmutable::now()->subDays($days);

        $query = VirtualPositionRecord::query()
            ->join('paper_accounts', 'virtual_positions.paper_account_id', '=', 'paper_accounts.id')
            ->where('paper_accounts.strategy_id', $strategyId)
            ->where('virtual_positions.status', 'LIKE', 'CLOSED%')
            ->where('virtual_positions.closed_at', '>=', $cutoff)
            ->when($symbol, fn ($q) => $q->where('virtual_positions.symbol', $symbol))
            ->select('virtual_positions.*')
            ->orderBy('virtual_positions.closed_at');

        $positions = $query->get();
        $sampleSize = $positions->count();

        if ($sampleSize < 3) {
            return StrategyDegradationSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'strategy_id' => $strategyId,
                'strategy_version' => '1.0.0',
                'symbol' => $symbol,
                'horizon' => $horizon,
                'sample_size' => $sampleSize,
                'expectancy_r' => null,
                'profit_factor' => null,
                'win_rate' => null,
                'win_rate_ci_lower' => null,
                'win_rate_ci_upper' => null,
                'max_drawdown_percent' => 0.0,
                'status' => 'INSUFFICIENT_DATA',
                'degradation_type' => 'NONE',
                'diagnostics' => [
                    'message' => "Insufficient closed sample size ({$sampleSize}/3 minimum).",
                ],
                'as_of_time' => CarbonImmutable::now(),
            ]);
        }

        $grossWins = 0.0;
        $grossLosses = 0.0;
        $wins = 0;
        $rMultiples = [];
        $startingBalance = (float) (PaperAccountRecord::query()
            ->where(function ($q) use ($strategyId): void {
                $q->where('strategy_id', $strategyId)
                    ->orWhere('primary_strategy_id', $strategyId);
            })->value('starting_balance') ?? 10000.0);

        $cumulativePnl = 0.0;
        $peakEquity = $startingBalance;
        $maxDd = 0.0;

        foreach ($positions as $pos) {
            $net = (float) $pos->net_pnl;
            $gross = (float) $pos->gross_pnl;
            $net = is_finite($net) ? $net : 0.0;
            $gross = is_finite($gross) ? $gross : 0.0;

            if ($net > 0) {
                $wins++;
                $grossWins += $gross;
            } else {
                $grossLosses += abs($gross);
            }

            // Estimate R from the recorded value only when it is finite.
            $recordedR = $pos->r_multiple;
            $r = is_numeric($recordedR) && is_finite((float) $recordedR) ? (float) $recordedR : ($net > 0 ? 1.5 : -1.0);
            $rMultiples[] = $r;

            $cumulativePnl += $net;
            $currentEquity = $startingBalance + $cumulativePnl;
            if ($currentEquity > $peakEquity) {
                $peakEquity = $currentEquity;
            }
            $dd = $peakEquity > 0 ? (($peakEquity - $currentEquity) / $peakEquity) * 100 : 0.0;
            if ($dd > $maxDd) {
                $maxDd = $dd;
            }
        }

        $winRate = $wins / $sampleSize;
        $expectancyR = count($rMultiples) > 0 ? array_sum($rMultiples) / count($rMultiples) : 0.0;
        $profitFactor = $grossLosses > 0 ? $grossWins / $grossLosses : ($grossWins > 0 ? 99.0 : 0.0);
        $profitFactor = is_finite($profitFactor) ? $profitFactor : 0.0;

        // Wilson 95% Confidence Interval for win rate
        $z = 1.96;
        $z2 = $z * $z;
        $n = $sampleSize;
        $p = $winRate;
        $denominator = 1 + ($z2 / $n);
        $center = ($p + ($z2 / (2 * $n))) / $denominator;
        $margin = ($z * sqrt(($p * (1 - $p) / $n) + ($z2 / (4 * $n * $n)))) / $denominator;
        $ciLower = max(0.0, $center - $margin);
        $ciUpper = min(1.0, $center + $margin);

        // Analyze regime context from observations
        $recentObs = StrategyObservationRecord::query()
            ->where('strategy_id', $strategyId)
            ->where('decision_time', '>=', $cutoff)
            ->latest('decision_time')
            ->limit(20)
            ->get();

        $rangingCount = 0;
        $trendingCount = 0;
        foreach ($recentObs as $obs) {
            $analysis = is_array($obs->analysis) ? $obs->analysis : [];
            $regime = (string) ($analysis['regime'] ?? implode(' ', (array) $obs->reasons));
            if (str_contains(strtoupper($regime), 'RANGE')) {
                $rangingCount++;
            } elseif (str_contains(strtoupper($regime), 'TREND')) {
                $trendingCount++;
            }
        }

        $isTrendStrategy = str_contains($strategyId, 'trend') || str_contains($strategyId, 'momentum') || str_contains($strategyId, 'breakout');
        $regimeMismatch = $isTrendStrategy && $rangingCount > $trendingCount;

        // Determine Status & Degradation Type
        $status = 'STABLE';
        $degradationType = 'NONE';

        if ($maxDd >= 20.0) {
            $status = 'CRITICAL_DRAWDOWN';
            $degradationType = $regimeMismatch ? 'REGIME_MISMATCH' : 'POSSIBLE_ALPHA_DECAY';
        } elseif ($profitFactor < 0.85 || $expectancyR < -0.1 || $maxDd >= 12.0) {
            $status = 'DEGRADING';
            $degradationType = $regimeMismatch ? 'REGIME_MISMATCH' : 'POSSIBLE_ALPHA_DECAY';
        } elseif ($profitFactor < 1.15 || $winRate < 0.40) {
            $status = 'WATCH';
            $degradationType = $regimeMismatch ? 'REGIME_MISMATCH' : 'NONE';
        }

        return StrategyDegradationSnapshotRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => $strategyId,
            'strategy_version' => '1.0.0',
            'symbol' => $symbol,
            'horizon' => $horizon,
            'sample_size' => $sampleSize,
            'expectancy_r' => round($expectancyR, 4),
            'profit_factor' => round($profitFactor, 4),
            'win_rate' => round($winRate, 4),
            'win_rate_ci_lower' => round($ciLower, 4),
            'win_rate_ci_upper' => round($ciUpper, 4),
            'max_drawdown_percent' => round($maxDd, 4),
            'status' => $status,
            'degradation_type' => $degradationType,
            'diagnostics' => [
                'ranging_observations' => $rangingCount,
                'trending_observations' => $trendingCount,
                'regime_mismatch' => $regimeMismatch,
                'wins' => $wins,
                'losses' => $sampleSize - $wins,
                'gross_profit' => round($grossWins, 2),
                'gross_loss' => round($grossLosses, 2),
            ],
            'as_of_time' => CarbonImmutable::now(),
        ]);
    }
}
