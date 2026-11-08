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

        if ($exchange !== '' && ! in_array($exchange, $registeredIds, true)) {
            return $this->badRequest('unsupported_exchange', "Exchange '{$exchange}' is not registered.");
        }

        $strategyId = $request->string('strategy_id')->toString();
        $symbol = strtoupper($request->string('symbol')->toString());
        $side = strtoupper($request->string('side')->toString());
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        // Metrics aggregation
        $obsQuery = StrategyObservationRecord::query();
        $sigQuery = SignalRecord::query();
        $posQuery = VirtualPositionRecord::query();
        $riskQuery = RiskDecisionRecord::query();

        if ($exchange !== '') {
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

        $totalEvaluations = (clone $obsQuery)->count();
        $totalSignals = (clone $sigQuery)->whereIn('side', ['BUY', 'SELL'])->count();
        $totalSkips = (clone $obsQuery)->where('side', 'SKIP')->count();
        $riskApproved = (clone $riskQuery)->where('allowed', true)->count();
        $activePositions = (clone $posQuery)->where('status', 'OPEN')->count();
        $closedPositions = (clone $posQuery)->whereIn('status', ['CLOSED', 'RESOLVED'])->count();

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
            $riskDecisions = RiskDecisionRecord::query()->whereIn('signal_id', $signalIds)->get()->keyBy('signal_id');
            $positions = VirtualPositionRecord::query()->whereIn('signal_id', $signalIds)->get()->keyBy('signal_id');

            foreach ($paginator->items() as $sig) {
                $rules = data_get($sig->contract, 'rules') ?? data_get($sig->indicator_snapshot, 'rules') ?? [];
                $risk = $riskDecisions->get($sig->id);
                $pos = $positions->get($sig->id);

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
                    'score' => $sig->score,
                    'entry_zone' => ['low' => $sig->entry_low, 'high' => $sig->entry_high],
                    'stop_loss' => $sig->stop_loss,
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
                    'risk_decision' => $risk ? [
                        'id' => $risk->id,
                        'allowed' => $risk->allowed,
                        'reasons' => $risk->reasons,
                        'snapshot' => $risk->snapshot,
                        'decided_at' => $risk->decided_at?->toIso8601String(),
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
                            'title' => 'Closed 15m Market Candle Ingested',
                            'timestamp' => $candle->open_time?->addMinutes(15)->toIso8601String(),
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
                $risk = RiskDecisionRecord::query()->where('signal_id', $signal->id)->latest('decided_at')->first();
                if ($risk !== null) {
                    $timeline[] = [
                        'stage' => 'RISK_DECISION',
                        'title' => $risk->allowed ? 'Risk Guardrails Approved' : 'Risk Guardrails Rejected',
                        'timestamp' => $risk->decided_at?->toIso8601String(),
                        'details' => [
                            'allowed' => $risk->allowed,
                            'reasons' => $risk->reasons,
                            'sizing' => data_get($risk->snapshot, 'sizing') ?? $risk->snapshot,
                        ],
                        'color' => $risk->allowed ? 'positive' : 'negative',
                        'icon' => $risk->allowed ? 'verified_user' : 'gpp_bad',
                    ];
                }

                // 5. Journal and execution events
                $journalEvents = JournalEventRecord::query()
                    ->where('signal_id', $signal->id)
                    ->orderBy('occurred_at')
                    ->orderBy('sequence')
                    ->get();

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
