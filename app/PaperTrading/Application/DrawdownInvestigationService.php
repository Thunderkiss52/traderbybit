<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Jobs\InvestigatePaperDrawdownJob;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DrawdownInvestigationService
{
    public function __construct(private TradingJournalService $journal) {}

    public function request(PaperAccountRecord $account, DateTimeImmutable $at): void
    {
        if (BigDecimal::of($account->current_drawdown_percent)->isLessThan('5')) {
            return;
        }
        $episode = JournalEventRecord::query()->where('paper_account_id', $account->id)
            ->where('event_type', 'OWNER_APPROVED_PAPER_RESUME')->max('sequence') ?? 'initial';
        $key = 'drawdown-entry-block:'.$account->id.':'.$episode;
        if (JournalEventRecord::query()->where('idempotency_key', $key)->exists()) {
            return;
        }
        $this->journal->append($account->id, null, null, 'DRAWDOWN_ENTRY_BLOCKED', $at, [
            'strategy_id' => $account->strategy_id, 'strategy_version' => $account->strategy_version,
            'category' => 'linear', 'drawdown_percent' => $account->current_drawdown_percent,
            'equity' => $account->current_equity, 'peak_equity' => $account->peak_equity,
            'status' => $account->status, 'entries_blocked' => true, 'exits_and_settlement_enabled' => true,
        ], $key);
        InvestigatePaperDrawdownJob::dispatch()->afterCommit();
    }

    /** Capture bounded, as-of evidence only. This never replays rejected signals as trades. */
    public function investigatePending(): int
    {
        $events = JournalEventRecord::query()
            ->where('event_type', 'DRAWDOWN_ENTRY_BLOCKED')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('trading_journal_events as captured')
                    ->whereColumn('captured.paper_account_id', 'trading_journal_events.paper_account_id')
                    ->where('captured.event_type', 'DRAWDOWN_INVESTIGATION_CAPTURED')
                    ->whereRaw("captured.idempotency_key = 'drawdown-investigation:' || trading_journal_events.id::text");
            })->oldest('sequence')->limit(5)->get();
        $count = 0;
        foreach ($events as $event) {
            $count += DB::transaction(function () use ($event): int {
                JournalEventRecord::query()->whereKey($event->sequence)->lockForUpdate()->firstOrFail();
                $key = 'drawdown-investigation:'.$event->id;
                if (JournalEventRecord::query()->where('idempotency_key', $key)->exists()) {
                    return 0;
                }
                $account = PaperAccountRecord::query()->findOrFail($event->paper_account_id);
                $at = CarbonImmutable::instance($event->occurred_at);
                $recordedAt = CarbonImmutable::instance($event->created_at);
                $strategy = (string) $event->payload['strategy_id'];
                $from = $at->subDays(14);
                $positions = VirtualPositionRecord::query()->where('paper_account_id', $account->id)
                    ->where('exchange_id', $event->exchange_id)->where('created_at', '<=', $recordedAt)
                    ->whereBetween('closed_at', [$from, $at])->orderByDesc('closed_at')->limit(30)
                    ->get(['id', 'signal_id', 'symbol', 'strategy_version', 'closed_at']);
                $losses = [];
                foreach ($positions as $position) {
                    $executions = VirtualExecutionRecord::query()->where('position_id', $position->id)
                        ->where('exchange_id', $event->exchange_id)->where('market_event_at', '<=', $at)
                        ->where('created_at', '<=', $recordedAt)->get(['type', 'gross_pnl', 'commission']);
                    if (! $executions->contains(fn ($execution): bool => in_array($execution->type, ['TAKE_PROFIT_2', 'STOP_LOSS', 'TIMEOUT', 'RISK_CLOSURE'], true))) {
                        continue;
                    }
                    $gross = BigDecimal::zero();
                    $fees = BigDecimal::zero();
                    foreach ($executions as $execution) {
                        $gross = $gross->plus($execution->gross_pnl);
                        $fees = $fees->plus($execution->commission);
                    }
                    $funding = BigDecimal::zero();
                    foreach (PositionFundingEventRecord::query()->where('position_id', $position->id)
                        ->where('exchange_id', $event->exchange_id)->where('funding_at', '<=', $at)
                        ->where('created_at', '<=', $recordedAt)->get(['amount']) as $payment) {
                        $funding = $funding->plus($payment->amount);
                    }
                    $net = $gross->minus($fees)->plus($funding);
                    if ($net->isNegative()) {
                        $losses[] = $position->toArray() + ['net_pnl' => (string) $net,
                            'fees' => (string) $fees, 'funding' => (string) $funding];
                    }
                }
                $risk = RiskDecisionRecord::query()->where('paper_account_id', $account->id)
                    ->where('exchange_id', $event->exchange_id)->where('allowed', false)->where('created_at', '<=', $recordedAt)
                    ->whereBetween('decided_at', [$from, $at])->orderByDesc('decided_at')->limit(30)
                    ->get(['signal_id', 'reasons', 'snapshot', 'decided_at'])->toArray();
                $states = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)
                    ->where('exchange_id', $event->exchange_id)->whereBetween('processed_at', [$from, $at])
                    ->where('created_at', '<=', $recordedAt)->where('updated_at', '<=', $recordedAt)
                    ->whereIn('status', ['REJECTED', 'EXPIRED'])->orderByDesc('processed_at')->limit(30)
                    ->get(['signal_id', 'status', 'reason', 'processed_at'])->toArray();
                $observations = StrategyObservationRecord::query()->where('exchange', $event->exchange_id)
                    ->where('strategy_id', $strategy)->where('strategy_version', $event->payload['strategy_version'])
                    ->where('category', $event->payload['category'])->whereNull('superseded_by')
                    ->where('created_at', '<=', $recordedAt)->where('updated_at', '<=', $recordedAt)
                    ->whereBetween('decision_time', [$from, $at])->orderByDesc('decision_time')->limit(30)
                    ->get(['id', 'symbol', 'side', 'decision_time', 'reasons', 'analysis'])->toArray();
                $timeframes = [];
                foreach (array_slice((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']), 0, 2) as $symbol) {
                    foreach ([1, 3, 15, 30] as $minutes) {
                        $bars = CandleRecord::query()->where('exchange', $event->exchange_id)
                            ->where('category', $event->payload['category'])->where('symbol', $symbol)
                            ->where('interval', (string) $minutes)->where('is_closed', true)
                            ->where('created_at', '<=', $recordedAt)->where('updated_at', '<=', $recordedAt)
                            ->where('open_time', '<=', $at->subMinutes($minutes))
                            ->orderByDesc('open_time')->limit(200)->get(['open_time']);
                        $gaps = 0;
                        $misaligned = 0;
                        $previous = null;
                        foreach ($bars as $bar) {
                            $timestamp = $bar->open_time->getTimestamp();
                            $misaligned += (int) ($timestamp % ($minutes * 60) !== 0);
                            $gaps += (int) ($previous !== null && $previous - $timestamp !== $minutes * 60);
                            $previous = $timestamp;
                        }
                        $latestClose = $bars->first()?->open_time->addMinutes($minutes);
                        $timeframes[$symbol][(string) $minutes] = [
                            'closed_bars_sampled' => $bars->count(), 'sample_limit' => 200,
                            'latest_close' => $latestClose?->toIso8601String(),
                            'status' => $latestClose === null ? 'UNAVAILABLE'
                                : ($latestClose->lt($at->subMinutes($minutes)) ? 'STALE' : 'AVAILABLE'),
                            'gap_count' => $gaps, 'misaligned_count' => $misaligned,
                        ];
                    }
                }
                $this->journal->append($account->id, null, null, 'DRAWDOWN_INVESTIGATION_CAPTURED', now()->toDateTimeImmutable(), [
                    'source_event_id' => $event->id, 'as_of' => $at->toIso8601String(),
                    'recorded_as_of' => $recordedAt->toIso8601String(),
                    'evidence_policy' => 'ECONOMIC_TIME_AND_RECORDED_AVAILABILITY_REQUIRED',
                    'strategy_id' => $strategy, 'strategy_version' => $event->payload['strategy_version'],
                    'losses' => $losses, 'risk_rejections' => $risk, 'signal_outcomes' => $states,
                    'observations' => $observations, 'timeframes' => $timeframes,
                    'status' => 'EVIDENCE_CAPTURED', 'parameter_evaluation' => 'OUT_OF_SAMPLE_VALIDATION_REQUIRED',
                    'candidate_generated' => false, 'account_reactivation_allowed' => false,
                    'historical_performance_modified' => false,
                ], $key);

                return 1;
            }, attempts: 3);
        }

        return $count;
    }
}
