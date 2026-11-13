<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exchanges\ExchangeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StrategyMonitorController extends Controller
{
    private function badRequest(string $code, string $message): JsonResponse
    {
        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    public function index(Request $request, ExchangeRegistry $exchanges, StrategyRegistry $strategies): ResearchResource|JsonResponse
    {
        $exchange = $request->header('X-Exchange') ?: $request->string('exchange')->toString();
        $registeredIds = $exchanges->registeredIds();

        if ($exchange !== '' && $exchange !== 'all' && ! in_array($exchange, $registeredIds, true)) {
            return $this->badRequest('unsupported_exchange', "Exchange '{$exchange}' is not registered.");
        }

        $strategyId = $request->string('strategy_id')->toString();
        $symbol = strtoupper($request->string('symbol')->toString());
        if ($symbol === '' || $symbol === 'ALL') {
            $symHeader = $request->header('X-Symbol') ?: $request->header('X-Instrument') ?: $request->string('instrument')->toString();
            if ($symHeader === 'BTC' || strtoupper($symHeader) === 'BTCUSDT') {
                $symbol = 'BTCUSDT';
            } elseif ($symHeader === 'ETH' || strtoupper($symHeader) === 'ETHUSDT') {
                $symbol = 'ETHUSDT';
            } else {
                $symbol = '';
            }
        }
        $side = strtoupper($request->string('side')->toString());
        $quality = strtoupper($request->string('quality')->toString());
        $risk = strtoupper($request->string('risk')->toString());
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        // Metrics aggregation
        $obsQuery = StrategyObservationRecord::query();
        $sigQuery = SignalRecord::query();
        $posQuery = VirtualPositionRecord::query();
        $riskQuery = RiskDecisionRecord::query();

        if ($exchange !== '' && $exchange !== 'all') {
            $obsQuery->where('exchange', $exchange);
            $sigQuery->where('exchange', $exchange);
            $posQuery->where('exchange_id', $exchange);
            $riskQuery->where('exchange_id', $exchange);
        }
        if ($strategyId !== '') {
            $obsQuery->where('strategy_id', $strategyId);
            $sigQuery->where('strategy_id', $strategyId);
        }
        if ($symbol !== '') {
            $obsQuery->where('symbol', $symbol);
            $sigQuery->where('symbol', $symbol);
            $posQuery->where('symbol', $symbol);
        }

        if ($quality !== '' && $quality !== 'ALL') {
            if ($quality === 'UNKNOWN') {
                $sigQuery->where(function (Builder $b): void {
                    $b->whereNull('signal_quality')->orWhere('signal_quality', 'UNKNOWN');
                });
            } else {
                $sigQuery->where(function (Builder $b) use ($quality): void {
                    $b->where('signal_quality', $quality)
                        ->orWhere('quality_class', match ($quality) {
                            'HIGH' => 'A',
                            'MEDIUM' => 'B',
                            'LOW' => 'C',
                            default => null,
                        });
                });
            }
        }

        if ($risk !== '' && $risk !== 'ALL') {
            if ($risk === 'UNKNOWN') {
                $sigQuery->where(function (Builder $b): void {
                    $b->whereNull('trade_risk')->orWhere('trade_risk', 'UNKNOWN');
                });
            } else {
                $sigQuery->where('trade_risk', $risk);
            }
        }

        // Consolidated aggregation queries for high performance (resolves 15s timeout)
        $obsAgg = (clone $obsQuery)
            ->selectRaw("
                COUNT(*) as total_evaluations,
                COUNT(*) FILTER (WHERE side = 'SKIP') as total_skips
            ")->first();
        $totalEvaluations = (int) ($obsAgg?->total_evaluations ?? 0);
        $totalSkips = (int) ($obsAgg?->total_skips ?? 0);

        $sigAgg = (clone $sigQuery)
            ->selectRaw("
                COUNT(*) FILTER (WHERE side IN ('BUY', 'SELL')) as total_signals,
                COUNT(*) FILTER (WHERE side = 'BUY') as long_signals,
                COUNT(*) FILTER (WHERE side = 'SELL') as short_signals,
                COUNT(*) FILTER (WHERE signal_quality = 'HIGH' OR quality_class = 'A') as q_high,
                COUNT(*) FILTER (WHERE signal_quality = 'MEDIUM' OR quality_class = 'B') as q_medium,
                COUNT(*) FILTER (WHERE signal_quality = 'LOW' OR quality_class = 'C') as q_low,
                COUNT(*) FILTER (WHERE signal_quality IS NULL AND quality_class IS NULL) as q_unknown,
                COUNT(*) FILTER (WHERE trade_risk = 'LOW') as r_low,
                COUNT(*) FILTER (WHERE trade_risk = 'MEDIUM') as r_medium,
                COUNT(*) FILTER (WHERE trade_risk = 'HIGH') as r_high,
                COUNT(*) FILTER (WHERE trade_risk IS NULL OR trade_risk = 'UNKNOWN') as r_unknown
            ")->first();

        $totalSignals = (int) ($sigAgg?->total_signals ?? 0);
        $longSignalsCount = (int) ($sigAgg?->long_signals ?? 0);
        $shortSignalsCount = (int) ($sigAgg?->short_signals ?? 0);
        $qualityCounts = [
            'HIGH' => (int) ($sigAgg?->q_high ?? 0),
            'MEDIUM' => (int) ($sigAgg?->q_medium ?? 0),
            'LOW' => (int) ($sigAgg?->q_low ?? 0),
            'UNKNOWN' => (int) ($sigAgg?->q_unknown ?? 0),
        ];
        $riskCounts = [
            'LOW' => (int) ($sigAgg?->r_low ?? 0),
            'MEDIUM' => (int) ($sigAgg?->r_medium ?? 0),
            'HIGH' => (int) ($sigAgg?->r_high ?? 0),
            'UNKNOWN' => (int) ($sigAgg?->r_unknown ?? 0),
        ];

        // Every risk decision and position must belong to the same filtered signal cohort.
        // Counting by venue alone leaks PnL/execution metrics across strategies and risk filters.
        $filteredSignalIds = (clone $sigQuery)->select('id');
        $posQuery->whereIn('signal_id', clone $filteredSignalIds);
        $riskQuery->whereIn('signal_id', clone $filteredSignalIds);
        $posAgg = (clone $posQuery)
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ('OPEN', 'PARTIALLY_CLOSED')) as active_positions, COUNT(*) FILTER (WHERE status IN ('CLOSED', 'CLOSED_TP', 'CLOSED_SL', 'CLOSED_TIMEOUT', 'CLOSED_RISK', 'RESOLVED')) as closed_positions")
            ->first();
        $activePositions = (int) ($posAgg?->active_positions ?? 0);
        $closedPositions = (int) ($posAgg?->closed_positions ?? 0);
        $riskApproved = (clone $riskQuery)->where('allowed', true)->count();

        // Feed items: BUY/SELL from SignalRecord or SKIP from StrategyObservationRecord
        $items = [];
        if ($side === 'SKIP') {
            $paginator = (clone $obsQuery)->where('side', 'SKIP')->latest('decision_time')->latest('id')->paginate($perPage);
            foreach ($paginator->items() as $obs) {
                $rules = data_get($obs->analysis, 'rules') ?? data_get($obs->analysis, 'indicator_snapshot.rules') ?? [];
                $items[] = [
                    'id' => $obs->id,
                    'type' => 'OBSERVATION',
                    'exchange' => $obs->exchange,
                    'category' => $obs->category,
                    'symbol' => $obs->symbol,
                    'timeframe' => '15m',
                    'strategy_id' => $obs->strategy_id,
                    'strategy_version' => $obs->strategy_version,
                    'side' => 'SKIP',
                    'quality_class' => null,
                    'signal_quality' => 'UNKNOWN',
                    'trade_risk' => 'UNKNOWN',
                    'score' => $obs->score,
                    'reasons' => $obs->reasons,
                    'risks' => [],
                    'rules' => $rules,
                    'decision_time' => $obs->decision_time?->toIso8601String(),
                    'candle_close_time' => $obs->decision_time?->toIso8601String(),
                    'candle_id' => null,
                    'diagnostics' => data_get($obs->analysis, 'diagnostics'),
                    'risk_decision' => null,
                    'position' => null,
                    'risk_decision_count' => 0,
                    'position_count' => 0,
                    'risk_decisions' => [],
                    'positions' => [],
                ];
            }
        } else {
            $signalsBuilder = (clone $sigQuery);
            if ($side !== '') {
                $signalsBuilder->where('side', $side);
            } else {
                $signalsBuilder->whereIn('side', ['BUY', 'SELL']);
            }
            $paginator = $signalsBuilder->latest('decision_time')->latest('id')->paginate($perPage);

            $signalIds = collect($paginator->items())->pluck('id')->all();
            $riskDecisions = RiskDecisionRecord::query()->whereIn('signal_id', $signalIds)
                ->orderBy('decided_at')->orderBy('id')->get()->groupBy('signal_id');
            $positions = VirtualPositionRecord::query()->whereIn('signal_id', $signalIds)
                ->orderBy('opened_at')->orderBy('id')->get()->groupBy('signal_id');

            foreach ($paginator->items() as $sig) {
                $rules = data_get($sig->contract, 'rules') ?? data_get($sig->indicator_snapshot, 'rules') ?? [];
                $signalRiskDecisions = $riskDecisions->get($sig->id, collect());
                $signalPositions = $positions->get($sig->id, collect());
                // Legacy singular fields are unambiguous only for one account.
                $riskRecord = $signalRiskDecisions->count() === 1 ? $signalRiskDecisions->first() : null;
                $pos = $signalPositions->count() === 1 ? $signalPositions->first() : null;

                // No fabricated 0% stop distance when the entry plan is missing.
                $entryMid = ($sig->entry_low !== null && $sig->entry_high !== null)
                    ? BigDecimal::of((string) $sig->entry_low)
                        ->plus(BigDecimal::of((string) $sig->entry_high))
                        ->dividedBy(2, 8, RoundingMode::HalfEven)
                    : null;
                $stopDistPercent = null;
                if ($entryMid !== null && $entryMid->isPositive() && $sig->stop_loss !== null) {
                    $dist = BigDecimal::of((string) $sig->stop_loss)->minus($entryMid)->abs();
                    $stopDistPercent = (string) $dist->dividedBy($entryMid, 6, RoundingMode::HalfEven)->multipliedBy(100);
                }

                $riskSnapshot = $riskRecord ? (is_array($riskRecord->snapshot) ? $riskRecord->snapshot : (json_decode((string) $riskRecord->snapshot, true) ?? [])) : [];
                $riskCapital = $riskSnapshot['risk_capital'] ?? null;
                $notional = $riskSnapshot['notional_after'] ?? $riskSnapshot['notional'] ?? null;
                // Margin must come from persisted sizing evidence, never a synthetic 10x leverage assumption.
                $margin = $riskSnapshot['margin'] ?? $riskSnapshot['margin_usdt'] ?? null;
                $signalQualityVal = $sig->signal_quality ?? ($sig->quality_class ? match ($sig->quality_class) {
                    'A' => 'HIGH', 'B' => 'MEDIUM', 'C' => 'LOW', default => 'UNKNOWN'
                } : 'UNKNOWN');
                $tradeRiskVal = $sig->trade_risk ?? $riskRecord?->trade_risk ?? $riskSnapshot['trade_risk'] ?? 'UNKNOWN';
                $gateDecision = $signalRiskDecisions->count() > 1 ? 'MULTI_ACCOUNT' :
                    ($riskSnapshot['gate_decision'] ?? ($riskRecord ? ($riskRecord->allowed ? 'ELIGIBLE' : 'REJECTED') : null));
                $expectedNetEdge = $riskSnapshot['expected_net_edge'] ?? null;

                $items[] = [
                    'id' => $sig->id,
                    'type' => 'SIGNAL',
                    'exchange' => $sig->exchange,
                    'category' => $sig->category,
                    'symbol' => $sig->symbol,
                    'timeframe' => $sig->timeframe,
                    'strategy_id' => $sig->strategy_id,
                    'strategy_version' => $sig->strategy_version,
                    'side' => $sig->side,
                    'quality_class' => $sig->quality_class,
                    'signal_quality' => $signalQualityVal,
                    'trade_risk' => $tradeRiskVal,
                    'score' => $sig->score,
                    'entry_zone' => ['low' => $sig->entry_low, 'high' => $sig->entry_high],
                    'stop_loss' => $sig->stop_loss,
                    'planned_stop_loss' => [
                        'price' => $sig->stop_loss,
                        'distance_percent' => $stopDistPercent,
                        'loss_amount' => $riskCapital,
                    ],
                    'notional' => $notional,
                    'margin' => $margin,
                    'expected_net_edge' => $expectedNetEdge,
                    'gate_decision' => $gateDecision,
                    'take_profit_1' => $sig->take_profit_1,
                    'take_profit_2' => $sig->take_profit_2,
                    'risk_reward' => ['tp1' => $sig->risk_reward_1, 'tp2' => $sig->risk_reward_2],
                    'model_risk_percent' => $sig->model_risk_percent,
                    'reasons' => $sig->reasons,
                    'risks' => $sig->risks,
                    'rules' => $rules,
                    'decision_time' => $sig->decision_time?->toIso8601String(),
                    'candle_close_time' => $sig->candle_close_time?->toIso8601String(),
                    'candle_id' => $sig->candle_id,
                    'risk_decision_count' => $signalRiskDecisions->count(),
                    'position_count' => $signalPositions->count(),
                    'risk_decisions' => $signalRiskDecisions->map(fn ($decision) => [
                        'id' => $decision->id,
                        'paper_account_id' => $decision->paper_account_id,
                        'exchange_id' => $decision->exchange_id,
                        'allowed' => $decision->allowed,
                        'reasons' => $decision->reasons,
                        'snapshot' => $decision->snapshot,
                        'decided_at' => $decision->decided_at?->toIso8601String(),
                    ])->values()->all(),
                    'positions' => $signalPositions->map(fn ($position) => [
                        'id' => $position->id,
                        'paper_account_id' => $position->paper_account_id,
                        'exchange_id' => $position->exchange_id,
                        'status' => $position->status,
                        'entry_price' => $position->entry_price,
                        'initial_quantity' => $position->initial_quantity,
                        'remaining_quantity' => $position->remaining_quantity,
                        'fees' => $position->fees,
                        'gross_pnl' => $position->gross_pnl,
                        'net_pnl' => $position->net_pnl,
                        'opened_at' => $position->opened_at?->toIso8601String(),
                        'closed_at' => $position->closed_at?->toIso8601String(),
                    ])->values()->all(),
                    'risk_decision' => $riskRecord ? [
                        'id' => $riskRecord->id,
                        'allowed' => $riskRecord->allowed,
                        'signal_quality' => $riskRecord->signal_quality ?? $signalQualityVal,
                        'trade_risk' => $riskRecord->trade_risk ?? $tradeRiskVal,
                        'reasons' => $riskRecord->reasons,
                        'snapshot' => $riskRecord->snapshot,
                        'decided_at' => $riskRecord->decided_at?->toIso8601String(),
                    ] : null,
                    'position' => $pos ? [
                        'id' => $pos->id,
                        'status' => $pos->status,
                        'entry_price' => $pos->entry_price,
                        'initial_quantity' => $pos->initial_quantity,
                        'remaining_quantity' => $pos->remaining_quantity,
                        'fees' => $pos->fees,
                        'gross_pnl' => $pos->gross_pnl,
                        'net_pnl' => $pos->net_pnl,
                        'opened_at' => $pos->opened_at?->toIso8601String(),
                        'closed_at' => $pos->closed_at?->toIso8601String(),
                    ] : null,
                ];
            }
        }

        // Active paper accounts summary
        $accountsSummary = PaperAccountRecord::query()
            ->selectRaw('exchange_id, count(*) as count, sum(case when status = \'ACTIVE\' then 1 else 0 end) as active')
            ->groupBy('exchange_id')
            ->get()
            ->keyBy('exchange_id');

        $exchangeList = [];
        foreach ($registeredIds as $eId) {
            $acc = $accountsSummary->get($eId);
            $exchangeList[] = [
                'id' => $eId,
                'name' => strtoupper($eId),
                'accounts_count' => (int) ($acc->count ?? 0),
                'active_accounts' => (int) ($acc->active ?? 0),
            ];
        }

        return new ResearchResource([
            'exchanges' => $exchangeList,
            'strategies' => $strategies->metadata(),
            'metrics' => [
                'total_evaluations' => $totalEvaluations,
                'total_signals' => $totalSignals,
                'total_skips' => $totalSkips,
                'risk_approved' => $riskApproved,
                'active_positions' => $activePositions,
                'closed_positions' => $closedPositions,
                'long_signals' => $longSignalsCount,
                'short_signals' => $shortSignalsCount,
                'quality_counts' => $qualityCounts,
                'risk_counts' => $riskCounts,
            ],
            'signals' => [
                'data' => $items,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function timeline(Request $request): ResearchResource|JsonResponse
    {
        $signalId = $request->string('signal_id')->toString();
        $limit = max(10, min(100, $request->integer('limit', 50)));

        $timeline = [];

        if ($signalId !== '') {
            $signal = SignalRecord::query()->find($signalId);
            if ($signal !== null) {
                // 1. Candle closed event
                if ($signal->candle_id) {
                    $candle = CandleRecord::query()->find($signal->candle_id);
                    if ($candle !== null) {
                        $timeline[] = [
                            'stage' => 'CANDLE_CLOSED',
                            'title' => 'Closed Market Candle Ingested',
                            'timestamp' => $signal->candle_close_time?->toIso8601String(),
                            'details' => [
                                'exchange' => $candle->exchange,
                                'symbol' => $candle->symbol,
                                'interval' => $candle->interval,
                                'open' => $candle->open,
                                'high' => $candle->high,
                                'low' => $candle->low,
                                'close' => $candle->close,
                                'volume' => $candle->volume,
                            ],
                            'color' => 'blue',
                            'icon' => 'candlestick_chart',
                        ];
                    }
                }

                // 2. Strategy evaluation
                $timeline[] = [
                    'stage' => 'STRATEGY_EVALUATED',
                    'title' => "Strategy Evaluated: {$signal->strategy_id} v{$signal->strategy_version}",
                    'timestamp' => $signal->decision_time?->toIso8601String(),
                    'details' => [
                        'side' => $signal->side,
                        'quality_class' => $signal->quality_class,
                        'score' => $signal->score,
                        'reasons' => $signal->reasons,
                        'rules_evaluated' => count(data_get($signal->contract, 'rules') ?? data_get($signal->indicator_snapshot, 'rules') ?? []),
                    ],
                    'color' => $signal->side === 'BUY' ? 'positive' : ($signal->side === 'SELL' ? 'negative' : 'warning'),
                    'icon' => 'psychology',
                ];

                // 3. Signal generated
                $timeline[] = [
                    'stage' => 'SIGNAL_GENERATED',
                    'title' => "Signal Formed ({$signal->side} {$signal->symbol})",
                    'timestamp' => $signal->decision_time?->toIso8601String(),
                    'details' => [
                        'entry_low' => $signal->entry_low,
                        'entry_high' => $signal->entry_high,
                        'stop_loss' => $signal->stop_loss,
                        'take_profit_1' => $signal->take_profit_1,
                        'take_profit_2' => $signal->take_profit_2,
                        'risk_reward_1' => $signal->risk_reward_1,
                        'risk_reward_2' => $signal->risk_reward_2,
                        'model_risk_percent' => $signal->model_risk_percent,
                        'expires_at' => $signal->expires_at?->toIso8601String(),
                    ],
                    'color' => 'primary',
                    'icon' => 'campaign',
                ];

                // 4. Risk decision
                $riskRecords = RiskDecisionRecord::query()->where('signal_id', $signal->id)
                    ->orderBy('decided_at')->orderBy('id')->get();
                foreach ($riskRecords as $risk) {
                    $timeline[] = [
                        'stage' => 'RISK_DECISION',
                        'title' => $risk->allowed ? 'Risk Guardrails Approved' : 'Risk Guardrails Rejected',
                        'timestamp' => $risk->decided_at?->toIso8601String(),
                        'details' => [
                            'allowed' => $risk->allowed,
                            'paper_account_id' => $risk->paper_account_id,
                            'exchange_id' => $risk->exchange_id,
                            'reasons' => $risk->reasons,
                            'sizing' => data_get($risk->snapshot, 'sizing') ?? $risk->snapshot,
                        ],
                        'color' => $risk->allowed ? 'positive' : 'negative',
                        'icon' => $risk->allowed ? 'verified_user' : 'gpp_bad',
                    ];
                }

                // 5. Journal and execution events
                // The timeline endpoint has a bounded response even for signals
                // with years of journal history. Return recent events in time order.
                $journalEvents = JournalEventRecord::query()
                    ->where('signal_id', $signal->id)
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('sequence')
                    ->limit($limit)
                    ->get()
                    ->reverse();

                foreach ($journalEvents as $je) {
                    $timeline[] = [
                        'stage' => $je->event_type,
                        'title' => "Journal Event: {$je->event_type}",
                        'timestamp' => $je->occurred_at?->toIso8601String(),
                        'details' => $je->payload,
                        'color' => str_contains($je->event_type, 'FAIL') ? 'negative' : (str_contains($je->event_type, 'CLOSED') ? 'purple' : 'teal'),
                        'icon' => 'receipt_long',
                    ];
                }
            }
        } else {
            // General recent timeline across system
            $recentEvents = JournalEventRecord::query()
                ->latest('occurred_at')
                ->latest('sequence')
                ->limit($limit)
                ->get();

            foreach ($recentEvents as $je) {
                $timeline[] = [
                    'stage' => $je->event_type,
                    'title' => "{$je->event_type} ({$je->exchange_id})",
                    'timestamp' => $je->occurred_at?->toIso8601String(),
                    'details' => [
                        'signal_id' => $je->signal_id,
                        'position_id' => $je->position_id,
                        'account_id' => $je->paper_account_id,
                        'payload' => $je->payload,
                    ],
                    'color' => str_contains($je->event_type, 'FAIL') ? 'negative' : (str_contains($je->event_type, 'OPEN') ? 'positive' : 'blue-grey'),
                    'icon' => 'event',
                ];
            }
        }

        return new ResearchResource([
            'signal_id' => $signalId ?: null,
            'events_count' => count($timeline),
            'timeline' => $timeline,
        ]);
    }
}
