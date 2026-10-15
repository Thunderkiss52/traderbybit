<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\PerformanceReport;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
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
        $positions = $query->oldest('closed_at')->get();
        $closed = $positions->whereNotNull('closed_at')
            ->filter(fn ($position): bool => ! (($filters['to'] ?? null) instanceof DateTimeImmutable) || $position->closed_at <= $filters['to'])
            ->values();
        $wins = $closed->filter(fn ($p) => BigDecimal::of($p->net_pnl)->isPositive());
        $losses = $closed->filter(fn ($p) => BigDecimal::of($p->net_pnl)->isNegative());
        $grossProfit = $this->sum($wins->pluck('net_pnl')->all());
        $grossLoss = $this->sum($losses->pluck('net_pnl')->all());
        $net = $this->sum($closed->pluck('net_pnl')->all());
        $fees = $this->sum($positions->pluck('fees')->all());
        $funding = $this->sum($positions->pluck('funding')->all());
        $grossPnl = $this->sum($closed->pluck('gross_pnl')->all());
        $positionIds = $positions->pluck('id');
        $slippage = VirtualExecutionRecord::query()->whereIn('position_id', $positionIds)->get(['quantity', 'slippage'])
            ->reduce(fn (BigDecimal $sum, VirtualExecutionRecord $execution): BigDecimal => $sum->plus(
                BigDecimal::of($execution->quantity)->multipliedBy($execution->slippage)
            ), BigDecimal::zero());
        $profitFactor = $grossLoss->isZero() ? null : (string) $grossProfit->dividedBy($grossLoss->abs(), 8, RoundingMode::HalfEven);
        [$consecutiveWins, $consecutiveLosses] = $this->streaks($closed->all());
        $acceptedIds = PaperSignalStateRecord::query()->where('paper_account_id', $accountId)->pluck('signal_id');
        $skipIds = JournalEventRecord::query()->where('paper_account_id', $accountId)->where('event_type', 'ANALYTICAL_SKIP')->pluck('signal_id');
        $signalQuery = SignalRecord::query()->whereIn('id', $acceptedIds->merge($skipIds)->unique());
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
        $signalRows = $signalQuery->get(['id', 'side']);
        $totalSignals = $signalRows->count();
        $sideCounts = $signalRows->countBy('side');
        $skip = (int) ($sideCounts['SKIP'] ?? 0);
        $snapshots = EquitySnapshotRecord::query()->where('paper_account_id', $accountId)->oldest('recorded_at')->oldest('id')->get();

        return new PerformanceReport([
            'total_signals' => $totalSignals, 'buy_signals' => (int) ($sideCounts['BUY'] ?? 0), 'sell_signals' => (int) ($sideCounts['SELL'] ?? 0), 'skip_signals' => $skip,
            'opened_positions' => $positions->count(), 'closed_positions' => $closed->count(),
            'winning_trades' => $wins->count(), 'losing_trades' => $losses->count(),
            'win_rate' => $this->ratio($wins->count(), $closed->count()), 'loss_rate' => $this->ratio($losses->count(), $closed->count()),
            'average_win' => $this->average($wins->pluck('net_pnl')->all()), 'average_loss' => $this->average($losses->pluck('net_pnl')->all()),
            'average_r' => $this->average($closed->pluck('r_multiple')->filter(fn ($v) => $v !== null)->all()),
            'expectancy' => $this->average($closed->pluck('net_pnl')->all()), 'gross_profit' => (string) $grossProfit, 'gross_loss' => (string) $grossLoss,
            'profit_factor' => $profitFactor, 'profit_factor_unavailable_reason' => $profitFactor === null ? 'no_negative_net_pnl' : null,
            'gross_pnl' => (string) $grossPnl, 'net_pnl' => (string) $net,
            'fees' => (string) $fees, 'total_commissions' => (string) $fees,
            'funding' => (string) $funding, 'total_funding_payments' => (string) $funding,
            'total_slippage_cost' => (string) $slippage,
            'maximum_drawdown_percent' => $this->drawdown($snapshots->pluck('equity')->all()),
            'average_holding_seconds' => $closed->isEmpty() ? null : (int) round($closed->average(fn ($p) => $p->closed_at->getTimestamp() - $p->opened_at->getTimestamp())),
            'maximum_consecutive_wins' => $consecutiveWins, 'maximum_consecutive_losses' => $consecutiveLosses,
        ], [
            'incomplete_funding_positions' => $positions->where('funding_complete', false)->count(),
            'ambiguous_execution_positions' => $positions->where('ambiguous_execution', true)->count(),
            'awaiting_market_data_positions' => $positions->where('awaiting_market_data', true)->count(),
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
            'maximum_drawdown_percent' => $account->maximum_drawdown_percent,
            'status' => $account->status,
        ];
    }

    private function sum(array $values): BigDecimal
    {
        return array_reduce($values, fn (BigDecimal $sum, $v): BigDecimal => $sum->plus((string) $v), BigDecimal::zero());
    }

    private function average(array $values): ?string
    {
        return $values === [] ? null : (string) $this->sum($values)->dividedBy(count($values), 8, RoundingMode::HalfEven);
    }

    private function ratio(int $part, int $total): ?string
    {
        return $total === 0 ? null : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($total, 8, RoundingMode::HalfEven);
    }

    private function drawdown(array $equities): string
    {
        $peak = null;
        $maximum = BigDecimal::zero();
        foreach ($equities as $value) {
            $equity = BigDecimal::of((string) $value);
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

        return (string) $maximum->toScale(8, RoundingMode::HalfEven);
    }

    private function streaks(array $positions): array
    {
        $wins = $losses = $maxWins = $maxLosses = 0;
        foreach ($positions as $position) {
            if (BigDecimal::of($position->net_pnl)->isPositive()) {
                $wins++;
                $losses = 0;
                $maxWins = max($maxWins, $wins);
            } elseif (BigDecimal::of($position->net_pnl)->isNegative()) {
                $losses++;
                $wins = 0;
                $maxLosses = max($maxLosses, $losses);
            } else {
                $wins = $losses = 0;
            }
        }

        return [$maxWins, $maxLosses];
    }
}
