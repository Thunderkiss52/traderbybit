<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exchanges\ExchangeRegistry;
use App\Http\Controllers\Controller;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\AutonomyResearchService;
use App\Research\Application\CostMfeMaeService;
use App\Research\Application\DecisionEvidenceService;
use App\Research\Application\DependenceConcentrationService;
use App\Research\Application\MetricRegistryService;
use App\Research\Application\QuantPortfolioMetricsService;
use App\Research\Application\RiskCapitalMetricsService;
use App\Research\Application\SlippageAttributionService;
use App\Research\Domain\SlippageAttributionFillRecord;
use App\Research\Domain\SlippageAttributionRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProfitabilityResearchController extends Controller
{
    public function __construct(
        private readonly PerformanceStatisticsService $performanceStatistics,
        private readonly DailyPerformanceService $dailyPerformance,
        private readonly QuantPortfolioMetricsService $quantPortfolioMetrics,
        private readonly MetricRegistryService $metricRegistry,
        private readonly SlippageAttributionService $slippageAttribution,
        private readonly RiskCapitalMetricsService $riskCapitalMetrics,
        private readonly DependenceConcentrationService $dependenceConcentration,
        private readonly CostMfeMaeService $costMfeMae,
        private readonly DecisionEvidenceService $decisionEvidence,
        private readonly AutonomyResearchService $autonomy,
    ) {}

    /** Keep authoritative totals decimal; research ratios remain diagnostic estimates. */
    private function lifecycleEconomics(Collection $positions, CarbonImmutable $asOf): array
    {
        $result = $this->quantPortfolioMetrics->computeLifecyclePopulationEconomics($positions, $asOf);
        foreach (['gross_pnl' => 'gross_pnl', 'fees' => 'fees', 'signed_funding' => 'funding', 'net_pnl' => 'net_pnl'] as $key => $column) {
            $result[$key] = (string) $positions->reduce(
                fn (BigDecimal $sum, VirtualPositionRecord $position): BigDecimal => $sum->plus($position->{$column}), BigDecimal::zero()
            );
        }

        return $result;
    }

    /** @return list<string> */
    private function primaryStrategies(): array
    {
        return array_values(PrimaryStrategies::ids());
    }

    /**
     * Overall profitability research summary across the primary accounts.
     * Enforces strict accounting scope separation:
     * - FULL_LIFECYCLE: complete position economics
     * - WINDOW_FLOW: time-bounded cashflows and equity changes
     * - POINT_IN_TIME: current balance, equity, and drawdown
     * Purely read-only; zero database writes.
     */
    public function summary(Request $request): JsonResponse
    {
        $asOf = CarbonImmutable::now('UTC');
        $exchange = $this->resolveExchange($request);
        $symbol = $this->resolveSymbol($request);
        $accounts = $this->getPrimaryAccountsReadOnly($exchange);

        $primaryAccountIds = $accounts->pluck('id')->filter()->all();

        // 1. Point-in-time balance totals
        $totalStartingBalance = BigDecimal::zero();
        $totalCurrentEquity = BigDecimal::zero();
        $totalPeakEquity = BigDecimal::zero();
        $totalRealizedPnl = BigDecimal::zero();

        $accountSummaries = [];

        foreach ($accounts as $account) {
            $starting = BigDecimal::of($account->starting_balance ?? throw new \DomainException('Missing starting balance.'));
            $equity = BigDecimal::of($account->current_equity ?? throw new \DomainException('Missing current equity.'));
            $peak = BigDecimal::of($account->peak_equity ?? throw new \DomainException('Missing peak equity.'));
            $realized = BigDecimal::of($account->realized_pnl ?? throw new \DomainException('Missing realized PnL.'));

            $totalStartingBalance = $totalStartingBalance->plus($starting);
            $totalCurrentEquity = $totalCurrentEquity->plus($equity);
            $totalPeakEquity = $totalPeakEquity->plus($peak);
            $totalRealizedPnl = $totalRealizedPnl->plus($realized);

            // Latest reconciliation run (READ-ONLY: querying existing record, NOT generating new one)
            $latestReconciliation = ReconciliationRunRecord::query()
                ->where('paper_account_id', $account->id)
                ->orderByDesc('created_at')
                ->first();

            $accountSummaries[] = [
                'account_id' => $account->id,
                'strategy_id' => $account->primary_strategy_id ?? $account->strategy_id,
                'name' => $account->name,
                'is_primary' => (bool) $account->is_primary,
                'exchange_id' => (string) $account->exchange_id,
                'starting_balance' => (string) $starting,
                'current_equity' => (string) $equity,
                'peak_equity' => (string) $peak,
                'realized_pnl' => (string) $realized,
                'current_drawdown_percent' => (string) ($account->current_drawdown_percent ?? '0.00'),
                'maximum_drawdown_percent' => (string) ($account->maximum_drawdown_percent ?? '0.00'),
                'reconciliation' => $latestReconciliation !== null ? [
                    'status' => $latestReconciliation->status,
                    'reconciled_at' => $latestReconciliation->created_at?->toIso8601String(),
                    'discrepancy_count' => $latestReconciliation->discrepancy_count,
                ] : [
                    'status' => 'NO_RECORDED_RUN',
                    'reconciled_at' => null,
                    'discrepancy_count' => null,
                ],
            ];
        }

        // 2. Full-lifecycle population economics (strictly isolated from window totals)
        $positionsQuery = VirtualPositionRecord::query()
            ->whereIn('paper_account_id', $primaryAccountIds);
        if ($exchange !== 'all') {
            $positionsQuery->where('exchange_id', $exchange);
        }
        if ($symbol !== null) {
            $positionsQuery->where('symbol', $symbol);
        }
        $allPositions = $positionsQuery->get();

        $lifecycleEconomics = $this->lifecycleEconomics($allPositions, $asOf);

        // Breakdown by exchange across filtered positions
        $breakdownByExchange = [];
        $positionsByExchange = $allPositions->groupBy('exchange_id');
        foreach ($positionsByExchange as $exId => $exPositions) {
            $exEcon = $this->lifecycleEconomics($exPositions, $asOf);
            $breakdownByExchange[$exId] = [
                'exchange_id' => (string) $exId,
                'total_positions' => $exEcon['total_positions'],
                'gross_pnl' => (string) $exEcon['gross_pnl'],
                'fees' => (string) $exEcon['fees'],
                'signed_funding' => (string) $exEcon['signed_funding'],
                'net_pnl' => (string) $exEcon['net_pnl'],
                'win_rate' => $exEcon['win_rate'],
                'profit_factor' => $exEcon['profit_factor'],
            ];
        }

        // 3. Execution-level slippage price delta diagnostic
        $executions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $allPositions->pluck('id'))
            ->get(['id', 'quantity', 'price', 'slippage', 'commission', 'type', 'created_at']);

        $totalSlippageDollarDrag = BigDecimal::zero();
        $executionCount = count($executions);

        foreach ($executions as $exec) {
            $qty = BigDecimal::of($exec->quantity ?? '0');
            $slip = BigDecimal::of($exec->slippage ?? '0');
            $totalSlippageDollarDrag = $totalSlippageDollarDrag->plus($qty->multipliedBy($slip));
        }

        $payload = [
            'status' => 'SUCCESS',
            'data' => [
                'as_of' => $asOf->toIso8601String(),
                'accounting_scopes' => [
                    'point_in_time' => [
                        'scope' => 'POINT_IN_TIME',
                        'as_of' => $asOf->toIso8601String(),
                        'availability' => $accounts->isEmpty() ? 'EMPTY' : 'AVAILABLE',
                        'total_starting_balance' => $accounts->isEmpty() ? null : (string) $totalStartingBalance,
                        'total_current_equity' => $accounts->isEmpty() ? null : (string) $totalCurrentEquity,
                        'total_peak_equity' => $accounts->isEmpty() ? null : (string) $totalPeakEquity,
                        'total_realized_pnl' => $accounts->isEmpty() ? null : (string) $totalRealizedPnl,
                        'active_accounts_count' => count($accounts),
                        'accounts' => $accountSummaries,
                    ],
                    'full_lifecycle_economics' => [
                        'scope' => 'FULL_LIFECYCLE',
                        'as_of' => $asOf->toIso8601String(),
                        'total_positions' => $lifecycleEconomics['total_positions'],
                        'gross_pnl' => $accounts->isEmpty() ? null : (string) $lifecycleEconomics['gross_pnl'],
                        'fees' => $accounts->isEmpty() ? null : (string) $lifecycleEconomics['fees'],
                        'signed_funding' => $accounts->isEmpty() ? null : (string) $lifecycleEconomics['signed_funding'],
                        'net_pnl' => $accounts->isEmpty() ? null : (string) $lifecycleEconomics['net_pnl'],
                        'accounting_identity_holds' => $accounts->isEmpty() ? null : BigDecimal::of($lifecycleEconomics['gross_pnl'])->minus($lifecycleEconomics['fees'])->plus($lifecycleEconomics['signed_funding'])->isEqualTo(BigDecimal::of($lifecycleEconomics['net_pnl'])),
                        'profit_factor' => $lifecycleEconomics['profit_factor'],
                        'win_rate' => $lifecycleEconomics['win_rate'],
                        'counts' => $lifecycleEconomics['counts'],
                        'r_metrics' => $lifecycleEconomics['r_metrics'],
                        'breakdown_by_symbol' => $lifecycleEconomics['breakdown_by_symbol'],
                        'breakdown_by_exchange' => $breakdownByExchange,
                        'provenance' => 'RECOMPUTED_FROM_SOURCE',
                    ],
                    'execution_slippage_diagnostic' => [
                        'scope' => 'EVENT_EXECUTION',
                        'execution_count' => $executionCount,
                        'stored_slippage_unit' => 'USDT_PER_CONTRACT_PRICE_DELTA',
                        'total_attributable_dollar_drag' => (string) $totalSlippageDollarDrag,
                        'double_deduction_prohibited' => true,
                        'diagnostic_warning' => 'Fill prices already reflect slippage. This dollar drag must NOT be subtracted from Net PnL a second time.',
                    ],
                ],
                'filters' => [
                    'exchange' => $exchange,
                    'symbol' => $symbol ?? 'ALL',
                    'instrument' => $request->header('X-Instrument') ?: $request->string('instrument')->toString() ?: 'all',
                ],
                'gated_modules' => [
                    'P-1' => ['name' => 'Slippage Attribution', 'status' => 'IMPLEMENTED_VERIFIED', 'authorized' => true],
                    'P-2' => ['name' => 'Risk-Adjusted Metrics & Capital Efficiency', 'status' => 'IMPLEMENTED', 'authorized' => true],
                    'P-3' => ['name' => 'Rejected-Signal SHADOW Replay', 'status' => 'BLOCKED_PROHIBITED', 'authorized' => false],
                    'P-4' => ['name' => 'Cost Structure & MFE/MAE', 'status' => 'NOT_IMPLEMENTED', 'authorized' => false],
                    'P-5' => ['name' => 'Dependence & Concentration', 'status' => 'NOT_IMPLEMENTED', 'authorized' => false],
                    'P-6' => ['name' => 'Decision Evidence Layer', 'status' => 'NOT_IMPLEMENTED', 'authorized' => false],
                ],
                'archives_status' => [
                    'primary_archive' => [
                        'filename' => 'vector-audit-1d-e534ac49-60de-5263-9685-4bea563094c2.zip',
                        'expected_sha256' => '54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4',
                        'status' => 'PENDING_OPERATOR_CONFIRMATION',
                    ],
                    'secondary_archive' => [
                        'filename' => 'vector-audit-1d-53d90c98-a643-5bd0-8b5b-d3f90bdf7757.zip',
                        'expected_sha256' => 'ee703740899b0b378b1d8adb70f2c6cfb21484a630be41a49783db9917db2d5a',
                        'status' => 'PRESERVED_UNAPPROVED_DATASET',
                    ],
                ],
            ],
        ];

        return response()->json($payload);
    }

    /**
     * List of the primary strategies with separated accounting scopes.
     * Purely read-only.
     */
    public function strategies(Request $request): JsonResponse
    {
        $exchange = $this->resolveExchange($request);
        $symbol = $this->resolveSymbol($request);
        $accounts = $this->getPrimaryAccountsReadOnly($exchange);
        $asOf = CarbonImmutable::now('UTC');

        $result = [];

        foreach ($accounts as $account) {
            $strategyId = $account->primary_strategy_id ?? $account->strategy_id;
            $posQuery = VirtualPositionRecord::query()
                ->where('paper_account_id', $account->id);
            if ($symbol !== null) {
                $posQuery->where('symbol', $symbol);
            }
            $positions = $posQuery->get();

            $economics = $this->lifecycleEconomics($positions, $asOf);

            $result[] = [
                'strategy_id' => $strategyId,
                'account_id' => $account->id,
                'name' => $account->name,
                'exchange_id' => (string) $account->exchange_id,
                'point_in_time' => [
                    'scope' => 'POINT_IN_TIME',
                    'starting_balance' => (string) $account->starting_balance,
                    'current_equity' => (string) $account->current_equity,
                    'peak_equity' => (string) $account->peak_equity,
                    'realized_pnl' => (string) $account->realized_pnl,
                    'drawdown_percent' => (string) ($account->current_drawdown_percent ?? '0.00'),
                ],
                'full_lifecycle' => [
                    'scope' => 'FULL_LIFECYCLE',
                    'position_count' => $economics['total_positions'],
                    'gross_pnl' => (string) $economics['gross_pnl'],
                    'fees' => (string) $economics['fees'],
                    'signed_funding' => (string) $economics['signed_funding'],
                    'net_pnl' => (string) $economics['net_pnl'],
                    'win_rate' => $economics['win_rate'],
                    'profit_factor' => $economics['profit_factor'],
                    'breakdown_by_symbol' => $economics['breakdown_by_symbol'],
                ],
            ];
        }

        return response()->json([
            'status' => 'SUCCESS',
            'data' => [
                'as_of' => $asOf->toIso8601String(),
                'filters' => [
                    'exchange' => $exchange,
                    'symbol' => $symbol ?? 'ALL',
                    'instrument' => $request->header('X-Instrument') ?: $request->string('instrument')->toString() ?: 'all',
                ],
                'strategies' => $result,
            ],
        ]);
    }

    /**
     * Detailed economic drill-down for a single primary strategy.
     * Purely read-only.
     */
    public function strategyDetail(string $strategyId, Request $request): JsonResponse
    {
        $exchange = $this->resolveExchange($request);
        $symbol = $this->resolveSymbol($request);

        $accQuery = PaperAccountRecord::query();
        if ($exchange !== 'all') {
            $accQuery->where('exchange_id', $exchange);
        }
        $accounts = $accQuery->where(function ($query) use ($strategyId): void {
            $query->where('primary_strategy_id', $strategyId)
                ->orWhere('strategy_id', $strategyId);
        })->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'error' => [
                    'code' => 'STRATEGY_NOT_FOUND',
                    'message' => "Primary paper strategy [{$strategyId}] was not found.",
                ],
            ], 404);
        }

        $account = $accounts->firstWhere('exchange_id', 'bybit') ?? $accounts->first();
        $totalStarting = $accounts->reduce(fn ($sum, $a) => $sum->plus($a->starting_balance ?? '0'), BigDecimal::zero());
        $totalCurrent = $accounts->reduce(fn ($sum, $a) => $sum->plus($a->current_equity ?? '0'), BigDecimal::zero());
        $totalPeak = $accounts->reduce(fn ($sum, $a) => $sum->plus($a->peak_equity ?? '0'), BigDecimal::zero());
        $totalRealized = $accounts->reduce(fn ($sum, $a) => $sum->plus($a->realized_pnl ?? '0'), BigDecimal::zero());

        $asOf = CarbonImmutable::now('UTC');
        $posQuery = VirtualPositionRecord::query()
            ->whereIn('paper_account_id', $accounts->pluck('id'));
        if ($symbol !== null) {
            $posQuery->where('symbol', $symbol);
        }
        $positions = $posQuery->orderByDesc('opened_at')->get();

        $lifecycleEconomics = $this->lifecycleEconomics($positions, $asOf);

        // Breakdown by exchange for this strategy
        $breakdownByExchange = [];
        foreach ($positions->groupBy('exchange_id') as $exId => $exPositions) {
            $exEcon = $this->lifecycleEconomics($exPositions, $asOf);
            $breakdownByExchange[$exId] = [
                'exchange_id' => (string) $exId,
                'total_positions' => $exEcon['total_positions'],
                'gross_pnl' => (string) $exEcon['gross_pnl'],
                'fees' => (string) $exEcon['fees'],
                'signed_funding' => (string) $exEcon['signed_funding'],
                'net_pnl' => (string) $exEcon['net_pnl'],
                'win_rate' => $exEcon['win_rate'],
                'profit_factor' => $exEcon['profit_factor'],
            ];
        }

        // Fetch recent executions
        $executions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $positions->pluck('id'))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'position_id', 'type', 'quantity', 'price', 'slippage', 'commission', 'created_at']);

        // Latest reconciliation
        $latestReconciliation = ReconciliationRunRecord::query()
            ->whereIn('paper_account_id', $accounts->pluck('id'))
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'status' => 'SUCCESS',
            'data' => [
                'as_of' => $asOf->toIso8601String(),
                'strategy_id' => $strategyId,
                'filters' => [
                    'exchange' => $exchange,
                    'symbol' => $symbol ?? 'ALL',
                ],
                'account' => [
                    'id' => $accounts->count() === 1 ? $account->id : 'aggregated',
                    'name' => $accounts->count() === 1 ? $account->name : "All Exchanges ({$accounts->count()} accounts)",
                    'mode' => $account->mode,
                    'is_primary' => (bool) $account->is_primary,
                    'exchange_id' => $accounts->count() === 1 ? (string) $account->exchange_id : 'all',
                    'starting_balance' => (string) $totalStarting,
                    'current_equity' => (string) $totalCurrent,
                    'peak_equity' => (string) $totalPeak,
                    'realized_pnl' => (string) $totalRealized,
                    'current_drawdown_percent' => (string) ($account->current_drawdown_percent ?? '0.00'),
                    'maximum_drawdown_percent' => (string) ($account->maximum_drawdown_percent ?? '0.00'),
                ],
                'lifecycle_economics' => array_merge($lifecycleEconomics, [
                    'breakdown_by_exchange' => $breakdownByExchange,
                ]),
                'lifecycle_economics' => [
                    'scope' => 'FULL_LIFECYCLE',
                    'as_of' => $asOf->toIso8601String(),
                    'total_positions' => $lifecycleEconomics['total_positions'],
                    'gross_pnl' => (string) $lifecycleEconomics['gross_pnl'],
                    'fees' => (string) $lifecycleEconomics['fees'],
                    'signed_funding' => (string) $lifecycleEconomics['signed_funding'],
                    'net_pnl' => (string) $lifecycleEconomics['net_pnl'],
                    'profit_factor' => $lifecycleEconomics['profit_factor'],
                    'win_rate' => $lifecycleEconomics['win_rate'],
                    'counts' => $lifecycleEconomics['counts'],
                    'breakdown_by_symbol' => $lifecycleEconomics['breakdown_by_symbol'],
                    'breakdown_by_exit_reason' => $lifecycleEconomics['breakdown_by_exit_reason'],
                ],
                'reconciliation' => $latestReconciliation !== null ? [
                    'status' => $latestReconciliation->status,
                    'reconciled_at' => $latestReconciliation->created_at?->toIso8601String(),
                    'discrepancy_count' => $latestReconciliation->discrepancy_count,
                    'discrepancies' => $latestReconciliation->findings['discrepancies'] ?? null,
                ] : [
                    'status' => 'NO_RECORDED_RUN',
                    'reconciled_at' => null,
                    'discrepancy_count' => null,
                    'discrepancies' => null,
                ],
                'recent_executions' => $executions,
            ],
        ]);
    }

    /**
     * Machine-readable metric registry catalog and accounting scope rules.
     * Purely read-only.
     */
    public function metricRegistry(): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'data' => [
                'catalog' => $this->metricRegistry->getCatalog(),
            ],
        ]);
    }

    /**
     * P-1: Slippage attribution summary and decomposition.
     * Purely read-only; zero database writes.
     */
    public function slippageAttribution(Request $request): JsonResponse
    {
        $scope = (string) $request->query('accounting_scope', 'FULL_LIFECYCLE');
        $data = $this->slippageAttribution->getLatestSummary($scope);

        return response()->json([
            'status' => 'SUCCESS',
            'data' => $data,
        ]);
    }

    /**
     * P-1: Position-level slippage attribution trace and individual fills.
     * Purely read-only; zero database writes.
     */
    public function positionSlippageAttribution(string $positionId, Request $request): JsonResponse
    {
        $position = Str::isUuid($positionId) ? VirtualPositionRecord::query()->find($positionId) : null;
        if ($position === null) {
            return response()->json([
                'error' => [
                    'code' => 'POSITION_NOT_FOUND',
                    'message' => "Position [{$positionId}] was not found.",
                ],
            ], 404);
        }

        $request->validate(['generation_id' => 'nullable|uuid']);
        $generationId = $request->query('generation_id');
        $accountId = $request->query('account_id') ?? $request->query('paper_account_id');
        $exchange = $request->query('exchange') ?? $request->query('exchange_id');

        if ($accountId !== null && $position->paper_account_id !== $accountId) {
            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'position_id' => $positionId,
                    'position' => null,
                    'slippage_attribution' => null,
                    'fills' => [],
                ],
            ]);
        }

        if ($exchange !== null) {
            $accountExchange = DB::table('paper_accounts')->where('id', $position->paper_account_id)->value('exchange_id');
            if ($accountExchange !== $exchange) {
                return response()->json([
                    'status' => 'SUCCESS',
                    'data' => [
                        'position_id' => $positionId,
                        'position' => null,
                        'slippage_attribution' => null,
                        'fills' => [],
                    ],
                ]);
            }
        }

        $positionAttribution = SlippageAttributionRecord::query()
            ->where('position_id', $positionId)
            ->when($accountId, fn ($query) => $query->where('paper_account_id', $accountId))
            ->when($generationId, fn ($query) => $query->where('generation_id', $generationId))
            ->latest('created_at')->latest('id')
            ->first();

        $fills = SlippageAttributionFillRecord::query()
            ->where('position_id', $positionId)
            ->when($accountId, fn ($query) => $query->where('paper_account_id', $accountId))
            ->where('generation_id', $positionAttribution?->generation_id)
            ->when($positionAttribution === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('market_event_at')
            ->get();

        return response()->json([
            'status' => 'SUCCESS',
            'data' => [
                'position_id' => $positionId,
                'position' => [
                    'symbol' => $position->symbol,
                    'side' => $position->side,
                    'status' => $position->status,
                    'entry_price' => (string) $position->entry_price,
                    'gross_pnl' => (string) $position->gross_pnl,
                    'net_pnl' => (string) $position->net_pnl,
                    'fees' => (string) $position->fees,
                    'funding' => (string) $position->funding,
                ],
                'slippage_attribution' => $positionAttribution,
                'fills' => $fills,
            ],
        ]);
    }

    /**
     * P-1: Trigger idempotent research generation.
     * Authenticated via research.token.
     * Enforces request_payload_hash and rejects conflicts with HTTP 409.
     */
    public function storeGeneration(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => 'required|string|max:160',
            'package_code' => 'required|string|in:P-1',
            'accounting_scope' => 'nullable|string|in:FULL_LIFECYCLE,WINDOW_FLOW',
            'as_of' => 'nullable|date',
            'window_start' => 'nullable|date',
            'window_end' => 'nullable|date',
        ]);

        try {
            $generation = $this->slippageAttribution->generate($payload);

            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'generation' => [
                        'id' => $generation->id,
                        'package_code' => $generation->package_code,
                        'accounting_scope' => $generation->accounting_scope,
                        'status' => $generation->status,
                        'idempotency_key' => $generation->idempotency_key,
                        'output_hash' => $generation->output_hash,
                        'row_counts' => $generation->row_counts,
                        'metadata' => $generation->metadata,
                        'created_at' => $generation->created_at?->toIso8601String(),
                    ],
                ],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A generation with this idempotency key has conflicting inputs.',
                ],
            ], 409);
        }
    }

    /**
     * P-2: Risk-Adjusted Metrics & Capital Efficiency (read-only latest generation).
     * Purely read-only; zero database writes.
     */
    public function riskCapitalMetrics(Request $request): JsonResponse
    {
        $scope = (string) $request->query('accounting_scope', 'FULL_LIFECYCLE');
        $data = $this->riskCapitalMetrics->getLatestMetrics($scope);

        return response()->json([
            'status' => $data['status'] === 'SUCCEEDED' ? 'SUCCESS' : $data['status'],
            'data' => $data,
        ]);
    }

    /**
     * P-5: Concentration, overlapping positions and dependence (read-only latest generation).
     * Purely read-only; zero database writes.
     */
    public function concentrationDependence(Request $request): JsonResponse
    {
        $scope = (string) $request->query('accounting_scope', 'FULL_LIFECYCLE');
        $data = $this->dependenceConcentration->getLatestConcentration($scope);

        return response()->json([
            'status' => $data['status'] === 'SUCCEEDED' ? 'SUCCESS' : $data['status'],
            'data' => $data,
        ]);
    }

    /**
     * P-4: Cost structure, MFE/MAE and the pre-entry expected-MFE estimator
     * (read-only latest generation). Purely read-only; zero database writes.
     */
    public function costMfeMae(Request $request): JsonResponse
    {
        $scope = (string) $request->query('accounting_scope', 'FULL_LIFECYCLE');
        $data = $this->costMfeMae->getLatestCostMfeMae($scope);

        return response()->json([
            'status' => $data['status'] === 'SUCCEEDED' ? 'SUCCESS' : $data['status'],
            'data' => $data,
        ]);
    }

    /**
     * P-4: Trigger idempotent P-4 research generation.
     * Authenticated via research.token. Enforces request_payload_hash and rejects conflicts with HTTP 409.
     */
    public function storeP4Generation(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => 'required|string|max:160',
            'accounting_scope' => 'nullable|string|in:FULL_LIFECYCLE,PIT_EVENT_TIME,PIT_KNOWN_AT,WINDOW_FLOW',
            'as_of' => 'nullable|date',
            'candle_interval' => 'nullable|string|max:8',
        ]);

        try {
            $generation = $this->costMfeMae->generate($payload);

            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'generation' => [
                        'id' => $generation->id,
                        'package_code' => $generation->package_code,
                        'accounting_scope' => $generation->accounting_scope,
                        'status' => $generation->status,
                        'idempotency_key' => $generation->idempotency_key,
                        'output_hash' => $generation->output_hash,
                        'row_counts' => $generation->row_counts,
                        'metadata' => $generation->metadata,
                        'created_at' => $generation->created_at?->toIso8601String(),
                    ],
                ],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A generation with this idempotency key has conflicting inputs.',
                ],
            ], 409);
        }
    }

    /**
     * A0: Read-only view of the immutable research candidate registry.
     */
    public function autonomyRegistry(): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'data' => $this->autonomy->registry(),
        ]);
    }

    /**
     * A1: Read-only view of the recorded challenger experiment transitions.
     */
    public function autonomyExperiments(Request $request): JsonResponse
    {
        $experimentId = $request->query('experiment_id');

        return response()->json([
            'status' => 'SUCCESS',
            'data' => $this->autonomy->experiments(is_string($experimentId) ? $experimentId : null),
        ]);
    }

    /**
     * A1: Record one challenger experiment state transition (research only).
     */
    public function storeAutonomyExperiment(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'hypothesis_id' => 'required|string|max:64',
            'experiment_id' => 'nullable|string|max:64',
            'cohort_id' => 'nullable|string|max:64',
            'from_state' => 'required|string|max:48',
            'to_state' => 'required|string|max:48',
            'reason' => 'nullable|string|max:160',
            'preregistration_hash' => 'nullable|string|max:64',
            'rules_hash' => 'nullable|string|max:64',
            'data_check_done' => 'nullable|boolean',
            'oos_viewed' => 'nullable|boolean',
            'promotion_verdict' => 'nullable|string|max:32',
            'evidence' => 'nullable|array',
        ]);

        $result = $this->autonomy->recordExperimentTransition($payload);

        return response()->json([
            'status' => $result['status'],
            'data' => $result,
        ], $result['status'] === 'RECORDED' ? 201 : 409);
    }

    /**
     * A2: Evaluate the paper-only promotion verdict (research only, never applied).
     */
    public function storeAutonomyPromotion(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'experiment_id' => 'nullable|string|max:64',
            'hypothesis_id' => 'nullable|string|max:64',
            'evidence' => 'nullable|array',
            'persist' => 'nullable|boolean',
        ]);

        $result = $this->autonomy->evaluatePromotion($payload);

        return response()->json([
            'status' => 'SUCCESS',
            'data' => $result,
        ], 201);
    }

    /**
     * A2: Read-only view of the promotion control events and the kill switch.
     */
    public function autonomyPromotionControls(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'data' => $this->autonomy->promotionControls(),
        ]);
    }

    /**
     * A2: Activate the paper-only kill switch (research only, append-only).
     */
    public function storeAutonomyKillSwitch(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'action' => 'required|string|in:activate,release',
            'reason' => 'nullable|string|max:160',
            'actor' => 'nullable|string|max:128',
        ]);

        $result = $payload['action'] === 'activate'
            ? $this->autonomy->activateKillSwitch((string) ($payload['reason'] ?? ''), $payload['actor'] ?? null)
            : $this->autonomy->releaseKillSwitch((string) ($payload['reason'] ?? ''), $payload['actor'] ?? null);

        return response()->json([
            'status' => $result['status'],
            'data' => $result,
        ], $result['status'] === 'RELEASE_REFUSED' ? 409 : 201);
    }

    /**
     * A2: Roll a paper challenger back (research only, append-only).
     */
    public function storeAutonomyRollback(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'promotion_decision_id' => 'required|string|max:64',
            'reason' => 'nullable|string|max:160',
            'actor' => 'nullable|string|max:128',
            'monitoring' => 'nullable|array',
        ]);

        $result = $this->autonomy->rollbackPromotion($payload);

        $statusCode = 201;
        if ($result['status'] === 'ROLLBACK_REFUSED') {
            $statusCode = ($result['reason'] ?? '') === 'PROMOTION_DECISION_NOT_FOUND' ? 404 : 409;
        }

        return response()->json([
            'status' => $result['status'],
            'data' => $result,
        ], $statusCode);
    }

    /**
     * P-6: Read-only view of the latest research decision record.
     */
    public function decisionGate(Request $request): JsonResponse
    {
        $scope = (string) $request->query('accounting_scope', 'FULL_LIFECYCLE');
        $data = $this->decisionEvidence->getLatestDecision($scope);

        return response()->json([
            'status' => $data['status'] === 'OK' ? 'SUCCESS' : $data['status'],
            'data' => $data,
        ]);
    }

    /**
     * P-6: Evaluate the evidence and append one research decision record.
     * Authenticated via research.token. Never changes a strategy and never places an order.
     */
    public function storeP6Generation(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => 'required|string|max:160',
            'claim_type' => 'required|string|in:PROFITABILITY_CLAIM,IMPROVEMENT_CLAIM,CONCENTRATION_RISK_CLAIM,COST_STRUCTURE_CLAIM,RISK_REJECTED_OPPORTUNITY_CLAIM',
            'accounting_scope' => 'nullable|string|in:FULL_LIFECYCLE,PIT_EVENT_TIME,PIT_KNOWN_AT,WINDOW_FLOW',
            'as_of' => 'nullable|date',
            'preregistration_hash' => 'nullable|string|max:128',
            'evidence_bundle_hash' => 'nullable|string|max:128',
            'test_outcome' => 'nullable|string|in:POSITIVE,NEGATIVE,REJECT',
            'previous_state' => 'nullable|string|max:64',
            'hypothesis' => 'nullable|string|max:2000',
            'actor_source' => 'nullable|string|max:128',
            'owner_authorization' => 'nullable|array',
            'owner_authorization.actor' => 'nullable|string|max:128',
            'owner_authorization.authorization_hash' => 'nullable|string|max:128',
            'hypotheses' => 'nullable|array',
        ]);

        try {
            $result = $this->decisionEvidence->evaluate($payload);

            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'generation_id' => $result['generation_id'],
                    'decision' => $result['decision'],
                    'outcome' => $result['outcome'] ?? null,
                    'idempotent_replay' => $result['idempotent_replay'],
                ],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A generation with this idempotency key has conflicting inputs.',
                ],
            ], 409);
        }
    }

    /**
     * P-5: Trigger idempotent P-5 research generation.
     * Authenticated via research.token. Enforces request_payload_hash and rejects conflicts with HTTP 409.
     */
    public function storeP5Generation(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => 'required|string|max:160',
            'accounting_scope' => 'nullable|string|in:FULL_LIFECYCLE,PIT_EVENT_TIME,PIT_KNOWN_AT,WINDOW_FLOW',
            'as_of' => 'nullable|date',
            'window_start' => 'nullable|date',
            'window_end' => 'nullable|date',
            'symbol' => 'nullable|string|max:32',
            'max_carry_forward_age_seconds' => 'nullable|integer|min:60|max:604800',
            'alignment_interval_seconds' => 'nullable|integer|min:60|max:86400',
        ]);

        try {
            $generation = $this->dependenceConcentration->generate($payload);

            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'generation' => [
                        'id' => $generation->id,
                        'package_code' => $generation->package_code,
                        'accounting_scope' => $generation->accounting_scope,
                        'status' => $generation->status,
                        'idempotency_key' => $generation->idempotency_key,
                        'output_hash' => $generation->output_hash,
                        'row_counts' => $generation->row_counts,
                        'metadata' => $generation->metadata,
                        'created_at' => $generation->created_at?->toIso8601String(),
                    ],
                ],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A generation with this idempotency key has conflicting inputs.',
                ],
            ], 409);
        }
    }

    /**
     * P-2: Trigger idempotent P-2 research generation.
     * Authenticated via research.token. Enforces request_payload_hash and rejects conflicts with HTTP 409.
     */
    public function storeP2Generation(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => 'required|string|max:160',
            'accounting_scope' => 'nullable|string|in:FULL_LIFECYCLE,PIT_EVENT_TIME,PIT_KNOWN_AT,WINDOW_FLOW',
            'as_of' => 'nullable|date',
            'window_start' => 'nullable|date',
            'window_end' => 'nullable|date',
            'symbol' => 'nullable|string|max:32',
            'max_carry_forward_age_seconds' => 'nullable|integer|min:60|max:604800',
        ]);

        try {
            $generation = $this->riskCapitalMetrics->generate($payload);

            return response()->json([
                'status' => 'SUCCESS',
                'data' => [
                    'generation' => [
                        'id' => $generation->id,
                        'package_code' => $generation->package_code,
                        'accounting_scope' => $generation->accounting_scope,
                        'status' => $generation->status,
                        'idempotency_key' => $generation->idempotency_key,
                        'output_hash' => $generation->output_hash,
                        'row_counts' => $generation->row_counts,
                        'metadata' => $generation->metadata,
                        'created_at' => $generation->created_at?->toIso8601String(),
                    ],
                ],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A generation with this idempotency key has conflicting inputs.',
                ],
            ], 409);
        }
    }

    /**
     * @return Collection<int, PaperAccountRecord>
     */
    private function resolveExchange(Request $request): string
    {
        $exchange = $request->header('X-Exchange') ?: $request->string('exchange')->toString();
        if ($exchange === '' || $exchange === 'bybit') {
            return 'bybit';
        }
        if ($exchange === 'all') {
            return 'all';
        }
        $registered = app(ExchangeRegistry::class)->registeredIds();

        return in_array($exchange, $registered, true) ? $exchange : 'bybit';
    }

    private function resolveSymbol(Request $request): ?string
    {
        $symbol = $request->header('X-Symbol') ?: $request->string('symbol')->toString();
        $instrument = $request->header('X-Instrument') ?: $request->string('instrument')->toString();

        if ($symbol !== '' && $symbol !== 'all' && $symbol !== 'ALL') {
            $sym = strtoupper($symbol);
            if (in_array($sym, config('ui.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
                return $sym;
            }
        }

        if ($instrument === 'BTC' || strtoupper($instrument) === 'BTCUSDT') {
            return 'BTCUSDT';
        }
        if ($instrument === 'ETH' || strtoupper($instrument) === 'ETHUSDT') {
            return 'ETHUSDT';
        }

        return null;
    }

    private function getPrimaryAccountsReadOnly(?string $exchange = 'bybit'): Collection
    {
        // Read endpoints must never provision or repair accounts. Missing primary
        // accounts are surfaced through an empty/incomplete response; provisioning
        // belongs to migrations, scheduled jobs or explicit mutation endpoints.
        $query = PaperAccountRecord::query()
            ->where('is_primary', true)
            ->whereIn('primary_strategy_id', $this->primaryStrategies())
            ->orderBy('exchange_id')
            ->orderBy('primary_strategy_id');

        if ($exchange !== null && $exchange !== '' && $exchange !== 'all') {
            $query->where('exchange_id', $exchange);
        }

        return $query->get();
    }
}
