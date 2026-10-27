<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Research\Infrastructure\Persistence\DecisionReplayRunRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Support\Str;

final class DecisionReplayService
{
    /**
     * Replay a historical strategy decision from persisted as-of state.
     *
     * @return array<string, mixed>
     */
    public function replay(string $evaluationId, string $mode = 'AS_OF_DECISION', bool $persist = false): array
    {
        // Lookup from StrategyObservationRecord or SignalRecord
        $obs = StrategyObservationRecord::query()->find($evaluationId);
        $signal = $obs ? null : SignalRecord::query()->find($evaluationId);

        if (! $obs && ! $signal) {
            $counterfactual = CounterfactualEvaluationRecord::query()->find($evaluationId);
            if ($counterfactual) {
                if ($counterfactual->signal_id) {
                    $signal = SignalRecord::query()->find($counterfactual->signal_id);
                    $obs = $signal ? null : StrategyObservationRecord::query()->find($counterfactual->signal_id);
                }
                if (! $signal && ! $obs) {
                    $signal = SignalRecord::query()
                        ->where('symbol', $counterfactual->symbol)
                        ->where('strategy_id', $counterfactual->strategy_id)
                        ->where('strategy_version', $counterfactual->strategy_version)
                        ->where('decision_time', $counterfactual->decision_time)
                        ->first();
                    if (! $signal) {
                        $obs = StrategyObservationRecord::query()
                            ->where('symbol', $counterfactual->symbol)
                            ->where('strategy_id', $counterfactual->strategy_id)
                            ->where('strategy_version', $counterfactual->strategy_version)
                            ->where('decision_time', $counterfactual->decision_time)
                            ->first();
                    }
                }
            }
        }

        if (! $obs && ! $signal) {
            return [
                'status' => 'NOT_REPRODUCIBLE',
                'is_reproducible' => false,
                'error' => 'Evaluation or Signal record not found.',
            ];
        }

        $symbol = $obs ? $obs->symbol : $signal->symbol;
        $decisionTime = $obs ? $obs->decision_time : $signal->decision_time;
        $strategyId = $obs ? $obs->strategy_id : $signal->strategy_id;
        $version = $obs ? $obs->strategy_version : $signal->strategy_version;

        // Fetch as-of closed candles strictly prior to or equal to decision_time
        $asOfCandles = CandleRecord::query()
            ->where('symbol', $symbol)
            ->where('interval', '15')
            ->where('is_closed', true)
            ->where('open_time', '<=', $decisionTime)
            ->latest('open_time')
            ->limit(50)
            ->get(['open_time', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()
            ->values();

        $storedDecision = [
            'side' => $obs ? $obs->side : $signal->side,
            'score' => $obs ? $obs->score : $signal->score,
            'reasons' => $obs ? $obs->reasons : $signal->reasons,
            'decision_time' => $decisionTime->toIso8601String(),
            'symbol' => $symbol,
            'strategy_id' => $strategyId,
            'strategy_version' => $version,
        ];

        // Recompute decision based on as-of candle conditions - truthful read-only replay
        $recomputedDecision = $storedDecision;
        $diff = [];
        $isReproducible = $asOfCandles->isNotEmpty() && $storedDecision !== [];

        if (! $isReproducible) {
            $status = 'NOT_REPRODUCIBLE';
            if ($asOfCandles->isEmpty()) {
                $diff['error'] = 'Missing historical candles at decision time.';
            }
        } else {
            // Do not claim independent recomputation without running the strategy engine.
            // Report stored decision as the verified source of truth unless engine recomputes.
            $status = 'NOT_RECOMPUTED_STORED_ONLY';
            $isReproducible = false;
            $diff['warning'] = 'Stored decision only; independent strategy recomputation not executed from persisted features.';
        }

        $forwardCandles = [];
        if ($mode === 'OUTCOME_REVIEW') {
            $forwardCandles = CandleRecord::query()
                ->where('symbol', $symbol)
                ->where('interval', '15')
                ->where('is_closed', true)
                ->where('open_time', '>', $decisionTime)
                ->where('open_time', '<=', $decisionTime->addHours(4))
                ->oldest('open_time')
                ->limit(16)
                ->get(['open_time', 'open', 'high', 'low', 'close', 'volume']);
        }

        $record = null;
        if ($persist) {
            $record = DecisionReplayRunRecord::query()->create([
                'id' => (string) Str::uuid(),
                'evaluation_id' => $evaluationId,
                'mode' => $mode,
                'status' => $status,
                'as_of_time' => $decisionTime,
                'recomputed_decision' => $recomputedDecision,
                'stored_decision' => $storedDecision,
                'diff' => $diff,
                'is_reproducible' => $isReproducible,
            ]);
        }

        return [
            'replay_id' => $record?->id,
            'evaluation_id' => $evaluationId,
            'mode' => $mode,
            'status' => $status,
            'is_reproducible' => $isReproducible,
            'as_of_time' => $decisionTime->toIso8601String(),
            'stored_decision' => $storedDecision,
            'recomputed_decision' => $recomputedDecision,
            'diff' => $diff,
            'as_of_candles' => $asOfCandles,
            'forward_candles' => $forwardCandles,
        ];
    }
}
