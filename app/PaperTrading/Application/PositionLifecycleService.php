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
        $eligibility = $this->eligibility ?? app(PaperExecutionEligibilityService::class);
        $eligibility->assertOpenEligible($account, $state, $signal, $at);

        return DB::transaction(function () use ($account, $state, $signal, $size, $quote, $at, $eligibility): VirtualPositionRecord {
            $lockedAccount = PaperAccountRecord::query()->lockForUpdate()->findOrFail($account->id);
            $lockedState = PaperSignalStateRecord::query()->lockForUpdate()->findOrFail($state->id);
            // Recheck authorization *inside* the account lock. A previously
            // loaded ACTIVE model is not evidence that the account is still
            // permitted to open an entry when concurrent risk/jobs change it.
            $eligibility->assertOpenEligible($lockedAccount, $lockedState, $signal, $at);
            $positionId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:position:{$account->id}:{$signal->id}")->toString();
            if ($existing = VirtualPositionRecord::query()->find($positionId)) {
                return $existing;
            }
            SignalLifecycle::from($lockedState->status)->transitionTo(SignalLifecycle::Active);
            $quantity = (string) $size->quantity;
            $fee = $this->execution->commission($quantity, $quote->price, $account->exchange_id);
            $position = VirtualPositionRecord::query()->create([
                'id' => $positionId, 'paper_account_id' => $account->id, 'exchange_id' => $account->exchange_id, 'signal_id' => $signal->id, 'symbol' => $signal->symbol,
                'side' => $signal->side, 'quality_class' => $signal->quality_class, 'status' => PositionStatus::Open->value,
                'entry_price' => $quote->price, 'stop_loss' => $signal->stop_loss, 'take_profit_1' => $signal->take_profit_1, 'take_profit_2' => $signal->take_profit_2,
                'initial_quantity' => $quantity, 'remaining_quantity' => $quantity, 'qty_step' => $size->qtyStep, 'risk_capital' => $size->riskCapital,
                'gross_pnl' => '0', 'net_pnl' => BigDecimal::of($fee)->negated(), 'unrealized_pnl' => '0', 'fees' => $fee, 'funding' => '0',
                'opened_at' => $at, 'maximum_holding_until' => $at->modify('+'.$signal->maximum_holding_minutes.' minutes'),
                'last_processed_event_at' => $at, 'ambiguous_execution' => $quote->ambiguous, 'funding_complete' => false,
                'execution_model' => $quote->priceSource, 'strategy_version' => $signal->strategy_version,
                'risk_version' => (string) $this->config['risk_version'], 'execution_version' => (string) $this->config['execution_version'], 'schema_version' => (string) $this->config['schema_version'],
            ]);
            $this->recordExecution($position, ExecutionType::Entry, $signal->side, $quantity, $quote, '0', $fee, $at);
            $lockedState->update(['status' => SignalLifecycle::Active->value, 'processed_at' => $at]);
            $realized = BigDecimal::of($lockedAccount->realized_pnl)->minus($fee);
            $portfolioUnrealized = $this->portfolioUnrealized($lockedAccount->id);
            $reservedRisk = BigDecimal::of($lockedAccount->reserved_risk)->plus($size->riskCapital);
            $this->refreshAccountMetrics($lockedAccount, $realized, $portfolioUnrealized, $reservedRisk);
            $this->snapshot($lockedAccount->refresh(), $at, "entry:{$position->id}");
            $this->journal->append($account->id, $signal->id, $position->id, 'POSITION_OPENED', $at, [
                'paper' => true, 'price' => $quote->price, 'quantity' => $quantity, 'fee' => $fee, 'slippage' => $quote->slippage,
                'execution_model' => $quote->priceSource, 'ambiguous_execution' => $quote->ambiguous,
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
            $unrealized = $closed ? BigDecimal::zero() : BigDecimal::of($this->execution->grossPnl($locked->side, $locked->entry_price, $quote->price, (string) $remaining));
            $locked->update([
                'status' => $next->value, 'remaining_quantity' => (string) $remaining, 'gross_pnl' => (string) $totalGross,
                'fees' => (string) $totalFees, 'net_pnl' => (string) $net,
                'unrealized_pnl' => (string) $unrealized,
                'r_multiple' => $closed ? (string) $net->dividedBy($locked->risk_capital, 10, RoundingMode::HalfEven) : null,
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
            $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized, $reservedRisk);
            if ($closed) {
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
                $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized);
                $this->snapshot($account->refresh(), $event->funding_at->toDateTimeImmutable(), "funding:{$locked->id}:{$event->id}");
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

        // Determine symbol- and exchange-specific funding interval from actual historical records
        $prevFunding = MarketFundingEventRecord::query()
            ->where('exchange', $position->exchange_id)
            ->where('category', 'linear')
            ->where('symbol', $position->symbol)
            ->where('funding_at', '<', $latestFunding->funding_at)
            ->latest('funding_at')
            ->first();

        $intervalSeconds = $prevFunding !== null
            ? max(3600, $latestFunding->funding_at->getTimestamp() - $prevFunding->funding_at->getTimestamp())
            : 28800; // 8 hours default

        $latestTs = $latestFunding->funding_at->toDateTimeImmutable()->getTimestamp();
        $openedTs = $position->opened_at->toDateTimeImmutable()->getTimestamp();
        $closedTs = $position->closed_at->toDateTimeImmutable()->getTimestamp();

        // Verified if:
        // 1. The market feed has advanced to or past position closure time ($latestTs >= $closedTs).
        // 2. OR the position closed shortly after the latest funding event (within the minimum possible funding interval of 1 hour / 3600s),
        //    guaranteeing no intermediate funding boundary could exist between $latestTs and $closedTs.
        // 3. OR the position opened and closed strictly inside the known interval following the latest funding event ($openedTs >= $latestTs && $closedTs < $latestTs + $intervalSeconds).
        $feedPastClosure = $latestTs >= $closedTs;
        $closedWithinMinIntervalAfterLatest = ($closedTs >= $latestTs) && (($closedTs - $latestTs) < min($intervalSeconds, 3600));
        $provableNoFundingWindow = ($openedTs >= $latestTs) && ($closedTs < ($latestTs + $intervalSeconds));

        if (! $feedPastClosure && ! $closedWithinMinIntervalAfterLatest && ! $provableNoFundingWindow) {
            if ($position->funding_complete) {
                $position->update(['funding_complete' => false]);
            }

            return false;
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

        $expectedBoundaries = $this->expectedFundingBoundaries(
            $position->opened_at->toDateTimeImmutable(),
            $position->closed_at->toDateTimeImmutable()
        );

        if (! empty($expectedBoundaries)) {
            // Verify there are no data gaps: every expected boundary must have a matching market funding event
            foreach ($expectedBoundaries as $expectedBoundary) {
                $hasMatchingEvent = $applicableEvents->contains(function (MarketFundingEventRecord $event) use ($expectedBoundary): bool {
                    $eventTs = $event->funding_at->toDateTimeImmutable()->getTimestamp();
                    $expectedTs = $expectedBoundary->getTimestamp();

                    return abs($eventTs - $expectedTs) <= 1800; // within 30 minutes of scheduled boundary
                });

                if (! $hasMatchingEvent) {
                    // Gap detected: an expected funding boundary has no market funding event ingested!
                    // A latest market event alone does NOT establish completeness if historical gaps exist.
                    if ($position->funding_complete) {
                        $position->update(['funding_complete' => false]);
                    }

                    return false;
                }
            }
        }

        if ($applicableEvents->isEmpty()) {
            // Proven: Market feed has advanced past closed_at, zero expected boundaries, and zero funding events fell within position lifetime.
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
     * Generate expected 8-hour funding boundaries (00:00, 08:00, 16:00 UTC) strictly between $from and $to.
     *
     * @return list<DateTimeImmutable>
     */
    public function expectedFundingBoundaries(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $boundaries = [];
        $current = $from->setTime((int) $from->format('H'), 0, 0);
        $hour = (int) $current->format('H');
        $nextFundingHour = match (true) {
            $hour < 0 => 0,
            $hour < 8 => 8,
            $hour < 16 => 16,
            default => 24,
        };

        if ($nextFundingHour === 24) {
            $candidate = $current->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $candidate = $current->setTime($nextFundingHour, 0, 0);
        }

        while ($candidate <= $to) {
            if ($candidate > $from) {
                $boundaries[] = $candidate;
            }
            $candidate = $candidate->modify('+8 hours');
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
            ->where('opened_at', '>=', $since)
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
            $this->refreshAccountMetrics($account, BigDecimal::of($account->realized_pnl), $portfolioUnrealized);
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
        EquitySnapshotRecord::query()->insertOrIgnore([[
            'paper_account_id' => $account->id, 'exchange_id' => $account->exchange_id, 'recorded_at' => $at, 'equity' => $account->current_equity,
            'realized_pnl' => $account->realized_pnl, 'unrealized_pnl' => $account->unrealized_pnl,
            'drawdown_percent' => $account->current_drawdown_percent, 'source_event_key' => $key, 'created_at' => now(), 'updated_at' => now(),
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
        ?BigDecimal $reservedRisk = null
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

        $status = $account->status;
        if ($drawdown->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['drawdown']['halt_percent']))) {
            $status = 'HALTED';
        } elseif ($drawdown->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['drawdown']['block_new_entries_percent']))) {
            $status = 'BLOCKED';
        } elseif ($status === 'BLOCKED' && $account->exchange_id !== 'binance') {
            // Binance BLOCKED also represents an owner-controlled activation gate.
            // Market marks, funding settlements and equity recovery must never
            // silently turn an explicitly blocked Binance paper account ACTIVE.
            $status = 'ACTIVE';
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
    }

    private function portfolioUnrealized(string $accountId): BigDecimal
    {
        return VirtualPositionRecord::query()->where('paper_account_id', $accountId)->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])
            ->get(['unrealized_pnl'])->reduce(fn (BigDecimal $sum, VirtualPositionRecord $position): BigDecimal => $sum->plus($position->unrealized_pnl), BigDecimal::zero());
    }
}
