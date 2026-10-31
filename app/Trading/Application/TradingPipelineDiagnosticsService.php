<?php

declare(strict_types=1);

namespace App\Trading\Application;

use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use Illuminate\Support\Facades\DB;

final class TradingPipelineDiagnosticsService
{
    /**
     * Inspect recent pipeline events and distinguish genuine calculation outcomes from status anomalies.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $exchange = 'binance', string $symbol = 'BTCUSDT', int $limit = 25): array
    {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $canonicalStrategies = (array) config('paper.primary_strategies', []);
        $canonicalCount = count($canonicalStrategies);

        $events = TradingPipelineEventRecord::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->latest('candle_close_at')
            ->limit($limit)
            ->get();

        $records = [];
        $completedCount = 0;
        $statusAnomalyCount = 0;
        $genuinelyStaleCount = 0;
        $genuinelyFailedCount = 0;
        $inFlightCount = 0;

        $closeToEventLatencies = [];
        $evalLatencies = [];

        foreach ($events as $event) {
            $closeAt = $event->candle_close_at->toDateTimeImmutable();
            $payload = (array) $event->payload;

            // Check if canonical strategy observations exist for this slot
            $observationsCount = StrategyObservationRecord::query()
                ->where('exchange', $exchange)
                ->where('category', 'linear')
                ->where('symbol', $symbol)
                ->where('decision_time', $closeAt)
                ->whereNull('superseded_by')
                ->count();

            // Check if virtual positions or executions exist for this decision slot
            $positionsCount = DB::table('virtual_positions')
                ->where('exchange_id', $exchange)
                ->where('symbol', $symbol)
                ->where('created_at', '>=', $closeAt->modify('-1 minute'))
                ->where('created_at', '<=', $closeAt->modify('+15 minutes'))
                ->count();

            $hasFullObservations = $canonicalCount > 0 && $observationsCount >= $canonicalCount;

            $classification = match ($event->status) {
                TradingPipelineEventRecord::STATUS_COMPLETED => 'GENUINELY_COMPLETED',
                TradingPipelineEventRecord::STATUS_STALE => $hasFullObservations
                    ? 'STATUS_ANOMALY: Observations exist but stage marked STALE'
                    : 'GENUINELY_STALE: Exceeded freshness budget without completed observations',
                TradingPipelineEventRecord::STATUS_FAILED => $hasFullObservations
                    ? 'STATUS_ANOMALY: Observations exist but stage marked FAILED'
                    : 'GENUINELY_FAILED: Calculation failed without completed observations',
                TradingPipelineEventRecord::STATUS_PARTIAL => 'PARTIAL_EXECUTION',
                TradingPipelineEventRecord::STATUS_PENDING,
                TradingPipelineEventRecord::STATUS_DISPATCHED => 'IN_FLIGHT',
                default => 'UNKNOWN',
            };

            if ($event->status === TradingPipelineEventRecord::STATUS_COMPLETED) {
                $completedCount++;
            } elseif (str_starts_with($classification, 'STATUS_ANOMALY')) {
                $statusAnomalyCount++;
            } elseif ($event->status === TradingPipelineEventRecord::STATUS_STALE) {
                $genuinelyStaleCount++;
            } elseif ($event->status === TradingPipelineEventRecord::STATUS_FAILED) {
                $genuinelyFailedCount++;
            } elseif (in_array($event->status, [TradingPipelineEventRecord::STATUS_PENDING, TradingPipelineEventRecord::STATUS_DISPATCHED], true)) {
                $inFlightCount++;
            }

            if (isset($payload['candle_close_to_event_ms']) && is_numeric($payload['candle_close_to_event_ms'])) {
                $closeToEventLatencies[] = (float) $payload['candle_close_to_event_ms'];
            }
            if (isset($payload['eval_latency_from_close_ms']) && is_numeric($payload['eval_latency_from_close_ms'])) {
                $evalLatencies[] = (float) $payload['eval_latency_from_close_ms'];
            }

            $records[] = [
                'event_key' => $event->event_key,
                'stage' => $event->stage,
                'timeframe' => $event->timeframe,
                'candle_close_at' => $event->candle_close_at->toIso8601String(),
                'status' => $event->status,
                'classification' => $classification,
                'canonical_observations_count' => $observationsCount,
                'matching_positions_count' => $positionsCount,
                'attempts' => $event->attempts,
                'last_error' => $event->last_error,
                'created_at' => $event->created_at->toIso8601String(),
                'dispatched_at' => $event->dispatched_at?->toIso8601String(),
                'completed_at' => $event->completed_at?->toIso8601String(),
            ];
        }

        return [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'inspected_events_count' => count($records),
            'summary' => [
                'genuinely_completed' => $completedCount,
                'status_anomalies' => $statusAnomalyCount,
                'genuinely_stale' => $genuinelyStaleCount,
                'genuinely_failed' => $genuinelyFailedCount,
                'in_flight' => $inFlightCount,
            ],
            'latency_metrics' => [
                'avg_candle_close_to_event_ms' => $closeToEventLatencies !== [] ? round(array_sum($closeToEventLatencies) / count($closeToEventLatencies), 2) : null,
                'avg_eval_latency_from_close_ms' => $evalLatencies !== [] ? round(array_sum($evalLatencies) / count($evalLatencies), 2) : null,
            ],
            'events' => $records,
        ];
    }
}
