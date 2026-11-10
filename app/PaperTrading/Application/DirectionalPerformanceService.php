<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

final readonly class DirectionalPerformanceService
{
    /**
     * Compute independent directional and quality/risk attribution metrics.
     *
     * @param array{
     *     account_id?: string|null,
     *     exchange_id?: string|null,
     *     strategy_id?: string|null,
     *     symbol?: string|null
     * } $criteria
     * @return array<string, mixed>
     */
    public function analyze(array $criteria = []): array
    {
        $accountId = $criteria['account_id'] ?? null;
        $exchangeId = $criteria['exchange_id'] ?? null;
        $strategyId = $criteria['strategy_id'] ?? null;
        $symbol = $criteria['symbol'] ?? null;

        // 1. Overall & Directional Splits (LONG vs SHORT)
        $longMetrics = $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, 'BUY');
        $shortMetrics = $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, 'SELL');
        $overallMetrics = $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null);

        // 2. Breakdown by Signal Quality (HIGH, MEDIUM, LOW, UNKNOWN)
        $qualityBreakdown = [
            'HIGH' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, quality: 'HIGH'),
            'MEDIUM' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, quality: 'MEDIUM'),
            'LOW' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, quality: 'LOW'),
            'UNKNOWN' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, quality: 'UNKNOWN'),
        ];

        // 3. Breakdown by Trade Risk (LOW, MEDIUM, HIGH, UNKNOWN)
        $riskBreakdown = [
            'LOW' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, risk: 'LOW'),
            'MEDIUM' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, risk: 'MEDIUM'),
            'HIGH' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, risk: 'HIGH'),
            'UNKNOWN' => $this->calculateCohortMetrics($accountId, $exchangeId, $strategyId, $symbol, null, risk: 'UNKNOWN'),
        ];

        // 4. Loss Concentration Analysis
        $lossConcentration = $this->analyzeLossConcentration($longMetrics, $shortMetrics, $qualityBreakdown, $riskBreakdown);

        return [
            'criteria' => $criteria,
            'overall' => $overallMetrics,
            'directional' => [
                'LONG' => $longMetrics,
                'SHORT' => $shortMetrics,
            ],
            'by_quality' => $qualityBreakdown,
            'by_risk' => $riskBreakdown,
            'loss_concentration' => $lossConcentration,
        ];
    }

    private function calculateCohortMetrics(
        ?string $accountId,
        ?string $exchangeId,
        ?string $strategyId,
        ?string $symbol,
        ?string $side = null,
        ?string $quality = null,
        ?string $risk = null
    ): array {
        // Query positions
        $posQuery = VirtualPositionRecord::query();
        if ($accountId !== null && $accountId !== '') {
            $posQuery->where('paper_account_id', $accountId);
        }
        if ($exchangeId !== null && $exchangeId !== '') {
            $posQuery->where('exchange_id', $exchangeId);
        }
        if ($strategyId !== null && $strategyId !== '') {
            $posQuery->whereIn('paper_account_id', function ($q) use ($strategyId): void {
                $q->select('id')->from('paper_accounts')->where('strategy_id', $strategyId)->orWhere('primary_strategy_id', $strategyId);
            });
        }
        if ($symbol !== null && $symbol !== '') {
            $posQuery->where('symbol', $symbol);
        }
        if ($side !== null) {
            $posQuery->where('side', $side);
        }
        if ($quality !== null) {
            if ($quality === 'UNKNOWN') {
                $posQuery->where(function (Builder $q): void {
                    $q->whereNull('signal_quality')->orWhere('signal_quality', 'UNKNOWN');
                });
            } else {
                $posQuery->where(function (Builder $q) use ($quality): void {
                    $q->where('signal_quality', $quality)
                        ->orWhere('quality_class', match ($quality) {
                            'HIGH' => 'A',
                            'MEDIUM' => 'B',
                            'LOW' => 'C',
                            default => null,
                        });
                });
            }
        }
        if ($risk !== null) {
            if ($risk === 'UNKNOWN') {
                $posQuery->where(function (Builder $q): void {
                    $q->whereNull('trade_risk')->orWhere('trade_risk', 'UNKNOWN');
                });
            } else {
                $posQuery->where('trade_risk', $risk);
            }
        }

        // Aggregate closed positions
        $openedPositions = (clone $posQuery)->count();
        $closedQuery = (clone $posQuery)->whereNotNull('closed_at');
        $closedPositions = (clone $closedQuery)->count();

        $grossProfit = BigDecimal::zero();
        $grossLoss = BigDecimal::zero();
        $totalNet = BigDecimal::zero();
        $totalGross = BigDecimal::zero();
        $totalFees = BigDecimal::zero();
        $totalFunding = BigDecimal::zero();
        $rTotal = BigDecimal::zero();
        $rCount = 0;
        $winCount = 0;
        $lossCount = 0;
        $currentWins = 0;
        $currentLosses = 0;
        $maxConsecutiveWins = 0;
        $maxConsecutiveLosses = 0;

        foreach ($closedQuery->orderBy('closed_at')->orderBy('id')->cursor() as $pos) {
            $net = BigDecimal::of((string) ($pos->net_pnl ?? '0'));
            $gross = BigDecimal::of((string) ($pos->gross_pnl ?? '0'));
            $fee = BigDecimal::of((string) ($pos->fees ?? '0'));
            $fund = BigDecimal::of((string) ($pos->funding ?? '0'));

            $totalNet = $totalNet->plus($net);
            $totalGross = $totalGross->plus($gross);
            $totalFees = $totalFees->plus($fee);
            $totalFunding = $totalFunding->plus($fund);

            if ($net->isPositive()) {
                $winCount++;
                $grossProfit = $grossProfit->plus($net);
                $currentWins++;
                $currentLosses = 0;
                $maxConsecutiveWins = max($maxConsecutiveWins, $currentWins);
            } elseif ($net->isNegative()) {
                $lossCount++;
                $grossLoss = $grossLoss->plus($net->abs());
                $currentLosses++;
                $currentWins = 0;
                $maxConsecutiveLosses = max($maxConsecutiveLosses, $currentLosses);
            } else {
                $currentWins = 0;
                $currentLosses = 0;
            }

            if ($pos->r_multiple !== null && is_numeric($pos->r_multiple)) {
                $rTotal = $rTotal->plus((string) $pos->r_multiple);
                $rCount++;
            }
        }

        $winRate = $closedPositions > 0 ? (float) round(($winCount / $closedPositions) * 100, 2) : null;
        $profitFactor = $grossLoss->isPositive()
            ? (float) round($grossProfit->dividedBy($grossLoss, 4, RoundingMode::HalfEven)->toFloat(), 2)
            : ($grossProfit->isPositive() ? 999.99 : null);
        $expectancy = $closedPositions > 0
            ? (string) $totalNet->dividedBy($closedPositions, 4, RoundingMode::HalfEven)
            : null;
        $averageR = $rCount > 0
            ? (float) round($rTotal->dividedBy($rCount, 4, RoundingMode::HalfEven)->toFloat(), 2)
            : null;

        // Slippage cost aggregation without double counting
        $slippageCost = VirtualExecutionRecord::query()
            ->whereIn('position_id', (clone $closedQuery)->select('id'))
            ->cursor(['quantity', 'slippage'])
            ->reduce(static fn (BigDecimal $sum, VirtualExecutionRecord $exec): BigDecimal => $sum->plus(
                BigDecimal::of((string) $exec->quantity)->multipliedBy((string) $exec->slippage)
            ), BigDecimal::zero());

        // Count signals & observations
        $sigQuery = SignalRecord::query();
        if ($exchangeId !== null && $exchangeId !== '') {
            $sigQuery->where('exchange', $exchangeId);
        }
        if ($symbol !== null && $symbol !== '') {
            $sigQuery->where('symbol', $symbol);
        }
        if ($strategyId !== null && $strategyId !== '') {
            $sigQuery->where('strategy_id', $strategyId);
        }
        if ($side !== null) {
            $sigQuery->where('side', $side);
        }
        $totalSignals = (clone $sigQuery)->whereIn('side', ['BUY', 'SELL'])->count();

        // Risk rejections count
        $riskRejections = RiskDecisionRecord::query()
            ->when($accountId, fn ($q) => $q->where('paper_account_id', $accountId))
            ->when($exchangeId, fn ($q) => $q->where('exchange_id', $exchangeId))
            ->when($strategyId, fn ($q) => $q->whereIn('paper_account_id', function ($sub) use ($strategyId): void {
                $sub->select('id')->from('paper_accounts')->where('strategy_id', $strategyId)->orWhere('primary_strategy_id', $strategyId);
            }))
            ->where('allowed', false)
            ->count();

        return [
            'opened_positions' => $openedPositions,
            'closed_positions' => $closedPositions,
            'winning_trades' => $winCount,
            'losing_trades' => $lossCount,
            'win_rate' => $winRate,
            'win_rate_percent' => $winRate,
            'profit_factor' => $profitFactor,
            'gross_profit' => (string) $grossProfit,
            'gross_loss' => (string) $grossLoss,
            'gross_pnl' => (string) $totalGross,
            'net_pnl' => (string) $totalNet,
            'total_net_pnl' => (string) $totalNet,
            'fees' => (string) $totalFees,
            'total_fees' => (string) $totalFees,
            'funding' => (string) $totalFunding,
            'slippage' => (string) $slippageCost,
            'expectancy' => $expectancy,
            'average_r' => $averageR,
            'avg_r' => $averageR,
            'max_consecutive_wins' => $maxConsecutiveWins,
            'max_consecutive_losses' => $maxConsecutiveLosses,
            'total_signals' => $totalSignals,
            'risk_rejections' => $riskRejections,
            'sample_size' => $closedPositions,
            'statistically_significant' => $closedPositions >= 30,
        ];
    }

    private function analyzeLossConcentration(
        array $long,
        array $short,
        array $byQuality,
        array $byRisk
    ): array {
        $longLoss = BigDecimal::of((string) ($long['gross_loss'] ?? '0'));
        $shortLoss = BigDecimal::of((string) ($short['gross_loss'] ?? '0'));
        $totalLoss = $longLoss->plus($shortLoss);

        $longLossPct = $totalLoss->isPositive() ? round($longLoss->dividedBy($totalLoss, 4, RoundingMode::HalfEven)->multipliedBy(100)->toFloat(), 1) : 0.0;
        $shortLossPct = $totalLoss->isPositive() ? round($shortLoss->dividedBy($totalLoss, 4, RoundingMode::HalfEven)->multipliedBy(100)->toFloat(), 1) : 0.0;

        $dominantLossDirection = $longLoss->isGreaterThan($shortLoss) ? 'LONG' : ($shortLoss->isGreaterThan($longLoss) ? 'SHORT' : 'BALANCED');

        // Quality concentration
        $qualityLosses = [];
        foreach ($byQuality as $qKey => $qData) {
            $qualityLosses[$qKey] = (string) ($qData['gross_loss'] ?? '0');
        }

        // Risk concentration
        $riskLosses = [];
        foreach ($byRisk as $rKey => $rData) {
            $riskLosses[$rKey] = (string) ($rData['gross_loss'] ?? '0');
        }

        return [
            'total_gross_loss' => (string) $totalLoss,
            'long_loss' => (string) $longLoss,
            'short_loss' => (string) $shortLoss,
            'long_loss_percent' => $longLossPct,
            'short_loss_percent' => $shortLossPct,
            'dominant_loss_direction' => $dominantLossDirection,
            'losses_by_quality' => $qualityLosses,
            'losses_by_risk' => $riskLosses,
            'sample_size_warning' => ($long['closed_positions'] + $short['closed_positions']) < 30,
        ];
    }
}
