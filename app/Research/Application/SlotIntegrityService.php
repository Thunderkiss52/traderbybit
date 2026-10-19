<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;

final class SlotIntegrityService
{
    public const STATE_EVALUATED_DIRECTIONAL = 'EVALUATED_DIRECTIONAL';

    public const STATE_EVALUATED_SKIP = 'EVALUATED_SKIP';

    public const STATE_PENDING = 'PENDING';

    public const STATE_MARKET_DATA_UNAVAILABLE = 'MARKET_DATA_UNAVAILABLE';

    public const STATE_FAILED = 'FAILED';

    public const STATE_SUPPRESSED_BY_POLICY = 'SUPPRESSED_BY_POLICY';

    public const STATE_MISSING_UNEXPLAINED = 'MISSING_UNEXPLAINED';

    public function __construct(
        private readonly StrategyRegistry $strategies,
    ) {}

    /**
     * Audit 15-minute cadence across all 8 strategies and symbols.
     *
     * @return array{
     *     metrics: array{
     *         missing_unexplained_slots_total: int,
     *         oldest_missing_slot_age_seconds: int,
     *         repeated_logical_evaluations_total: int,
     *         total_slots_audited: int,
     *         state_distribution: array<string, int>
     *     },
     *     slots: list<array<string, mixed>>,
     *     alerts: list<array<string, mixed>>,
     *     audited_at: string
     * }
     */
    public function auditCadence(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $symbols = ['BTCUSDT', 'ETHUSDT']
    ): array {
        $start = $start->utc()->setSecond(0)->setMicrosecond(0);
        $end = $end->utc()->setSecond(0)->setMicrosecond(0);

        // Normalize start to 15m boundary
        $minute = (int) $start->format('i');
        $remainder = $minute % 15;
        if ($remainder !== 0) {
            $start = $start->subMinutes($remainder);
        }

        $now = CarbonImmutable::now('UTC');
        $slots = [];
        $stateCounts = [
            self::STATE_EVALUATED_DIRECTIONAL => 0,
            self::STATE_EVALUATED_SKIP => 0,
            self::STATE_PENDING => 0,
            self::STATE_MARKET_DATA_UNAVAILABLE => 0,
            self::STATE_FAILED => 0,
            self::STATE_SUPPRESSED_BY_POLICY => 0,
            self::STATE_MISSING_UNEXPLAINED => 0,
        ];

        $oldestMissingSlotTime = null;
        $repeatedLogicalEvaluations = 0;

        $strategyList = collect($this->strategies->all())
            ->map(fn ($s) => ['id' => $s->id(), 'version' => $s->version()])
            ->values()
            ->all();

        // Preload observations and candles in window
        $observations = StrategyObservationRecord::query()
            ->where('decision_time', '>=', $start)
            ->where('decision_time', '<=', $end)
            ->get(['id', 'symbol', 'strategy_id', 'strategy_version', 'side', 'decision_time', 'configuration_hash']);

        $obsMap = [];
        foreach ($observations as $obs) {
            $key = sprintf(
                '%s|%s|%s',
                $obs->symbol,
                $obs->strategy_id,
                $obs->decision_time->format('Y-m-d H:i:s')
            );
            $obsMap[$key][] = $obs;
        }

        $candles = CandleRecord::query()
            ->where('interval', '15')
            ->where('is_closed', true)
            ->where('open_time', '>=', $start->subMinutes(15))
            ->where('open_time', '<=', $end)
            ->get(['symbol', 'open_time', 'close', 'is_closed']);

        $candleMap = [];
        foreach ($candles as $c) {
            // Candle close time is open_time + 15m
            $closeTime = $c->open_time->addMinutes(15)->format('Y-m-d H:i:s');
            $candleMap[$c->symbol.'|'.$closeTime] = $c;
        }

        $currentTime = $start;
        while ($currentTime <= $end) {
            $timeStr = $currentTime->format('Y-m-d H:i:s');
            $ageSeconds = max(0, $now->getTimestamp() - $currentTime->getTimestamp());

            foreach ($symbols as $symbol) {
                $hasCandle = isset($candleMap[$symbol.'|'.$timeStr]);

                foreach ($strategyList as $strat) {
                    $key = $symbol.'|'.$strat['id'].'|'.$timeStr;
                    $matchedObs = $obsMap[$key] ?? [];
                    $obsCount = count($matchedObs);

                    if ($obsCount > 1) {
                        $repeatedLogicalEvaluations += ($obsCount - 1);
                    }

                    $state = self::STATE_MISSING_UNEXPLAINED;
                    $details = [];

                    if ($obsCount > 0) {
                        $primary = $matchedObs[0];
                        if (in_array($primary->side, ['BUY', 'SELL'], true)) {
                            $state = self::STATE_EVALUATED_DIRECTIONAL;
                        } else {
                            $state = self::STATE_EVALUATED_SKIP;
                        }
                        $details['observation_id'] = $primary->id;
                        $details['side'] = $primary->side;
                    } elseif (! $hasCandle) {
                        $state = self::STATE_MARKET_DATA_UNAVAILABLE;
                        $details['reason'] = 'no_closed_15m_candle';
                    } elseif ($ageSeconds < 1800) { // Under 30 minutes (2 intervals grace)
                        $state = self::STATE_PENDING;
                        $details['reason'] = 'within_evaluation_grace_period';
                    } else {
                        $state = self::STATE_MISSING_UNEXPLAINED;
                        $details['reason'] = 'unexplained_evaluation_gap';
                        if ($oldestMissingSlotTime === null || $currentTime < $oldestMissingSlotTime) {
                            $oldestMissingSlotTime = $currentTime;
                        }
                    }

                    $stateCounts[$state]++;
                    $slots[] = [
                        'symbol' => $symbol,
                        'strategy_id' => $strat['id'],
                        'strategy_version' => $strat['version'],
                        'slot_time' => $currentTime->toIso8601String(),
                        'state' => $state,
                        'age_seconds' => $ageSeconds,
                        'details' => $details,
                    ];
                }
            }

            $currentTime = $currentTime->addMinutes(15);
        }

        $oldestMissingAge = $oldestMissingSlotTime !== null
            ? max(0, $now->getTimestamp() - $oldestMissingSlotTime->getTimestamp())
            : 0;

        $alerts = $this->evaluateAlerts(
            $stateCounts[self::STATE_MISSING_UNEXPLAINED],
            $oldestMissingAge,
            $repeatedLogicalEvaluations
        );

        return [
            'metrics' => [
                'missing_unexplained_slots_total' => $stateCounts[self::STATE_MISSING_UNEXPLAINED],
                'oldest_missing_slot_age_seconds' => $oldestMissingAge,
                'repeated_logical_evaluations_total' => $repeatedLogicalEvaluations,
                'total_slots_audited' => count($slots),
                'state_distribution' => $stateCounts,
            ],
            'slots' => $slots,
            'alerts' => $alerts,
            'audited_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Opportunity vs Risk Decision event model tracer.
     * Separates candidate opportunity (SignalRecord) from decision evaluation events (RiskDecisionRecord).
     *
     * @return array{
     *     opportunity_id: string,
     *     symbol: string,
     *     strategy_id: string,
     *     side: string,
     *     decision_time: string,
     *     events: list<array{
     *         event_id: string,
     *         allowed: bool,
     *         reasons: list<string>,
     *         risk_version: string,
     *         decided_at: string
     *     }>,
     *     state: ?array<string, mixed>,
     *     position: ?array<string, mixed>
     * }
     */
    public function traceOpportunityFunnel(string $signalId): ?array
    {
        $signal = SignalRecord::query()->find($signalId);
        if ($signal === null) {
            return null;
        }

        $decisionEvents = RiskDecisionRecord::query()
            ->where('signal_id', $signal->id)
            ->oldest('decided_at')
            ->get()
            ->map(fn (RiskDecisionRecord $r) => [
                'event_id' => $r->id,
                'allowed' => (bool) $r->allowed,
                'reasons' => (array) ($r->reasons ?? []),
                'risk_version' => (string) $r->risk_version,
                'decided_at' => $r->decided_at?->toIso8601String(),
            ])
            ->all();

        $signalState = PaperSignalStateRecord::query()
            ->where('signal_id', $signal->id)
            ->first();

        $position = VirtualPositionRecord::query()
            ->where('signal_id', $signal->id)
            ->first();

        return [
            'opportunity_id' => (string) $signal->id,
            'symbol' => (string) $signal->symbol,
            'strategy_id' => (string) $signal->strategy_id,
            'side' => (string) $signal->side,
            'decision_time' => $signal->decision_time->toIso8601String(),
            'events' => $decisionEvents,
            'state' => $signalState ? [
                'status' => $signalState->status,
                'reason' => $signalState->reason,
                'processed_at' => $signalState->processed_at?->toIso8601String(),
            ] : null,
            'position' => $position ? [
                'position_id' => $position->id,
                'status' => $position->status,
                'net_pnl' => (string) $position->net_pnl,
                'opened_at' => $position->opened_at?->toIso8601String(),
                'closed_at' => $position->closed_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Evaluate alert conditions with deduplication and rate limiting.
     *
     * @return list<array<string, mixed>>
     */
    private function evaluateAlerts(
        int $missingUnexplainedTotal,
        int $oldestMissingAgeSeconds,
        int $repeatedLogicalTotal
    ): array {
        $alerts = [];

        if ($missingUnexplainedTotal > 0) {
            $alertKey = "alert:missing_slots:{$missingUnexplainedTotal}";
            $alerts[] = [
                'name' => 'MISSING_UNEXPLAINED_SLOTS_DETECTED',
                'severity' => $oldestMissingAgeSeconds >= 3600 ? 'CRITICAL' : 'WARNING',
                'message' => "Detected {$missingUnexplainedTotal} unexplained missing strategy evaluation slots. Oldest slot age: {$oldestMissingAgeSeconds}s.",
                'dedup_key' => $alertKey,
                'triggered_at' => now('UTC')->toIso8601String(),
            ];
        }

        if ($repeatedLogicalTotal > 0) {
            $alertKey = "alert:repeated_evaluations:{$repeatedLogicalTotal}";
            $alerts[] = [
                'name' => 'REPEATED_LOGICAL_EVALUATIONS_DETECTED',
                'severity' => 'WARNING',
                'message' => "Detected {$repeatedLogicalTotal} duplicate evaluation attempts on identical logical slots.",
                'dedup_key' => $alertKey,
                'triggered_at' => now('UTC')->toIso8601String(),
            ];
        }

        return $alerts;
    }
}
