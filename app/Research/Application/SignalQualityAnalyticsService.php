<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SignalQualityAnalyticsService
{
    public const CANONICAL_TIMEFRAMES = ['1m', '3m', '5m', '15m', '30m', '60m'];

    public function __construct(
        private readonly ExchangeRegistry $exchanges,
        private readonly SignalQualitySummary $summaries,
    ) {}

    /**
     * Compute comprehensive signal-quality analytics and multi-timeframe profitability attribution.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function analyze(array $filters): array
    {
        return $this->analyzeSnapshot($filters);
    }

    private function analyzeSnapshot(array $filters): array
    {
        $scope = $this->buildFilteredSignalsQuery($filters);
        $sql = $this->summaries;
        $accounts = PaperAccountRecord::query()->where('is_primary', true);
        if (! empty($filters['exchange']) && strtolower((string) $filters['exchange']) !== 'all') {
            $accounts->where('exchange_id', strtolower((string) $filters['exchange']));
        }
        if (! empty($filters['strategy']) && strtolower((string) $filters['strategy']) !== 'all') {
            $accounts->where('primary_strategy_id', $filters['strategy']);
        }
        $panels = $sql->snapshot($scope, $accounts);
        $signals = $panels['signal_totals'][0];
        $positions = $panels['position_totals'][0];
        $overview = [
            'overall_net_pnl' => $positions['net_pnl'],
            'pnl_scope' => 'POSTED_REALIZED_TO_DATE_EXCLUDES_UNREALIZED',
            'overall_gross_pnl' => $positions['gross_pnl'],
            'overall_fees' => $positions['fees'],
            'overall_funding' => $positions['funding'],
            'win_rate' => $this->percent($positions['wins'], $positions['closed']),
            'profit_factor' => BigDecimal::of($positions['losing_pnl'])->isPositive()
                ? round((float) (string) BigDecimal::of($positions['winning_pnl'])->dividedBy($positions['losing_pnl'], 4, RoundingMode::HalfEven), 2) : null,
            'total_signals' => (int) $signals['signals'],
            'buy_signals' => (int) $signals['side_buy'],
            'sell_signals' => (int) $signals['side_sell'],
            'executed_trades' => (int) $positions['executed'],
            'closed_trades' => (int) $positions['closed'],
            'rejected_signals' => (int) $signals['rejected'],
            'rejected_signals_scope' => 'SIGNALS_WITH_REJECTED_RISK_DECISION',
            'observation_only_signals' => (int) $signals['observations'],
            'observation_only_signals_scope' => 'PERSISTED_GATE_OBSERVATION_ONLY',
            'outcome_distribution' => [
                'GOOD' => (int) $signals['retrospective_outcome_good'],
                'BAD' => (int) $signals['retrospective_outcome_bad'],
                'UNPROVEN' => (int) $signals['retrospective_outcome_unproven'],
                'good_percent' => $this->percent($signals['retrospective_outcome_good'], $signals['signals']),
            ],
            'quality_distribution' => [], 'risk_distribution' => [],
        ];
        foreach (['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $quality) {
            $overview['quality_distribution'][$quality] = (int) $signals['signal_quality_'.strtolower($quality)];
            $overview['risk_distribution'][$quality] = (int) $signals['trade_risk_'.strtolower($quality)];
        }
        $drawdowns = collect($panels['drawdowns'])->pluck('drawdown', 'exchange_id');
        $hasCohortFilter = collect(['symbol', 'timeframe', 'signal_quality', 'trade_risk', 'outcome', 'side', 'gate_decision', 'execution_status', 'from', 'to'])
            ->contains(fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== '' && strtolower((string) $filters[$key]) !== 'all');
        $accountMaxDd = $drawdowns->filter(fn ($value): bool => $value !== null)->max();
        $overview['max_drawdown'] = $hasCohortFilter || $accountMaxDd === null ? null : (float) $accountMaxDd;
        $overview['max_drawdown_scope'] = $hasCohortFilter ? 'COHORT_DRAWDOWN_UNAVAILABLE'
            : ($accountMaxDd === null ? 'NO_ACCOUNT_DRAWDOWN_EVIDENCE' : 'MAX_ACCOUNT_DRAWDOWN_NOT_AGGREGATE');

        $groups = [];
        foreach (['exchange', 'strategy_id', 'timeframe', 'symbol'] as $dimension) {
            $signalRows = $panels['signals_'.$dimension];
            $positionRows = $panels['positions_'.$dimension];
            $groups[$dimension] = $this->combineSummary($signalRows, $positionRows, [$dimension]);
            $ranked = $positionRows;
            usort($ranked, static fn (array $a, array $b): int => BigDecimal::of($b['net_pnl'])->compareTo($a['net_pnl']) ?: strcmp((string) $a[$dimension], (string) $b[$dimension]));
            $label = $dimension === 'strategy_id' ? 'strategy' : ($dimension === 'symbol' ? 'instrument' : $dimension);
            $overview['best_'.$label] = $ranked[0][$dimension] ?? null;
            if ($dimension !== 'symbol') {
                $overview['worst_'.$label] = $ranked === [] ? null : $ranked[count($ranked) - 1][$dimension];
            }
        }
        $exchangeRows = [];
        $venues = ! empty($filters['exchange']) && strtolower((string) $filters['exchange']) !== 'all' ? [strtolower((string) $filters['exchange'])] : $this->exchanges->registeredIds();
        foreach ($venues as $venue) {
            $row = $groups['exchange'][json_encode([$venue], JSON_THROW_ON_ERROR)] ?? $this->emptySummary();
            $dd = ! $hasCohortFilter ? $drawdowns->get($venue) : null;
            $exchangeRows[] = array_merge($row, [
                'exchange' => $venue, 'strategy' => 'all', 'instrument' => 'all', 'timeframe' => 'multi',
                'max_drawdown' => $dd === null ? null : (float) $dd,
                'max_drawdown_scope' => $dd === null ? 'NOT_AVAILABLE_FOR_SELECTED_SCOPE' : 'MAX_ACCOUNT_DRAWDOWN_NOT_AGGREGATE',
            ]);
        }
        $strategyRows = array_values(array_map(static fn (array $row): array => array_merge($row, ['strategy' => $row['strategy_id']]), $groups['strategy_id']));
        $timeframeRows = [];
        foreach (self::CANONICAL_TIMEFRAMES as $tf) {
            $row = $groups['timeframe'][json_encode([$tf], JSON_THROW_ON_ERROR)] ?? $this->emptySummary();
            $row['timeframe'] = $tf;
            $row['max_drawdown'] = null;
            $row['max_drawdown_scope'] = 'TIMEFRAME_DRAWDOWN_UNAVAILABLE';
            $row['status'] = $row['signals'] === 0 ? ($tf === '15m' ? 'INSUFFICIENT_DATA' : 'NO_SIGNAL_ENGINE_DATA')
                : ($tf === '15m' ? 'AGGREGATED' : 'HISTORICAL_COHORT_NOT_LIVE_ENGINE_VERIFIED');
            $timeframeRows[] = $row;
        }
        $qualityGroups = $this->combineSummary($panels['signals_quality_risk'], $panels['positions_quality_risk'], ['signal_quality', 'trade_risk']);
        $matrix = [];
        foreach (['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'] as $quality) {
            foreach (['LOW', 'MEDIUM', 'HIGH', 'UNKNOWN'] as $risk) {
                $row = $qualityGroups[json_encode([$quality, $risk], JSON_THROW_ON_ERROR)] ?? $this->emptySummary();
                $matrix[] = array_merge($row, ['quality' => $quality, 'trade_risk' => $risk]);
            }
        }
        $direction = $panels['positions_side'];
        $overview['invalid_attribution_positions'] = (int) $panels['invalid_positions'][0]['total'];
        $overview['invalid_risk_decisions'] = (int) $panels['invalid_risk'][0]['total'];
        $overview['net_pnl_verification_status'] = $overview['invalid_attribution_positions'] > 0 ? 'INVALID_DATA' : 'SCOPED_POSTED';
        $diagnostics = $this->computeDiagnostics($overview, $direction, $timeframeRows);
        if ($overview['invalid_risk_decisions'] > 0) {
            $diagnostics[] = ['severity' => 'ERROR', 'type' => 'INVALID_RISK_ATTRIBUTION', 'message' => 'Risk decisions with inconsistent account/exchange scope were excluded.'];
        }
        if ($overview['invalid_attribution_positions'] > 0) {
            $diagnostics[] = ['severity' => 'ERROR', 'type' => 'INVALID_POSITION_ATTRIBUTION',
                'message' => 'Positions with inconsistent exchange, instrument or account scope were excluded. Review the recorded history; no financial record was changed.'];
        }

        return [
            'overview' => $overview, 'exchange_comparison' => $exchangeRows, 'strategy_comparison' => $strategyRows,
            'timeframe_comparison' => $timeframeRows, 'quality_risk_matrix' => $matrix,
            'diagnostics' => $diagnostics, 'filters_applied' => $filters,
        ];
    }

    private function percent(int|string $count, int|string $total): ?float
    {
        return (int) $total > 0 ? round(((int) $count / (int) $total) * 100, 2) : null;
    }

    private function emptySummary(): array
    {
        return ['signals' => 0, 'executed' => 0, 'closed' => 0, 'wins' => 0, 'net_pnl' => '0', 'win_rate' => null,
            'profit_factor' => null, 'good_percent' => null, 'expectancy' => null, 'rejected' => 0,
            'quality_distribution' => ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'UNKNOWN' => 0]];
    }

    private function combineSummary(array $signals, array $positions, array $dimensions): array
    {
        $byGroup = [];
        foreach ($positions as $row) {
            $byGroup[SignalQualitySummary::key($row, $dimensions)] = $row;
        }
        $result = [];
        foreach ($signals as $row) {
            $key = SignalQualitySummary::key($row, $dimensions);
            $p = $byGroup[$key] ?? ['executed' => 0, 'closed' => 0, 'wins' => 0, 'net_pnl' => '0', 'winning_pnl' => '0', 'losing_pnl' => '0'];
            $item = $this->emptySummary();
            foreach ($dimensions as $field) {
                $item[$field] = $row[$field];
            }
            foreach (['signals', 'rejected'] as $field) {
                $item[$field] = (int) $row[$field];
            }
            foreach (['executed', 'closed', 'wins'] as $field) {
                $item[$field] = (int) $p[$field];
            }
            $item['net_pnl'] = $p['net_pnl'];
            $item['good_percent'] = $this->percent($row['retrospective_outcome_good'], $row['signals']);
            $item['win_rate'] = $this->percent($p['wins'], $p['closed']);
            $item['expectancy'] = (int) $row['signals'] > 0 ? (string) BigDecimal::of($p['net_pnl'])->dividedBy((int) $row['signals'], 4, RoundingMode::HalfEven) : null;
            $item['profit_factor'] = BigDecimal::of($p['losing_pnl'])->isPositive() ? round((float) (string) BigDecimal::of($p['winning_pnl'])->dividedBy($p['losing_pnl'], 4, RoundingMode::HalfEven), 2) : null;
            foreach (array_keys($item['quality_distribution']) as $quality) {
                $item['quality_distribution'][$quality] = (int) $row['signal_quality_'.strtolower($quality)];
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Paginated Signal Explorer with complete drill-down attributes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateSignals(array $filters, int $perPage = 10, string $sortBy = 'decision_time', string $sortDir = 'desc'): LengthAwarePaginator
    {
        $query = $this->buildFilteredSignalsQuery($filters);

        $allowedSorts = ['decision_time', 'symbol', 'exchange', 'strategy_id', 'timeframe', 'side', 'signal_quality', 'trade_risk', 'score'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'decision_time';
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir)->paginate($perPage);

        // Preserve all account-level executions tied to each signal.
        $paginator->setCollection($this->decorateSignals($paginator->getCollection()));

        return $paginator;
    }

    /**
     * Stream an arbitrary number of signals without silently discarding all but
     * the first 1,000 records. Cursor pagination is stable on (decision_time,id).
     *
     * @param  array<string,mixed>  $filters
     * @return \Generator<array<string,mixed>>
     */
    public function iterateSignalExplorer(array $filters, int $batchSize = 250): \Generator
    {
        $cursor = null;
        do {
            $page = $this->buildFilteredSignalsQuery($filters)
                ->orderBy('decision_time', 'desc')->orderBy('id', 'desc')
                ->cursorPaginate($batchSize, ['*'], 'cursor', $cursor);

            foreach ($this->decorateSignals(collect($page->items())) as $row) {
                yield $row;
            }
            $cursor = $page->nextCursor();
        } while ($cursor !== null);
    }

    /** @param  Collection<int,SignalRecord>  $signals */
    private function decorateSignals(Collection $signals): Collection
    {
        $signalIds = $signals->pluck('id')->all();
        if ($signalIds === []) {
            return collect();
        }

        $scope = SignalRecord::query()->whereIn('id', $signalIds);
        $invalidPositions = $this->summaries->invalidPositionScope($scope)->selectRaw('p.signal_id, COUNT(*) AS total')->groupBy('p.signal_id')->pluck('total', 'signal_id');
        $invalidRisk = $this->summaries->invalidRiskScope($scope)->selectRaw('r.signal_id, COUNT(*) AS total')->groupBy('r.signal_id')->pluck('total', 'signal_id');
        $positions = $this->validPositions($scope)
            ->orderBy('opened_at')->orderBy('id')->get()->groupBy('signal_id');
        $riskDecisions = $this->validRiskDecisions($scope)
            ->orderBy('decided_at')->orderBy('id')->get()->groupBy('signal_id');

        return $signals->map(function (SignalRecord $sig) use ($positions, $riskDecisions, $invalidPositions, $invalidRisk): array {
            $signalPositions = $positions->get($sig->id, collect());
            $signalRiskDecisions = $riskDecisions->get($sig->id, collect());
            $pos = $signalPositions->last();
            $risk = $signalRiskDecisions->last();

            $riskSnapshot = $risk ? (is_array($risk->snapshot) ? $risk->snapshot : (json_decode((string) $risk->snapshot, true) ?? [])) : [];
            $gateDecision = $signalRiskDecisions->count() > 1 ? 'MULTIPLE_ACCOUNTS'
                : ($riskSnapshot['gate_decision'] ?? ($risk ? ($risk->allowed ? 'ELIGIBLE' : 'REJECTED') : 'PENDING'));
            $rejectionReason = $signalRiskDecisions->count() > 1 ? 'MULTIPLE_ACCOUNT_DECISIONS'
                : (! empty($risk?->reasons) ? implode(', ', (array) $risk->reasons) : ($riskSnapshot['rejection_reasons'][0] ?? null));

            $executionStatus = $pos ? 'EXECUTED' : ($gateDecision === 'REJECTED' ? 'REJECTED' : ($gateDecision === 'OBSERVATION_ONLY' ? 'OBSERVATION_ONLY' : 'NON_EXECUTED'));

            $entryMid = $sig->entry_low !== null && $sig->entry_high !== null
                ? (string) BigDecimal::of((string) $sig->entry_low)->plus(BigDecimal::of((string) $sig->entry_high))->dividedBy(2, 4, RoundingMode::HalfEven)
                : (string) ($sig->entry_low ?? $pos?->entry_price ?? '0');

            $netPnl = BigDecimal::zero();
            foreach ($signalPositions as $trade) {
                // net_pnl on an open/partially-closed PAPER position already
                // contains posted entry fees, funding and TP1 realized PnL.
                $netPnl = $netPnl->plus((string) $trade->net_pnl);
            }
            $realizedOutcome = $signalPositions->count() === 1 ? $pos?->status : ($signalPositions->isEmpty() ? null : 'MULTIPLE_POSITIONS');
            // A single retrospective label cannot be derived from a mixed set of
            // independently executed portfolios. Use recorded signal evidence.
            $retrospective = $sig->retrospective_outcome ?? 'UNPROVEN';

            return [
                'id' => $sig->id,
                'decision_time' => $sig->decision_time?->toIso8601String(),
                'exchange' => $sig->exchange,
                'strategy_id' => $sig->strategy_id,
                'symbol' => $sig->symbol,
                'timeframe' => $sig->timeframe,
                'side' => $sig->side,
                'entry_price' => $entryMid,
                'stop_loss' => $sig->stop_loss,
                'take_profit_1' => $sig->take_profit_1,
                'take_profit_2' => $sig->take_profit_2,
                'risk_reward_1' => $sig->risk_reward_1,
                'risk_reward_2' => $sig->risk_reward_2,
                'signal_quality' => $sig->signal_quality,
                'trade_risk' => $sig->trade_risk,
                'gate_decision' => $gateDecision,
                'execution_status' => $executionStatus,
                'realized_outcome' => $realizedOutcome,
                'net_pnl' => (string) $netPnl,
                'pnl_scope' => 'POSTED_REALIZED_TO_DATE_EXCLUDES_UNREALIZED',
                'position_count' => $signalPositions->count(),
                'invalid_attribution_positions' => (int) $invalidPositions->get($sig->id, 0),
                'invalid_risk_decisions' => (int) $invalidRisk->get($sig->id, 0),
                'attribution_verification_status' => $invalidPositions->get($sig->id, 0) > 0 || $invalidRisk->get($sig->id, 0) > 0 ? 'INVALID_DATA' : 'SCOPED_POSTED',
                'closed_position_count' => $signalPositions->filter(fn (VirtualPositionRecord $trade) => $this->isPositionClosed($trade->status))->count(),
                'risk_decision_count' => $signalRiskDecisions->count(),
                'retrospective_outcome' => $retrospective,
                'rejection_reason' => $rejectionReason,
                'reasons' => $sig->reasons,
                'risks' => $sig->risks,
            ];
        });
    }

    /**
     * Retrieve complete explainability details for a single signal.
     *
     * @return array<string, mixed>
     */
    public function getSignalDetail(string $signalId): array
    {
        $sig = Str::isUuid($signalId) ? SignalRecord::query()->find($signalId) : null;
        if ($sig === null) {
            abort(404, "Signal [{$signalId}] was not found.");
        }
        $scope = SignalRecord::query()->whereKey($sig->id);
        $invalidPositions = $this->summaries->invalidPositionScope($scope)->count();
        $invalidRisk = $this->summaries->invalidRiskScope($scope)->count();
        $allPositions = $this->validPositions($scope)
            ->orderBy('opened_at')->orderBy('id')->get();
        $pos = $allPositions->last();
        $allRiskDecisions = $this->validRiskDecisions($scope)
            ->orderBy('decided_at')->orderBy('id')->get();
        $risk = $allRiskDecisions->last();

        $rules = data_get($sig->contract, 'rules') ?? data_get($sig->indicator_snapshot, 'rules') ?? [];
        $riskSnapshot = $risk ? (is_array($risk->snapshot) ? $risk->snapshot : (json_decode((string) $risk->snapshot, true) ?? [])) : [];

        // Historical evidence is immutable: never re-evaluate an earlier decision
        // under today's risk-gate version, thresholds, or market conditions.
        $recordedGateDecision = $allRiskDecisions->count() > 1 ? 'MULTIPLE_ACCOUNTS'
            : ($riskSnapshot['gate_decision'] ?? ($risk !== null
                ? ($risk->allowed ? 'ELIGIBLE' : 'REJECTED')
                : 'PENDING'));

        return [
            'id' => $sig->id,
            'exchange' => $sig->exchange,
            'symbol' => $sig->symbol,
            'timeframe' => $sig->timeframe,
            'strategy_id' => $sig->strategy_id,
            'strategy_version' => $sig->strategy_version,
            'side' => $sig->side,
            'decision_time' => $sig->decision_time?->toIso8601String(),
            'candle_close_time' => $sig->candle_close_time?->toIso8601String(),
            'candle_id' => $sig->candle_id,
            'entry_low' => $sig->entry_low,
            'entry_high' => $sig->entry_high,
            'stop_loss' => $sig->stop_loss,
            'take_profit_1' => $sig->take_profit_1,
            'take_profit_2' => $sig->take_profit_2,
            'signal_quality' => $sig->signal_quality,
            'trade_risk' => $sig->trade_risk,
            'gate_decision' => $recordedGateDecision,
            'gate_allowed' => $allRiskDecisions->count() === 1 ? (bool) $risk->allowed : false,
            'gate_reasons' => $allRiskDecisions->count() === 1 ? (array) ($risk?->reasons ?? []) : [],
            'gate_diagnostics' => $allRiskDecisions->count() === 1 ? $riskSnapshot : [],
            'gate_evidence_source' => $allRiskDecisions->count() > 1 ? 'MULTIPLE_ACCOUNT_DECISIONS'
                : ($risk !== null ? 'PERSISTED_RISK_DECISION' : 'NO_RISK_DECISION'),
            'reasons' => $sig->reasons,
            'risks' => $sig->risks,
            'indicator_snapshot' => $sig->indicator_snapshot,
            'rules' => $rules,
            'contract' => $sig->contract,
            'retrospective_outcome' => $sig->retrospective_outcome,
            'position' => $allPositions->count() === 1 ? [
                'id' => $pos->id,
                'paper_account_id' => $pos->paper_account_id,
                'status' => $pos->status,
                'entry_price' => $pos->entry_price,
                'initial_quantity' => $pos->initial_quantity,
                'remaining_quantity' => $pos->remaining_quantity,
                'risk_capital' => $pos->risk_capital,
                'gross_pnl' => $pos->gross_pnl,
                'fees' => $pos->fees,
                'funding' => $pos->funding,
                'net_pnl' => $pos->net_pnl,
                'r_multiple' => $pos->r_multiple,
                'opened_at' => $pos->opened_at?->toIso8601String(),
                'closed_at' => $pos->closed_at?->toIso8601String(),
            ] : null,
            'positions' => $allPositions->map(fn (VirtualPositionRecord $trade) => [
                'id' => $trade->id,
                'paper_account_id' => $trade->paper_account_id,
                'status' => $trade->status,
                'net_pnl' => $trade->net_pnl,
                'gross_pnl' => $trade->gross_pnl,
                'fees' => $trade->fees,
                'funding' => $trade->funding,
                'opened_at' => $trade->opened_at?->toIso8601String(),
                'closed_at' => $trade->closed_at?->toIso8601String(),
            ])->values()->all(),
            'position_count' => $allPositions->count(),
            'net_pnl' => (string) $allPositions->reduce(fn (BigDecimal $total, VirtualPositionRecord $position) => $total->plus($position->net_pnl), BigDecimal::zero()),
            'invalid_attribution_positions' => $invalidPositions,
            'invalid_risk_decisions' => $invalidRisk,
            'attribution_verification_status' => $invalidPositions > 0 || $invalidRisk > 0 ? 'INVALID_DATA' : 'SCOPED_POSTED',
            'risk_decisions' => $allRiskDecisions->map(fn (RiskDecisionRecord $decision) => [
                'id' => $decision->id,
                'paper_account_id' => $decision->paper_account_id,
                'allowed' => $decision->allowed,
                'reasons' => $decision->reasons,
                'snapshot' => $decision->snapshot,
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ])->values()->all(),
            'risk_decision' => $allRiskDecisions->count() === 1 ? [
                'id' => $risk->id,
                'allowed' => $risk->allowed,
                'reasons' => $risk->reasons,
                'snapshot' => $risk->snapshot,
                'decided_at' => $risk->decided_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function buildFilteredSignalsQuery(array $filters): Builder
    {
        $query = SignalRecord::query();

        if (! empty($filters['exchange']) && strtolower((string) $filters['exchange']) !== 'all') {
            $query->where('exchange', strtolower((string) $filters['exchange']));
        }

        if (! empty($filters['strategy']) && strtolower((string) $filters['strategy']) !== 'all') {
            $query->where('strategy_id', (string) $filters['strategy']);
        }

        if (! empty($filters['symbol']) && strtolower((string) $filters['symbol']) !== 'all') {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }

        if (! empty($filters['timeframe']) && strtolower((string) $filters['timeframe']) !== 'all') {
            $query->where('timeframe', (string) $filters['timeframe']);
        }

        if (! empty($filters['side']) && strtolower((string) $filters['side']) !== 'all') {
            $query->where('side', strtoupper((string) $filters['side']));
        }

        if (! empty($filters['signal_quality']) && strtolower((string) $filters['signal_quality']) !== 'all') {
            $quality = strtoupper((string) $filters['signal_quality']);
            $query->whereRaw(SignalQualitySummary::expression('signal_quality', 'signals').' = ?', [$quality]);
        }

        if (! empty($filters['trade_risk']) && strtolower((string) $filters['trade_risk']) !== 'all') {
            $risk = strtoupper((string) $filters['trade_risk']);
            $query->whereRaw(SignalQualitySummary::expression('trade_risk', 'signals').' = ?', [$risk]);
        }

        // Both filters are accepted by API/UI contracts. Ignoring either makes
        // the operator believe a filtered cohort is profitable when it is not.
        $decisionExpression = "COALESCE(NULLIF(risk_decisions.snapshot->>'gate_decision', ''), CASE WHEN risk_decisions.allowed THEN 'ELIGIBLE' ELSE 'REJECTED' END)";
        $gate = strtoupper((string) ($filters['gate_decision'] ?? 'ALL'));
        $allowedGates = ['ALL', 'ELIGIBLE', 'CONDITIONALLY_ELIGIBLE', 'OBSERVATION_ONLY', 'INSUFFICIENT_EVIDENCE', 'REJECTED', 'PENDING'];
        if (! in_array($gate, $allowedGates, true)) {
            $query->whereRaw('1 = 0'); // Unknown value must not broaden financial scope.
        } elseif ($gate === 'PENDING') {
            $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('risk_decisions')
                ->whereColumn('risk_decisions.signal_id', 'signals.id')
                ->whereRaw('EXISTS (SELECT 1 FROM paper_accounts ra WHERE ra.id = risk_decisions.paper_account_id AND ra.exchange_id = signals.exchange)'));
        } elseif ($gate !== 'ALL') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('risk_decisions')
                ->whereColumn('risk_decisions.signal_id', 'signals.id')
                ->whereRaw('EXISTS (SELECT 1 FROM paper_accounts ra WHERE ra.id = risk_decisions.paper_account_id AND ra.exchange_id = signals.exchange)')
                ->whereRaw($decisionExpression.' = ?', [$gate]));
        }

        $executionStatus = strtoupper((string) ($filters['execution_status'] ?? 'ALL'));
        $allowedStatuses = ['ALL', 'EXECUTED', 'NON_EXECUTED', 'REJECTED', 'OBSERVATION_ONLY'];
        if (! in_array($executionStatus, $allowedStatuses, true)) {
            $query->whereRaw('1 = 0');
        } elseif ($executionStatus !== 'ALL') {
            $positionExists = fn ($sub) => $sub->selectRaw('1')->from('virtual_positions')
                ->whereColumn('virtual_positions.signal_id', 'signals.id')
                ->whereRaw(SignalQualitySummary::positionIdentity('virtual_positions', 'signals'));
            if ($executionStatus === 'EXECUTED') {
                $query->whereExists($positionExists);
            } else {
                $query->whereNotExists($positionExists);
                if ($executionStatus === 'NON_EXECUTED') {
                    $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('risk_decisions')
                        ->whereColumn('risk_decisions.signal_id', 'signals.id')
                        ->whereRaw('EXISTS (SELECT 1 FROM paper_accounts ra WHERE ra.id = risk_decisions.paper_account_id AND ra.exchange_id = signals.exchange)')
                        ->whereRaw($decisionExpression.' IN (?, ?)', ['REJECTED', 'OBSERVATION_ONLY']));
                } else {
                    $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('risk_decisions')
                        ->whereColumn('risk_decisions.signal_id', 'signals.id')
                        ->whereRaw('EXISTS (SELECT 1 FROM paper_accounts ra WHERE ra.id = risk_decisions.paper_account_id AND ra.exchange_id = signals.exchange)')
                        ->whereRaw($decisionExpression.' = ?', [$executionStatus]));
                }
            }
        }

        if (! empty($filters['outcome']) && strtolower((string) $filters['outcome']) !== 'all') {
            $query->where('retrospective_outcome', strtoupper((string) $filters['outcome']));
        }

        if (! empty($filters['from'])) {
            $query->where('decision_time', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('decision_time', '<=', $filters['to']);
        }

        return $query;
    }

    private function validPositions(Builder $scope): Builder
    {
        return VirtualPositionRecord::query()->whereIn('id', $this->summaries->positionScope($scope)->select('p.id'));
    }

    private function validRiskDecisions(Builder $scope): Builder
    {
        return RiskDecisionRecord::query()->whereIn('id', $this->summaries->riskScope($scope)->select('r.id'));
    }

    private function computeDiagnostics(Collection|array $signals, Collection|array $positionsBySignalId, array $timeframeComparison): array
    {
        $diagnostics = [];

        // 1. Timeframes with negative expectancy
        foreach ($timeframeComparison as $tfRow) {
            // No recorded source-timeframe signals means expectancy is undefined,
            // not numeric zero. BigDecimal::of('') throws and previously turned
            // an otherwise valid empty analytics cohort into HTTP 500.
            if ($tfRow['expectancy'] !== null
                && $tfRow['signals'] >= 3
                && BigDecimal::of((string) $tfRow['expectancy'])->isNegative()) {
                $diagnostics[] = [
                    'severity' => 'WARNING',
                    'type' => 'NEGATIVE_TIMEFRAME_EXPECTANCY',
                    'message' => "Timeframe {$tfRow['timeframe']} demonstrates negative net expectancy ({$tfRow['expectancy']} USDT/signal) over {$tfRow['signals']} signals.",
                    'timeframe' => $tfRow['timeframe'],
                ];
            }
        }

        // 2. High proportion of low-quality signals
        $totalSignals = is_array($signals) ? $signals['total_signals'] : $signals->count();
        $lowCount = is_array($signals) ? $signals['quality_distribution']['LOW'] : $signals->where('signal_quality', 'LOW')->count();
        if ($totalSignals >= 10 && ($lowCount / $totalSignals) > 0.40) {
            $diagnostics[] = [
                'severity' => 'INFO',
                'type' => 'HIGH_LOW_QUALITY_RATIO',
                'message' => sprintf('Low-quality signals represent %.1f%% of all generated setups (%d/%d). Review the corresponding persisted gate decisions before assuming these signals were filtered.', ($lowCount / $totalSignals) * 100, $lowCount, $totalSignals),
            ];
        }

        // 3. Directional asymmetry (BUY vs SELL)
        $buyClosed = $buyWins = $sellClosed = $sellWins = 0;
        if (is_array($positionsBySignalId)) {
            foreach ($positionsBySignalId as $row) {
                if ($row['side'] === 'BUY') {
                    $buyClosed = (int) $row['closed'];
                    $buyWins = (int) $row['wins'];
                } elseif ($row['side'] === 'SELL') {
                    $sellClosed = (int) $row['closed'];
                    $sellWins = (int) $row['wins'];
                }
            }
        }

        if ($buyClosed >= 5 && $sellClosed >= 5) {
            $buyRate = $buyWins / $buyClosed;
            $sellRate = $sellWins / $sellClosed;
            if (abs($buyRate - $sellRate) > 0.25) {
                $diagnostics[] = [
                    'severity' => 'WARNING',
                    'type' => 'DIRECTIONAL_PERFORMANCE_ASYMMETRY',
                    'message' => sprintf('Significant directional asymmetry observed: BUY win rate %.1f%% vs SELL win rate %.1f%%.', $buyRate * 100, $sellRate * 100),
                ];
            }
        }

        return $diagnostics;
    }

    private function isPositionClosed(?string $status): bool
    {
        return $status !== null && ($status === 'CLOSED' || str_starts_with($status, 'CLOSED_'));
    }
}
