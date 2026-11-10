<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final readonly class PositionLifecycleService
{
    public function __construct(
        private PaperExecutionEngine $execution,
        private TradingJournalService $journal,
        private array $config,
        private ?PaperExecutionEligibilityService $eligibility = null,
    ) {}

    public function open(PaperAccountRecord $account, PaperSignalStateRecord $state, SignalRecord $signal, PositionSize $size, ExecutionQuote $quote, DateTimeImmutable $at): VirtualPositionRecord
    {
        $positionId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:position:{$account->id}:{$signal->id}")->toString();
        if ($existing = VirtualPositionRecord::query()->find($positionId)) {
            return $existing;
        }

        $eligibility = $this->eligibility ?? app(PaperExecutionEligibilityService::class);
        $eligibility->assertOpenEligible($account, $state, $signal, $at);

        return DB::transaction(function () use ($account, $state, $signal, $size, $quote, $at, $eligibility, $positionId): VirtualPositionRecord {
            // All PAPER entry transactions acquire the same guard lock before account rows.
            app(GlobalPaperCapitalGuard::class)->lockEntryDecision();
            $lockedAccount = PaperAccountRecord::query()->lockForUpdate()->findOrFail($account->id);
            $lockedState = PaperSignalStateRecord::query()->lockForUpdate()->findOrFail($state->id);

            if ($existing = VirtualPositionRecord::query()->find($positionId)) {
                return $existing;
            }

            // Recheck authorization *inside* the account lock. A previously
            // loaded ACTIVE model is not evidence that the account is still
            // permitted to open an entry when concurrent risk/jobs change it.
            $eligibility->assertOpenEligible($lockedAccount, $lockedState, $signal, $at);
            // Include the prospective position risk, not just existing reservations.
            // Guard uses a shared PostgreSQL entry lock to prevent simultaneous oversubscription.
            $aggregateReason = $lockedAccount->is_primary
                ? app(GlobalPaperCapitalGuard::class)->entryRejectionReason((string) $size->riskCapital) : null;
            if ($aggregateReason !== null) {
                throw new \DomainException('Entry ineligible: '.$aggregateReason);
            }
            SignalLifecycle::from($lockedState->status)->transitionTo(SignalLifecycle::Active);
            $quantity = (string) $size->quantity;
            $fee = $this->execution->commission($quantity, $quote->price, $account->exchange_id);
            $sizingSettings = $lockedAccount->sizing_settings;
            $effectiveMode = $sizingSettings['mode'] ?? 'fixed_notional';
            $requestedNotional = match ($effectiveMode) {
                'fixed_margin' => (string) BigDecimal::of((string) ($sizingSettings['amount'] ?? '5'))->multipliedBy((string) ($sizingSettings['leverage'] ?? '1')),
                'fixed_notional' => (string) ($sizingSettings['amount'] ?? '5'),
                default => (string) $size->notional,
            };
            $costModelVersion = (string) (config("exchanges.{$account->exchange_id}.cost_model.version") ?? '1.0.0');

            $sizingSnapshot = [
                'sizing_mode' => $effectiveMode,
                'requested_notional' => $requestedNotional,
                'actual_notional' => (string) $size->notional,
                'allocated_margin' => (string) $size->allocatedMargin,
                'leverage' => (string) $size->leverage,
                'planned_risk' => (string) $signal->model_risk_percent,
                'effective_risk' => (string) $size->riskCapital,
                'settings_version' => '1.0.0',
                'instrument_metadata_version' => '1.0.0',
                'exchange_cost_model_version' => $costModelVersion,
            ];

            $position = VirtualPositionRecord::query()->create([
                'id' => $positionId, 'paper_account_id' => $account->id, 'exchange_id' => $account->exchange_id, 'signal_id' => $signal->id, 'symbol' => $signal->symbol,
                'side' => $signal->side, 'quality_class' => $signal->quality_class ?? ($signal->signal_quality === 'HIGH' ? 'A' : ($signal->signal_quality === 'MEDIUM' ? 'B' : 'C')),
                'signal_quality' => $signal->signal_quality, 'trade_risk' => $signal->trade_risk,
                'status' => PositionStatus::Open->value,
                'entry_price' => $quote->price, 'stop_loss' => $signal->stop_loss, 'take_profit_1' => $signal->take_profit_1, 'take_profit_2' => $signal->take_profit_2,
                'initial_quantity' => $quantity, 'remaining_quantity' => $quantity, 'qty_step' => $size->qtyStep, 'risk_capital' => $size->riskCapital,
                'gross_pnl' => '0', 'net_pnl' => BigDecimal::of($fee)->negated(), 'unrealized_pnl' => '0', 'fees' => $fee, 'funding' => '0',
                'opened_at' => $at, 'maximum_holding_until' => $at->modify('+'.$signal->maximum_holding_minutes.' minutes'),
                'last_processed_event_at' => $at, 'ambiguous_execution' => $quote->ambiguous, 'funding_complete' => false,
                'execution_model' => $quote->priceSource, 'strategy_version' => $signal->strategy_version,
                'sizing_snapshot' => $sizingSnapshot,
                'risk_version' => (string) $this->config['risk_version'], 'execution_version' => (string) $this->config['execution_version'], 'schema_version' => (string) $this->config['schema_version'],
            ]);
            $this->recordExecution($position, ExecutionType::Entry, $signal->side, $quantity, $quote, '0', $fee, $at);
            $lockedState->update(['status' => SignalLifecycle::Active->value, 'processed_at' => $at]);
            $realized = BigDecimal::of($lockedAccount->realized_pnl)->minus($fee);
            $portfolioUnrealized = $this->portfolioUnrealized($lockedAccount->id);
            $reservedRisk = BigDecimal::of($lockedAccount->reserved_risk)->plus($size->riskCapital);
            $this->refreshAccountMetrics($lockedAccount, $realized, $portfolioUnrealized, $reservedRisk, $at);
            $this->snapshot($lockedAccount->refresh(), $at, "entry:{$position->id}");
            $this->journal->append($account->id, $signal->id, $position->id, 'POSITION_OPENED', $at, [
                'paper' => true, 'price' => $quote->price, 'quantity' => $quantity, 'fee' => $fee, 'slippage' => $quote->slippage,
                'execution_model' => $quote->priceSource, 'ambiguous_execution' => $quote->ambiguous,
                'sizing_snapshot' => $sizingSnapshot,
            ], "position-opened:{$position->id}");

            return $position->refresh();
        });
    }

    public function executeExit(VirtualPositionRecord $position, ExecutionQuote $quote, DateTimeImmutable $at): VirtualPositionRecord
    {
        return DB::transaction(function () use ($position, $quote, $at): VirtualPositionRecord {
            $locked = VirtualPositionRecord::query()->lockForUpdate()->findOrFail($position->id);
            if (PositionStatus::from($locked->status)->isClosed()) {
                return $locked;
            }
            $key = "{$locked->id}:{$at->format('U.u')}:{$quote->type->value}";
            if (VirtualExecutionRecord::query()->where('idempotency_key', $key)->exists()) {
                return $locked;
            }
            $status = PositionStatus::from($locked->status);
            $remainingBefore = BigDecimal::of($locked->remaining_quantity);

            if ($quote->type === ExecutionType::TakeProfit1) {
                $rawTp1Qty = BigDecimal::of($locked->initial_quantity)
                    ->multipliedBy((string) $this->config['tp1_fraction'])
                    ->dividedBy($locked->qty_step, 0, RoundingMode::Floor)
                    ->multipliedBy($locked->qty_step);

                if ($rawTp1Qty->isLessThanOrEqualTo(0)) {
                    Log::warning("executeExit: TP1 quantity rounded to zero for position {$locked->id} (initial: {$locked->initial_quantity}, step: {$locked->qty_step}). Skipping partial exit.");

                    return $locked;
                }

                if ($rawTp1Qty->isGreaterThanOrEqualTo($remainingBefore)) {
                    Log::warning("executeExit: TP1 quantity {$rawTp1Qty} >= remaining {$remainingBefore} for position {$locked->id}. Skipping partial exit.");

                    return $locked;
                }

                $quantity = (string) $rawTp1Qty;
            } else {
                $quantity = $locked->remaining_quantity;
            }

            if (BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                Log::warning("executeExit: Exit quantity is <= 0 for position {$locked->id}. Aborting exit.");

                return $locked;
            }

            $gross = $this->execution->grossPnl($locked->side, $locked->entry_price, $quote->price, $quantity);
            // Verify exchange provenance before charging exchange-specific costs.
            $positionAccount = PaperAccountRecord::query()->whereKey($locked->paper_account_id)->firstOrFail();
            if ($locked->exchange_id !== $positionAccount->exchange_id) {
                throw new \DomainException('Position/account exchange provenance mismatch.');
            }
            $fee = $this->execution->commission($quantity, $quote->price, $positionAccount->exchange_id);
            $remaining = $remainingBefore->minus($quantity);
            $next = match ($quote->type) {
                ExecutionType::TakeProfit1 => PositionStatus::PartiallyClosed,
                ExecutionType::TakeProfit2 => PositionStatus::ClosedTp,
                ExecutionType::StopLoss => PositionStatus::ClosedSl,
                ExecutionType::Timeout => PositionStatus::ClosedTimeout,
                ExecutionType::RiskClosure => PositionStatus::ClosedRisk,
                default => throw new \DomainException('Entry cannot be processed as an exit.'),
            };
            $status->transitionTo($next);
            $totalGross = BigDecimal::of($locked->gross_pnl)->plus($gross);
            $totalFees = BigDecimal::of($locked->fees)->plus($fee);
            $net = $totalGross->plus($locked->funding)->minus($totalFees);
            $closed = $next->isClosed();
            $retrospectiveOutcome = $closed
                ? ($net->isPositive() ? 'GOOD' : ($net->isNegative() ? 'BAD' : 'UNPROVEN'))
                : 'UNPROVEN';
            $unrealized = $closed ? BigDecimal::zero() : BigDecimal::of($this->execution->grossPnl($locked->side, $locked->entry_price, $quote->price, (string) $remaining));
            $locked->update([
                'status' => $next->value, 'remaining_quantity' => (string) $remaining, 'gross_pnl' => (string) $totalGross,
                'fees' => (string) $totalFees, 'net_pnl' => (string) $net,
                'unrealized_pnl' => (string) $unrealized,
                'r_multiple' => $closed ? (string) $net->dividedBy($locked->risk_capital, 10, RoundingMode::HalfEven) : null,
                'retrospective_outcome' => $retrospectiveOutcome,
                'closed_at' => $closed ? $at : null, 'last_processed_event_at' => $at,
                'timeout_delay_seconds' => $quote->type === ExecutionType::Timeout ? max(0, $at->getTimestamp() - $locked->maximum_holding_until->getTimestamp()) : $locked->timeout_delay_seconds,
                'ambiguous_execution' => $locked->ambiguous_execution || $quote->ambiguous,
            ]);
            $this->recordExecution($locked, $quote->type, $locked->side === 'BUY' ? 'SELL' : 'BUY', $quantity, $quote, $gross, $fee, $at);
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($locked->paper_account_id);
            $release = $closed
                ? BigDecimal::of($locked->risk_capital)->multipliedBy($remainingBefore)->dividedBy($locked->initial_quantity, 24, RoundingMode::HalfEven)
                : BigDecimal::of($locked->risk_capital)->multipliedBy($quantity)->dividedBy($locked->initial_quantity, 24, RoundingMode::HalfEven);
            $realizedDelta = BigDecimal::of($gross)->minus($fee);
            $realized = BigDecimal::of($account->realized_pnl)->plus($realizedDelta);
            $portfolioUnrealized = $this->portfolioUnrealized($account->id);
            $hasOtherPositions = VirtualPositionRecord::query()
                ->where('paper_account_id', $account->id)
                ->where('id', '!=', $locked->id)
                ->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
                ->exists();
            $reservedRisk = ($closed && ! $hasOtherPositions)
                ? BigDecimal::zero()
                : BigDecimal::max(BigDecimal::of($account->reserved_risk)->minus($release)->toScale(12, RoundingMode::HalfEven), BigDecimal::zero());
            $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized, $reservedRisk, $at);
            if ($closed) {
                SignalRecord::query()->where('id', $locked->signal_id)->update(['retrospective_outcome' => $retrospectiveOutcome]);
                SignalLifecycle::Active->transitionTo(SignalLifecycle::Completed);
                PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $locked->signal_id)->update(['status' => SignalLifecycle::Completed->value, 'processed_at' => $at]);
                $this->applyCooldown($account->refresh(), $at);
                $this->evaluateFundingCompleteness($locked->fresh());
            }
            $this->snapshot($account->refresh(), $at, "execution:{$key}");
            $this->journal->append($account->id, $locked->signal_id, $locked->id, $closed ? 'POSITION_CLOSED' : 'POSITION_PARTIALLY_CLOSED', $at, [
                'type' => $quote->type->value, 'price' => $quote->price, 'quantity' => $quantity, 'gross_pnl' => $gross,
                'commission' => $fee, 'net_pnl_total' => (string) $net, 'ambiguous_execution' => $quote->ambiguous,
            ], "execution:{$key}");

            return $locked->refresh();
        });
    }

    public function applyFunding(VirtualPositionRecord $position, DateTimeImmutable $through): void
    {
        $signal = SignalRecord::query()->findOrFail($position->signal_id, ['exchange', 'category']);
        if ($signal->exchange !== $position->exchange_id || $signal->category !== 'linear') {
            throw new \DomainException('Funding source/position exchange mismatch.');
        }

        $cutoff = $position->closed_at !== null
            ? min($through, $position->closed_at->toDateTimeImmutable())
            : $through;

        $events = MarketFundingEventRecord::query()->where('exchange', $signal->exchange)
            ->where('category', $signal->category)->where('symbol', $position->symbol)->where('funding_at', '>', $position->opened_at)->where('funding_at', '<=', $cutoff)->oldest('funding_at')->get();

        foreach ($events as $event) {
            DB::transaction(function () use ($position, $event): void {
                $locked = VirtualPositionRecord::query()->lockForUpdate()->findOrFail($position->id);
                if (PositionFundingEventRecord::query()->where('position_id', $locked->id)->where('market_funding_event_id', $event->id)->exists()) {
                    return;
                }

                $quantityHeld = $this->quantityHeldAt($locked, $event->funding_at->toDateTimeImmutable());
                if (BigDecimal::of($quantityHeld)->isLessThanOrEqualTo(0)) {
                    return;
                }

                $amount = $this->execution->funding($locked->side, $quantityHeld, $event->mark_price, $event->rate);
                PositionFundingEventRecord::query()->create([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:funding:{$locked->id}:{$event->id}")->toString(),
                    'position_id' => $locked->id,
                    'exchange_id' => $locked->exchange_id,
                    'market_funding_event_id' => $event->id,
                    'quantity' => $quantityHeld,
                    'mark_price' => $event->mark_price,
                    'rate' => $event->rate,
                    'amount' => $amount,
                    'funding_at' => $event->funding_at,
                ]);

                $funding = BigDecimal::of($locked->funding)->plus($amount);
                $net = BigDecimal::of($locked->gross_pnl)->plus($funding)->minus($locked->fees);
                $isClosed = PositionStatus::from($locked->status)->isClosed();
                $rMultiple = $isClosed && $locked->risk_capital !== null && ! BigDecimal::of($locked->risk_capital)->isZero()
                    ? (string) $net->dividedBy($locked->risk_capital, 10, RoundingMode::HalfEven)
                    : $locked->r_multiple;

                $locked->update([
                    'funding' => (string) $funding,
                    'net_pnl' => (string) $net,
                    'r_multiple' => $rMultiple,
                ]);

                $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($locked->paper_account_id);
                $realized = BigDecimal::of($account->realized_pnl)->plus($amount);
                $portfolioUnrealized = $this->portfolioUnrealized($account->id);
                $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized, at: now('UTC')->toDateTimeImmutable());
                // Settlement economic time belongs to the funding ledger. This is
                // current account equity observed when the adjustment was applied.
                $this->snapshot($account->refresh(), now('UTC')->toDateTimeImmutable(), "funding:{$locked->id}:{$event->id}");
                $this->journal->append($account->id, $locked->signal_id, $locked->id, 'FUNDING_APPLIED', $event->funding_at->toDateTimeImmutable(), [
                    'amount' => $amount,
                    'rate' => $event->rate,
                    'mark_price' => $event->mark_price,
                    'quantity' => $quantityHeld,
                ], "funding:{$locked->id}:{$event->id}");
            });
        }

        $this->evaluateFundingCompleteness($position->fresh());
    }

    /**
     * Reconstruct the exact position quantity held at historical timestamp $at.
     */
    public function quantityHeldAt(VirtualPositionRecord $position, DateTimeImmutable $at): string
    {
        $initial = BigDecimal::of($position->initial_quantity);
        $executions = VirtualExecutionRecord::query()
            ->where('position_id', $position->id)
            ->where('market_event_at', '<=', $at)
            ->orderBy('market_event_at')
            ->orderBy('id')
            ->get();

        $remaining = $initial;
        foreach ($executions as $execution) {
            $execQty = BigDecimal::of($execution->quantity);
            if ($execution->type === ExecutionType::TakeProfit1->value) {
                $remaining = $remaining->minus($execQty);
            } elseif ($execution->type !== ExecutionType::Entry->value) {
                // Any other exit (StopLoss, TakeProfit2, Timeout, RiskClosure) closes the position
                $remaining = BigDecimal::zero();
            }
        }

        return (string) BigDecimal::max($remaining, BigDecimal::zero());
    }

    /**
     * Evaluate evidence-based funding completeness for a position.
     */
    public function evaluateFundingCompleteness(VirtualPositionRecord $position): bool
    {
        $status = PositionStatus::from($position->status);
        if (! $status->isClosed() || $position->closed_at === null) {
            if ($position->funding_complete) {
                $position->update(['funding_complete' => false]);
            }

            return false;
        }

        $latestFunding = MarketFundingEventRecord::query()
            ->where('exchange', $position->exchange_id)
            ->where('category', 'linear')
            ->where('symbol', $position->symbol)
            ->latest('funding_at')
            ->first();

        if ($latestFunding === null) {
            if ($position->funding_complete) {
                $position->update(['funding_complete' => false]);
            }

            return false;
        }

        $openedTs = $position->opened_at->toDateTimeImmutable()->getTimestamp();
        $closedTs = $position->closed_at->toDateTimeImmutable()->getTimestamp();
        $latestTs = $latestFunding->funding_at->toDateTimeImmutable()->getTimestamp();

        // 1. Resolve certified expected boundaries
        $expectedBoundaries = $this->expectedFundingBoundaries(
            $position->opened_at->toDateTimeImmutable(),
            $position->closed_at->toDateTimeImmutable(),
            $position->exchange_id,
            $position->symbol,
        );

        if ($expectedBoundaries === null) {
            // Source of settlement schedule or required history is insufficient (INSUFFICIENT_DATA)
            if ($position->funding_complete) {
                $position->update(['funding_complete' => false]);
            }

            return false;
        }

        $intervalSeconds = $this->resolveFundingScheduleInterval(
            $position->exchange_id,
            $position->symbol,
            $position->opened_at->toDateTimeImmutable(),
            $position->closed_at->toDateTimeImmutable()
        );

        // Feed advancement / completeness check:
        // Do not infer historical coverage merely from a fixed interval or recent event.
        if (! empty($expectedBoundaries)) {
            $lastExpected = end($expectedBoundaries);
            if ($latestFunding->funding_at->toDateTimeImmutable() < $lastExpected) {
                if ($position->funding_complete) {
                    $position->update(['funding_complete' => false]);
                }

                return false;
            }
        } else {
            // Zero expected boundaries strictly between opened_at and closed_at.
            // Certified only if:
            // 1) Feed has advanced past closed_at ($latestTs >= $closedTs).
            // 2) OR position lifetime falls strictly within certified interval after latest event.
            $feedPastClosure = $latestTs >= $closedTs;
            $provableNoFundingWindow = $intervalSeconds !== null
                && ($openedTs >= $latestTs)
                && ($closedTs < ($latestTs + $intervalSeconds));

            if (! $feedPastClosure && ! $provableNoFundingWindow) {
                if ($position->funding_complete) {
                    $position->update(['funding_complete' => false]);
                }

                return false;
            }
        }

        // Query all actual market funding events for this exchange and symbol
        // strictly between opened_at and closed_at (exclusive of opened_at, inclusive of closed_at)
        $applicableEvents = MarketFundingEventRecord::query()
            ->where('exchange', $position->exchange_id)
            ->where('category', 'linear')
            ->where('symbol', $position->symbol)
            ->where('funding_at', '>', $position->opened_at)
            ->where('funding_at', '<=', $position->closed_at)
            ->orderBy('funding_at')
            ->get();

        if (! empty($expectedBoundaries)) {
            // Verify there are no data gaps: every expected boundary must have an exact matching market funding event
            // Matching tolerance is tight (within 60s for clock drift tolerance), never an arbitrary ±30 minutes
            foreach ($expectedBoundaries as $expectedBoundary) {
                $hasMatchingEvent = $applicableEvents->contains(function (MarketFundingEventRecord $event) use ($expectedBoundary): bool {
                    $eventTs = $event->funding_at->toDateTimeImmutable()->getTimestamp();
                    $expectedTs = $expectedBoundary->getTimestamp();

                    return abs($eventTs - $expectedTs) <= 60;
                });

                if (! $hasMatchingEvent) {
                    // Gap detected: an expected funding boundary has no market funding event ingested!
                    Log::warning("funding_gap_detected: position {$position->id} symbol {$position->symbol} missing boundary {$expectedBoundary->format(DATE_ATOM)}", [
                        'diagnostic' => 'funding_gap_detected',
                        'position_id' => $position->id,
                        'symbol' => $position->symbol,
                        'expected_boundary' => $expectedBoundary->format(DATE_ATOM),
                    ]);

                    if ($position->funding_complete) {
                        $position->update(['funding_complete' => false]);
                    }

                    return false;
                }
            }
        }

        if ($applicableEvents->isEmpty()) {
            // Proven: Market feed verified, zero expected boundaries, and zero funding events fell within position lifetime.
            if (! $position->funding_complete) {
                $position->update(['funding_complete' => true]);
            }

            return true;
        }

        // For each applicable market funding event, verify whether the position had active exposure
        // and whether a corresponding position funding event was settled.
        foreach ($applicableEvents as $mfe) {
            $qtyHeld = $this->quantityHeldAt($position, $mfe->funding_at->toDateTimeImmutable());
            if (BigDecimal::of($qtyHeld)->isGreaterThan(0)) {
                $pfeExists = PositionFundingEventRecord::query()
                    ->where('position_id', $position->id)
                    ->where('market_funding_event_id', $mfe->id)
                    ->exists();

                if (! $pfeExists) {
                    if ($position->funding_complete) {
                        $position->update(['funding_complete' => false]);
                    }

                    return false;
                }
            }
        }

        if (! $position->funding_complete) {
            $position->update(['funding_complete' => true]);
        }

        return true;
    }

    /**
     * Resolve certified funding interval in seconds for an exchange and symbol,
     * taking into account configured schedules and empirical continuous historical records.
     * Returns null if schedule is uncertified / insufficient data.
     */
    public function resolveFundingScheduleInterval(
        string $exchange,
        string $symbol,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?int {
        // 1. Check empirical continuous events in database around the window
        $events = MarketFundingEventRecord::query()
            ->where('exchange', $exchange)
            ->where('category', 'linear')
            ->where('symbol', $symbol)
            ->where('funding_at', '>=', $from->modify('-48 hours'))
            ->where('funding_at', '<=', $to->modify('+48 hours'))
            ->orderBy('funding_at')
            ->get();

        if ($events->count() >= 2) {
            $intervals = [];
            for ($i = 1; $i < $events->count(); $i++) {
                $diff = $events[$i]->funding_at->getTimestamp() - $events[$i - 1]->funding_at->getTimestamp();
                if ($diff > 0) {
                    $intervals[] = $diff;
                }
            }

            if (! empty($intervals)) {
                $first = $intervals[0];
                $allSame = true;
                foreach ($intervals as $inv) {
                    if ($inv !== $first) {
                        $allSame = false;
                        break;
                    }
                }
                if ($allSame && in_array($first, [3600, 7200, 14400, 28800], true)) {
                    return $first;
                }
            }
        }

        // 2. Check certified configuration schedule
        $configured = config("market_data.funding_schedules.{$exchange}.{$symbol}");
        if (is_array($configured) && isset($configured['interval_hours'])) {
            $hours = (int) $configured['interval_hours'];
            if (in_array($hours, [1, 2, 4, 8], true)) {
                return $hours * 3600;
            }
        }

        // 3. Uncertified / insufficient data
        return null;
    }

    /**
     * Generate expected funding boundaries strictly between $from and $to.
     * Respects exchange-specific, symbol-specific, and time-dependent funding schedules.
     * Returns null if the source of the settlement schedule or required history is insufficient (INSUFFICIENT_DATA).
     *
     * @return list<DateTimeImmutable>|null
     */
    public function expectedFundingBoundaries(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $exchange = null,
        ?string $symbol = null,
    ): ?array {
        if ($from > $to) {
            return [];
        }

        if ($exchange === null || $symbol === null) {
            return null; // INSUFFICIENT_DATA
        }

        $intervalSeconds = $this->resolveFundingScheduleInterval($exchange, $symbol, $from, $to);
        if ($intervalSeconds === null || $intervalSeconds <= 0) {
            return null; // INSUFFICIENT_DATA
        }

        $from = $from->setTimezone(new \DateTimeZone('UTC'));
        $to = $to->setTimezone(new \DateTimeZone('UTC'));
        $intervalHours = (int) ($intervalSeconds / 3600);
        if ($intervalHours < 1 || ($intervalSeconds % 3600) !== 0 || (24 % $intervalHours) !== 0) {
            return null; // Non-standard or uncertified interval
        }

        $boundaries = [];
        $current = $from->setTime((int) $from->format('H'), 0, 0);
        $hour = (int) $current->format('H');
        $remainder = $hour % $intervalHours;
        $nextFundingHour = $hour + ($remainder === 0 ? 0 : ($intervalHours - $remainder));

        if ($nextFundingHour >= 24) {
            $candidate = $current->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $candidate = $current->setTime($nextFundingHour, 0, 0);
        }

        while ($candidate <= $from) {
            $candidate = $candidate->modify("+{$intervalHours} hours");
        }

        while ($candidate <= $to) {
            $boundaries[] = $candidate;
            $candidate = $candidate->modify("+{$intervalHours} hours");
        }

        return $boundaries;
    }

    /**
     * Idempotently reconcile pending or late-arriving funding events across positions.
     */
    public function reconcilePendingFunding(string $exchange, string $symbol, ?DateTimeImmutable $since = null): int
    {
        $since ??= now('UTC')->subDays(30)->toDateTimeImmutable();
        $positions = VirtualPositionRecord::query()
            ->where('exchange_id', strtolower($exchange))
            ->where('symbol', strtoupper($symbol))
            ->where(function ($query) {
                $query->whereIn('status', [
                    PositionStatus::Open->value,
                    PositionStatus::PartiallyClosed->value,
                ])->orWhere('funding_complete', false);
            })
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $since))
            ->orderBy('opened_at')
            ->get();

        $applied = 0;
        $now = now('UTC')->toDateTimeImmutable();
        foreach ($positions as $position) {
            $through = $position->closed_at !== null ? $position->closed_at->toDateTimeImmutable() : $now;
            $beforeFunding = $position->funding;
            $this->applyFunding($position, $through);
            if ($position->fresh()->funding !== $beforeFunding) {
                $applied++;
            }
        }

        return $applied;
    }

    public function markToMarket(VirtualPositionRecord $position, string $price, DateTimeImmutable $at): void
    {
        DB::transaction(function () use ($position, $price, $at): void {
            $locked = VirtualPositionRecord::query()->lockForUpdate()->findOrFail($position->id);
            if (PositionStatus::from($locked->status)->isClosed()) {
                return;
            }
            $unrealized = $this->execution->grossPnl($locked->side, $locked->entry_price, $price, $locked->remaining_quantity);
            $locked->update(['unrealized_pnl' => $unrealized, 'last_processed_event_at' => $at, 'awaiting_market_data' => false]);
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($locked->paper_account_id);
            $portfolioUnrealized = $this->portfolioUnrealized($account->id);
            $this->refreshAccountMetrics($account, BigDecimal::of($account->realized_pnl), $portfolioUnrealized, at: $at);
            $this->snapshot($account->refresh(), $at, "mark:{$locked->id}:{$at->format('U.u')}");
        });
    }

    private function recordExecution(VirtualPositionRecord $position, ExecutionType $type, string $side, string $quantity, ExecutionQuote $quote, string $gross, string $fee, DateTimeImmutable $at): void
    {
        $key = "{$position->id}:{$at->format('U.u')}:{$type->value}";
        VirtualExecutionRecord::query()->insertOrIgnore([[
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:execution:'.$key)->toString(), 'position_id' => $position->id, 'exchange_id' => $position->exchange_id,
            'type' => $type->value, 'side' => $side, 'quantity' => $quantity, 'price' => $quote->price, 'gross_pnl' => $gross,
            'commission' => $fee, 'slippage' => $quote->slippage, 'market_event_at' => $at, 'registered_at' => now(),
            'price_source' => $quote->priceSource, 'ambiguous_execution' => $quote->ambiguous, 'idempotency_key' => $key,
            'execution_version' => (string) $this->config['execution_version'], 'schema_version' => (string) $this->config['schema_version'],
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function snapshot(PaperAccountRecord $account, DateTimeImmutable $at, string $key): void
    {
        $isFlat = BigDecimal::of((string) $account->unrealized_pnl)->isZero();
        $valuationFresh = true;
        $valuedThrough = $at;
        if (! $isFlat && ! str_starts_with($key, 'mark:')) {
            $oldestMark = VirtualPositionRecord::query()
                ->where('paper_account_id', $account->id)
                ->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
                ->min('last_processed_event_at');
            if ($oldestMark !== null) {
                $valuedThrough = CarbonImmutable::parse($oldestMark)->toDateTimeImmutable();
                if (max(0, $at->getTimestamp() - $valuedThrough->getTimestamp()) > 300) {
                    $valuationFresh = false;
                }
            }
        }

        EquitySnapshotRecord::query()->insertOrIgnore([[
            'paper_account_id' => $account->id, 'exchange_id' => $account->exchange_id, 'recorded_at' => $at, 'equity' => $account->current_equity,
            'realized_pnl' => $account->realized_pnl, 'unrealized_pnl' => $account->unrealized_pnl,
            'drawdown_percent' => $account->current_drawdown_percent, 'source_event_key' => $key,
            'valued_through' => $valuedThrough, 'valuation_fresh' => $valuationFresh,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function applyCooldown(PaperAccountRecord $account, DateTimeImmutable $at): void
    {
        $count = (int) $this->config['losing_streak']['count'];
        $last = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNotNull('closed_at')->latest('closed_at')->latest('id')->limit($count)->get(['net_pnl']);
        if ($last->count() === $count && $last->every(fn (VirtualPositionRecord $position): bool => BigDecimal::of($position->net_pnl)->isNegative())) {
            $cooldownUntil = $at->modify('+'.(int) $this->config['losing_streak']['cooldown_minutes'].' minutes');
            if ($account->cooldown_until === null || $cooldownUntil > $account->cooldown_until) {
                $account->update(['cooldown_until' => $cooldownUntil]);
            }
        }
    }

    private function refreshAccountMetrics(
        PaperAccountRecord $account,
        BigDecimal $realized,
        BigDecimal $portfolioUnrealized,
        ?BigDecimal $reservedRisk = null,
        ?DateTimeImmutable $at = null,
    ): void {
        $equity = BigDecimal::of($account->starting_balance)->plus($realized)->plus($portfolioUnrealized);
        $peak = BigDecimal::max(
            $account->starting_balance,
            $account->peak_equity ?? $account->starting_balance,
            $equity
        );
        $drawdown = $peak->isZero()
            ? BigDecimal::zero()
            : $peak->minus($equity)->dividedBy($peak, 24, RoundingMode::HalfEven)->multipliedBy(100);
        $maxDrawdown = BigDecimal::max($account->maximum_drawdown_percent ?? '0', $drawdown);

        $halt = BigDecimal::min(BigDecimal::of((string) $this->config['drawdown']['halt_percent']), BigDecimal::of('10'));
        $block = BigDecimal::min(BigDecimal::of((string) $this->config['drawdown']['block_new_entries_percent']), BigDecimal::of('5'));
        $status = $account->status;
        if ($drawdown->isGreaterThanOrEqualTo($halt)) {
            $status = 'HALTED';
        } elseif ($drawdown->isGreaterThanOrEqualTo($block) && $status !== 'HALTED') {
            $status = 'BLOCKED';
        }

        $payload = [
            'realized_pnl' => (string) $realized,
            'unrealized_pnl' => (string) $portfolioUnrealized,
            'current_equity' => (string) $equity,
            'peak_equity' => (string) $peak,
            'current_drawdown_percent' => (string) $drawdown,
            'maximum_drawdown_percent' => (string) $maxDrawdown,
            'status' => $status,
            'drawdown_override' => false,
        ];
        if ($reservedRisk !== null) {
            $payload['reserved_risk'] = (string) BigDecimal::max($reservedRisk, BigDecimal::zero());
        }

        $account->update($payload);
        app(DrawdownInvestigationService::class)->request($account, $at ?? now()->toDateTimeImmutable());
    }

    private function portfolioUnrealized(string $accountId): BigDecimal
    {
        return VirtualPositionRecord::query()->where('paper_account_id', $accountId)->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
            ->get(['unrealized_pnl'])->reduce(fn (BigDecimal $sum, VirtualPositionRecord $position): BigDecimal => $sum->plus($position->unrealized_pnl), BigDecimal::zero());
    }
}
