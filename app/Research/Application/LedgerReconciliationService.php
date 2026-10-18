<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

final class LedgerReconciliationService
{
    public function __construct(
        private readonly PerformanceStatisticsService $statistics,
    ) {}

    /**
     * Reconcile an individual paper trading account.
     *
     * @return array<string, mixed>
     */
    public function reconcileAccount(string $accountId): array
    {
        $account = PaperAccountRecord::query()->findOrFail($accountId);
        $startingBalance = BigDecimal::of($account->starting_balance);

        // Sum executions for realized PnL and fees
        $executions = VirtualExecutionRecord::query()
            ->whereIn('position_id', VirtualPositionRecord::query()->where('paper_account_id', $accountId)->select('id'))
            ->get(['id', 'position_id', 'type', 'quantity', 'commission', 'gross_pnl']);

        $realizedGrossPnl = BigDecimal::zero();
        $totalFees = BigDecimal::zero();
        foreach ($executions as $exec) {
            $realizedGrossPnl = $realizedGrossPnl->plus(BigDecimal::of($exec->gross_pnl ?? '0'));
            $totalFees = $totalFees->plus(BigDecimal::of($exec->commission ?? '0'));
        }

        // Sum funding events
        $fundingEvents = PositionFundingEventRecord::query()
            ->whereIn('position_id', VirtualPositionRecord::query()->where('paper_account_id', $accountId)->select('id'))
            ->get(['id', 'amount']);

        $totalFunding = BigDecimal::zero();
        foreach ($fundingEvents as $fe) {
            $totalFunding = $totalFunding->plus(BigDecimal::of($fe->amount ?? '0'));
        }

        // Open positions
        $openPositions = VirtualPositionRecord::query()
            ->where('paper_account_id', $accountId)
            ->whereNull('closed_at')
            ->get(['id', 'symbol', 'remaining_quantity', 'unrealized_pnl', 'status', 'signal_id']);

        $unrealizedPnl = BigDecimal::zero();
        $discrepancies = [];

        foreach ($openPositions as $op) {
            $unrealizedPnl = $unrealizedPnl->plus(BigDecimal::of($op->unrealized_pnl ?? '0'));
            // Check for overclosed positions
            if (BigDecimal::of($op->remaining_quantity ?? '0')->isNegative()) {
                $discrepancies[] = [
                    'code' => 'OVERCLOSED_POSITION',
                    'severity' => 'CRITICAL',
                    'position_id' => $op->id,
                    'details' => "Position {$op->id} has negative quantity: {$op->remaining_quantity}.",
                ];
            }
        }

        // Check for duplicate signal-to-position fills
        $signalCounts = VirtualPositionRecord::query()
            ->where('paper_account_id', $accountId)
            ->selectRaw('signal_id, COUNT(*) as count')
            ->groupBy('signal_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($signalCounts as $sc) {
            $discrepancies[] = [
                'code' => 'DUPLICATE_SIGNAL_FILL',
                'severity' => 'CRITICAL',
                'signal_id' => $sc->signal_id,
                'details' => "Signal {$sc->signal_id} has {$sc->count} associated positions.",
            ];
        }

        // Check for orphan executions
        $positionIds = VirtualPositionRecord::query()->where('paper_account_id', $accountId)->pluck('id');
        $orphanExecutions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $positionIds->all())
            ->whereNotIn('position_id', VirtualPositionRecord::query()->select('id'))
            ->count();

        if ($orphanExecutions > 0) {
            $discrepancies[] = [
                'code' => 'ORPHAN_EXECUTIONS',
                'severity' => 'CRITICAL',
                'details' => "Found {$orphanExecutions} executions without a valid position.",
            ];
        }

        // Accounting identity:
        // Expected Balance = Starting Balance + Realized PnL - Fees + Funding
        $recalculatedRealized = $realizedGrossPnl->minus($totalFees);
        $recalculatedBalance = $startingBalance->plus($recalculatedRealized)->plus($totalFunding);
        $recalculatedEquity = $recalculatedBalance->plus($unrealizedPnl);

