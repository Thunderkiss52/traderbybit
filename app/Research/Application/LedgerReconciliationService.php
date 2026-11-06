<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        return DB::transaction(fn (): array => $this->reconcileLockedAccount($accountId));
    }

    private function reconcileLockedAccount(string $accountId): array
    {
        $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($accountId);
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

        // An orphan has no attributable account: do not infer ownership from its exchange.
        $globalIntegrity = $this->globalExecutionIntegrity();
        $incompleteReasons = [];
        if ($globalIntegrity['orphan_execution_count'] > 0) {
            $incompleteReasons[] = 'global_orphan_executions_unattributable';
        }
        if (! EquitySnapshotRecord::query()->performance()->where('paper_account_id', $accountId)->openingCapital()->exists()) {
            $incompleteReasons[] = 'initial_equity_snapshot_unavailable';
        }
        $missingEntries = VirtualPositionRecord::query()->where('paper_account_id', $accountId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('virtual_executions')
                ->whereColumn('virtual_executions.position_id', 'virtual_positions.id')->where('type', 'ENTRY'))->count();
        if ($missingEntries > 0) {
            $incompleteReasons[] = 'position_entry_executions_unavailable';
        }
        $incompleteFundingPositions = VirtualPositionRecord::query()->where('paper_account_id', $accountId)->where('funding_complete', false)->get();
        if ($incompleteFundingPositions->isNotEmpty()) {
            $incompleteReasons[] = 'funding_history_incomplete';
        }

        // Check for funding gaps across positions of this paper account
        $accountPositions = VirtualPositionRecord::query()->where('paper_account_id', $accountId)->get();
        $lifecycle = app(PositionLifecycleService::class);
        $hasFundingGap = false;

        foreach ($accountPositions as $pos) {
            $status = PositionStatus::tryFrom($pos->status);
            $toTime = ($status?->isClosed() && $pos->closed_at !== null)
                ? $pos->closed_at->toDateTimeImmutable()
                : now('UTC')->toDateTimeImmutable();

            $expectedBoundaries = $lifecycle->expectedFundingBoundaries(
                $pos->opened_at->toDateTimeImmutable(),
                $toTime,
                $pos->exchange_id,
                $pos->symbol,
            );

            if (! empty($expectedBoundaries)) {
                $events = MarketFundingEventRecord::query()
                    ->where('exchange', $pos->exchange_id)
                    ->where('category', 'linear')
                    ->where('symbol', $pos->symbol)
                    ->where('funding_at', '>', $pos->opened_at)
                    ->where('funding_at', '<=', $toTime)
                    ->get();

                foreach ($expectedBoundaries as $expectedBoundary) {
                    $hasMatching = $events->contains(function (MarketFundingEventRecord $ev) use ($expectedBoundary): bool {
                        return abs($ev->funding_at->getTimestamp() - $expectedBoundary->getTimestamp()) <= 60;
                    });
                    if (! $hasMatching) {
                        $hasFundingGap = true;
                        if ($pos->funding_complete) {
                            $pos->update(['funding_complete' => false]);
                        }
                        break 2;
                    }
                }
            }
        }

        if ($hasFundingGap) {
            if (! in_array('funding_history_incomplete', $incompleteReasons, true)) {
                $incompleteReasons[] = 'funding_history_incomplete';
            }
            $incompleteReasons[] = 'funding_gap_detected';
            Log::warning("funding_gap_detected during ledger reconciliation for account {$accountId}", [
                'diagnostic' => 'funding_gap_detected',
                'paper_account_id' => $accountId,
            ]);
        }

        // Accounting identity:
        // Expected Balance = Starting Balance + Realized PnL (where Realized PnL = Gross - Fees + Funding)
        $recalculatedRealized = $realizedGrossPnl->minus($totalFees)->plus($totalFunding);
        $recalculatedBalance = $startingBalance->plus($recalculatedRealized);
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

        // Observed snapshots provide a lower bound. A larger stored historical maximum
        // may be legitimate and is never reduced or treated as corruption merely by comparison.
        if ($recalculatedDrawdown->minus($storedDrawdown)->isGreaterThan('0.00000001')) {
            $discrepancies[] = ['code' => 'HISTORICAL_DRAWDOWN_UNDERSTATED', 'severity' => 'WARNING',
                'observed_lower_bound' => (string) $recalculatedDrawdown, 'stored_maximum' => (string) $storedDrawdown];
        }
        $currentDrawdown = BigDecimal::of($account->current_drawdown_percent);
        if ($currentDrawdown->isGreaterThan($storedDrawdown)) {
            $discrepancies[] = ['code' => 'CURRENT_DRAWDOWN_EXCEEDS_HISTORICAL_MAXIMUM', 'severity' => 'WARNING'];
        }
        $status = $discrepancies !== [] ? 'DISCREPANCY_DETECTED' : ($incompleteReasons !== [] ? 'INCOMPLETE' : 'PASSED');

        $legacyUnverifiedPeaks = EquitySnapshotRecord::query()
            ->where('paper_account_id', $accountId)
            ->whereNull('valuation_fresh')
            ->where('unrealized_pnl', '!=', 0)
            ->where('equity', '>', $account->peak_equity ?? $account->starting_balance)
            ->count();

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
                'incomplete_reasons' => $incompleteReasons,
                'global_execution_integrity' => $globalIntegrity,
                'drawdown_comparison' => 'OBSERVED_LOWER_BOUND_NOT_FULL_HISTORY',
                'legacy_unverified_peaks_count' => $legacyUnverifiedPeaks,
                'current_drawdown_percent' => (string) $currentDrawdown,
                'discrepancies' => $discrepancies,
                'open_positions_count' => $openPositions->count(),
                'total_executions_count' => $executions->count(),
                'total_funding_events_count' => $fundingEvents->count(),
                'account_name' => $account->name,
                'strategy_id' => $account->strategy_id,
            ],
            'methodology_version' => 'v1.1.0',
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
            'incomplete_reasons' => $incompleteReasons,
            'global_execution_integrity' => $globalIntegrity,
            'findings' => $record->findings,
            'completed_at' => $record->completed_at->toIso8601String(),
        ];
    }

    /** Global relational integrity, without inventing an account for a missing parent. */
    public function globalExecutionIntegrity(): array
    {
        $count = VirtualExecutionRecord::query()->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('virtual_positions')->whereColumn('virtual_positions.id', 'virtual_executions.position_id'))->count();

        return ['scope' => 'GLOBAL_UNATTRIBUTED', 'status' => $count === 0 ? 'PASSED' : 'DISCREPANCY_DETECTED', 'orphan_execution_count' => $count];
    }

    /**
     * Reconcile account transactions, boundary fills, and equity over a specific time window [start, end).
     *
     * @return array<string, mixed>
     */
    public function reconcileWindow(string $accountId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $account = PaperAccountRecord::query()->findOrFail($accountId);
        $positionIds = VirtualPositionRecord::query()
            ->where('paper_account_id', $accountId)
            ->pluck('id')
            ->all();

        // In-window executions
        $inWindowExecutions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $positionIds)
            ->where('market_event_at', '>=', $start)
            ->where('market_event_at', '<', $end)
            ->get(['id', 'position_id', 'type', 'quantity', 'commission', 'gross_pnl', 'market_event_at']);

        $inWindowGrossPnl = BigDecimal::zero();
        $inWindowFees = BigDecimal::zero();
        foreach ($inWindowExecutions as $exec) {
            $inWindowGrossPnl = $inWindowGrossPnl->plus(BigDecimal::of($exec->gross_pnl ?? '0'));
            $inWindowFees = $inWindowFees->plus(BigDecimal::of($exec->commission ?? '0'));
        }

        // In-window funding events
        $inWindowFunding = PositionFundingEventRecord::query()
            ->whereIn('position_id', $positionIds)
            ->where('funding_at', '>=', $start)
            ->where('funding_at', '<', $end)
            ->get(['id', 'position_id', 'amount', 'funding_at']);

        $inWindowFundingTotal = BigDecimal::zero();
        foreach ($inWindowFunding as $fe) {
            $inWindowFundingTotal = $inWindowFundingTotal->plus(BigDecimal::of($fe->amount ?? '0'));
        }

        // Positions active during window (opened before end and closed after start or still open)
        $activePositions = VirtualPositionRecord::query()
            ->where('paper_account_id', $accountId)
            ->where('opened_at', '<', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('closed_at')->orWhere('closed_at', '>=', $start);
            })
            ->get(['id', 'fees', 'gross_pnl', 'net_pnl', 'funding', 'opened_at', 'closed_at']);

        $activePositionFees = BigDecimal::zero();
        foreach ($activePositions as $p) {
            $activePositionFees = $activePositionFees->plus(BigDecimal::of($p->fees ?? '0'));
        }

        // Boundary fills: executions of active positions whose market_event_at is outside [start, end)
        $boundaryExecutions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $activePositions->pluck('id')->all())
            ->where(function ($q) use ($start, $end) {
                $q->where('market_event_at', '<', $start)->orWhere('market_event_at', '>=', $end);
            })
            ->get(['id', 'position_id', 'type', 'commission', 'gross_pnl', 'market_event_at']);

        $boundaryFees = BigDecimal::zero();
        foreach ($boundaryExecutions as $be) {
            $boundaryFees = $boundaryFees->plus(BigDecimal::of($be->commission ?? '0'));
        }

        // Equity snapshots across window
        $firstSnapshot = EquitySnapshotRecord::query()->performance()
            ->where('paper_account_id', $accountId)
            ->where('recorded_at', '>=', $start)
            ->oldest('recorded_at')->oldest('id')
            ->first();

        $lastSnapshot = EquitySnapshotRecord::query()->performance()
            ->where('paper_account_id', $accountId)
            ->where('recorded_at', '<=', $end)
            ->latest('recorded_at')->latest('id')
            ->first();

        $startEquity = $firstSnapshot ? BigDecimal::of($firstSnapshot->equity) : BigDecimal::of($account->starting_balance);
        $endEquity = $lastSnapshot ? BigDecimal::of($lastSnapshot->equity) : BigDecimal::of($account->current_equity);
        $equityChange = $endEquity->minus($startEquity);

        $recalculatedWindowNetPnl = $inWindowGrossPnl->minus($inWindowFees)->plus($inWindowFundingTotal);

        return [
            'account_id' => $accountId,
            'window_start' => $start->toIso8601String(),
            'window_end' => $end->toIso8601String(),
            'in_window_executions_count' => $inWindowExecutions->count(),
            'in_window_gross_pnl' => (string) $inWindowGrossPnl,
            'in_window_fees' => (string) $inWindowFees,
            'in_window_funding' => (string) $inWindowFundingTotal,
            'recalculated_window_net_pnl' => (string) $recalculatedWindowNetPnl,
            'active_positions_count' => $activePositions->count(),
            'active_positions_lifecycle_fees' => (string) $activePositionFees,
            'boundary_executions_count' => $boundaryExecutions->count(),
            'boundary_execution_ids' => $boundaryExecutions->pluck('id')->all(),
            'boundary_fees' => (string) $boundaryFees,
            'fee_difference_reconciled' => $activePositionFees->minus($inWindowFees)->isEqualTo($boundaryFees),
            'start_equity' => (string) $startEquity,
            'end_equity' => (string) $endEquity,
            'equity_change' => (string) $equityChange,
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
