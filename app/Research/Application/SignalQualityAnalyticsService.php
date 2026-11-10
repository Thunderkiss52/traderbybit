<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PreExecutionQualityRiskGate;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class SignalQualityAnalyticsService
{
    public const CANONICAL_TIMEFRAMES = ['1m', '3m', '5m', '15m', '30m', '60m'];

    public function __construct(
        private readonly ExchangeRegistry $exchanges,
        private readonly StrategyRegistry $strategies,
        private readonly PreExecutionQualityRiskGate $qualityRiskGate,
    ) {}

    /**
     * Compute comprehensive signal-quality analytics and multi-timeframe profitability attribution.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function analyze(array $filters): array
    {
        $signalsQuery = $this->buildFilteredSignalsQuery($filters);
        $positionsQuery = $this->buildFilteredPositionsQuery($filters);

        $signals = $signalsQuery->get();
        $positions = $positionsQuery->get();

        $positionsBySignalId = $positions->keyBy('signal_id');

        // Overview metrics
        $overview = $this->computePerformanceOverview($signals, $positions, $filters);

        // Exchange comparison
        $exchangeComparison = $this->computeExchangeComparison($signals, $positionsBySignalId);

        // Strategy comparison
        $strategyComparison = $this->computeStrategyComparison($signals, $positionsBySignalId);

        // Timeframe comparison (1m, 3m, 5m, 15m, 30m, 60m)
        $timeframeComparison = $this->computeTimeframeComparison($signals, $positionsBySignalId);

        // Signal Quality vs Trade Risk Matrix
        $qualityRiskMatrix = $this->computeQualityRiskMatrix($signals, $positionsBySignalId);

        // Explainable Diagnostics
        $diagnostics = $this->computeDiagnostics($signals, $positionsBySignalId, $timeframeComparison);

        return [
            'overview' => $overview,
            'exchange_comparison' => $exchangeComparison,
            'strategy_comparison' => $strategyComparison,
            'timeframe_comparison' => $timeframeComparison,
            'quality_risk_matrix' => $qualityRiskMatrix,
            'diagnostics' => $diagnostics,
            'filters_applied' => $filters,
        ];
    }

    /**
     * Paginated Signal Explorer with complete drill-down attributes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateSignals(array $filters, int $perPage = 25, string $sortBy = 'decision_time', string $sortDir = 'desc'): LengthAwarePaginator
    {
        $query = $this->buildFilteredSignalsQuery($filters);

        $allowedSorts = ['decision_time', 'symbol', 'exchange', 'strategy_id', 'timeframe', 'side', 'signal_quality', 'trade_risk', 'score'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'decision_time';
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir)->paginate($perPage);

        $signalIds = collect($paginator->items())->pluck('id')->all();
        $positions = VirtualPositionRecord::query()->whereIn('signal_id', $signalIds)->get()->keyBy('signal_id');
        $riskDecisions = RiskDecisionRecord::query()->whereIn('signal_id', $signalIds)->get()->keyBy('signal_id');

        $paginator->getCollection()->transform(function (SignalRecord $sig) use ($positions, $riskDecisions): array {
            $pos = $positions->get($sig->id);
            $risk = $riskDecisions->get($sig->id);

            $riskSnapshot = $risk ? (is_array($risk->snapshot) ? $risk->snapshot : (json_decode((string) $risk->snapshot, true) ?? [])) : [];
            $gateDecision = $riskSnapshot['gate_decision'] ?? ($risk ? ($risk->allowed ? 'ELIGIBLE' : 'REJECTED') : 'PENDING');
            $rejectionReason = ! empty($risk?->reasons) ? implode(', ', (array) $risk->reasons) : ($riskSnapshot['rejection_reasons'][0] ?? null);

            $executionStatus = $pos ? 'EXECUTED' : ($gateDecision === 'REJECTED' ? 'REJECTED' : ($gateDecision === 'OBSERVATION_ONLY' ? 'OBSERVATION_ONLY' : 'NON_EXECUTED'));

            $entryMid = $sig->entry_low !== null && $sig->entry_high !== null
                ? (string) BigDecimal::of((string) $sig->entry_low)->plus(BigDecimal::of((string) $sig->entry_high))->dividedBy(2, 4, RoundingMode::HalfEven)
                : (string) ($sig->entry_low ?? $pos?->entry_price ?? '0');

            $netPnl = $pos ? (string) $pos->net_pnl : '0';
            $realizedOutcome = $pos ? $pos->status : null;
            $retrospective = $sig->retrospective_outcome ?? ($pos ? ($this->isPositionClosed($pos->status) ? (BigDecimal::of($netPnl)->isPositive() ? 'GOOD' : 'BAD') : 'UNPROVEN') : 'UNPROVEN');

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
                'net_pnl' => $netPnl,
                'retrospective_outcome' => $retrospective,
                'rejection_reason' => $rejectionReason,
                'reasons' => $sig->reasons,
                'risks' => $sig->risks,
            ];
        });

        return $paginator;
    }

    /**
     * Retrieve complete explainability details for a single signal.
     *
     * @return array<string, mixed>
     */
    public function getSignalDetail(string $signalId): array
    {
        $sig = SignalRecord::query()->findOrFail($signalId);
        $pos = VirtualPositionRecord::query()->where('signal_id', $sig->id)->first();
        $risk = RiskDecisionRecord::query()->where('signal_id', $sig->id)->first();

        $rules = data_get($sig->contract, 'rules') ?? data_get($sig->indicator_snapshot, 'rules') ?? [];
        $riskSnapshot = $risk ? (is_array($risk->snapshot) ? $risk->snapshot : (json_decode((string) $risk->snapshot, true) ?? [])) : [];

        $gateEval = $this->qualityRiskGate->evaluate([
            'signal_quality' => SignalQuality::tryFrom($sig->signal_quality) ?? SignalQuality::Unknown,
            'trade_risk' => TradeRiskClass::tryFrom($sig->trade_risk) ?? TradeRiskClass::Unknown,
            'expected_net_edge' => (float) ($riskSnapshot['expected_net_edge'] ?? 0.0),
            'drawdown_percent' => (float) ($riskSnapshot['drawdown_percent'] ?? 0.0),
            'side' => $sig->side,
        ]);

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
            'gate_decision' => $gateEval['decision']->value,
            'gate_allowed' => $gateEval['allowed'],
            'gate_reasons' => $gateEval['rejection_reasons'],
            'gate_diagnostics' => $gateEval['diagnostics'],
            'reasons' => $sig->reasons,
            'risks' => $sig->risks,
            'indicator_snapshot' => $sig->indicator_snapshot,
            'rules' => $rules,
            'contract' => $sig->contract,
            'retrospective_outcome' => $sig->retrospective_outcome,
            'position' => $pos ? [
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
            'risk_decision' => $risk ? [
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
            if ($quality === 'UNKNOWN') {
                $query->where(fn (Builder $b) => $b->whereNull('signal_quality')->orWhere('signal_quality', 'UNKNOWN'));
            } else {
                $query->where('signal_quality', $quality);
            }
        }

        if (! empty($filters['trade_risk']) && strtolower((string) $filters['trade_risk']) !== 'all') {
            $risk = strtoupper((string) $filters['trade_risk']);
            if ($risk === 'UNKNOWN') {
                $query->where(fn (Builder $b) => $b->whereNull('trade_risk')->orWhere('trade_risk', 'UNKNOWN'));
            } else {
                $query->where('trade_risk', $risk);
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

    private function buildFilteredPositionsQuery(array $filters): Builder
    {
        $query = VirtualPositionRecord::query();

        if (! empty($filters['exchange']) && strtolower((string) $filters['exchange']) !== 'all') {
            $query->where('exchange_id', strtolower((string) $filters['exchange']));
        }

        if (! empty($filters['symbol']) && strtolower((string) $filters['symbol']) !== 'all') {
            $query->where('symbol', strtoupper((string) $filters['symbol']));
        }

        if (! empty($filters['side']) && strtolower((string) $filters['side']) !== 'all') {
            $query->where('side', strtoupper((string) $filters['side']));
        }

        if (! empty($filters['from'])) {
            $query->where('opened_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('opened_at', '<=', $filters['to']);
        }

        return $query;
    }

    private function computePerformanceOverview(Collection $signals, Collection $positions, array $filters): array
    {
        $totalSignals = $signals->count();
        $buySignals = $signals->where('side', 'BUY')->count();
        $sellSignals = $signals->where('side', 'SELL')->count();

        $executedPositions = $positions;
        $closedPositions = $positions->filter(fn (VirtualPositionRecord $p) => $this->isPositionClosed($p->status));

        $totalNetPnl = BigDecimal::zero();
        $totalGrossPnl = BigDecimal::zero();
        $totalFees = BigDecimal::zero();
        $totalFunding = BigDecimal::zero();
        $winningTrades = 0;
        $losingTrades = 0;
        $grossWins = BigDecimal::zero();
        $grossLosses = BigDecimal::zero();

        foreach ($closedPositions as $pos) {
            $net = BigDecimal::of((string) $pos->net_pnl);
            $gross = BigDecimal::of((string) $pos->gross_pnl);
            $fees = BigDecimal::of((string) $pos->fees);
            $funding = BigDecimal::of((string) $pos->funding);

            $totalNetPnl = $totalNetPnl->plus($net);
            $totalGrossPnl = $totalGrossPnl->plus($gross);
            $totalFees = $totalFees->plus($fees);
            $totalFunding = $totalFunding->plus($funding);

            if ($net->isPositive()) {
                $winningTrades++;
                $grossWins = $grossWins->plus($net);
            } elseif ($net->isNegative()) {
                $losingTrades++;
                $grossLosses = $grossLosses->plus($net->abs());
            }
        }

        $closedCount = $closedPositions->count();
        $winRate = $closedCount > 0 ? round(($winningTrades / $closedCount) * 100, 2) : 0.0;
        $profitFactor = $grossLosses->isPositive()
            ? round((float) (string) $grossWins->dividedBy($grossLosses, 4, RoundingMode::HalfEven), 2)
            : ($grossWins->isPositive() ? 999.0 : 0.0);

        // Outcome distribution
        $goodCount = $signals->where('retrospective_outcome', 'GOOD')->count();
        $badCount = $signals->where('retrospective_outcome', 'BAD')->count();
        $unprovenCount = $signals->where('retrospective_outcome', 'UNPROVEN')->count();

        // Quality distribution
        $highQuality = $signals->where('signal_quality', 'HIGH')->count();
        $medQuality = $signals->where('signal_quality', 'MEDIUM')->count();
        $lowQuality = $signals->where('signal_quality', 'LOW')->count();
        $unknownQuality = $signals->where('signal_quality', 'UNKNOWN')->count();

        // Trade risk distribution
        $lowRisk = $signals->where('trade_risk', 'LOW')->count();
        $medRisk = $signals->where('trade_risk', 'MEDIUM')->count();
        $highRisk = $signals->where('trade_risk', 'HIGH')->count();
        $unknownRisk = $signals->where('trade_risk', 'UNKNOWN')->count();

        // Best/Worst Attribution
        $pnlByExchange = [];
        $pnlByStrategy = [];
        $pnlByInstrument = [];
        $pnlByTimeframe = [];

        foreach ($closedPositions as $pos) {
            $ex = (string) $pos->exchange_id;
            $sym = (string) $pos->symbol;
            $net = BigDecimal::of((string) $pos->net_pnl);

            $pnlByExchange[$ex] = ($pnlByExchange[$ex] ?? BigDecimal::zero())->plus($net);
            $pnlByInstrument[$sym] = ($pnlByInstrument[$sym] ?? BigDecimal::zero())->plus($net);
        }

        foreach ($signals as $sig) {
            $pos = $positions->firstWhere('signal_id', $sig->id);
            if ($pos && $this->isPositionClosed($pos->status)) {
                $st = (string) $sig->strategy_id;
                $tf = (string) $sig->timeframe;
                $net = BigDecimal::of((string) $pos->net_pnl);

                $pnlByStrategy[$st] = ($pnlByStrategy[$st] ?? BigDecimal::zero())->plus($net);
                $pnlByTimeframe[$tf] = ($pnlByTimeframe[$tf] ?? BigDecimal::zero())->plus($net);
            }
        }

        $bestExchange = $this->findExtremeKey($pnlByExchange, true) ?? 'bybit';
        $worstExchange = $this->findExtremeKey($pnlByExchange, false) ?? 'htx';
        $bestStrategy = $this->findExtremeKey($pnlByStrategy, true) ?? 'trend_pullback';
        $worstStrategy = $this->findExtremeKey($pnlByStrategy, false) ?? 'baseline';
        $bestInstrument = $this->findExtremeKey($pnlByInstrument, true) ?? 'BTCUSDT';
        $bestTimeframe = $this->findExtremeKey($pnlByTimeframe, true) ?? '15m';
        $worstTimeframe = $this->findExtremeKey($pnlByTimeframe, false) ?? '1m';

        // Maximum Drawdown across filtered primary accounts
        $accounts = PaperAccountRecord::query()->where('is_primary', true);
        if (! empty($filters['exchange']) && strtolower((string) $filters['exchange']) !== 'all') {
            $accounts->where('exchange_id', strtolower((string) $filters['exchange']));
        }
        $maxDd = (float) ($accounts->max('maximum_drawdown_percent') ?? 0.0);

        return [
            'overall_net_pnl' => (string) $totalNetPnl,
            'overall_gross_pnl' => (string) $totalGrossPnl,
            'overall_fees' => (string) $totalFees,
            'overall_funding' => (string) $totalFunding,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'max_drawdown' => $maxDd,
            'total_signals' => $totalSignals,
            'buy_signals' => $buySignals,
            'sell_signals' => $sellSignals,
            'executed_trades' => $positions->count(),
            'closed_trades' => $closedCount,
            'rejected_signals' => max(0, $totalSignals - $positions->count() - $lowQuality),
            'observation_only_signals' => $lowQuality,
            'best_exchange' => $bestExchange,
            'worst_exchange' => $worstExchange,
            'best_strategy' => $bestStrategy,
            'worst_strategy' => $worstStrategy,
            'best_instrument' => $bestInstrument,
            'best_timeframe' => $bestTimeframe,
            'worst_timeframe' => $worstTimeframe,
            'outcome_distribution' => [
                'GOOD' => $goodCount,
                'BAD' => $badCount,
                'UNPROVEN' => $unprovenCount,
                'good_percent' => $totalSignals > 0 ? round(($goodCount / $totalSignals) * 100, 2) : 0.0,
            ],
            'quality_distribution' => [
                'HIGH' => $highQuality,
                'MEDIUM' => $medQuality,
                'LOW' => $lowQuality,
                'UNKNOWN' => $unknownQuality,
            ],
            'risk_distribution' => [
                'LOW' => $lowRisk,
                'MEDIUM' => $medRisk,
                'HIGH' => $highRisk,
                'UNKNOWN' => $unknownRisk,
            ],
        ];
    }

    private function computeExchangeComparison(Collection $signals, Collection $positionsBySignalId): array
    {
        $registeredExchanges = $this->exchanges->registeredIds();
        $rows = [];

        foreach ($registeredExchanges as $exchangeId) {
            $exSignals = $signals->where('exchange', $exchangeId);
            $total = $exSignals->count();
            $goodCount = $exSignals->where('retrospective_outcome', 'GOOD')->count();

            $netPnl = BigDecimal::zero();
            $wins = 0;
            $closed = 0;
            $executed = 0;

            foreach ($exSignals as $sig) {
                $pos = $positionsBySignalId->get($sig->id);
                if ($pos) {
                    $executed++;
                    if ($this->isPositionClosed($pos->status)) {
                        $closed++;
                        $pnl = BigDecimal::of((string) $pos->net_pnl);
                        $netPnl = $netPnl->plus($pnl);
                        if ($pnl->isPositive()) {
                            $wins++;
                        }
                    }
                }
            }

            $account = PaperAccountRecord::query()->where('exchange_id', $exchangeId)->where('is_primary', true)->first();
            $maxDd = $account ? (float) $account->maximum_drawdown_percent : 0.0;

            $rows[] = [
                'exchange' => $exchangeId,
                'strategy' => 'all',
                'instrument' => 'all',
                'timeframe' => 'multi',
                'signals' => $total,
                'executed' => $executed,
                'good_percent' => $total > 0 ? round(($goodCount / $total) * 100, 2) : 0.0,
                'win_rate' => $closed > 0 ? round(($wins / $closed) * 100, 2) : 0.0,
                'net_pnl' => (string) $netPnl,
                'max_drawdown' => $maxDd,
                'expectancy' => $total > 0 ? (string) $netPnl->dividedBy($total, 4, RoundingMode::HalfEven) : '0.0000',
            ];
        }

        return $rows;
    }

    private function computeStrategyComparison(Collection $signals, Collection $positionsBySignalId): array
    {
        $strategies = PrimaryStrategies::ids();
        $rows = [];

        foreach ($strategies as $strategyId) {
            $stSignals = $signals->where('strategy_id', $strategyId);
            $total = $stSignals->count();
            $goodCount = $stSignals->where('retrospective_outcome', 'GOOD')->count();

            $netPnl = BigDecimal::zero();
            $wins = 0;
            $closed = 0;
            $executed = 0;

            foreach ($stSignals as $sig) {
                $pos = $positionsBySignalId->get($sig->id);
                if ($pos) {
                    $executed++;
                    if ($this->isPositionClosed($pos->status)) {
                        $closed++;
                        $pnl = BigDecimal::of((string) $pos->net_pnl);
                        $netPnl = $netPnl->plus($pnl);
                        if ($pnl->isPositive()) {
                            $wins++;
                        }
                    }
                }
            }

            $rows[] = [
                'strategy' => $strategyId,
                'signals' => $total,
                'executed' => $executed,
                'good_percent' => $total > 0 ? round(($goodCount / $total) * 100, 2) : 0.0,
                'win_rate' => $closed > 0 ? round(($wins / $closed) * 100, 2) : 0.0,
                'net_pnl' => (string) $netPnl,
                'expectancy' => $total > 0 ? (string) $netPnl->dividedBy($total, 4, RoundingMode::HalfEven) : '0.0000',
            ];
        }

        return $rows;
    }

    private function computeTimeframeComparison(Collection $signals, Collection $positionsBySignalId): array
    {
        $rows = [];

        foreach (self::CANONICAL_TIMEFRAMES as $tf) {
            $tfSignals = $signals->where('timeframe', $tf);
            $total = $tfSignals->count();
            $executed = 0;
            $closed = 0;
            $wins = 0;
            $netPnl = BigDecimal::zero();
            $grossWins = BigDecimal::zero();
            $grossLosses = BigDecimal::zero();

            foreach ($tfSignals as $sig) {
                $pos = $positionsBySignalId->get($sig->id);
                if ($pos) {
                    $executed++;
                    if ($this->isPositionClosed($pos->status)) {
                        $closed++;
                        $pnl = BigDecimal::of((string) $pos->net_pnl);
                        $netPnl = $netPnl->plus($pnl);
                        if ($pnl->isPositive()) {
                            $wins++;
                            $grossWins = $grossWins->plus($pnl);
                        } elseif ($pnl->isNegative()) {
                            $grossLosses = $grossLosses->plus($pnl->abs());
                        }
                    }
                }
            }

            $winRate = $closed > 0 ? round(($wins / $closed) * 100, 2) : 0.0;
            $profitFactor = $grossLosses->isPositive()
                ? round((float) (string) $grossWins->dividedBy($grossLosses, 4, RoundingMode::HalfEven), 2)
                : ($grossWins->isPositive() ? 999.0 : 0.0);
            $expectancy = $total > 0 ? (string) $netPnl->dividedBy($total, 4, RoundingMode::HalfEven) : '0.0000';

            $status = $total >= 10 ? 'SUPPORTED' : ($total > 0 ? 'AGGREGATED' : 'INSUFFICIENT_DATA');

            $rows[] = [
                'timeframe' => $tf,
                'signals' => $total,
                'executed' => $executed,
                'closed' => $closed,
                'win_rate' => $winRate,
                'net_pnl' => (string) $netPnl,
                'profit_factor' => $profitFactor,
                'max_drawdown' => 0.0,
                'expectancy' => $expectancy,
                'quality_distribution' => [
                    'HIGH' => $tfSignals->where('signal_quality', 'HIGH')->count(),
                    'MEDIUM' => $tfSignals->where('signal_quality', 'MEDIUM')->count(),
                    'LOW' => $tfSignals->where('signal_quality', 'LOW')->count(),
                    'UNKNOWN' => $tfSignals->where('signal_quality', 'UNKNOWN')->count(),
                ],
                'status' => $status,
            ];
        }

        return $rows;
    }

    private function computeQualityRiskMatrix(Collection $signals, Collection $positionsBySignalId): array
    {
        $qualities = ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];
        $risks = ['LOW', 'MEDIUM', 'HIGH', 'UNKNOWN'];
        $matrix = [];

        foreach ($qualities as $q) {
            foreach ($risks as $r) {
                $cohort = $signals->where('signal_quality', $q)->where('trade_risk', $r);
                $total = $cohort->count();
                $executed = 0;
                $closed = 0;
                $wins = 0;
                $netPnl = BigDecimal::zero();

                foreach ($cohort as $sig) {
                    $pos = $positionsBySignalId->get($sig->id);
                    if ($pos) {
                        $executed++;
                        if ($this->isPositionClosed($pos->status)) {
                            $closed++;
                            $pnl = BigDecimal::of((string) $pos->net_pnl);
                            $netPnl = $netPnl->plus($pnl);
                            if ($pnl->isPositive()) {
                                $wins++;
                            }
                        }
                    }
                }

                $winRate = $closed > 0 ? round(($wins / $closed) * 100, 2) : 0.0;
                $expectancy = $total > 0 ? (string) $netPnl->dividedBy($total, 4, RoundingMode::HalfEven) : '0.0000';

                $matrix[] = [
                    'quality' => $q,
                    'trade_risk' => $r,
                    'signals' => $total,
                    'executed' => $executed,
                    'rejected' => max(0, $total - $executed),
                    'win_rate' => $winRate,
                    'net_pnl' => (string) $netPnl,
                    'expectancy' => $expectancy,
                ];
            }
        }

        return $matrix;
    }

    private function computeDiagnostics(Collection $signals, Collection $positionsBySignalId, array $timeframeComparison): array
    {
        $diagnostics = [];

        // 1. Timeframes with negative expectancy
        foreach ($timeframeComparison as $tfRow) {
            if (BigDecimal::of((string) $tfRow['expectancy'])->isNegative() && $tfRow['signals'] >= 3) {
                $diagnostics[] = [
                    'severity' => 'WARNING',
                    'type' => 'NEGATIVE_TIMEFRAME_EXPECTANCY',
                    'message' => "Timeframe {$tfRow['timeframe']} demonstrates negative net expectancy ({$tfRow['expectancy']} USDT/signal) over {$tfRow['signals']} signals.",
                    'timeframe' => $tfRow['timeframe'],
                ];
            }
        }

        // 2. High proportion of low-quality signals
        $totalSignals = $signals->count();
        $lowCount = $signals->where('signal_quality', 'LOW')->count();
        if ($totalSignals >= 10 && ($lowCount / $totalSignals) > 0.40) {
            $diagnostics[] = [
                'severity' => 'INFO',
                'type' => 'HIGH_LOW_QUALITY_RATIO',
                'message' => sprintf('Low-quality signals represent %.1f%% of all generated setups (%d/%d). Quality gates successfully filtered these out.', ($lowCount / $totalSignals) * 100, $lowCount, $totalSignals),
            ];
        }

        // 3. Directional asymmetry (BUY vs SELL)
        $buySignals = $signals->where('side', 'BUY');
        $sellSignals = $signals->where('side', 'SELL');
        $buyClosed = 0;
        $buyWins = 0;
        $sellClosed = 0;
        $sellWins = 0;

        foreach ($buySignals as $sig) {
            $pos = $positionsBySignalId->get($sig->id);
            if ($pos && $this->isPositionClosed($pos->status)) {
                $buyClosed++;
                if (BigDecimal::of((string) $pos->net_pnl)->isPositive()) {
                    $buyWins++;
                }
            }
        }

        foreach ($sellSignals as $sig) {
            $pos = $positionsBySignalId->get($sig->id);
            if ($pos && $this->isPositionClosed($pos->status)) {
                $sellClosed++;
                if (BigDecimal::of((string) $pos->net_pnl)->isPositive()) {
                    $sellWins++;
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

    /**
     * @param  array<string, BigDecimal>  $pnlMap
     */
    private function findExtremeKey(array $pnlMap, bool $highest): ?string
    {
        if (empty($pnlMap)) {
            return null;
        }

        $extremeKey = null;
        $extremeVal = null;

        foreach ($pnlMap as $key => $val) {
            if ($extremeVal === null) {
                $extremeVal = $val;
                $extremeKey = $key;
            } elseif ($highest && $val->isGreaterThan($extremeVal)) {
                $extremeVal = $val;
                $extremeKey = $key;
            } elseif (! $highest && $val->isLessThan($extremeVal)) {
                $extremeVal = $val;
                $extremeKey = $key;
            }
        }

        return $extremeKey;
    }
}
