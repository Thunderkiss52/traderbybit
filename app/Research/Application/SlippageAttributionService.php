<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Domain\SlippageAttribution\SlippageAllocation;
use App\Research\Domain\SlippageAttributionFillRecord;
use App\Research\Domain\SlippageAttributionRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

final class SlippageAttributionService
{
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly PaperAccountService $accountService,
    ) {}

    /**
     * Compute and store an immutable research generation of slippage attribution.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws IdempotencyConflictException
     */
    public function generate(array $parameters = []): ResearchGenerationRecord
    {
        $idempotencyKey = (string) ($parameters['idempotency_key'] ?? Uuid::uuid4()->toString());
        $accountingScope = (string) ($parameters['accounting_scope'] ?? 'FULL_LIFECYCLE');
        $asOf = isset($parameters['as_of'])
            ? CarbonImmutable::parse($parameters['as_of'], 'UTC')
            : CarbonImmutable::now('UTC');

        $normalizedParams = [
            'package_code' => 'P-1',
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf->toIso8601String(),
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
        $payloadHash = hash('sha256', json_encode($normalizedParams, JSON_THROW_ON_ERROR));

        // Idempotency check
        $existing = ResearchGenerationRecord::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->request_payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    "Idempotency conflict: Key [{$idempotencyKey}] was previously used with a different request payload."
                );
            }

            return $existing;
        }

        $generationId = Uuid::uuid4()->toString();
        $generation = ResearchGenerationRecord::query()->create([
            'id' => $generationId,
            'package_code' => 'P-1',
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'status' => 'RUNNING',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => $payloadHash,
            'versioned_config' => [
                'algorithm_version' => self::ALGORITHM_VERSION,
                'paper_slippage_rate' => config('paper.slippage_rate', '0.0002'),
                'fee_schedule_version' => config('paper.fee_schedule_version', '1.0.0'),
            ],
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 300,
        ]);

        try {
            // Retrieve the configured independent primary accounts.
            $accounts = $this->accountService->allPrimaryAccounts();
            $accountIds = $accounts->pluck('id')->filter()->all();

            // Query positions based on scope
            $positionsQuery = VirtualPositionRecord::query()->whereIn('paper_account_id', $accountIds);
            if ($accountingScope === 'WINDOW_FLOW' && isset($parameters['window_start'], $parameters['window_end'])) {
                $positionsQuery->where('opened_at', '>=', $parameters['window_start'])
                    ->where('opened_at', '<', $parameters['window_end']);
            }
            $positions = $positionsQuery->get();
            $positionIds = $positions->pluck('id')->all();

            // Query executions
            $executions = VirtualExecutionRecord::query()
                ->whereIn('position_id', $positionIds)
                ->orderBy('market_event_at')
                ->get();

            $fillsData = [];
            $positionAggregates = [];
            $positionsMap = $positions->keyBy('id');

            $totalSlippageDrag = BigDecimal::zero();
            $totalReferenceNotional = BigDecimal::zero();

            foreach ($executions as $exec) {
                $position = $positionsMap->get($exec->position_id);
                if ($position === null) {
                    continue;
                }

                $side = strtoupper((string) $exec->side); // BUY or SELL execution side
                $fillPrice = BigDecimal::of((string) $exec->price);
                $slippageMagnitude = BigDecimal::of((string) $exec->slippage);
                $quantity = BigDecimal::of((string) $exec->quantity);
                $multiplier = BigDecimal::of('1.0');

                // Determine reference price and signed adverse drag
                // In Bybit linear perp simulator:
                // For BUY fill: adverse slippage raised price -> ref = fill - slip
                // For SELL fill: adverse slippage lowered price -> ref = fill + slip
                if ($side === 'BUY') {
                    $refPrice = $fillPrice->minus($slippageMagnitude);
                    $dragUsdt = $fillPrice->minus($refPrice)->multipliedBy($quantity)->multipliedBy($multiplier);
                } else {
                    $refPrice = $fillPrice->plus($slippageMagnitude);
                    $dragUsdt = $refPrice->minus($fillPrice)->multipliedBy($quantity)->multipliedBy($multiplier);
                }

                $refNotional = $refPrice->multipliedBy($quantity)->multipliedBy($multiplier);
                $bps = $refNotional->isZero()
                    ? null
                    : $dragUsdt->dividedBy($refNotional, 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

                $execType = strtoupper((string) $exec->type);
                $fillRole = match ($execType) {
                    'ENTRY' => 'ENTRY',
                    'TAKE_PROFIT_1' => 'PARTIAL_EXIT',
                    default => 'FINAL_EXIT',
                };

                $ambiguityReason = null;
                if ($exec->ambiguous_execution) {
                    $ambiguityReason = 'IN_BAR_BOTH_TP_SL_HIT';
                }

                $fillId = Uuid::uuid4()->toString();
                $fillsData[] = [
                    'id' => $fillId,
                    'generation_id' => $generationId,
                    'execution_id' => $exec->id,
                    'position_id' => $exec->position_id,
                    'paper_account_id' => $position->paper_account_id,
                    'strategy_id' => $position->strategy_version ?? 'unknown',
                    'symbol' => $position->symbol,
                    'execution_side' => $side,
                    'fill_role' => $fillRole,
                    'quantity' => (string) $quantity,
                    'contract_multiplier' => (string) $multiplier,
                    'reference_price' => (string) $refPrice,
                    'fill_price' => (string) $fillPrice,
                    'reference_notional' => (string) $refNotional,
                    'slippage_drag_usdt' => (string) $dragUsdt,
                    'slippage_bps' => $bps !== null ? (string) $bps : null,
                    'price_source' => $exec->price_source ?? 'historical_quote',
                    'provenance_status' => 'RECOMPUTED_FROM_SOURCE',
                    'ambiguous_execution' => (bool) $exec->ambiguous_execution,
                    'ambiguity_reason' => $ambiguityReason,
                    'market_event_at' => $exec->market_event_at ?? $exec->created_at,
                    'created_at' => CarbonImmutable::now('UTC'),
                    'updated_at' => CarbonImmutable::now('UTC'),
                ];

                // Aggregate by position
                if (! isset($positionAggregates[$exec->position_id])) {
                    $positionAggregates[$exec->position_id] = [
                        'position_id' => $exec->position_id,
                        'paper_account_id' => $position->paper_account_id,
                        'strategy_id' => $position->strategy_version ?? 'unknown',
                        'symbol' => $position->symbol,
                        'position_side' => $position->side,
                        'entry_slippage_usdt' => BigDecimal::zero(),
                        'partial_exit_slippage_usdt' => BigDecimal::zero(),
                        'final_exit_slippage_usdt' => BigDecimal::zero(),
                        'total_slippage_drag_usdt' => BigDecimal::zero(),
                        'total_reference_notional' => BigDecimal::zero(),
                        'fill_count' => 0,
                        'ambiguous_fill_count' => 0,
                    ];
                }

                if ($fillRole === 'ENTRY') {
                    $positionAggregates[$exec->position_id]['entry_slippage_usdt'] = $positionAggregates[$exec->position_id]['entry_slippage_usdt']->plus($dragUsdt);
                } elseif ($fillRole === 'PARTIAL_EXIT') {
                    $positionAggregates[$exec->position_id]['partial_exit_slippage_usdt'] = $positionAggregates[$exec->position_id]['partial_exit_slippage_usdt']->plus($dragUsdt);
                } else {
                    $positionAggregates[$exec->position_id]['final_exit_slippage_usdt'] = $positionAggregates[$exec->position_id]['final_exit_slippage_usdt']->plus($dragUsdt);
                }

                $positionAggregates[$exec->position_id]['total_slippage_drag_usdt'] = $positionAggregates[$exec->position_id]['total_slippage_drag_usdt']->plus($dragUsdt);
                $positionAggregates[$exec->position_id]['total_reference_notional'] = $positionAggregates[$exec->position_id]['total_reference_notional']->plus($refNotional);
                $positionAggregates[$exec->position_id]['fill_count']++;
                if ($exec->ambiguous_execution) {
                    $positionAggregates[$exec->position_id]['ambiguous_fill_count']++;
                }

                $totalSlippageDrag = $totalSlippageDrag->plus($dragUsdt);
                $totalReferenceNotional = $totalReferenceNotional->plus($refNotional);
            }

            // Prepare position aggregate records
            $positionRecords = [];
            foreach ($positionAggregates as $posId => $agg) {
                $weightedBps = $agg['total_reference_notional']->isZero()
                    ? null
                    : $agg['total_slippage_drag_usdt']->dividedBy($agg['total_reference_notional'], 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

                $positionRecords[] = [
                    'id' => Uuid::uuid4()->toString(),
                    'generation_id' => $generationId,
                    'position_id' => $posId,
                    'paper_account_id' => $agg['paper_account_id'],
                    'strategy_id' => $agg['strategy_id'],
                    'symbol' => $agg['symbol'],
                    'position_side' => $agg['position_side'],
                    'entry_slippage_usdt' => (string) $agg['entry_slippage_usdt'],
                    'partial_exit_slippage_usdt' => (string) $agg['partial_exit_slippage_usdt'],
                    'final_exit_slippage_usdt' => (string) $agg['final_exit_slippage_usdt'],
                    'total_slippage_drag_usdt' => (string) $agg['total_slippage_drag_usdt'],
                    'total_reference_notional' => (string) $agg['total_reference_notional'],
                    'weighted_slippage_bps' => $weightedBps !== null ? (string) $weightedBps : null,
                    'fill_count' => $agg['fill_count'],
                    'ambiguous_fill_count' => $agg['ambiguous_fill_count'],
                    'provenance_status' => 'RECOMPUTED_FROM_SOURCE',
                    'algorithm_version' => self::ALGORITHM_VERSION,
                    'created_at' => CarbonImmutable::now('UTC'),
                    'updated_at' => CarbonImmutable::now('UTC'),
                ];
            }

            // Chunked insert
            foreach (array_chunk($fillsData, 200) as $chunk) {
                SlippageAttributionFillRecord::query()->insert($chunk);
            }
            foreach (array_chunk($positionRecords, 200) as $chunk) {
                SlippageAttributionRecord::query()->insert($chunk);
            }

            $overallWeightedBps = $totalReferenceNotional->isZero()
                ? null
                : $totalSlippageDrag->dividedBy($totalReferenceNotional, 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

            $summaryResult = [
                'total_fills' => count($fillsData),
                'total_positions' => count($positionRecords),
                'total_slippage_drag_usdt' => (string) $totalSlippageDrag,
                'total_reference_notional_usdt' => (string) $totalReferenceNotional,
                'weighted_slippage_bps' => $overallWeightedBps !== null ? (string) $overallWeightedBps : null,
            ];

            $outputHash = hash('sha256', json_encode($summaryResult, JSON_THROW_ON_ERROR));

            $generation->update([
                'status' => 'SUCCEEDED',
                'output_hash' => $outputHash,
                'row_counts' => [
                    'fills' => count($fillsData),
                    'positions' => count($positionRecords),
                ],
                'metadata' => [
                    'summary' => $summaryResult,
                ],
                'heartbeat_at' => CarbonImmutable::now('UTC'),
            ]);

            return $generation->refresh();
        } catch (\Throwable $e) {
            $generation->update([
                'status' => 'FAILED',
                'error_message' => 'Research calculation unavailable.',
                'heartbeat_at' => CarbonImmutable::now('UTC'),
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve the latest succeeded generation summary and breakdown.
     * Purely read-only; zero database writes.
     *
     * @return array<string, mixed>
     */
    public function getLatestSummary(string $accountingScope = 'FULL_LIFECYCLE'): array
    {
        $generation = ResearchGenerationRecord::query()
            ->where('package_code', 'P-1')
            ->where('status', 'SUCCEEDED')
            ->where('accounting_scope', $accountingScope)
            ->latest('created_at')
            ->first();

        if ($generation === null) {
            return [
                'status' => 'NO_GENERATION_AVAILABLE',
                'message' => 'No completed P-1 research generation exists yet for scope: '.$accountingScope,
                'provenance' => 'INSUFFICIENT_DATA',
            ];
        }

        $positionAttributions = SlippageAttributionRecord::query()
            ->where('generation_id', $generation->id)
            ->get();

        $fillAttributions = SlippageAttributionFillRecord::query()
            ->where('generation_id', $generation->id)
            ->get();

        $entryDrag = BigDecimal::zero();
        $partialExitDrag = BigDecimal::zero();
        $finalExitDrag = BigDecimal::zero();
        $totalDrag = BigDecimal::zero();
        $totalNotional = BigDecimal::zero();
        $ambiguousFillsCount = 0;

        foreach ($fillAttributions as $fill) {
            $drag = BigDecimal::of($fill->slippage_drag_usdt);
            $notional = BigDecimal::of($fill->reference_notional);

            if ($fill->fill_role === 'ENTRY') {
                $entryDrag = $entryDrag->plus($drag);
            } elseif ($fill->fill_role === 'PARTIAL_EXIT') {
                $partialExitDrag = $partialExitDrag->plus($drag);
            } else {
                $finalExitDrag = $finalExitDrag->plus($drag);
            }

            $totalDrag = $totalDrag->plus($drag);
            $totalNotional = $totalNotional->plus($notional);
            if ($fill->ambiguous_execution) {
                $ambiguousFillsCount++;
            }
        }

        $weightedBps = $totalNotional->isZero()
            ? null
            : $totalDrag->dividedBy($totalNotional, 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

        // Group by strategy
        $byStrategy = [];
        foreach ($positionAttributions->groupBy('strategy_id') as $strategyId => $group) {
            $stratDrag = BigDecimal::zero();
            $stratNotional = BigDecimal::zero();
            foreach ($group as $item) {
                $stratDrag = $stratDrag->plus(BigDecimal::of($item->total_slippage_drag_usdt));
                $stratNotional = $stratNotional->plus(BigDecimal::of($item->total_reference_notional));
            }
            $stratBps = $stratNotional->isZero()
                ? null
                : $stratDrag->dividedBy($stratNotional, 12, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);

            $byStrategy[$strategyId] = [
                'strategy_id' => $strategyId,
                'position_count' => $group->count(),
                'total_slippage_drag_usdt' => (string) $stratDrag,
                'total_reference_notional' => (string) $stratNotional,
                'weighted_slippage_bps' => $stratBps !== null ? (string) $stratBps : null,
            ];
        }

        // Counterfactual zero-slippage check
        // Check if baseline PnL and slippage population reconcile
        $reconciledPositions = VirtualPositionRecord::query()
            ->whereIn('id', $positionAttributions->pluck('position_id'))
            ->get();

        $baselineNetPnl = BigDecimal::zero();

        $posAttrMap = $positionAttributions->keyBy('position_id');

        $allocationRows = [];
        foreach ($reconciledPositions as $pos) {
            $attr = $posAttrMap->get($pos->id);

            if ($attr === null) {
                // A source position without an attribution is not part of the
                // attributed population. Keep it out of the totals; the allocator
                // population check below detects the mismatch.
                continue;
            }

            $netPnl = $this->numericOrNull($pos->net_pnl);
            if ($netPnl !== null) {
                $baselineNetPnl = $baselineNetPnl->plus(BigDecimal::of($netPnl));
            }

            $partialExitDrag = $this->numericOrNull($attr->partial_exit_slippage_usdt);
            $finalExitDrag = $this->numericOrNull($attr->final_exit_slippage_usdt);
            $exitDrag = ($partialExitDrag !== null && $finalExitDrag !== null)
                ? (string) BigDecimal::of($partialExitDrag)->plus(BigDecimal::of($finalExitDrag))
                : '';

            $allocationRows[] = [
                'position_id' => (string) $pos->id,
                'initial_quantity' => $pos->initial_quantity === null ? '' : (string) $pos->initial_quantity,
                'remaining_quantity' => $pos->remaining_quantity === null ? '' : (string) $pos->remaining_quantity,
                'net_pnl' => $netPnl ?? '',
                'entry_slippage_usdt' => $this->numericOrNull($attr->entry_slippage_usdt) ?? '',
                'exit_slippage_usdt' => $exitDrag,
            ];
        }

        $allocation = (new SlippageAllocation)->allocate($allocationRows, count($positionAttributions));

        // Never coerce unavailable allocation values to zero: the counterfactual
        // stays NULL unless the population reconciles, every position is valid,
        // the allocator allows a verified calculation, and the realized PnL scope
        // is established (no unresolved partial close).
        $populationReconciled = (bool) $allocation['population_match']
            && (int) $allocation['invalid_position_count'] === 0;

        $rawFullFillCounterfactual = $populationReconciled
            ? (string) $baselineNetPnl->plus($totalDrag)
            : null;

        $counterfactual = [
            'scope' => $accountingScope,
            'status' => $allocation['status'],
            'provenance' => $allocation['provenance'],
            'baseline_actual_net_pnl' => (string) $baselineNetPnl,
            'baseline_scope' => 'ALL_RECONCILED_POSITIONS',
            'realized_scope_net_pnl' => $allocation['realized_scope_net_pnl'],
            'realized_pnl_scope_status' => $allocation['realized_pnl_scope_status'],
            'realized_pnl_scope_reason' => $allocation['realized_pnl_scope_reason'],
            'raw_total_slippage_drag' => (string) $totalDrag,
            'realized_allocated_slippage_drag' => $allocation['realized_allocated_slippage_drag'],
            'open_unallocated_slippage_drag' => $allocation['open_unallocated_slippage_drag'],
            'attributable_slippage_drag' => $allocation['realized_allocated_slippage_drag'],
            'open_position_net_pnl' => $allocation['open_position_net_pnl'],
            'closed_position_count' => $allocation['closed_position_count'],
            'partial_position_count' => $allocation['partial_position_count'],
            'open_position_count' => $allocation['open_position_count'],
            'invalid_position_count' => $allocation['invalid_position_count'],
            'hypothetical_net_pnl_zero_slippage' => $allocation['hypothetical_net_pnl_zero_slippage'],
            'raw_unallocated_counterfactual_net_pnl' => $rawFullFillCounterfactual,
            'raw_counterfactual_status' => $allocation['raw_counterfactual_status'],
            'reconciliation_note' => $allocation['reconciliation_note'],
            'disclaimer' => 'Hypothetical counterfactual for research calibration only. Commissions and funding held constant. Real-market liquidity friction cannot be eliminated.',
            'double_deduction_prohibited' => true,
        ];

        return [
            'status' => 'SUCCEEDED',
            'generation_id' => $generation->id,
            'accounting_scope' => $generation->accounting_scope,
            'as_of' => $generation->as_of?->toIso8601String(),
            'output_hash' => $generation->output_hash,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'summary' => [
                'total_fills' => count($fillAttributions),
                'total_positions' => count($positionAttributions),
                'ambiguous_fills_count' => $ambiguousFillsCount,
                'entry_slippage_drag_usdt' => (string) $entryDrag,
                'partial_exit_slippage_drag_usdt' => (string) $partialExitDrag,
                'final_exit_slippage_drag_usdt' => (string) $finalExitDrag,
                'total_slippage_drag_usdt' => (string) $totalDrag,
                'total_reference_notional_usdt' => (string) $totalNotional,
                'weighted_slippage_bps' => $weightedBps !== null ? (string) $weightedBps : null,
            ],
            'by_strategy' => $byStrategy,
            'counterfactual' => $counterfactual,
        ];
    }

    /**
     * Return the trimmed decimal string when the value is a valid number,
     * otherwise NULL. NULL is never coerced to zero downstream.
     */
    private function numericOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            BigDecimal::of($raw);

            return $raw;
        } catch (MathException) {
            return null;
        }
    }
}
