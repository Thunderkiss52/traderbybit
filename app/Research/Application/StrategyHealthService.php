<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;

final class StrategyHealthService
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
    ) {}

    /**
     * Compute health funnel and diagnostics for all strategies.
     *
     * @return array{strategies: list<array<string, mixed>>, system_status: string, generated_at: string}
     */
    public function overview(string $period = '24h'): array
    {
        $since = match ($period) {
            '7d' => now('UTC')->subDays(7),
            '14d' => now('UTC')->subDays(14),
            '30d' => now('UTC')->subDays(30),
            default => now('UTC')->subDay(),
        };

        $results = [];
        $hasCritical = false;
        $hasWarning = false;

        foreach ($this->strategies->metadata() as $meta) {
            $health = $this->evaluateStrategy($meta, $since, $period);
            if ($health['status'] === 'CRITICAL') {
                $hasCritical = true;
            } elseif ($health['status'] === 'WARNING') {
                $hasWarning = true;
            }
            $results[] = $health;
        }

        $systemStatus = $hasCritical ? 'CRITICAL' : ($hasWarning ? 'WARNING' : 'HEALTHY');

        return [
            'strategies' => $results,
            'system_status' => $systemStatus,
            'generated_at' => now('UTC')->toIso8601String(),
        ];
    }

    /**
     * Evaluate health of a single strategy.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function evaluateStrategy(array $meta, \DateTimeInterface $since, string $period = '24h', ?string $symbol = null): array
    {
        $strategyId = (string) $meta['id'];
        $version = (string) ($meta['version'] ?? '1.0.0');

        // Query base observation slots
        $obsQuery = StrategyObservationRecord::query()
            ->where('strategy_id', $strategyId)
            ->where('decision_time', '>=', $since);
        if ($symbol) {
            $obsQuery->where('symbol', $symbol);
        }

        $evaluations = (clone $obsQuery)->count();
        $buy = (clone $obsQuery)->where('side', 'BUY')->count();
        $sell = (clone $obsQuery)->where('side', 'SELL')->count();
        $skip = (clone $obsQuery)->where('side', 'SKIP')->count();
        $rawCandidates = $buy + $sell;

        // Query signal records for this strategy
        $signalQuery = SignalRecord::query()
            ->where('strategy_id', $strategyId)
            ->where('decision_time', '>=', $since);
        if ($symbol) {
            $signalQuery->where('symbol', $symbol);
        }
        $signalIds = (clone $signalQuery)->pluck('id');

        // Risk engine evaluations
        $riskDecisions = RiskDecisionRecord::query()->whereIn('signal_id', $signalIds)->get(['allowed', 'reasons', 'signal_id']);
        $riskAssessed = $riskDecisions->count();
        $riskApproved = $riskDecisions->where('allowed', true)->count();
        $riskRejected = $riskDecisions->where('allowed', false)->count();

        // Top risk rejection reasons
        $rejectionReasons = [];
        foreach ($riskDecisions->where('allowed', false) as $rejection) {
            foreach ($rejection->reasons ?? [] as $reason) {
                if (is_string($reason)) {
                    $rejectionReasons[$reason] = ($rejectionReasons[$reason] ?? 0) + 1;
                }
            }
        }
        arsort($rejectionReasons);

        // Virtual position & signal state tracking
        $states = PaperSignalStateRecord::query()->whereIn('signal_id', $signalIds)->get(['status', 'signal_id']);
        $pendingEntries = $states->where('status', 'PENDING_ENTRY')->count();
        $expiredEntries = $states->where('status', 'EXPIRED')->count();

        $positions = VirtualPositionRecord::query()->whereIn('signal_id', $signalIds)->get(['id', 'status']);
        $filledPositions = $positions->count();
        $partiallyClosed = $positions->where('status', 'PARTIALLY_CLOSED')->count();
        $closedPositions = $positions->filter(fn ($p) => str_starts_with((string) $p->status, 'CLOSED_'))->count();

        // Execution failures
        $executionFailures = JournalEventRecord::query()
            ->where('event_type', 'EXECUTION_FAILED')
            ->where('occurred_at', '>=', $since)
            ->whereIn('signal_id', $signalIds)
            ->count();

        // Top skip reasons
        $skipReasons = [];
        foreach ((clone $obsQuery)->where('side', 'SKIP')->cursor() as $obs) {
            foreach ($obs->reasons ?? [] as $r) {
                if (is_string($r) && ! str_starts_with($r, 'regime_')) {
                    $skipReasons[$r] = ($skipReasons[$r] ?? 0) + 1;
                }
            }
        }
        arsort($skipReasons);

        $funnel = [
            'expected_slots' => max($evaluations, 96), // 96 15m intervals per 24h
            'evaluations' => $evaluations,
            'raw_candidates' => $rawCandidates,
            'buy' => $buy,
            'sell' => $sell,
            'skip' => $skip,
            'risk_assessed' => $riskAssessed,
            'risk_approved' => $riskApproved,
            'risk_rejected' => $riskRejected,
            'pending_entries' => $pendingEntries,
            'expired_entries' => $expiredEntries,
            'filled_positions' => $filledPositions,
            'partially_closed' => $partiallyClosed,
            'closed_positions' => $closedPositions,
            'execution_failures' => $executionFailures,
            'top_skip_reasons' => array_slice($skipReasons, 0, 5, true),
            'top_risk_rejection_reasons' => array_slice($rejectionReasons, 0, 5, true),
        ];

        // Determine status and diagnostics
        [$status, $diagnostics] = $this->classifyHealth($strategyId, $funnel);

        return [
            'strategy_id' => $strategyId,
            'strategy_version' => $version,
            'symbol' => $symbol ?? 'ALL',
            'period' => $period,
            'status' => $status,
            'funnel' => $funnel,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Classify health status based on funnel metrics and thresholds.
     *
     * @param  array<string, mixed>  $funnel
     * @return array{0: string, 1: list<array{code: string, severity: string, message: string, recommendation: string}>}
     */
    private function classifyHealth(string $strategyId, array $funnel): array
    {
        $diagnostics = [];
        $status = 'HEALTHY';

        if ($funnel['execution_failures'] > 0) {
            $status = 'CRITICAL';
            $diagnostics[] = [
                'code' => 'EXECUTION_FAILURES_DETECTED',
                'severity' => 'CRITICAL',
                'message' => "Detected {$funnel['execution_failures']} virtual execution failures.",
                'recommendation' => 'Inspect paper trading order sizing, account leverage limits, and journal event logs.',
            ];
        }

        if ($funnel['evaluations'] < 5) {
            $status = $status === 'CRITICAL' ? 'CRITICAL' : 'INSUFFICIENT_DATA';
            $diagnostics[] = [
                'code' => 'INSUFFICIENT_OBSERVATIONS',
                'severity' => 'INFO',
                'message' => "Only {$funnel['evaluations']} evaluation slots recorded in this period.",
                'recommendation' => 'Allow the system to observe at least 10 closed candles before evaluating strategy health.',
            ];
        }

        if ($funnel['raw_candidates'] > 0 && $funnel['risk_rejected'] === $funnel['raw_candidates']) {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $diagnostics[] = [
                'code' => 'RISK_REJECTION_SPIKE',
                'severity' => 'WARNING',
                'message' => "All {$funnel['raw_candidates']} actionable candidates were rejected by the Risk Engine.",
                'recommendation' => 'Check account drawdown limits, maximum concurrent exposure, or daily risk limits in config/paper.php.',
            ];
        }

        if ($funnel['raw_candidates'] === 0 && $funnel['evaluations'] >= 50) {
            $topReason = array_key_first($funnel['top_skip_reasons']) ?? 'regime or trend filters';
            $diagnostics[] = [
                'code' => 'ZERO_ACTIONABLE_SIGNALS_REGIME',
                'severity' => 'INFO',
                'message' => "Strategy generated 0 raw signals across {$funnel['evaluations']} evaluations (primary filter: {$topReason}).",
                'recommendation' => 'This is an expected research outcome if market conditions (regime/ADX) do not match strategy requirements.',
            ];
        }

        if ($funnel['expired_entries'] > 0 && $funnel['filled_positions'] === 0 && $funnel['risk_approved'] > 0) {
            if ($status !== 'CRITICAL') {
                $status = 'WARNING';
            }
            $diagnostics[] = [
                'code' => 'EXPIRED_UNFILLED_ENTRIES',
                'severity' => 'WARNING',
                'message' => "Approved signals expired before price entered the entry zone ({$funnel['expired_entries']} expired).",
                'recommendation' => 'Analyze whether the entry zone tolerance is too narrow or validity horizon is too short.',
            ];
        }

        if (empty($diagnostics)) {
            $diagnostics[] = [
                'code' => 'STRATEGY_OPERATIONAL',
                'severity' => 'INFO',
                'message' => 'Strategy is operating normally with closed-candle evaluations and healthy state transitions.',
                'recommendation' => 'Maintain current forward observation.',
            ];
        }

        return [$status, $diagnostics];
    }
}
