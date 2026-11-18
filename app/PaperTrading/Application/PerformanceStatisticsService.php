<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\PerformanceReport;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final class PerformanceStatisticsService
{
    public function calculate(string $accountId, array $filters = []): PerformanceReport
    {
        $accountForDrawdown = PaperAccountRecord::query()->findOrFail($accountId);
        $maximumDrawdown = $this->drawdown(
            $accountForDrawdown->starting_balance,
            EquitySnapshotRecord::query()->performance()->where('paper_account_id', $accountId)
                ->oldest('recorded_at')->oldest('id')->toBase()->cursor(['equity', 'source_event_key', 'unrealized_pnl', 'valuation_fresh']),
            $accountForDrawdown->current_equity
        );

        $positionQuery = VirtualPositionRecord::query()->where('paper_account_id', $accountId);
        $signalQuery = SignalRecord::query()->where(function ($signals) use ($accountId): void {
            $signals->whereExists(function ($states) use ($accountId): void {
                $states->selectRaw('1')->from('paper_signal_states')
                    ->whereColumn('paper_signal_states.signal_id', 'signals.id')
                    ->where('paper_signal_states.paper_account_id', $accountId);
            })->orWhereExists(function ($events) use ($accountId): void {
                $events->selectRaw('1')->from('trading_journal_events')
                    ->whereColumn('trading_journal_events.signal_id', 'signals.id')
                    ->where('trading_journal_events.paper_account_id', $accountId)
                    ->where('trading_journal_events.event_type', 'ANALYTICAL_SKIP');
            });
        });

        return $this->evaluateQueryPerformance($positionQuery, $signalQuery, $maximumDrawdown, $filters);
    }

    public function calculateForAccounts(array $accountIds, string $exchange, array $filters = []): PerformanceReport
    {
        if (empty($accountIds)) {
            return new PerformanceReport([
                'total_signals' => 0, 'buy_signals' => 0, 'sell_signals' => 0, 'skip_signals' => 0,
                'opened_positions' => 0, 'closed_positions' => 0, 'winning_trades' => 0, 'losing_trades' => 0,
                'win_rate' => null, 'loss_rate' => null, 'average_win' => null, 'average_loss' => null,
                'average_r' => null, 'expectancy' => null, 'gross_profit' => '0', 'gross_loss' => '0',
                'profit_factor' => null, 'profit_factor_unavailable_reason' => 'no_positions',
                'gross_pnl' => '0', 'net_pnl' => '0', 'fees' => '0', 'total_commissions' => '0',
                'funding' => '0', 'total_funding_payments' => '0', 'total_slippage_cost' => '0',
                'slippage_population' => 'ALL_SELECTED_POSITIONS_DIAGNOSTIC_ONLY',
                'trade_statistics_population' => 'CLOSED_POSITIONS',
                'open_position_fees' => '0', 'open_position_funding' => '0',
                'maximum_drawdown_percent' => '0', 'average_holding_seconds' => null,
                'maximum_consecutive_wins' => 0, 'maximum_consecutive_losses' => 0,
            ], [
                'incomplete_funding_positions' => 0,
                'ambiguous_execution_positions' => 0,
                'awaiting_market_data_positions' => 0,
            ]);
        }

        $accounts = PaperAccountRecord::query()->whereIn('id', $accountIds)->get();
        $maximumDrawdown = (string) ($accounts->max('maximum_drawdown_percent') ?? '0');

        $positionQuery = VirtualPositionRecord::query()->whereIn('paper_account_id', $accountIds);
        $signalQuery = SignalRecord::query()->where(function ($signals) use ($accountIds, $exchange): void {
            $signals->whereExists(function ($states) use ($accountIds): void {
                $states->selectRaw('1')->from('paper_signal_states')
                    ->whereColumn('paper_signal_states.signal_id', 'signals.id')
                    ->whereIn('paper_signal_states.paper_account_id', $accountIds);
            })->orWhereExists(function ($events) use ($accountIds): void {
                $events->selectRaw('1')->from('trading_journal_events')
                    ->whereColumn('trading_journal_events.signal_id', 'signals.id')
                    ->whereIn('trading_journal_events.paper_account_id', $accountIds)
                    ->where('trading_journal_events.event_type', 'ANALYTICAL_SKIP');
            })->orWhere(function ($q) use ($exchange): void {
                $q->where('exchange', $exchange);
            });
        });

        return $this->evaluateQueryPerformance($positionQuery, $signalQuery, $maximumDrawdown, $filters);
    }

    private function evaluateQueryPerformance($query, $signalQuery, ?string $maximumDrawdown, array $filters): PerformanceReport
    {
        foreach (['symbol', 'side', 'quality_class', 'strategy_version'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['from'] ?? null) instanceof DateTimeImmutable) {
            $query->where('opened_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof DateTimeImmutable) {
            $query->where('opened_at', '<=', $filters['to']);
        }
        $opened = $closedCount = $winCount = $lossCount = $incompleteFunding = $ambiguous = $awaitingMarketData = 0;
        $consecutiveWins = $consecutiveLosses = $currentWins = $currentLosses = 0;
        $holdingSeconds = 0;
        $grossProfit = $grossLoss = $net = $fees = $funding = $grossPnl = $rTotal = BigDecimal::zero();
        $rCount = 0;
        $openFees = $openFunding = BigDecimal::zero();
        foreach ((clone $query)->orderBy('closed_at')->orderBy('id')->toBase()->cursor([
            'id', 'closed_at', 'opened_at', 'net_pnl', 'gross_pnl', 'fees', 'funding', 'r_multiple',
            'funding_complete', 'ambiguous_execution', 'awaiting_market_data',
        ]) as $position) {
            $opened++;
            $incompleteFunding += $position->funding_complete ? 0 : 1;
            $ambiguous += $position->ambiguous_execution ? 1 : 0;
            $awaitingMarketData += $position->awaiting_market_data ? 1 : 0;
            $closedAtTime = $position->closed_at instanceof \DateTimeInterface
                ? $position->closed_at->getTimestamp()
                : ($position->closed_at !== null ? strtotime((string) $position->closed_at) : null);
            $openedAtTime = $position->opened_at instanceof \DateTimeInterface
                ? $position->opened_at->getTimestamp()
                : ($position->opened_at !== null ? strtotime((string) $position->opened_at) : 0);

            if ($closedAtTime === null || (($filters['to'] ?? null) instanceof DateTimeImmutable && $closedAtTime > $filters['to']->getTimestamp())) {
                $openFees = $openFees->plus($position->fees);
                $openFunding = $openFunding->plus($position->funding);

                continue;
            }

            $fees = $fees->plus($position->fees);
            $funding = $funding->plus($position->funding);
            $closedCount++;
            $value = BigDecimal::of($position->net_pnl);
            $net = $net->plus($value);
            $grossPnl = $grossPnl->plus($position->gross_pnl);
            $holdingSeconds += max(0, (int) ($closedAtTime - $openedAtTime));
            if ($value->isPositive()) {
                $winCount++;
                $grossProfit = $grossProfit->plus($value);
                $currentWins++;
                $currentLosses = 0;
                $consecutiveWins = max($consecutiveWins, $currentWins);
            } elseif ($value->isNegative()) {
                $lossCount++;
                $grossLoss = $grossLoss->plus($value);
                $currentLosses++;
                $currentWins = 0;
                $consecutiveLosses = max($consecutiveLosses, $currentLosses);
            } else {
                $currentWins = $currentLosses = 0;
            }
            if ($position->r_multiple !== null) {
                $rTotal = $rTotal->plus($position->r_multiple);
                $rCount++;
            }
        }
        $slippageVal = VirtualExecutionRecord::query()
            ->whereIn('position_id', (clone $query)->select('id'))
            ->selectRaw('COALESCE(SUM(quantity * slippage), 0) as total_slippage')
            ->value('total_slippage');
        $slippage = BigDecimal::of((string) ($slippageVal ?? '0'));
        $profitFactor = $grossLoss->isZero() ? null : (string) $grossProfit->dividedBy($grossLoss->abs(), 8, RoundingMode::HalfEven);

        foreach (['symbol', 'side', 'quality_class', 'strategy_version'] as $field) {
            if (isset($filters[$field])) {
                $signalQuery->where($field, $filters[$field]);
            }
        }
        if (($filters['from'] ?? null) instanceof DateTimeImmutable) {
            $signalQuery->where('decision_time', '>=', $filters['from']);
        }
        if (($filters['to'] ?? null) instanceof DateTimeImmutable) {
            $signalQuery->where('decision_time', '<=', $filters['to']);
        }
        $sideCounts = ['BUY' => 0, 'SELL' => 0, 'SKIP' => 0];
        $sideResults = (clone $signalQuery)->selectRaw('side, COUNT(*) as cnt')->groupBy('side')->pluck('cnt', 'side');
        foreach ($sideResults as $side => $count) {
            $sideCounts[$side] = (int) $count;
        }
        $totalSignals = array_sum($sideCounts);
        $skip = (int) ($sideCounts['SKIP'] ?? 0);

        return new PerformanceReport([
            'total_signals' => $totalSignals, 'buy_signals' => (int) ($sideCounts['BUY'] ?? 0), 'sell_signals' => (int) ($sideCounts['SELL'] ?? 0), 'skip_signals' => $skip,
            'opened_positions' => $opened, 'closed_positions' => $closedCount,
            'winning_trades' => $winCount, 'losing_trades' => $lossCount,
            'win_rate' => $this->ratio($winCount, $closedCount), 'loss_rate' => $this->ratio($lossCount, $closedCount),
            'average_win' => $winCount === 0 ? null : (string) $grossProfit->dividedBy($winCount, 8, RoundingMode::HalfEven),
            'average_loss' => $lossCount === 0 ? null : (string) $grossLoss->dividedBy($lossCount, 8, RoundingMode::HalfEven),
            'average_r' => $rCount === 0 ? null : (string) $rTotal->dividedBy($rCount, 8, RoundingMode::HalfEven),
            'expectancy' => $closedCount === 0 ? null : (string) $net->dividedBy($closedCount, 8, RoundingMode::HalfEven), 'gross_profit' => (string) $grossProfit, 'gross_loss' => (string) $grossLoss,
            'profit_factor' => $profitFactor, 'profit_factor_unavailable_reason' => $profitFactor === null ? 'no_negative_net_pnl' : null,
            'gross_pnl' => (string) $grossPnl, 'net_pnl' => (string) $net,
            'fees' => (string) $fees, 'total_commissions' => (string) $fees,
            'funding' => (string) $funding, 'total_funding_payments' => (string) $funding,
            'total_slippage_cost' => (string) $slippage,
            'slippage_population' => 'ALL_SELECTED_POSITIONS_DIAGNOSTIC_ONLY',
            'trade_statistics_population' => 'CLOSED_POSITIONS',
            'open_position_fees' => (string) $openFees,
            'open_position_funding' => (string) $openFunding,
            'maximum_drawdown_percent' => $maximumDrawdown,
            'average_holding_seconds' => $closedCount === 0 ? null : (int) round($holdingSeconds / $closedCount),
            'maximum_consecutive_wins' => $consecutiveWins, 'maximum_consecutive_losses' => $consecutiveLosses,
        ], [
            'incomplete_funding_positions' => $incompleteFunding,
            'ambiguous_execution_positions' => $ambiguous,
            'awaiting_market_data_positions' => $awaitingMarketData,
        ]);
    }

    public function accountSummary(PaperAccountRecord $account): array
    {
        $starting = BigDecimal::of($account->starting_balance);
        $realized = BigDecimal::of($account->realized_pnl);
        $unrealized = BigDecimal::of($account->unrealized_pnl);
        $net = $realized->plus($unrealized);
        $return = $starting->isZero()
            ? null
            : (string) $net->multipliedBy(100)->dividedBy($starting, 8, RoundingMode::HalfEven);

        return [
            'account_id' => $account->id,
            'mode' => 'FORWARD_PAPER',
            'starting_balance' => $account->starting_balance,
            'current_balance' => (string) $starting->plus($realized),
            'current_equity' => $account->current_equity,
            'available_virtual_capital' => (string) BigDecimal::of($account->current_equity)->minus($account->reserved_risk),
            'realized_pnl' => $account->realized_pnl,
            'unrealized_pnl' => $account->unrealized_pnl,
            'total_net_pnl' => (string) $net,
            'total_return_percent' => $return,
            'reserved_risk' => $account->reserved_risk,
            'maximum_drawdown_percent' => (string) BigDecimal::max(
                $account->maximum_drawdown_percent,
                $this->drawdown(
                    $account->starting_balance,
                    EquitySnapshotRecord::query()->performance()->where('paper_account_id', $account->id)
                        ->oldest('recorded_at')->oldest('id')->toBase()->cursor(['equity', 'source_event_key', 'unrealized_pnl', 'valuation_fresh']),
                    $account->current_equity
                )
            ),
            'status' => $account->status,
        ];
    }

    private function ratio(int $part, int $total): ?string
    {
        return $total === 0 ? null : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($total, 8, RoundingMode::HalfEven);
    }

    private function drawdown(string $startingEquity, iterable $equities, ?string $currentEquity = null): string
    {
        $peak = BigDecimal::of($startingEquity);
        $maximum = BigDecimal::zero();
        $hasActualValuation = false;
        foreach ($equities as $value) {
            if (($value->source_event_key ?? null) === 'account:initial' && $hasActualValuation) {
                // Historical imported valuations can predate account creation in fixtures.
                continue;
            }
            if (($value->source_event_key ?? null) !== 'account:initial') {
                $hasActualValuation = true;
            }
            $equity = BigDecimal::of((string) ($value->equity ?? $value));

            // Valuation provenance guard:
            // An equity snapshot can elevate the historical peak only if its valuation is verified:
            // 1. Flat snapshots with zero unrealized PnL (FLAT_VERIFIED) - equity is 100% realized and cash-backed.
            // 2. Snapshots with valuation_fresh === true (FRESH) - contemporaneous market mark (<= 300s).
            // Snapshots with valuation_fresh === false (STALE) or valuation_fresh === null with non-zero unrealized PnL
            // (LEGACY_UNKNOWN) represent unverified floating mark-to-market valuations and must not elevate peak equity.
            $canElevatePeak = true;
            if (is_object($value)) {
                $valuationFresh = $value->valuation_fresh ?? null;
                $hasUnrealized = isset($value->unrealized_pnl);
                $unrealizedIsZero = $hasUnrealized && BigDecimal::of((string) $value->unrealized_pnl)->isZero();

                if ($valuationFresh === false) {
                    $canElevatePeak = false;
                } elseif ($valuationFresh === null && $hasUnrealized && ! $unrealizedIsZero) {
                    $canElevatePeak = false;
                }
            }

            if ($canElevatePeak && ($peak === null || $equity->isGreaterThan($peak))) {
                $peak = $equity;
            }
            if (! $peak->isZero()) {
                $drawdown = $peak->minus($equity)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
                if ($drawdown->isGreaterThan($maximum)) {
                    $maximum = $drawdown;
                }
            }
        }

        // Recent account equity may not have been checkpointed yet. Never display a
        // drawdown below the decline already visible in the current portfolio.
        if ($currentEquity !== null) {
            $current = BigDecimal::of($currentEquity);
            if ($current->isGreaterThan($peak)) {
                $peak = $current;
            }
            if (! $peak->isZero()) {
                $drop = $peak->minus($current)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
                $maximum = BigDecimal::max($maximum, $drop);
            }
        }

        return (string) $maximum->toScale(8, RoundingMode::HalfEven);
    }
}
