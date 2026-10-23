<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /** @return list<string> */
    private function primaryStrategies(): array
    {
        return array_values((array) config('paper.primary_strategies', ['baseline']));
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
        $accounts = $this->getPrimaryAccountsReadOnly();

        $primaryAccountIds = $accounts->pluck('id')->filter()->all();

        // 1. Point-in-time balance totals
        $totalStartingBalance = BigDecimal::zero();
        $totalCurrentEquity = BigDecimal::zero();
        $totalPeakEquity = BigDecimal::zero();
        $totalRealizedPnl = BigDecimal::zero();

        $accountSummaries = [];

        foreach ($accounts as $account) {
            $starting = BigDecimal::of($account->starting_balance ?? '10000');
            $equity = BigDecimal::of($account->current_equity ?? (string) $starting);
            $peak = BigDecimal::of($account->peak_equity ?? (string) $starting);
            $realized = BigDecimal::of($account->realized_pnl ?? '0');

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
                'starting_balance' => (string) $starting,
                'current_equity' => (string) $equity,
                'peak_equity' => (string) $peak,
                'realized_pnl' => (string) $realized,
                'current_drawdown_percent' => (string) ($account->current_drawdown_percent ?? '0.00'),
                'maximum_drawdown_percent' => (string) ($account->maximum_drawdown_percent ?? '0.00'),
                'reconciliation' => $latestReconciliation !== null ? [
                    'status' => $latestReconciliation->status,
                    'reconciled_at' => $latestReconciliation->created_at?->toIso8601String(),
                    'discrepancy_count' => count($latestReconciliation->discrepancies ?? []),
                ] : [
                    'status' => 'NO_RECORDED_RUN',
                    'reconciled_at' => null,
                    'discrepancy_count' => 0,
                ],
            ];
        }

        // 2. Full-lifecycle population economics (strictly isolated from window totals)
        $allPositions = VirtualPositionRecord::query()
            ->whereIn('paper_account_id', $primaryAccountIds)
            ->get();

        $lifecycleEconomics = $this->quantPortfolioMetrics->computeLifecyclePopulationEconomics($allPositions, $asOf);

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

        return response()->json([
            'status' => 'SUCCESS',
            'as_of' => $asOf->toIso8601String(),
            'accounting_scopes' => [
                'point_in_time' => [
                    'scope' => 'POINT_IN_TIME',
                    'as_of' => $asOf->toIso8601String(),
                    'total_starting_balance' => (string) $totalStartingBalance,
                    'total_current_equity' => (string) $totalCurrentEquity,
                    'total_peak_equity' => (string) $totalPeakEquity,
                    'total_realized_pnl' => (string) $totalRealizedPnl,
                    'active_accounts_count' => count($accounts),
                    'accounts' => $accountSummaries,
                ],
                'full_lifecycle_economics' => [
                    'scope' => 'FULL_LIFECYCLE',
                    'as_of' => $asOf->toIso8601String(),
                    'total_positions' => $lifecycleEconomics['total_positions'],
                    'gross_pnl' => (string) $lifecycleEconomics['gross_pnl'],
                    'fees' => (string) $lifecycleEconomics['fees'],
                    'signed_funding' => (string) $lifecycleEconomics['signed_funding'],
                    'net_pnl' => (string) $lifecycleEconomics['net_pnl'],
                    'accounting_identity_holds' => abs(
                        ((float) $lifecycleEconomics['gross_pnl'] - (float) $lifecycleEconomics['fees'] + (float) $lifecycleEconomics['signed_funding']) - (float) $lifecycleEconomics['net_pnl']
                    ) < 1e-8,
                    'profit_factor' => $lifecycleEconomics['profit_factor'],
                    'win_rate' => $lifecycleEconomics['win_rate'],
                    'counts' => $lifecycleEconomics['counts'],
                    'r_metrics' => $lifecycleEconomics['r_metrics'],
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
        ]);
    }

    /**
     * List of the primary strategies with separated accounting scopes.
     * Purely read-only.
     */
    public function strategies(Request $request): JsonResponse
    {
        $accounts = $this->getPrimaryAccountsReadOnly();
        $asOf = CarbonImmutable::now('UTC');

        $result = [];

        foreach ($accounts as $account) {
            $strategyId = $account->primary_strategy_id ?? $account->strategy_id;
            $positions = VirtualPositionRecord::query()
                ->where('paper_account_id', $account->id)
                ->get();

            $economics = $this->quantPortfolioMetrics->computeLifecyclePopulationEconomics($positions, $asOf);

            $result[] = [
                'strategy_id' => $strategyId,
                'account_id' => $account->id,
                'name' => $account->name,
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
                ],
            ];
        }

        return response()->json([
            'status' => 'SUCCESS',
            'as_of' => $asOf->toIso8601String(),
            'strategies' => $result,
        ]);
    }

    /**
     * Detailed economic drill-down for a single primary strategy.
     * Purely read-only.
     */
    public function strategyDetail(string $strategyId, Request $request): JsonResponse
    {
        $account = PaperAccountRecord::query()
            ->where(function ($query) use ($strategyId): void {
                $query->where('primary_strategy_id', $strategyId)
                    ->orWhere('strategy_id', $strategyId);
            })
            ->first();

        if ($account === null) {
            return response()->json([
                'error' => [
                    'code' => 'STRATEGY_NOT_FOUND',
                    'message' => "Primary paper strategy [{$strategyId}] was not found.",
                ],
            ], 404);
        }

        $asOf = CarbonImmutable::now('UTC');
        $positions = VirtualPositionRecord::query()
            ->where('paper_account_id', $account->id)
            ->orderByDesc('opened_at')
            ->get();

        $lifecycleEconomics = $this->quantPortfolioMetrics->computeLifecyclePopulationEconomics($positions, $asOf);

        // Fetch recent executions
        $executions = VirtualExecutionRecord::query()
            ->whereIn('position_id', $positions->pluck('id'))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'position_id', 'type', 'quantity', 'price', 'slippage', 'commission', 'created_at']);

        // Latest reconciliation
        $latestReconciliation = ReconciliationRunRecord::query()
            ->where('paper_account_id', $account->id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'status' => 'SUCCESS',
            'as_of' => $asOf->toIso8601String(),
            'strategy_id' => $strategyId,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'mode' => $account->mode,
                'is_primary' => (bool) $account->is_primary,
                'starting_balance' => (string) $account->starting_balance,
                'current_equity' => (string) $account->current_equity,
                'peak_equity' => (string) $account->peak_equity,
                'realized_pnl' => (string) $account->realized_pnl,
                'current_drawdown_percent' => (string) ($account->current_drawdown_percent ?? '0.00'),
                'maximum_drawdown_percent' => (string) ($account->maximum_drawdown_percent ?? '0.00'),
            ],
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
                'discrepancies' => $latestReconciliation->discrepancies ?? [],
            ] : [
                'status' => 'NO_RECORDED_RUN',
                'reconciled_at' => null,
                'discrepancies' => [],
            ],
            'recent_executions' => $executions,
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
            'catalog' => $this->metricRegistry->getCatalog(),
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

        $positionAttribution = SlippageAttributionRecord::query()
            ->where('position_id', $positionId)
            ->latest('created_at')
            ->first();

        $fills = SlippageAttributionFillRecord::query()
            ->where('position_id', $positionId)
            ->orderBy('market_event_at')
            ->get();

        return response()->json([
            'status' => 'SUCCESS',
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
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => $e->getMessage(),
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
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => $e->getMessage(),
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
                'generation_id' => $result['generation_id'],
                'decision' => $result['decision'],
                'outcome' => $result['outcome'] ?? null,
                'idempotent_replay' => $result['idempotent_replay'],
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => $e->getMessage(),
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
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => $e->getMessage(),
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
            ], 201);
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * @return Collection<int, PaperAccountRecord>
     */
    private function getPrimaryAccountsReadOnly(): Collection
    {
        // Read endpoints must never provision or repair accounts. Missing primary
        // accounts are surfaced through an empty/incomplete response; provisioning
        // belongs to migrations, scheduled jobs or explicit mutation endpoints.
        return PaperAccountRecord::query()
            ->where('is_primary', true)
            ->whereIn('primary_strategy_id', $this->primaryStrategies())
            ->orderBy('primary_strategy_id')
            ->get();
    }
}
