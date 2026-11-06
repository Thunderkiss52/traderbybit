<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
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
    public function evaluate(string $strategyId, string $horizon = '14d', ?string $symbol = null, bool $persist = true, ?string $exchange = null): StrategyDegradationSnapshotRecord
    {
        $method = $persist ? 'create' : 'make';
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
            ->when($exchange, fn ($q) => $q->where('paper_accounts.exchange_id', $exchange))
            ->select('virtual_positions.*')
            ->orderBy('virtual_positions.closed_at');

        $positions = $query->get();
        $sampleSize = $positions->count();

        if ($sampleSize < 3) {
            return StrategyDegradationSnapshotRecord::query()->{$method}([
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
                    'exchange' => $exchange,
                    'message' => "Insufficient closed sample size ({$sampleSize}/3 minimum).",
                ],
                'as_of_time' => CarbonImmutable::now(),
            ]);
        }

        $grossWins = 0.0;
        $grossLosses = 0.0;
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalFees = 0.0;
        $totalFunding = 0.0;
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

        // Fetch execution-level slippage costs to isolate slippage drag
        $positionIds = $positions->pluck('id')->all();
        $slippageByPosition = [];
        if (! empty($positionIds)) {
            $executions = VirtualExecutionRecord::query()
                ->whereIn('position_id', $positionIds)
                ->get(['position_id', 'quantity', 'slippage']);

            foreach ($executions as $exec) {
                $qty = (float) ($exec->quantity ?? 0.0);
                $slip = (float) ($exec->slippage ?? 0.0);
                $cost = abs($qty * $slip);
                $slippageByPosition[$exec->position_id] = ($slippageByPosition[$exec->position_id] ?? 0.0) + $cost;
            }
        }

        $totalSlippage = 0.0;

        foreach ($positions as $pos) {
            $net = (float) $pos->net_pnl;
            $gross = (float) $pos->gross_pnl;
            $fees = (float) ($pos->fees ?? 0.0);
            $funding = (float) ($pos->funding ?? 0.0);
            $posSlip = $slippageByPosition[$pos->id] ?? 0.0;

            $net = is_finite($net) ? $net : 0.0;
            $gross = is_finite($gross) ? $gross : 0.0;
            $fees = is_finite($fees) ? $fees : 0.0;
            $funding = is_finite($funding) ? $funding : 0.0;

            $totalGross += $gross;
            $totalNet += $net;
            $totalFees += $fees;
            $totalFunding += $funding;
            $totalSlippage += $posSlip;

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

        // Standard Error & 95% Confidence Interval for Expectancy (R)
        $rVariance = 0.0;
        if ($sampleSize > 1) {
            $sumSqDiff = 0.0;
            foreach ($rMultiples as $rVal) {
                $diff = $rVal - $expectancyR;
                $sumSqDiff += $diff * $diff;
            }
            $rVariance = $sumSqDiff / ($sampleSize - 1);
        }
        $expectancySe = $sampleSize > 0 ? sqrt($rVariance / $sampleSize) : 0.0;
        $expectancyCiLower = $expectancyR - ($z * $expectancySe);
        $expectancyCiUpper = $expectancyR + ($z * $expectancySe);

        // Delta Method Standard Error & 95% Confidence Interval for Profit Factor
        $profitFactorSe = 0.0;
        if ($sampleSize > 1 && $grossLosses > 0) {
            $sumU2 = 0.0;
            foreach ($positions as $pos) {
                $g = (float) $pos->gross_pnl;
                $w = max(0.0, $g);
                $l = max(0.0, -$g);
                $u = $w - ($profitFactor * $l);
                $sumU2 += $u * $u;
            }
            $varU = $sumU2 / ($sampleSize - 1);
            $meanLoss = $grossLosses / $sampleSize;
            if ($meanLoss > 0) {
                $profitFactorSe = sqrt($varU / $sampleSize) / $meanLoss;
            }
        }
        $profitFactorCiLower = max(0.0, $profitFactor - ($z * $profitFactorSe));
        $profitFactorCiUpper = $profitFactor + ($z * $profitFactorSe);

        // Sample Size Check ($N >= 30) for statistical significance
        $isSampleSizeSufficient = ($sampleSize >= 30);
        $confidenceRating = $isSampleSizeSufficient ? 'HIGH' : 'LOW';

        // Fee Drag & Execution Cost Isolation
        // If gross PnL >= 0 (or gross expectancy >= 0) but net PnL < 0, degradation is caused by fees/slippage rather than negative alpha
        $grossExpectancyUsd = $sampleSize > 0 ? $totalGross / $sampleSize : 0.0;
        $netExpectancyUsd = $sampleSize > 0 ? $totalNet / $sampleSize : 0.0;
        $isFeeDrag = ($totalGross >= 0.0 && $totalNet < 0.0);
        $structuralNegativeAlpha = ($grossExpectancyUsd < 0.0);

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
        $definitiveAlphaDecay = false;

        if ($maxDd >= 20.0) {
            $status = 'CRITICAL_DRAWDOWN';
            if ($regimeMismatch) {
                $degradationType = 'REGIME_MISMATCH';
            } elseif ($isFeeDrag) {
                $degradationType = 'FEE_DRAG';
            } elseif ($isSampleSizeSufficient && $structuralNegativeAlpha) {
                $degradationType = 'ALPHA_DECAY';
                $definitiveAlphaDecay = true;
            } else {
                $degradationType = 'POSSIBLE_ALPHA_DECAY';
            }
        } elseif ($profitFactor < 0.85 || $expectancyR < -0.1 || $maxDd >= 12.0) {
            if ($regimeMismatch) {
                $status = 'DEGRADING';
                $degradationType = 'REGIME_MISMATCH';
            } elseif ($isFeeDrag) {
                $status = 'DEGRADING';
                $degradationType = 'FEE_DRAG';
            } elseif ($isSampleSizeSufficient) {
                $status = 'DEGRADING';
                $degradationType = 'ALPHA_DECAY';
                $definitiveAlphaDecay = true;
            } else {
                // Insufficient sample size (N < 30): flag with low confidence, cannot declare definitive alpha decay
                $status = 'WATCH';
                $degradationType = 'POSSIBLE_ALPHA_DECAY';
                $definitiveAlphaDecay = false;
            }
        } elseif ($profitFactor < 1.15 || $winRate < 0.40) {
            $status = 'WATCH';
            if ($regimeMismatch) {
                $degradationType = 'REGIME_MISMATCH';
            } elseif ($isFeeDrag) {
                $degradationType = 'FEE_DRAG';
            } else {
                $degradationType = 'NONE';
            }
        }

        return StrategyDegradationSnapshotRecord::query()->{$method}([
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
                'exchange' => $exchange,
                'sample_size' => $sampleSize,
                'min_sample_size' => 30,
                'sample_size_sufficient' => $isSampleSizeSufficient,
                'insufficient_sample_size' => ! $isSampleSizeSufficient,
                'confidence_rating' => $confidenceRating,
                'definitive_alpha_decay' => $definitiveAlphaDecay,
                'is_fee_drag' => $isFeeDrag,
                'structural_negative_alpha' => $structuralNegativeAlpha,
                'expectancy_confidence' => [
                    'mean' => round($expectancyR, 4),
                    'standard_error' => round($expectancySe, 4),
                    'ci_lower' => round($expectancyCiLower, 4),
                    'ci_upper' => round($expectancyCiUpper, 4),
                    'confidence_level' => 0.95,
                ],
                'profit_factor_confidence' => [
                    'value' => round($profitFactor, 4),
                    'standard_error' => round($profitFactorSe, 4),
                    'ci_lower' => round($profitFactorCiLower, 4),
                    'ci_upper' => round($profitFactorCiUpper, 4),
                    'confidence_level' => 0.95,
                ],
                'financial_breakdown' => [
                    'gross_pnl' => round($totalGross, 4),
                    'net_pnl' => round($totalNet, 4),
                    'total_fees' => round($totalFees, 4),
                    'total_slippage' => round($totalSlippage, 4),
                    'total_execution_costs' => round($totalFees + $totalSlippage, 4),
                    'total_funding' => round($totalFunding, 4),
                    'gross_expectancy_usd' => round($grossExpectancyUsd, 4),
                    'net_expectancy_usd' => round($netExpectancyUsd, 4),
                ],
                'ranging_observations' => $rangingCount,
                'trending_observations' => $trendingCount,
                'regime_mismatch' => $regimeMismatch,
                'wins' => $wins,
                'losses' => $sampleSize - $wins,
                'gross_profit' => round($grossWins, 2),
                'gross_loss' => round($grossLosses, 2),
                'gross_pnl' => round($totalGross, 4),
                'net_pnl' => round($totalNet, 4),
                'fees' => round($totalFees, 4),
                'slippage' => round($totalSlippage, 4),
            ],
            'as_of_time' => CarbonImmutable::now(),
        ]);
    }
}
