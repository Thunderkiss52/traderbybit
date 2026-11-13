<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\HistoricalData\UniversalAcquisitionService;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Computes strictly point-in-time, pre-entry strategy performance evidence.
 * NEVER uses future candles or retrospective outcomes.
 */
final class PreEntryStrategyEvidenceService
{
    /**
     * Compute historical performance evidence available strictly prior to the entry timestamp.
     *
     * @return array{
     *     sample_size: int,
     *     expected_net_edge: float|null,
     *     average_r: float|null,
     *     win_rate: float|null,
     *     profit_factor: float|null,
     *     directional_sample_size: int,
     *     directional_win_rate: float|null,
     *     directional_profit_factor: float|null,
     *     stale_evidence: bool,
     *     status: string,
     *     rejection_reason: string|null
     * }
     */
    public function computePreEntryEvidence(
        string $strategyId,
        string $exchangeId,
        string $symbol,
        string $side,
        DateTimeImmutable $asOf,
        ?string $strategyVersion = null,
        ?string $sourceTimeframe = null,
    ): array {
        if (! Schema::hasTable('virtual_positions') || ! Schema::hasTable('paper_accounts')) {
            return $this->insufficientEvidenceResult('INSUFFICIENT_SAMPLE_SIZE');
        }

        // 1. Query closed positions for this strategy strictly prior to asOf
        $positions = VirtualPositionRecord::query()
            ->whereIn('paper_account_id', function ($query) use ($strategyId, $exchangeId): void {
                $query->select('id')
                    ->from('paper_accounts')
                    ->where(function ($q) use ($strategyId): void {
                        $q->where('strategy_id', $strategyId)
                            ->orWhere('primary_strategy_id', $strategyId);
                    })
                    ->where('exchange_id', $exchangeId);
            })
            ->where('symbol', strtoupper($symbol))
            ->where('side', strtoupper($side))
            ->when($strategyVersion !== null, fn ($query) => $query->where('strategy_version', $strategyVersion))
            ->when($sourceTimeframe !== null, function ($query) use ($sourceTimeframe): void {
                $interval = UniversalAcquisitionService::normalizeInterval($sourceTimeframe);
                $aliases = array_unique([$interval, UniversalAcquisitionService::displayInterval($interval), strtolower(trim($sourceTimeframe))]);
                $query->whereExists(function ($signals) use ($aliases): void {
                    $signals->selectRaw('1')->from('signals')
                        ->whereColumn('signals.id', 'virtual_positions.signal_id')
                        ->whereIn('signals.timeframe', $aliases);
                });
            })
            ->whereNotNull('closed_at')
            ->where('funding_complete', true)
            ->where('closed_at', '<', $asOf)
            ->where('created_at', '<=', $asOf)
            ->where('updated_at', '<=', $asOf)
            ->orderByDesc('closed_at')
            ->limit(1000)
            ->get(['id', 'side', 'net_pnl', 'gross_pnl', 'fees', 'funding', 'r_multiple', 'closed_at']);

        // Never augment a small cohort with data from other venues, symbols, or directions.
        $sampleSize = $positions->count();
        if ($sampleSize < 10) {
            return $this->insufficientEvidenceResult('INSUFFICIENT_SAMPLE_SIZE', $sampleSize);
        }

        // Check if latest trade is stale (> 30 days prior to asOf)
        $latestClose = $positions->max('closed_at');
        $isStale = false;
        if ($latestClose !== null) {
            $closeTime = CarbonImmutable::parse($latestClose);
            if ($closeTime->diffInDays(CarbonImmutable::instance($asOf)) > 30) {
                $isStale = true;
            }
        }

        // Aggregate overall metrics
        $totalNet = BigDecimal::zero();
        $grossProfit = BigDecimal::zero();
        $grossLoss = BigDecimal::zero();
        $winCount = 0;
        $rTotal = BigDecimal::zero();
        $rCount = 0;

        foreach ($positions as $pos) {
            $net = BigDecimal::of((string) ($pos->net_pnl ?? '0'));
            $totalNet = $totalNet->plus($net);

            if ($net->isPositive()) {
                $winCount++;
                $grossProfit = $grossProfit->plus($net);
            } elseif ($net->isNegative()) {
                $grossLoss = $grossLoss->plus($net->abs());
            }

            if ($pos->r_multiple !== null && is_numeric($pos->r_multiple)) {
                $rTotal = $rTotal->plus((string) $pos->r_multiple);
                $rCount++;
            }
        }

        $expectedNetEdge = (float) $totalNet->dividedBy($sampleSize, 4, RoundingMode::HalfEven)->toFloat();
        $winRate = (float) round($winCount / $sampleSize, 4);
        $profitFactor = $grossLoss->isPositive()
            ? (float) round($grossProfit->dividedBy($grossLoss, 4, RoundingMode::HalfEven)->toFloat(), 2)
            : null;
        $averageR = $rCount > 0 ? (float) round($rTotal->dividedBy($rCount, 4, RoundingMode::HalfEven)->toFloat(), 2) : null;

        // Aggregate directional cohort metrics
        $dirPositions = $positions->where('side', $side);
        $dirSampleSize = $dirPositions->count();
        $dirWinRate = null;
        $dirProfitFactor = null;

        if ($dirSampleSize > 0) {
            $dirWins = 0;
            $dirGrossProfit = BigDecimal::zero();
            $dirGrossLoss = BigDecimal::zero();

            foreach ($dirPositions as $dp) {
                $dNet = BigDecimal::of((string) ($dp->net_pnl ?? '0'));
                if ($dNet->isPositive()) {
                    $dirWins++;
                    $dirGrossProfit = $dirGrossProfit->plus($dNet);
                } elseif ($dNet->isNegative()) {
                    $dirGrossLoss = $dirGrossLoss->plus($dNet->abs());
                }
            }

            $dirWinRate = (float) round($dirWins / $dirSampleSize, 4);
            $dirProfitFactor = $dirGrossLoss->isPositive()
                ? (float) round($dirGrossProfit->dividedBy($dirGrossLoss, 4, RoundingMode::HalfEven)->toFloat(), 2)
                : null;
        }

        $rejectionReason = null;
        $status = 'VALIDATED';

        if ($isStale) {
            $status = 'STALE';
            $rejectionReason = 'STALE_PERFORMANCE_EVIDENCE';
        } elseif ($expectedNetEdge <= 0.0) {
            $status = 'NEGATIVE_EDGE';
            $rejectionReason = 'INSUFFICIENT_VALIDATED_EDGE';
        }

        return [
            'sample_size' => $sampleSize,
            'expected_net_edge' => $expectedNetEdge,
            'average_r' => $averageR,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'directional_sample_size' => $dirSampleSize,
            'directional_win_rate' => $dirWinRate,
            'directional_profit_factor' => $dirProfitFactor,
            'stale_evidence' => $isStale,
            'status' => $status,
            'rejection_reason' => $rejectionReason,
        ];
    }

    private function insufficientEvidenceResult(string $reason, int $sampleSize = 0): array
    {
        return [
            'sample_size' => $sampleSize,
            'expected_net_edge' => null,
            'average_r' => null,
            'win_rate' => null,
            'profit_factor' => null,
            'directional_sample_size' => 0,
            'directional_win_rate' => null,
            'directional_profit_factor' => null,
            'stale_evidence' => false,
            'status' => 'INSUFFICIENT_DATA',
            'rejection_reason' => $reason,
        ];
    }
}
