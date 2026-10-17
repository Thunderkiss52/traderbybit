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
use Ramsey\Uuid\Uuid;

final readonly class PositionLifecycleService
{
    public function __construct(
        private PaperExecutionEngine $execution,
        private TradingJournalService $journal,
        private array $config,
    ) {}

    public function open(PaperAccountRecord $account, PaperSignalStateRecord $state, SignalRecord $signal, PositionSize $size, ExecutionQuote $quote, DateTimeImmutable $at): VirtualPositionRecord
    {
        return DB::transaction(function () use ($account, $state, $signal, $size, $quote, $at): VirtualPositionRecord {
            $lockedAccount = PaperAccountRecord::query()->lockForUpdate()->findOrFail($account->id);
            $lockedState = PaperSignalStateRecord::query()->lockForUpdate()->findOrFail($state->id);
            $positionId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:position:{$account->id}:{$signal->id}")->toString();
            if ($existing = VirtualPositionRecord::query()->find($positionId)) {
                return $existing;
            }
            SignalLifecycle::from($lockedState->status)->transitionTo(SignalLifecycle::Active);
            $quantity = (string) $size->quantity;
            $fee = $this->execution->commission($quantity, $quote->price);
            $position = VirtualPositionRecord::query()->create([
                'id' => $positionId, 'paper_account_id' => $account->id, 'signal_id' => $signal->id, 'symbol' => $signal->symbol,
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
            $quantity = $quote->type === ExecutionType::TakeProfit1
                ? (string) BigDecimal::of($locked->initial_quantity)->multipliedBy((string) $this->config['tp1_fraction'])->dividedBy($locked->qty_step, 0, RoundingMode::Floor)->multipliedBy($locked->qty_step)
                : $locked->remaining_quantity;
            $gross = $this->execution->grossPnl($locked->side, $locked->entry_price, $quote->price, $quantity);
            $fee = $this->execution->commission($quantity, $quote->price);
            $remainingBefore = BigDecimal::of($locked->remaining_quantity);
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
            $reservedRisk = BigDecimal::of($account->reserved_risk)->minus($release);
            $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized, $reservedRisk);
            if ($closed) {
                SignalLifecycle::Active->transitionTo(SignalLifecycle::Completed);
                PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $locked->signal_id)->update(['status' => SignalLifecycle::Completed->value, 'processed_at' => $at]);
                $this->applyCooldown($account->refresh(), $at);
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
        $events = MarketFundingEventRecord::query()->where('exchange', $signal->exchange)
            ->where('category', $signal->category)->where('symbol', $position->symbol)->where('funding_at', '>', $position->opened_at)->where('funding_at', '<=', $through)->oldest('funding_at')->get();
        foreach ($events as $event) {
            DB::transaction(function () use ($position, $event): void {
                $locked = VirtualPositionRecord::query()->lockForUpdate()->findOrFail($position->id);
                if (PositionFundingEventRecord::query()->where('position_id', $locked->id)->where('market_funding_event_id', $event->id)->exists()) {
                    return;
                }
                $amount = $this->execution->funding($locked->side, $locked->remaining_quantity, $event->mark_price, $event->rate);
                PositionFundingEventRecord::query()->create([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:funding:{$locked->id}:{$event->id}")->toString(),
                    'position_id' => $locked->id, 'market_funding_event_id' => $event->id, 'quantity' => $locked->remaining_quantity,
                    'mark_price' => $event->mark_price, 'rate' => $event->rate, 'amount' => $amount, 'funding_at' => $event->funding_at,
                ]);
                $funding = BigDecimal::of($locked->funding)->plus($amount);
                $locked->update(['funding' => (string) $funding, 'net_pnl' => (string) BigDecimal::of($locked->gross_pnl)->plus($funding)->minus($locked->fees)]);
                $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($locked->paper_account_id);
                $realized = BigDecimal::of($account->realized_pnl)->plus($amount);
                $portfolioUnrealized = $this->portfolioUnrealized($account->id);
                $this->refreshAccountMetrics($account, $realized, $portfolioUnrealized);
                $this->snapshot($account->refresh(), $event->funding_at->toDateTimeImmutable(), "funding:{$locked->id}:{$event->id}");
                $this->journal->append($account->id, $locked->signal_id, $locked->id, 'FUNDING_APPLIED', $event->funding_at->toDateTimeImmutable(), ['amount' => $amount, 'rate' => $event->rate, 'mark_price' => $event->mark_price], "funding:{$locked->id}:{$event->id}");
            });
        }
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
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:execution:'.$key)->toString(), 'position_id' => $position->id,
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
            'paper_account_id' => $account->id, 'recorded_at' => $at, 'equity' => $account->current_equity,
            'realized_pnl' => $account->realized_pnl, 'unrealized_pnl' => $account->unrealized_pnl,
            'drawdown_percent' => $account->current_drawdown_percent, 'source_event_key' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    private function applyCooldown(PaperAccountRecord $account, DateTimeImmutable $at): void
    {
        $count = (int) $this->config['losing_streak']['count'];
        $last = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereNotNull('closed_at')->latest('closed_at')->limit($count)->get(['net_pnl']);
        if ($last->count() === $count && $last->every(fn (VirtualPositionRecord $position): bool => BigDecimal::of($position->net_pnl)->isNegative())) {
            $account->update(['cooldown_until' => $at->modify('+'.(int) $this->config['losing_streak']['cooldown_minutes'].' minutes')]);
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
        } elseif ($status === 'BLOCKED') {
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
