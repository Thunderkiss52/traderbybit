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
        $query = VirtualPositionRecord::query()->where('paper_account_id', $accountId);
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
        foreach ((clone $query)->orderBy('closed_at')->orderBy('id')->cursor([
            'id', 'closed_at', 'opened_at', 'net_pnl', 'gross_pnl', 'fees', 'funding', 'r_multiple',
            'funding_complete', 'ambiguous_execution', 'awaiting_market_data',
        ]) as $position) {
            $opened++;
            $fees = $fees->plus($position->fees);
            $funding = $funding->plus($position->funding);
            $incompleteFunding += $position->funding_complete ? 0 : 1;
            $ambiguous += $position->ambiguous_execution ? 1 : 0;
            $awaitingMarketData += $position->awaiting_market_data ? 1 : 0;
            if ($position->closed_at === null || (($filters['to'] ?? null) instanceof DateTimeImmutable && $position->closed_at > $filters['to'])) {
                continue;
            }

            $closedCount++;
            $value = BigDecimal::of($position->net_pnl);
            $net = $net->plus($value);
            $grossPnl = $grossPnl->plus($position->gross_pnl);
            $holdingSeconds += $position->closed_at->getTimestamp() - $position->opened_at->getTimestamp();
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
        $slippage = VirtualExecutionRecord::query()
            ->whereIn('position_id', (clone $query)->select('id'))
            ->cursor(['quantity', 'slippage'])
            ->reduce(fn (BigDecimal $sum, VirtualExecutionRecord $execution): BigDecimal => $sum->plus(
                BigDecimal::of($execution->quantity)->multipliedBy($execution->slippage)
            ), BigDecimal::zero());
        $profitFactor = $grossLoss->isZero() ? null : (string) $grossProfit->dividedBy($grossLoss->abs(), 8, RoundingMode::HalfEven);
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
        $totalSignals = 0;
        foreach ($signalQuery->cursor(['id', 'side']) as $signal) {
            $totalSignals++;
            $sideCounts[$signal->side] = ($sideCounts[$signal->side] ?? 0) + 1;
        }
        $skip = (int) ($sideCounts['SKIP'] ?? 0);
        $accountForDrawdown = PaperAccountRecord::query()->findOrFail($accountId);
        $maximumDrawdown = $this->drawdown(
            $accountForDrawdown->starting_balance,
            EquitySnapshotRecord::query()->where('paper_account_id', $accountId)
                ->oldest('recorded_at')->oldest('id')->cursor(['equity', 'source_event_key']),
            $accountForDrawdown->current_equity
        );

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
                    EquitySnapshotRecord::query()->where('paper_account_id', $account->id)
                        ->oldest('recorded_at')->oldest('id')->cursor(['equity', 'source_event_key']),
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
            if ($peak === null || $equity->isGreaterThan($peak)) {
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
