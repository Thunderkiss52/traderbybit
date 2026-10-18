<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\MarketBar;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class PaperProcessingService
{
    public function __construct(
        private PaperAccountService $accounts,
        private RiskEngine $risk,
        private CorrelationService $correlation,
        private PositionSizingService $sizing,
        private PaperExecutionEngine $execution,
        private PositionLifecycleService $lifecycle,
        private TradingJournalService $journal,
        private CacheFactory $cache,
        private array $config,
    ) {}

    public function process(?string $accountId = null, ?string $symbol = null, ?DateTimeImmutable $at = null, bool $allowNewEntries = true): array
    {
        $account = $accountId === null ? $this->accounts->default() : PaperAccountRecord::query()->findOrFail($accountId);
        $historical = $at !== null;
        $at ??= $this->latestClosedTime($symbol) ?? new DateTimeImmutable('now UTC');
        $lock = $this->cache->lock("paper:process:{$account->id}", (int) $this->config['processing']['lock_seconds']);
        if (! $lock->get()) {
            throw new \RuntimeException('Paper account is already being processed.');
        }
        try {
            if ($allowNewEntries && $account->status === 'ACTIVE') {
                $this->ingestSignals($account, $symbol, $at, ! $historical);
            }
            $processed = 0;
            $states = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)
                ->whereIn('status', [SignalLifecycle::PendingEntry->value, SignalLifecycle::Active->value])
                ->when($symbol, fn ($q) => $q->whereIn('signal_id', SignalRecord::query()->select('id')->where('symbol', $symbol)))
                ->get();
            foreach ($states as $state) {
                if ($state->status === SignalLifecycle::PendingEntry->value
                    && (! $allowNewEntries || $account->status !== 'ACTIVE')) {
                    DB::transaction(function () use ($account, $state, $at): void {
                        $locked = PaperSignalStateRecord::query()->lockForUpdate()->findOrFail($state->id);
                        if ($locked->status !== SignalLifecycle::PendingEntry->value) {
                            return;
                        }
                        $reason = $account->status !== 'ACTIVE' ? 'account_not_active' : 'forward_configuration_retired';
                        $locked->update(['status' => SignalLifecycle::Rejected->value, 'reason' => $reason, 'processed_at' => $at]);
                        $this->journal->append($account->id, $locked->signal_id, null, 'ENTRY_RISK_REJECTED', $at,
                            ['reason' => $reason], "pending-cancel:{$account->id}:{$locked->signal_id}");
                    });

                    continue;
                }
                $processed += $this->processState($account, $state, $at, $historical);
            }

            return ['account_id' => $account->id, 'through' => $at->format(DATE_ATOM), 'events_processed' => $processed];
        } finally {
            $lock->release();
        }
    }

    private function ingestSignals(PaperAccountRecord $account, ?string $symbol, DateTimeImmutable $at, bool $forwardOnly): void
    {
        // Scheduled forward paper trading must never backfill old strategy decisions
        // into an account that did not yet exist. Historical --at replay is explicit.
        $signals = SignalRecord::query()->where('decision_time', '<=', $at)
            ->where('strategy_id', $account->strategy_id ?? 'baseline')
            ->when($account->mode === 'STRATEGY_FORWARD_PAPER',
                fn ($q) => $q->where('strategy_version', $account->strategy_version))
            ->when($forwardOnly, fn ($q) => $q->where('decision_time', '>=', $account->created_at)
                ->whereColumn('created_at', '<=', 'expires_at'))
            ->when($symbol, fn ($q) => $q->where('symbol', $symbol))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('paper_signal_states')
                ->whereColumn('paper_signal_states.signal_id', 'signals.id')
                ->where('paper_signal_states.paper_account_id', $account->id))
            ->oldest('decision_time')->oldest('id')->limit((int) $this->config['processing']['batch_size'])->get();
        foreach ($signals as $signal) {
            if ($forwardOnly && $signal->created_at->getTimestamp() > $signal->decision_time->getTimestamp() + 300) {
                // Late historical observations are research-only, never forward fills.
                DB::transaction(function () use ($account, $signal): void {
                    $decisionId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:risk:{$account->id}:{$signal->id}:{$this->config['risk_version']}")->toString();
                    RiskDecisionRecord::query()->insertOrIgnore([[
                        'id' => $decisionId,
                        'paper_account_id' => $account->id,
                        'signal_id' => $signal->id,
                        'allowed' => false,
                        'reasons' => json_encode(['late_signal_not_executable'], JSON_THROW_ON_ERROR),
                        'snapshot' => json_encode([
                            'reason' => 'late_signal_not_executable',
                            'delay_seconds' => $signal->created_at->getTimestamp() - $signal->decision_time->getTimestamp(),
                            'decision_time' => $signal->decision_time->format(DATE_ATOM),
                            'created_at' => $signal->created_at->format(DATE_ATOM),
                        ], JSON_THROW_ON_ERROR),
                        'risk_version' => (string) $this->config['risk_version'],
                        'decided_at' => $signal->decision_time,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]]);
                    PaperSignalStateRecord::query()->firstOrCreate([
                        'paper_account_id' => $account->id,
                        'signal_id' => $signal->id,
                    ], [
                        'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:signal-state:{$account->id}:{$signal->id}")->toString(),
                        'status' => SignalLifecycle::LateSignalNotExecutable->value,
                        'reason' => 'late_signal_not_executable',
                        'processed_at' => $signal->decision_time,
                    ]);
                    $this->journal->append(
                        $account->id,
                        $signal->id,
                        null,
                        'LATE_SIGNAL_NOT_EXECUTABLE',
                        $signal->decision_time->toDateTimeImmutable(),
                        [
                            'reason' => 'late_signal_not_executable',
                            'delay_seconds' => $signal->created_at->getTimestamp() - $signal->decision_time->getTimestamp(),
                        ],
                        "late:{$account->id}:{$signal->id}"
                    );
                    $this->updateObservationDiagnostics($account, $signal, [
                        'risk_approved' => false,
                        'risk_rejection_reasons' => ['late_signal_not_executable'],
                        'paper_execution' => 'LATE_SIGNAL_NOT_EXECUTABLE',
                    ]);
                });

                continue;
            }
            if (PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->exists()) {
                continue;
            }
            if ($signal->side === 'SKIP') {
                DB::transaction(function () use ($account, $signal): void {
                    PaperSignalStateRecord::query()->firstOrCreate([
                        'paper_account_id' => $account->id, 'signal_id' => $signal->id,
                    ], [
                        'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:signal-state:{$account->id}:{$signal->id}")->toString(),
                        'status' => SignalLifecycle::Completed->value, 'reason' => $signal->reasons[0] ?? 'analytical_skip',
                        'processed_at' => $signal->decision_time,
                    ]);
                    $this->journal->append($account->id, $signal->id, null, 'ANALYTICAL_SKIP', $signal->decision_time->toDateTimeImmutable(), ['reasons' => $signal->reasons], "skip:{$account->id}:{$signal->id}");
                });

                continue;
            }
            DB::transaction(function () use ($account, $signal): void {
                $locked = PaperAccountRecord::query()->lockForUpdate()->findOrFail($account->id);
                $assessment = $this->assessment($locked, $signal);
                $decisionId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:risk:{$locked->id}:{$signal->id}:{$this->config['risk_version']}")->toString();
                RiskDecisionRecord::query()->insertOrIgnore([[
                    'id' => $decisionId, 'paper_account_id' => $locked->id, 'signal_id' => $signal->id, 'allowed' => $assessment['allowed'],
                    'reasons' => json_encode($assessment['reasons'], JSON_THROW_ON_ERROR), 'snapshot' => json_encode($assessment['snapshot'], JSON_THROW_ON_ERROR),
                    'risk_version' => (string) $this->config['risk_version'], 'decided_at' => $signal->decision_time, 'created_at' => now(), 'updated_at' => now(),
                ]]);
                $status = $assessment['allowed'] ? SignalLifecycle::PendingEntry : SignalLifecycle::Rejected;
                PaperSignalStateRecord::query()->create([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:signal-state:{$locked->id}:{$signal->id}")->toString(),
                    'paper_account_id' => $locked->id, 'signal_id' => $signal->id, 'status' => $status->value,
                    'reason' => $assessment['reasons'][0] ?? null, 'processed_at' => $signal->decision_time,
                ]);
                $this->journal->append($locked->id, $signal->id, null, $assessment['allowed'] ? 'RISK_ACCEPTED' : 'RISK_REJECTED', $signal->decision_time->toDateTimeImmutable(), $assessment, "risk:{$decisionId}");
                $this->updateObservationDiagnostics($locked, $signal, [
                    'risk_approved' => $assessment['allowed'],
                    'risk_rejection_reasons' => $assessment['allowed'] ? [] : $assessment['reasons'],
                    'paper_execution' => $assessment['allowed'] ? 'RISK_APPROVED' : 'RISK_REJECTED',
                ]);
            });
        }
    }

    private function assessment(PaperAccountRecord $account, SignalRecord $signal): array
    {
        $context = MarketContextRecord::query()->where('exchange', $signal->exchange)->where('category', $signal->category)->where('symbol', $signal->symbol)
            ->where('observed_at', '<=', $signal->decision_time)->latest('observed_at')->first();
        $failures = [];
        if ($signal->category === 'spot' && $signal->side === 'SELL') {
            $failures[] = 'spot_short_unsupported';
        }
        $limits = null;
        if ($context === null) {
            $failures[] = 'market_metadata_unavailable';
        } else {
            $age = $signal->decision_time->getTimestamp() - $context->observed_at->getTimestamp();
            if ($age > (int) $this->config['market_safety']['maximum_context_age_seconds']) {
                $failures[] = 'market_data_stale';
            }
            $mid = BigDecimal::of($context->bid)->plus($context->ask)->dividedBy(2, 24, RoundingMode::HalfEven);
            if ($mid->isZero() || BigDecimal::of($context->ask)->minus($context->bid)->dividedBy($mid, 24, RoundingMode::HalfEven)->isGreaterThan(BigDecimal::of((string) $this->config['market_safety']['maximum_spread_percent']))) {
                $failures[] = 'spread_above_maximum';
            }
            if (BigDecimal::of($context->turnover_24h)->isLessThan(BigDecimal::of((string) $this->config['market_safety']['minimum_turnover_24h']))) {
                $failures[] = 'insufficient_liquidity';
            }
            if ($context->funding_rate !== null && BigDecimal::of($context->funding_rate)->abs()
                ->isGreaterThan(BigDecimal::of((string) $this->config['market_safety']['maximum_absolute_funding_rate']))) {
                $failures[] = 'extreme_funding_rate';
            }
            if ($context->open_interest_change !== null && BigDecimal::of($context->open_interest_change)->abs()
                ->isGreaterThan(BigDecimal::of((string) $this->config['market_safety']['maximum_absolute_open_interest_change']))) {
                $failures[] = 'extreme_open_interest_change';
            }
            if ($context->qty_step === null || $context->min_order_qty === null || $context->max_order_qty === null || $context->min_notional_value === null) {
                $failures[] = 'instrument_constraints_unavailable';
            } else {
                $limits = new InstrumentConstraints($context->qty_step, $context->min_order_qty, $context->max_order_qty, $context->min_notional_value);
            }
        }
        $entryIndicators = $signal->indicator_snapshot['15m'] ?? null;
        if (! is_array($entryIndicators) || ! is_string($entryIndicators['atr_14'] ?? null) || ! is_string($entryIndicators['close'] ?? null)) {
            if ((bool) $this->config['market_safety']['require_indicator_snapshot']) {
                $failures[] = 'incomplete_indicator_history';
            }
        } else {
            $atrPercent = BigDecimal::of($entryIndicators['atr_14'])->dividedBy($entryIndicators['close'], 24, RoundingMode::HalfEven);
            if ($atrPercent->isLessThan(BigDecimal::of((string) $this->config['market_safety']['minimum_atr_percent']))) {
                $failures[] = 'volatility_below_minimum';
            }
            if ($atrPercent->isGreaterThan(BigDecimal::of((string) $this->config['market_safety']['maximum_atr_percent']))) {
                $failures[] = 'extreme_volatility';
            }
        }
        $entry = $signal->side === 'BUY' ? $signal->entry_high : $signal->entry_low;
        $size = $limits === null ? null : $this->sizing->calculate($account->current_equity, $signal->model_risk_percent, $entry, $signal->stop_loss, $limits);
        if ($size !== null && ! $size->allowed) {
            $failures[] = (string) $size->reason;
        }
        $active = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->whereIn('status', [PositionStatus::Open->value, PositionStatus::PartiallyClosed->value])->get();
        $existingSymbolPosition = $active->first(fn (VirtualPositionRecord $p): bool => $p->symbol === $signal->symbol);
        if ($existingSymbolPosition !== null) {
            if ($existingSymbolPosition->side !== $signal->side) {
                $failures[] = 'position_conflict';
            } else {
                $failures[] = 'position_already_open';
            }
        }
        $same = $active->first(fn (VirtualPositionRecord $p): bool => $p->side === $signal->side && $p->symbol !== $signal->symbol);
        $corr = $same === null ? null : $this->correlation->between($signal->symbol, $same->symbol, $signal->decision_time->toDateTimeImmutable());
        $totalRiskPercent = BigDecimal::of($account->current_equity)->isZero() ? '999' : (string) BigDecimal::of($account->reserved_risk)->dividedBy($account->current_equity, 24, RoundingMode::HalfEven)->multipliedBy(100);
        $notional = $active->reduce(fn (BigDecimal $sum, VirtualPositionRecord $p): BigDecimal => $sum->plus(BigDecimal::of($p->entry_price)->multipliedBy($p->remaining_quantity)), BigDecimal::zero());
        $result = $this->risk->assess([
            'account_status' => $account->status, 'cooldown_until' => $account->cooldown_until?->toDateTimeImmutable(), 'at' => $signal->decision_time->toDateTimeImmutable(),
            'open_positions' => $active->count(), 'equity' => $account->current_equity,
            'free_capital' => (string) BigDecimal::of($account->current_equity)->minus($account->reserved_risk),
            'risk_percent' => $signal->model_risk_percent, 'quality_class' => $signal->quality_class,
            'total_risk_percent' => $totalRiskPercent, 'drawdown_percent' => $account->current_drawdown_percent, 'drawdown_override' => $account->drawdown_override,
            'market_failures' => $failures, 'same_direction_correlated_exposure' => $same !== null, 'correlation' => $corr,
            'notional_after' => (string) $notional->plus($size?->notional ?? '0'),
        ]);

        return ['allowed' => $result->allowed, 'reasons' => $result->reasons, 'snapshot' => array_merge($result->snapshot, ['position_size' => $size?->quantity, 'risk_capital' => $size?->riskCapital])];
    }

    private function processState(PaperAccountRecord $account, PaperSignalStateRecord $state, DateTimeImmutable $at, bool $historical): int
    {
        $signal = SignalRecord::query()->findOrFail($state->signal_id);
        $watermark = $state->processed_at?->toDateTimeImmutable() ?? $signal->decision_time->toDateTimeImmutable();
        $bars = $this->bars($signal->exchange, $signal->category, $signal->symbol, $watermark, $at);
        $processed = 0;
        $expected = $watermark->modify('+5 minutes');
        foreach ($bars as $bar) {
            if ($bar->closeTime != $expected) {
                if ($position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->first()) {
                    $position->update(['awaiting_market_data' => true]);
                }
                if (! $historical) {
                    RecoverCandleGapsJob::dispatch();
                }
                break;
            }
            $processed++;
            $expected = $bar->closeTime->modify('+5 minutes');
            $state->refresh();
            if ($state->status === SignalLifecycle::PendingEntry->value) {
                if (! $historical && $bar->closeTime->getTimestamp() < time() - (int) ($this->config['processing']['max_forward_fill_lag_seconds'] ?? 600)) {
                    // Never simulate a fill on a bar that had already passed before the processor ran.
                    $state->update(['processed_at' => $bar->closeTime]);

                    continue;
                }
                if ($bar->closeTime > $signal->expires_at->toDateTimeImmutable()) {
                    SignalLifecycle::PendingEntry->transitionTo(SignalLifecycle::Expired);
                    $state->update(['status' => SignalLifecycle::Expired->value, 'reason' => 'entry_window_expired', 'processed_at' => $bar->closeTime]);
                    $this->updateObservationDiagnostics($account, $signal, ['paper_execution' => 'ENTRY_EXPIRED']);
                    $this->journal->append($account->id, $signal->id, null, 'SIGNAL_EXPIRED', $bar->closeTime, [], "expired:{$account->id}:{$signal->id}");
                    break;
                }
                $quote = $this->execution->entry($signal->side, $signal->entry_low, $signal->entry_high, $signal->stop_loss, $signal->take_profit_1, $signal->take_profit_2, $signal->decision_time->toDateTimeImmutable(), $signal->expires_at->toDateTimeImmutable(), $bar);
                $this->journal->append($account->id, $signal->id, null, 'ENTRY_EVALUATED', $bar->closeTime, [
                    'paper' => true, 'price_source' => $bar->source, 'entry_reached' => $quote !== null,
                    'bar' => ['open' => $bar->open, 'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close],
                ], "entry-evaluated:{$account->id}:{$signal->id}:{$bar->closeTime->format('U.u')}");
                if ($quote !== null) {
                    $entryAssessment = $this->assessment($account->refresh(), $signal);
                    if (! $entryAssessment['allowed']) {
                        SignalLifecycle::from($state->status)->transitionTo(SignalLifecycle::Rejected);
                        $state->update(['status' => SignalLifecycle::Rejected->value, 'reason' => $entryAssessment['reasons'][0] ?? 'entry_risk_rejected', 'processed_at' => $bar->closeTime]);
                        $this->updateObservationDiagnostics($account, $signal, ['risk_approved' => false, 'risk_rejection_reasons' => $entryAssessment['reasons'], 'paper_execution' => 'ENTRY_RISK_REJECTED']);
                        $this->journal->append($account->id, $signal->id, null, 'ENTRY_RISK_REJECTED', $bar->closeTime, $entryAssessment, "entry-risk:{$account->id}:{$signal->id}:{$bar->closeTime->format('U.u')}");
                        break;
                    }
                    $context = MarketContextRecord::query()->where('exchange', $signal->exchange)->where('category', $signal->category)
                        ->where('symbol', $signal->symbol)->where('observed_at', '<=', $signal->decision_time)->latest('observed_at')->firstOrFail();
                    $limits = new InstrumentConstraints($context->qty_step, $context->min_order_qty, $context->max_order_qty, $context->min_notional_value);
                    $size = $this->sizing->calculate($account->refresh()->current_equity, $signal->model_risk_percent, $quote->price, $signal->stop_loss, $limits);
                    if (! $size->allowed) {
                        SignalLifecycle::from($state->status)->transitionTo(SignalLifecycle::Rejected);
                        $state->update(['status' => SignalLifecycle::Rejected->value, 'reason' => $size->reason, 'processed_at' => $bar->closeTime]);
                        $this->updateObservationDiagnostics($account, $signal, ['paper_execution' => 'EXECUTION_FAILED', 'execution_failure_reason' => $size->reason]);
                        $this->journal->append($account->id, $signal->id, null, 'EXECUTION_FAILED', $bar->closeTime, [
                            'stage' => 'position_sizing', 'reason' => $size->reason,
                        ], "execution-failed:{$account->id}:{$signal->id}:{$bar->closeTime->format('U.u')}");
                        break;
                    }
                    $position = $this->lifecycle->open($account->refresh(), $state, $signal, $size, $quote, $bar->closeTime);
                    $this->updateObservationDiagnostics($account, $signal, ['paper_execution' => 'OPENED', 'position_id' => $position->id]);
                    $exit = $this->execution->exit($position->side, $position->status, $position->stop_loss, $position->take_profit_1, $position->take_profit_2, $position->maximum_holding_until->toDateTimeImmutable(), $bar);
                    if ($quote->ambiguous && $exit !== null) {
                        $position = $this->lifecycle->executeExit($position, $exit, $bar->closeTime);
                        if (PositionStatus::from($position->status)->isClosed()) {
                            $this->updateObservationDiagnostics($account, $signal, ['paper_execution' => 'CLOSED', 'position_id' => $position->id]);
                        }
                    }
                } else {
                    $state->update(['processed_at' => $bar->closeTime]);
                }
            } else {
                $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
                if (PositionStatus::from($position->status)->isClosed()) {
                    $this->updateObservationDiagnostics($account, $signal, ['paper_execution' => 'CLOSED', 'position_id' => $position->id]);
                    break;
                }
                $this->lifecycle->applyFunding($position, $bar->closeTime);
                $quote = $this->execution->exit($position->side, $position->status, $position->stop_loss, $position->take_profit_1, $position->take_profit_2, $position->maximum_holding_until->toDateTimeImmutable(), $bar);
                if ($quote !== null) {
                    $position = $this->lifecycle->executeExit($position, $quote, $bar->closeTime);
                } else {
                    $this->lifecycle->markToMarket($position, $bar->close, $bar->closeTime);
                    $freshAccount = PaperAccountRecord::query()->findOrFail($account->id);
                    if (BigDecimal::of($freshAccount->current_drawdown_percent)->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['drawdown']['risk_closure_percent']))) {
                        $position = $this->lifecycle->executeExit($position->refresh(), $this->execution->riskClosure($position->side, $bar->close, $bar->source), $bar->closeTime);
                    }
                }
                $state->update(['processed_at' => $bar->closeTime]);
                if (PositionStatus::from($position->status)->isClosed()) {
                    break;
                }
            }
        }

        return $processed;
    }

    private function bars(string $exchange, string $category, string $symbol, DateTimeImmutable $after, DateTimeImmutable $through): array
    {
        return CandleRecord::query()->where('exchange', $exchange)->where('category', $category)->where('symbol', $symbol)->where('interval', '5')->where('is_closed', true)
            ->where('open_time', '>=', $after)->where('open_time', '<=', $through->modify('-5 minutes'))->oldest('open_time')->limit((int) $this->config['processing']['batch_size'])->get()
            ->map(fn (CandleRecord $c): MarketBar => new MarketBar($c->open_time->toDateTimeImmutable(), $c->open_time->toDateTimeImmutable()->modify('+5 minutes'), $c->open, $c->high, $c->low, $c->close, true))->all();
    }

    private function latestClosedTime(?string $symbol): ?DateTimeImmutable
    {
        $record = CandleRecord::query()->where('interval', '5')->where('is_closed', true)->when($symbol, fn ($q) => $q->where('symbol', $symbol))->latest('open_time')->first();

        return $record?->open_time->toDateTimeImmutable()->modify('+5 minutes');
    }

    private function updateObservationDiagnostics(PaperAccountRecord $account, SignalRecord $signal, array $changes): void
    {
        if ($account->mode === 'RESEARCH_FORWARD_PAPER') {
            return;
        }
        $observation = StrategyObservationRecord::query()->where('strategy_id', $signal->strategy_id)
            ->where('strategy_version', $signal->strategy_version)->where('symbol', $signal->symbol)
            ->where('decision_time', $signal->decision_time)->latest('id')->first();
        if ($observation === null) {
            return;
        }
        $analysis = $observation->analysis ?? [];
        $analysis['diagnostics'] = array_replace($analysis['diagnostics'] ?? [], $changes);
        $observation->update(['analysis' => $analysis]);
    }
}