        $storedBalance = BigDecimal::of($account->starting_balance)->plus($account->realized_pnl);
        $storedEquity = BigDecimal::of($account->current_equity);

        // Balance & Equity discrepancy checks (tolerance $0.05 for rounding)
        $balanceDiff = $recalculatedBalance->minus($storedBalance)->abs();
        if ($balanceDiff->isGreaterThan(BigDecimal::of('0.05'))) {
            $discrepancies[] = [
                'code' => 'BALANCE_MISMATCH',
                'severity' => 'WARNING',
                'details' => "Stored balance ({$storedBalance}) differs from recalculated ({$recalculatedBalance}) by {$balanceDiff}.",
            ];
        }

        $equityDiff = $recalculatedEquity->minus($storedEquity)->abs();
        if ($equityDiff->isGreaterThan(BigDecimal::of('0.05'))) {
            $discrepancies[] = [
                'code' => 'EQUITY_MISMATCH',
                'severity' => 'WARNING',
                'details' => "Stored equity ({$storedEquity}) differs from recalculated ({$recalculatedEquity}) by {$equityDiff}.",
            ];
        }

        // Recalculate max drawdown from snapshots
        $stats = $this->statistics->calculate($accountId);
        $recalculatedDrawdown = BigDecimal::of($stats->metrics['maximum_drawdown_percent'] ?? '0');
        $storedDrawdown = BigDecimal::of($account->maximum_drawdown_percent ?? '0');

        $status = empty($discrepancies) ? 'PASSED' : 'DISCREPANCY_DETECTED';

        // Persist run record
        $record = ReconciliationRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $accountId,
            'status' => $status,
            'starting_balance' => (string) $startingBalance,
            'recalculated_balance' => (string) $recalculatedBalance,
            'stored_balance' => (string) $storedBalance,
            'recalculated_equity' => (string) $recalculatedEquity,
            'stored_equity' => (string) $storedEquity,
            'recalculated_realized_pnl' => (string) $recalculatedRealized,
            'recalculated_unrealized_pnl' => (string) $unrealizedPnl,
            'recalculated_fees' => (string) $totalFees,
            'recalculated_funding' => (string) $totalFunding,
            'recalculated_drawdown_percent' => (string) $recalculatedDrawdown,
            'stored_drawdown_percent' => (string) $storedDrawdown,
            'discrepancy_count' => count($discrepancies),
            'findings' => [
                'discrepancies' => $discrepancies,
                'open_positions_count' => $openPositions->count(),
                'total_executions_count' => $executions->count(),
                'total_funding_events_count' => $fundingEvents->count(),
                'account_name' => $account->name,
                'strategy_id' => $account->strategy_id,
            ],
            'methodology_version' => 'v1.0.0',
            'completed_at' => now('UTC'),
        ]);

        return [
            'id' => $record->id,
            'paper_account_id' => $accountId,
            'status' => $status,
            'starting_balance' => (string) $startingBalance,
            'recalculated_balance' => (string) $recalculatedBalance,
            'stored_balance' => (string) $storedBalance,
            'recalculated_equity' => (string) $recalculatedEquity,
            'stored_equity' => (string) $storedEquity,
            'recalculated_drawdown_percent' => (string) $recalculatedDrawdown,
            'stored_drawdown_percent' => (string) $storedDrawdown,
            'discrepancy_count' => count($discrepancies),
            'discrepancies' => $discrepancies,
            'completed_at' => $record->completed_at->toIso8601String(),
        ];
    }

    /**
     * Reconcile all active paper trading accounts.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcileAll(): array
    {
        $accounts = PaperAccountRecord::query()->get(['id']);
        $results = [];
        foreach ($accounts as $acc) {
            $results[] = $this->reconcileAccount($acc->id);
        }

        return $results;
    }
}
