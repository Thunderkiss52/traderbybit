<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Domain\CostMfe\CostAttribution;
use App\Research\Domain\CostMfe\MfeMaeCalculator;
use App\Research\Domain\CostMfe\MfeMaeResult;
use App\Research\Domain\CostMfe\PreEntryMfeEstimator;
use App\Research\Domain\CostMfe\PriceCandle;
use App\Research\Domain\ResearchCostAttributionRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Domain\ResearchMfeMaeRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * PROFIT-1 / P-4 — cost structure, MFE/MAE and the pre-entry expected-MFE
 * estimator (§9).
 *
 * The service is read-only with respect to the paper ledger: it reads
 * positions, executions, funding events and closed candles, and writes only to
 * research-owned append-only tables. Every result is bounded by the selected
 * `as_of`, uses closed candles only and never uses a candle or observation that
 * is not already known at the moment of the computation.
 */
final class CostMfeMaeService
{
    public const PACKAGE_CODE = 'P-4';

    public const METRIC_SCHEMA_VERSION = '1.1.0';

    public const ALGORITHM_VERSION = '1.1.0';

    public const DEFAULT_CANDLE_INTERVAL = '5';

    /**
     * Read-only view of the latest completed P-4 generation. Zero writes.
     *
     * @return array<string, mixed>
     */
    public function getLatestCostMfeMae(string $accountingScope = 'FULL_LIFECYCLE'): array
    {
        $generation = ResearchGenerationRecord::query()
            ->where('package_code', self::PACKAGE_CODE)
            ->whereIn('status', ['SUCCEEDED', 'PARTIAL', 'INSUFFICIENT_DATA'])
            ->where('accounting_scope', $accountingScope)
            ->latest('created_at')
            ->first();

        if ($generation === null) {
            return [
                'status' => 'NO_GENERATION_AVAILABLE',
                'message' => 'No completed P-4 research generation exists yet for scope: '.$accountingScope,
                'provenance' => 'INSUFFICIENT_DATA',
            ];
        }

        $records = ResearchMfeMaeRecord::query()
            ->where('generation_id', $generation->id)
            ->orderBy('symbol')
            ->orderBy('holding_start_at')
            ->get()
            ->map(static fn (ResearchMfeMaeRecord $record): array => [
                'position_id' => $record->position_id,
                'paper_account_id' => $record->paper_account_id,
                'symbol' => $record->symbol,
                'side' => $record->side,
                'status' => $record->status,
                'holding_start_at' => $record->holding_start_at?->toIso8601String(),
                'holding_end_at' => $record->holding_end_at?->toIso8601String(),
                'open_at_cutoff' => (bool) $record->open_at_cutoff,
                'candle_count' => (int) $record->candle_count,
                'mfe_price' => $record->mfe_price,
                'mae_price' => $record->mae_price,
                'mfe_usdt' => $record->mfe_usdt,
                'mae_usdt' => $record->mae_usdt,
                'mfe_r' => $record->mfe_r,
                'mae_r' => $record->mae_r,
                'mfe_candle_id' => $record->mfe_candle_id,
                'mae_candle_id' => $record->mae_candle_id,
                'ambiguous_path' => (bool) $record->ambiguous_path,
                'ambiguity_reason' => $record->ambiguity_reason,
                'risk_reason' => $record->risk_reason,
                'source_candle_ids' => $record->source_candle_ids,
                'source_candle_close_times' => $record->source_candle_close_times,
                'limitations' => $record->limitations,
            ])
            ->all();

        $attribution = ResearchCostAttributionRecord::query()
            ->where('generation_id', $generation->id)
            ->orderBy('dimension')
            ->orderBy('group_key')
            ->get()
            ->map(static fn (ResearchCostAttributionRecord $record): array => [
                'dimension' => $record->dimension,
                'group_key' => $record->group_key,
                'metrics' => $record->metrics,
            ])
            ->all();

        return [
            'status' => $generation->status,
            'generation_id' => $generation->id,
            'accounting_scope' => $generation->accounting_scope,
            'as_of' => $generation->as_of?->toIso8601String(),
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'versioned_config' => $generation->versioned_config,
            'row_counts' => $generation->row_counts,
            'pre_entry_mfe' => $generation->metadata['pre_entry'] ?? [],
            'cost_attribution' => $attribution,
            'positions' => $records,
            'created_at' => $generation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws IdempotencyConflictException
     */
    public function generate(array $parameters = []): ResearchGenerationRecord
    {
        $idempotencyKey = (string) ($parameters['idempotency_key'] ?? Uuid::uuid4()->toString());
        $accountingScope = (string) ($parameters['accounting_scope'] ?? 'FULL_LIFECYCLE');
        $candleInterval = (string) ($parameters['candle_interval'] ?? self::DEFAULT_CANDLE_INTERVAL);
        $asOf = isset($parameters['as_of'])
            ? CarbonImmutable::parse((string) $parameters['as_of'], 'UTC')
            : CarbonImmutable::now('UTC');

        $normalizedParams = [
            'package_code' => self::PACKAGE_CODE,
            'accounting_scope' => $accountingScope,
            'candle_interval' => $candleInterval,
            'as_of' => $asOf->toIso8601String(),
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
        $payloadHash = hash('sha256', json_encode($normalizedParams, JSON_THROW_ON_ERROR));

        $existing = ResearchGenerationRecord::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->request_payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    "Idempotency conflict: Key [{$idempotencyKey}] was previously used with a different request payload."
                );
            }

            return $existing;
        }

        $generation = ResearchGenerationRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'package_code' => self::PACKAGE_CODE,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'status' => 'RUNNING',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => $payloadHash,
            'versioned_config' => [
                'algorithm_version' => self::ALGORITHM_VERSION,
                'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
                'candle_interval' => $candleInterval,
                'accounting_scope' => $accountingScope,
            ],
        ]);

        $strategyByAccount = PaperAccountRecord::query()
            ->pluck('strategy_id', 'id')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        $candles = $this->loadCandles($candleInterval);
        $positions = VirtualPositionRecord::query()->orderBy('opened_at')->get();
        $executions = VirtualExecutionRecord::query()->orderBy('market_event_at')->get();
        $funding = PositionFundingEventRecord::query()->orderBy('funding_at')->get();

        $calculator = new MfeMaeCalculator;
        $estimator = new PreEntryMfeEstimator;

        $results = [];
        $positionMeta = [];
        foreach ($positions as $position) {
            $openedAt = $position->opened_at;
            if (! $openedAt instanceof CarbonImmutable) {
                continue;
            }

            $closedAt = $position->closed_at instanceof CarbonImmutable ? $position->closed_at : null;
            $holdingEnd = $closedAt ?? $asOf;
            if (! $holdingEnd->greaterThan($openedAt)) {
                continue;
            }

            $symbol = (string) $position->symbol;
            $result = $calculator->calculate(
                positionId: (string) $position->id,
                accountId: (string) $position->paper_account_id,
                symbol: $symbol,
                side: (string) $position->side,
                entryReference: (string) $position->entry_price,
                quantity: (string) $position->initial_quantity,
                stopLoss: (string) ($position->stop_loss ?? ''),
                takeProfit: (string) ($position->take_profit_1 ?? ''),
                holdingStart: $openedAt,
                holdingEnd: $holdingEnd,
                candles: $candles[$symbol] ?? [],
                asOf: $asOf,
            );

            $results[(string) $position->id] = $result;
            $positionMeta[(string) $position->id] = [
                'position' => $position,
                'opened_at' => $openedAt,
                'closed_at' => $closedAt,
            ];
        }

        $preEntry = [];
        foreach ($positionMeta as $positionId => $meta) {
            $observations = [];
            foreach ($positionMeta as $otherId => $other) {
                if ($otherId === $positionId || $other['closed_at'] === null) {
                    continue;
                }

                if ((string) $other['position']->symbol !== (string) $meta['position']->symbol) {
                    continue;
                }

                $observations[] = [
                    'result' => $results[$otherId],
                    'matured_at' => $other['closed_at'],
                ];
            }

            $preEntry[$positionId] = $estimator->estimate($meta['opened_at'], $observations);
        }

        $attribution = (new CostAttribution)->aggregate(
            $this->executionRows($executions, $positionMeta, $strategyByAccount),
            $this->fundingRows($funding, $positionMeta, $strategyByAccount),
            $this->positionRows($positionMeta, $strategyByAccount),
        );

        $this->persistMfeMae($generation->id, $results, $positionMeta, $asOf);
        $this->persistCostAttribution($generation->id, $attribution);

        $computed = 0;
        $ambiguous = 0;
        foreach ($results as $result) {
            if ($result->status === MfeMaeResult::STATUS_COMPUTED) {
                $computed++;
            }

            if ($result->ambiguousPath) {
                $ambiguous++;
            }
        }

        $preEntryComputed = 0;
        foreach ($preEntry as $estimate) {
            if (($estimate['status'] ?? null) === PreEntryMfeEstimator::STATUS_COMPUTED) {
                $preEntryComputed++;
            }
        }

        $generation->status = 'SUCCEEDED';
        $generation->completed_at = CarbonImmutable::now('UTC');
        $generation->row_counts = [
            'positions' => count($positionMeta),
            'mfe_mae_computed' => $computed,
            'mfe_mae_ambiguous' => $ambiguous,
            'pre_entry_estimates' => count($preEntry),
            'pre_entry_computed' => $preEntryComputed,
        ];
        $generation->metadata = [
            'pre_entry' => $preEntry,
            'cost_attribution' => $attribution,
            'accounting_identity' => 'NET_PNL_EQUALS_GROSS_MINUS_FEES_PLUS_SIGNED_FUNDING',
            'slippage_treatment' => 'Attribution only; slippage is never deducted twice.',
        ];
        $generation->save();

        return $generation->refresh();
    }

    /**
     * @return array<string, list<PriceCandle>>
     */
    private function loadCandles(string $interval): array
    {
        $candles = [];
        CandleRecord::query()
            ->where('interval', $interval)
            ->where('is_closed', true)
            ->orderBy('open_time')
            ->each(function (CandleRecord $record) use (&$candles): void {
                $openTime = $record->open_time;
                if (! $openTime instanceof CarbonImmutable) {
                    return;
                }

                $candles[(string) $record->symbol][] = new PriceCandle(
                    candleId: (string) $record->id,
                    symbol: (string) $record->symbol,
                    interval: (string) $record->interval,
                    openTime: $openTime,
                    closeTime: $openTime->addMinutes((int) $record->interval),
                    open: (string) $record->open,
                    high: (string) $record->high,
                    low: (string) $record->low,
                    close: (string) $record->close,
                );
            });

        return $candles;
    }

    /**
     * @param  Collection<int, VirtualExecutionRecord>  $executions
     * @param  array<string, array{position: VirtualPositionRecord, opened_at: CarbonImmutable, closed_at: ?CarbonImmutable}>  $positionMeta
     * @param  array<string, string>  $strategyByAccount
     * @return list<array<string, mixed>>
     */
    private function executionRows($executions, array $positionMeta, array $strategyByAccount): array
    {
        $rows = [];
        foreach ($executions as $execution) {
            $positionId = (string) $execution->position_id;
            $position = $positionMeta[$positionId]['position'] ?? null;
            $accountId = (string) ($position?->paper_account_id ?? '');

            $rows[] = [
                'id' => (string) $execution->id,
                'position_id' => $positionId,
                'account_id' => $accountId,
                'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
                'symbol' => (string) ($position?->symbol ?? ''),
                'type' => (string) $execution->type,
                'side' => (string) $execution->side,
                'quantity' => (string) $execution->quantity,
                'price' => (string) $execution->price,
                'slippage' => (string) $execution->slippage,
                'commission' => (string) $execution->commission,
                'gross_pnl' => (string) $execution->gross_pnl,
                'ambiguous_execution' => (bool) $execution->ambiguous_execution,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, PositionFundingEventRecord>  $funding
     * @param  array<string, array{position: VirtualPositionRecord, opened_at: CarbonImmutable, closed_at: ?CarbonImmutable}>  $positionMeta
     * @param  array<string, string>  $strategyByAccount
     * @return list<array<string, mixed>>
     */
    private function fundingRows($funding, array $positionMeta, array $strategyByAccount): array
    {
        $rows = [];
        foreach ($funding as $event) {
            $positionId = (string) $event->position_id;
            $position = $positionMeta[$positionId]['position'] ?? null;
            $accountId = (string) ($position?->paper_account_id ?? '');

            $rows[] = [
                'position_id' => $positionId,
                'account_id' => $accountId,
                'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
                'symbol' => (string) ($position?->symbol ?? ''),
                'amount' => (string) $event->amount,
                'funding_at' => (string) $event->funding_at,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{position: VirtualPositionRecord, opened_at: CarbonImmutable, closed_at: ?CarbonImmutable}>  $positionMeta
     * @param  array<string, string>  $strategyByAccount
     * @return list<array<string, mixed>>
     */
    private function positionRows(array $positionMeta, array $strategyByAccount): array
    {
        $rows = [];
        foreach ($positionMeta as $positionId => $meta) {
            $position = $meta['position'];
            $accountId = (string) $position->paper_account_id;

            $rows[] = [
                'id' => $positionId,
                'account_id' => $accountId,
                'strategy_id' => $strategyByAccount[$accountId] ?? 'unknown',
                'symbol' => (string) $position->symbol,
                'status' => (string) $position->status,
                'gross_pnl' => (string) $position->gross_pnl,
                'net_pnl' => (string) $position->net_pnl,
                'fees' => (string) $position->fees,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, MfeMaeResult>  $results
     * @param  array<string, array{position: VirtualPositionRecord, opened_at: CarbonImmutable, closed_at: ?CarbonImmutable}>  $positionMeta
     */
    private function persistMfeMae(string $generationId, array $results, array $positionMeta, CarbonImmutable $asOf): void
    {
        foreach ($results as $positionId => $result) {
            ResearchMfeMaeRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'generation_id' => $generationId,
                'position_id' => $positionId,
                'paper_account_id' => $result->accountId,
                'symbol' => $result->symbol,
                'side' => $result->side,
                'status' => $result->status,
                'holding_start_at' => $result->holdingStart,
                'holding_end_at' => $result->holdingEnd,
                'open_at_cutoff' => $positionMeta[$positionId]['closed_at'] === null,
                'candle_count' => $result->candleCount,
                'mfe_price' => $result->mfePrice,
                'mae_price' => $result->maePrice,
                'mfe_usdt' => $result->mfeUsdt,
                'mae_usdt' => $result->maeUsdt,
                'mfe_r' => $result->mfeR,
                'mae_r' => $result->maeR,
                'mfe_candle_id' => $result->mfeCandleId,
                'mfe_candle_close_time' => $result->mfeCandleCloseTime,
                'mae_candle_id' => $result->maeCandleId,
                'mae_candle_close_time' => $result->maeCandleCloseTime,
                'ambiguous_path' => $result->ambiguousPath,
                'ambiguity_reason' => $result->ambiguityReason,
                'risk_reason' => $result->riskReason,
                'source_candle_ids' => $result->sourceCandleIds,
                'source_candle_close_times' => $result->sourceCandleCloseTimes,
                'limitations' => $result->limitations,
                'as_of' => $asOf,
            ]);
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $attribution
     */
    private function persistCostAttribution(string $generationId, array $attribution): void
    {
        foreach ($attribution as $dimension => $groups) {
            foreach ($groups as $groupKey => $metrics) {
                ResearchCostAttributionRecord::query()->create([
                    'id' => Uuid::uuid4()->toString(),
                    'generation_id' => $generationId,
                    'dimension' => $dimension,
                    'group_key' => (string) $groupKey,
                    'metrics' => $metrics,
                ]);
            }
        }
    }
}
