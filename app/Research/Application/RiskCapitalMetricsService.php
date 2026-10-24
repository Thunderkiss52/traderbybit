<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Domain\SlippageAttributionRecord;
use App\Research\Domain\StrategyRiskMetricRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * PROFIT-1 / P-2 — risk-adjusted metrics and capital efficiency.
 *
 * Normative source: VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §6, §8, §18.
 *
 * Design rules enforced here:
 *  - exactly one accounting scope per generation (§6.1);
 *  - `as_of` is a real cutoff: no source event after the cutoff may contribute
 *    to a point-in-time result (§6.1, "A position opened before a cutoff and
 *    closed after it must not contribute its future final realized PnL");
 *  - the account population is read-only: research code never creates, merges
 *    or deletes paper accounts (§3 safety, §8 "keep the eight accounts independent");
 *  - financial metrics use exact decimal arithmetic, never float64 (§9);
 *  - undefined metrics are NULL plus an explicit reason, never zero (§6.2);
 *  - `cost_to_risk_ratio` is a diagnostic only and is never subtracted from
 *    ledger PnL (§8.2).
 */
final class RiskCapitalMetricsService
{
    public const METRIC_SCHEMA_VERSION = '1.1.0';

    public const ALGORITHM_VERSION = '1.1.0';

    public const DEFAULT_MAX_CARRY_FORWARD_AGE_SECONDS = 86400;

    public const EPSILON = '0.00000001';

    public const SUPPORTED_SCOPES = ['FULL_LIFECYCLE', 'PIT_EVENT_TIME', 'PIT_KNOWN_AT', 'WINDOW_FLOW'];

    public const MIN_VALID_DAYS = 20;

    public const MIN_ESS = 20.0;

    /** @return list<string> */
    private function primaryStrategies(): array
    {
        return array_values((array) config('paper.primary_strategies', ['baseline']));
    }

    /**
     * Compute and store an immutable research generation of P-2 risk and
     * capital-efficiency metrics.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws IdempotencyConflictException
     */
    public function generate(array $parameters = []): ResearchGenerationRecord
    {
        $idempotencyKey = (string) ($parameters['idempotency_key'] ?? Uuid::uuid4()->toString());
        $accountingScope = strtoupper((string) ($parameters['accounting_scope'] ?? 'FULL_LIFECYCLE'));
        if (! in_array($accountingScope, self::SUPPORTED_SCOPES, true)) {
            throw new \InvalidArgumentException('Unsupported accounting scope ['.$accountingScope.'].');
        }

        $asOf = isset($parameters['as_of'])
            ? CarbonImmutable::parse($parameters['as_of'], 'UTC')
            : CarbonImmutable::now('UTC');
        $symbol = strtoupper((string) ($parameters['symbol'] ?? 'ALL'));
        $maxCarryForwardAge = (int) ($parameters['max_carry_forward_age_seconds'] ?? self::DEFAULT_MAX_CARRY_FORWARD_AGE_SECONDS);
        $windowStart = isset($parameters['window_start']) ? CarbonImmutable::parse($parameters['window_start'], 'UTC') : null;
        $windowEnd = isset($parameters['window_end']) ? CarbonImmutable::parse($parameters['window_end'], 'UTC') : null;

        if ($accountingScope === 'WINDOW_FLOW' && ($windowStart === null || $windowEnd === null)) {
            throw new \InvalidArgumentException('WINDOW_FLOW scope requires both window_start and window_end.');
        }
        if ($windowStart !== null && $windowEnd !== null && $windowStart->greaterThanOrEqualTo($windowEnd)) {
            throw new \InvalidArgumentException('window_start must be strictly before window_end (half-open [start, end) interval).');
        }

        $normalizedParams = [
            'package_code' => 'P-2',
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf->toIso8601String(),
            'symbol' => $symbol,
            'window_start' => $windowStart?->toIso8601String(),
            'window_end' => $windowEnd?->toIso8601String(),
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
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

        $versionedConfig = $this->versionedConfig($accountingScope, $maxCarryForwardAge);
        $configHash = hash('sha256', json_encode($versionedConfig, JSON_THROW_ON_ERROR));

        $generationId = Uuid::uuid4()->toString();
        $generation = ResearchGenerationRecord::query()->create([
            'id' => $generationId,
            'package_code' => 'P-2',
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'status' => 'RUNNING',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => $payloadHash,
            'config_hash' => $configHash,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'versioned_config' => $versionedConfig,
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 600,
        ]);

        try {
            $accounts = $this->primaryAccountsReadOnly();
            $records = [];
            foreach ($accounts as $account) {
                $records[] = $this->computeForAccount(
                    $account,
                    $generationId,
                    $accountingScope,
                    $asOf,
                    $symbol,
                    $windowStart,
                    $windowEnd,
                    $maxCarryForwardAge
                );
            }

            foreach (array_chunk($records, 50) as $chunk) {
                StrategyRiskMetricRecord::query()->insert(
                    array_map(fn (array $record): array => $this->encodeJsonColumns($record), $chunk)
                );
            }

            $missingAccounts = count($this->primaryStrategies()) - count($accounts);
            $outputHash = hash('sha256', json_encode([
                'generation_id' => $generationId,
                'records_count' => count($records),
                'account_ids' => $accounts->pluck('id')->sort()->values()->all(),
                'config_hash' => $configHash,
            ], JSON_THROW_ON_ERROR));

            $generation->update([
                'status' => $missingAccounts > 0 ? 'PARTIAL' : 'SUCCEEDED',
                'output_hash' => $outputHash,
                'row_counts' => ['strategy_risk_metrics' => count($records)],
                'missing_data_count' => $missingAccounts,
                'metadata' => [
                    'accounts_processed' => count($accounts),
                    'expected_primary_accounts' => count($this->primaryStrategies()),
                    'missing_primary_accounts' => $missingAccounts,
                    'missing_data_count' => $missingAccounts,
                    'accounting_scope' => $accountingScope,
                    'as_of' => $asOf->toIso8601String(),
                    'window_start' => $windowStart?->toIso8601String(),
                    'window_end' => $windowEnd?->toIso8601String(),
                    'symbol' => $symbol,
                ],
                'coverage_manifest' => [
                    'primary_accounts_found' => $accounts->pluck('primary_strategy_id')->sort()->values()->all(),
                    'primary_accounts_missing' => array_values(array_diff(
                        $this->primaryStrategies(),
                        $accounts->pluck('primary_strategy_id')->filter()->sort()->values()->all()
                    )),
                ],
                'heartbeat_at' => CarbonImmutable::now('UTC'),
                'completed_at' => CarbonImmutable::now('UTC'),
            ]);

            return $generation->refresh();
        } catch (\Throwable $e) {
            $generation->update([
                'status' => 'FAILED',
                'error_message' => 'Research calculation unavailable.',
                'failure_code' => 'GENERATION_FAILED',
                'heartbeat_at' => CarbonImmutable::now('UTC'),
                'completed_at' => CarbonImmutable::now('UTC'),
            ]);

            throw $e;
        }
    }

    /**
     * Read-only resolution of the independent primary paper accounts.
     * Never provisions, merges or mutates accounts.
     *
     * @return Collection<int, PaperAccountRecord>
     */
    public function primaryAccountsReadOnly(): Collection
    {
        return PaperAccountRecord::query()
            ->where('is_primary', true)
            ->where('exchange_id', 'bybit')
            ->whereNotNull('primary_strategy_id')
            ->orderBy('primary_strategy_id')
            ->get();
    }

    /**
     * Compute P-2 risk metrics for a single independent paper account.
     *
     * @return array<string, mixed>
     */
    public function computeForAccount(
        PaperAccountRecord $account,
        string $generationId,
        string $accountingScope,
        CarbonImmutable $asOf,
        string $symbol,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        int $maxCarryForwardAge
    ): array {
        $strategyId = (string) ($account->primary_strategy_id ?? $account->strategy_id ?? 'unknown');
        $cutoff = $this->cutoff($accountingScope, $asOf, $windowEnd);
        $epsilon = BigDecimal::of(self::EPSILON);

        $positions = $this->positionsInScope($account, $symbol, $cutoff, $windowStart, $accountingScope, $windowEnd);
        $positionIds = $positions->pluck('id')->all();
        $executions = $this->executionsInScope($positionIds, $accountingScope, $asOf, $windowStart, $windowEnd);
        $fundings = $this->fundingsInScope($positionIds, $accountingScope, $asOf, $windowStart, $windowEnd);

        $executionsByPosition = $this->groupBy($executions, 'position_id');
        $fundingsByPosition = $this->groupBy($fundings, 'position_id');

        $nWins = 0;
        $nLosses = 0;
        $nFlat = 0;
        $exclusionsCount = 0;
        $exclusionReasons = [];

        $netRValues = [];
        $grossGains = BigDecimal::zero();
        $grossLosses = BigDecimal::zero();
        $winNetPnls = [];
        $lossNetPnls = [];

        $totalRealizedNetPnl = BigDecimal::zero();
        $totalAllocatedRiskCapital = BigDecimal::zero();
        $totalEntryNotional = BigDecimal::zero();
        $totalFees = BigDecimal::zero();
        $totalSignedFunding = BigDecimal::zero();
        $openPositionsCount = 0;
        $partialPositionsCount = 0;
        $accountingDiscrepancies = [];

        $population = [];

        foreach ($positions as $position) {
            $positionExecutions = $executionsByPosition[$position->id] ?? collect();
            $positionFundings = $fundingsByPosition[$position->id] ?? collect();

            $initialQuantity = BigDecimal::of((string) ($position->initial_quantity ?? '0'));
            $riskCapital = BigDecimal::of((string) ($position->risk_capital ?? '0'));
            $entryPrice = BigDecimal::of((string) ($position->entry_price ?? '0'));

            $closedQuantity = BigDecimal::zero();
            $grossInScope = BigDecimal::zero();
            $feesInScope = BigDecimal::zero();
            foreach ($positionExecutions as $execution) {
                $quantity = BigDecimal::of((string) $execution->quantity);
                $grossInScope = $grossInScope->plus(BigDecimal::of((string) ($execution->gross_pnl ?? '0')));
                $feesInScope = $feesInScope->plus(BigDecimal::of((string) ($execution->commission ?? '0')));
                if (strtoupper((string) $execution->type) !== 'ENTRY') {
                    $closedQuantity = $closedQuantity->plus($quantity);
                }
            }
            $fundingInScope = BigDecimal::zero();
            foreach ($positionFundings as $funding) {
                $fundingInScope = $fundingInScope->plus(BigDecimal::of((string) ($funding->amount ?? '0')));
            }

            $netInScope = $grossInScope->minus($feesInScope)->plus($fundingInScope);
            $isClosedAtCutoff = $position->closed_at !== null
                && $position->closed_at->toDateTimeImmutable()->getTimestamp() <= $cutoff->getTimestamp();
            $isPartial = $closedQuantity->isGreaterThan(BigDecimal::zero())
                && $closedQuantity->isLessThan($initialQuantity);

            $allocatedRisk = $initialQuantity->isZero()
                ? BigDecimal::zero()
                : $riskCapital->multipliedBy($closedQuantity)->dividedBy($initialQuantity, 24, RoundingMode::HalfEven);

            $eligible = $accountingScope === 'WINDOW_FLOW'
                ? $allocatedRisk->isGreaterThan(BigDecimal::zero())
                : ($isClosedAtCutoff && $allocatedRisk->isGreaterThan(BigDecimal::zero()));

            if (! $eligible) {
                $exclusionsCount++;
                if ($riskCapital->isLessThanOrEqualTo(BigDecimal::zero())) {
                    $exclusionReasons['MISSING_OR_ZERO_RISK_CAPITAL'] = ($exclusionReasons['MISSING_OR_ZERO_RISK_CAPITAL'] ?? 0) + 1;
                } elseif (! $isClosedAtCutoff && $accountingScope !== 'WINDOW_FLOW') {
                    $exclusionReasons[$isPartial ? 'PARTIALLY_CLOSED_AT_CUTOFF' : 'OPEN_AT_CUTOFF'] = ($exclusionReasons[$isPartial ? 'PARTIALLY_CLOSED_AT_CUTOFF' : 'OPEN_AT_CUTOFF'] ?? 0) + 1;
                } else {
                    $exclusionReasons['NO_REALIZED_QUANTITY_IN_SCOPE'] = ($exclusionReasons['NO_REALIZED_QUANTITY_IN_SCOPE'] ?? 0) + 1;
                }

                if (! $isClosedAtCutoff) {
                    $openPositionsCount++;
                    if ($isPartial) {
                        $partialPositionsCount++;
                    }
                }

                continue;
            }

            if ($isPartial) {
                $partialPositionsCount++;
            }

            if ($netInScope->isGreaterThan($epsilon)) {
                $nWins++;
                $grossGains = $grossGains->plus($netInScope);
                $winNetPnls[] = $netInScope;
            } elseif ($netInScope->isLessThan($epsilon->negated())) {
                $nLosses++;
                $grossLosses = $grossLosses->plus($netInScope->abs());
                $lossNetPnls[] = $netInScope->abs();
            } else {
                $nFlat++;
            }

            $netRValues[] = $netInScope->dividedBy($allocatedRisk, 8, RoundingMode::HalfEven);

            $totalRealizedNetPnl = $totalRealizedNetPnl->plus($netInScope);
            $totalAllocatedRiskCapital = $totalAllocatedRiskCapital->plus($allocatedRisk);
            $totalEntryNotional = $totalEntryNotional->plus($entryPrice->abs()->multipliedBy($closedQuantity));
            $totalFees = $totalFees->plus($feesInScope);
            $totalSignedFunding = $totalSignedFunding->plus($fundingInScope);

            if ($isClosedAtCutoff) {
                $ledgerNet = BigDecimal::of((string) ($position->net_pnl ?? '0'));
                $difference = $ledgerNet->minus($netInScope)->abs();
                if ($difference->isGreaterThan($epsilon)) {
                    $accountingDiscrepancies[] = [
                        'position_id' => $position->id,
                        'ledger_net_pnl' => (string) $ledgerNet,
                        'recomputed_net_pnl' => (string) $netInScope,
                        'difference' => (string) $difference,
                    ];
                }
            }

            $population[] = [
                'position_id' => $position->id,
                'symbol' => $position->symbol,
                'side' => $position->side,
                'opened_at' => $position->opened_at?->toIso8601String(),
                'closed_at' => $position->closed_at?->toIso8601String(),
                'realized_quantity' => (string) $closedQuantity,
                'initial_quantity' => (string) $initialQuantity,
                'allocated_risk_capital' => (string) $allocatedRisk,
                'net_pnl' => (string) $netInScope,
            ];
        }

        [$netRMean, $netRMedian, $netRReason] = $this->meanMedian($netRValues);

        $profitFactor = null;
        $profitFactorReason = null;
        if ($grossLosses->isZero()) {
            $profitFactorReason = 'UNDEFINED_NO_LOSSES';
        } else {
            $profitFactor = (string) $grossGains->dividedBy($grossLosses, 8, RoundingMode::HalfEven);
        }

        $winRate = null;
        $winRateReason = null;
        $winLossTotal = $nWins + $nLosses;
        if ($winLossTotal > 0) {
            $winRate = (string) BigDecimal::of((string) $nWins)->dividedBy((string) $winLossTotal, 6, RoundingMode::HalfEven);
        } else {
            $winRateReason = 'UNDEFINED_NO_WINS_OR_LOSSES';
        }

        [$payoffRatio, $payoffRatioReason] = $this->payoff($winNetPnls, $lossNetPnls);

        [$costToRiskRatio, $costToRiskReason, $costToRiskProvenance] = $this->costToRisk(
            $population,
            $totalFees,
            $totalSignedFunding,
            $totalAllocatedRiskCapital,
            $accountingScope
        );

        $equity = $this->equitySeries($account, $accountingScope, $asOf, $windowStart, $windowEnd);
        $equityMetrics = $this->equityMetrics($equity, $maxCarryForwardAge);

        $exposure = $this->exposureMetrics(
            $positions,
            $executionsByPosition,
            $equity['intervals'],
            $equityMetrics['twa_equity'],
            $maxCarryForwardAge
        );

        $daily = $this->dailyRealizedSeries(
            $executions,
            $fundings,
            $positions->keyBy('id'),
            $executionsByPosition
        );
        $dailySeriesDays = count($daily['series']);
        [$ess, $essClamped] = $this->calculateBartlettEss($daily['series']);

        $netReturnOnEquity = null;
        $turnover = null;
        $netPnlPerRiskCapital = null;
        $notionalPerInitialRisk = null;
        if ($equityMetrics['twa_equity'] !== null) {
            $twa = BigDecimal::of($equityMetrics['twa_equity']);
            if ($twa->isGreaterThan(BigDecimal::zero())) {
                $netReturnOnEquity = (string) $totalRealizedNetPnl->dividedBy($twa, 8, RoundingMode::HalfEven);
                $turnover = (string) $daily['fill_notional']->dividedBy($twa, 8, RoundingMode::HalfEven);
            }
        }
        if ($totalAllocatedRiskCapital->isGreaterThan(BigDecimal::zero())) {
            $netPnlPerRiskCapital = (string) $totalRealizedNetPnl->dividedBy($totalAllocatedRiskCapital, 8, RoundingMode::HalfEven);
            $notionalPerInitialRisk = (string) $totalEntryNotional->dividedBy($totalAllocatedRiskCapital, 8, RoundingMode::HalfEven);
        }

        $sufficiencyReasons = [];
        if ($dailySeriesDays < self::MIN_VALID_DAYS) {
            $sufficiencyReasons[] = 'INSUFFICIENT_CALENDAR_DAYS: expected >= '.self::MIN_VALID_DAYS.' valid non-overlapping UTC days, observed ['.$dailySeriesDays.'].';
        }
        if ($ess === null || $ess < self::MIN_ESS) {
            $sufficiencyReasons[] = 'ESS_BELOW_MINIMUM_20: effective sample size ['.($ess === null ? 'NULL' : (string) round($ess, 4)).'] is below the normative threshold of 20.';
        }
        $coverageRatio = $equityMetrics['coverage_gap_ratio'];
        if ($coverageRatio !== null && $coverageRatio > 0.20) {
            $sufficiencyReasons[] = 'EXCESSIVE_EQUITY_COVERAGE_GAPS: uncovered gap ratio ['.number_format($coverageRatio, 6, '.', '').'] exceeds 20% of the observation window.';
        }
        if ($accountingDiscrepancies !== []) {
            $sufficiencyReasons[] = 'UNRESOLVED_ACCOUNTING_DISCREPANCY: '.count($accountingDiscrepancies).' position(s) differ between ledger net_pnl and recomputed scope net PnL.';
        }
        if ($equity['observations'] === 0) {
            $sufficiencyReasons[] = 'NO_EQUITY_SERIES: the account has no equity snapshot inside the declared scope.';
        }

        $sampleSufficiency = $sufficiencyReasons === [] ? 'SUFFICIENT' : 'INSUFFICIENT_DATA';

        $dimensionKey = hash('sha256', implode('|', [
            $account->id,
            $strategyId,
            $symbol,
            $accountingScope,
            $asOf->toIso8601String(),
            $windowStart?->toIso8601String() ?? 'NONE',
            $windowEnd?->toIso8601String() ?? 'NONE',
        ]));

        return [
            'id' => Uuid::uuid4()->toString(),
            'generation_id' => $generationId,
            'dimension_key' => $dimensionKey,
            'paper_account_id' => $account->id,
            'strategy_id' => $strategyId,
            'symbol' => $symbol,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,

            'n_positions' => count($population),
            'n_wins' => $nWins,
            'n_losses' => $nLosses,
            'n_flat' => $nFlat,
            'n_days' => $dailySeriesDays,
            'exclusions_count' => $exclusionsCount,
            'exclusion_reasons' => $exclusionReasons,

            'net_r_mean' => $netRMean,
            'net_r_median' => $netRMedian,
            'net_r_reason' => $netRReason,
            'cost_to_risk_ratio' => $costToRiskRatio,
            'cost_to_risk_reason' => $costToRiskReason,
            'cost_to_risk_provenance' => $costToRiskProvenance,
            'profit_factor' => $profitFactor,
            'profit_factor_reason' => $profitFactorReason,
            'win_rate' => $winRate,
            'win_rate_reason' => $winRateReason,
            'payoff_ratio' => $payoffRatio,
            'payoff_ratio_reason' => $payoffRatioReason,

            'max_drawdown_pct' => $equityMetrics['max_drawdown_pct'],
            'time_underwater_pct' => $equityMetrics['time_underwater_pct'],
            'twa_equity' => $equityMetrics['twa_equity'],
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'coverage_gap_seconds' => $equityMetrics['coverage_gap_seconds'],
            'equity_observations' => $equity['observations'],

            'turnover' => $turnover,
            'net_return_on_equity' => $netReturnOnEquity,
            'net_pnl_per_risk_capital' => $netPnlPerRiskCapital,
            'average_gross_notional_exposure_pct' => $exposure['average_gross_notional_exposure_pct'],
            'average_open_risk_capital_pct' => $exposure['average_open_risk_capital_pct'],
            'time_with_open_exposure_pct' => $exposure['time_with_open_exposure_pct'],
            'notional_per_initial_risk_capital' => $notionalPerInitialRisk,
            'average_margin_used_pct_of_equity' => null,
            'average_margin_reason' => 'NOT_RECORDED_IN_LEDGER',
            'notional_basis' => 'ENTRY_PRICE_X_REMAINING_QUANTITY_EVENT_RECONSTRUCTED',

            'sharpe_like' => null,
            'sharpe_like_reason' => 'NOT_COMPARABLE_EXTERNAL_FLOWS_NOT_IDENTIFIED_IN_LEDGER',
            'ess' => $ess !== null ? (string) round($ess, 4) : null,
            'ess_clamped' => $essClamped,
            'ess_method' => 'BARTLETT_AUTOCORRELATION_LAG_FLOOR_N_DIV_4',
            'sample_sufficiency' => $sampleSufficiency,
            'sample_sufficiency_reasons' => $sufficiencyReasons,

            'position_population' => [
                'included' => $population,
                'open_positions_at_cutoff' => $openPositionsCount,
                'partially_closed_positions_at_cutoff' => $partialPositionsCount,
                'accounting_discrepancies' => $accountingDiscrepancies,
                'daily_series_days' => $dailySeriesDays,
            ],
            'provenance' => 'RECOMPUTED_FROM_SOURCE',
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * Calculate Bartlett-style Effective Sample Size on a discrete series:
     * ESS = n / (1 + 2 * sum(rho_k, k=1..K)) where K = floor(n / 4).
     *
     * @param  array<int, float>  $series
     * @return array{0: float|null, 1: bool} [ess, clamped]
     */
    public function calculateBartlettEss(array $series): array
    {
        $n = count($series);
        if ($n < 4) {
            return [$n > 0 ? (float) $n : null, false];
        }

        $mean = array_sum($series) / $n;
        $variance = 0.0;
        foreach ($series as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= $n;

        if ($variance <= 1e-12) {
            return [1.0, true];
        }

        $kMax = (int) floor($n / 4);
        $rhoSum = 0.0;
        for ($k = 1; $k <= $kMax; $k++) {
            $covK = 0.0;
            for ($t = 0; $t < $n - $k; $t++) {
                $covK += ($series[$t] - $mean) * ($series[$t + $k] - $mean);
            }
            $rhoSum += $covK / $n / $variance;
        }

        $denom = 1.0 + 2.0 * $rhoSum;
        if (! is_finite($denom) || $denom <= 0.0) {
            return [1.0, true];
        }

        $rawEss = $n / $denom;
        if ($rawEss > $n) {
            return [(float) $n, true];
        }
        if ($rawEss < 1.0) {
            return [1.0, true];
        }

        return [$rawEss, false];
    }

    /**
     * Retrieve the latest succeeded P-2 risk metrics per strategy.
     * Purely read-only; zero database writes.
     *
     * @return array<string, mixed>
     */
    public function getLatestMetrics(string $accountingScope = 'FULL_LIFECYCLE'): array
    {
        $generation = ResearchGenerationRecord::query()
            ->where('package_code', 'P-2')
            ->whereIn('status', ['SUCCEEDED', 'PARTIAL'])
            ->where('accounting_scope', $accountingScope)
            ->latest('created_at')
            ->first();

        if ($generation === null) {
            return [
                'status' => 'NO_GENERATION_AVAILABLE',
                'message' => 'No completed P-2 research generation exists yet for scope: '.$accountingScope,
                'provenance' => 'INSUFFICIENT_DATA',
            ];
        }

        $metrics = StrategyRiskMetricRecord::query()
            ->where('generation_id', $generation->id)
            ->orderBy('strategy_id')
            ->get();

        return [
            'status' => $generation->status,
            'generation_id' => $generation->id,
            'accounting_scope' => $generation->accounting_scope,
            'as_of' => $generation->as_of?->toIso8601String(),
            'output_hash' => $generation->output_hash,
            'config_hash' => $generation->config_hash,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'versioned_config' => $generation->versioned_config,
            'metadata' => $generation->metadata,
            'coverage_manifest' => $generation->coverage_manifest,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function encodeJsonColumns(array $record): array
    {
        foreach (['exclusion_reasons', 'sample_sufficiency_reasons', 'position_population'] as $column) {
            if (array_key_exists($column, $record) && is_array($record[$column])) {
                $record[$column] = json_encode($record[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionedConfig(string $accountingScope, int $maxCarryForwardAge): array
    {
        return [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'accounting_scope' => $accountingScope,
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'epsilon' => self::EPSILON,
            'min_days_sample_sufficiency' => self::MIN_VALID_DAYS,
            'min_ess_sample_sufficiency' => self::MIN_ESS,
            'risk_capital_basis' => 'PROPORTIONAL_ALLOCATION_TO_REALIZED_QUANTITY',
            'notional_basis' => 'ENTRY_PRICE_X_REMAINING_QUANTITY_EVENT_RECONSTRUCTED',
            'underwater_equality_rule' => 'EQUITY_EQUAL_TO_HIGH_WATER_MARK_IS_NOT_UNDERWATER',
            'ess_method' => 'BARTLETT_AUTOCORRELATION_LAG_FLOOR_N_DIV_4',
            'ess_series' => 'DAILY_REALIZED_NET_R_DAYS_WITH_VALID_DENOMINATOR',
            'daily_booking_timestamp' => 'EXECUTION_REGISTERED_AT_FALLBACK_MARKET_EVENT_AT',
            'funding_booking_timestamp' => 'FUNDING_AT',
            'external_flows_status' => 'NOT_IDENTIFIED_IN_LEDGER',
            'margin_status' => 'NOT_RECORDED_IN_LEDGER',
            'interval_convention' => 'HALF_OPEN_[start,end)',
            'timezone' => 'UTC',
        ];
    }

    private function cutoff(string $accountingScope, CarbonImmutable $asOf, ?CarbonImmutable $windowEnd): CarbonImmutable
    {
        return $accountingScope === 'WINDOW_FLOW' && $windowEnd !== null ? $windowEnd : $asOf;
    }

    /**
     * @return Collection<int, VirtualPositionRecord>
     */
    private function positionsInScope(
        PaperAccountRecord $account,
        string $symbol,
        CarbonImmutable $cutoff,
        ?CarbonImmutable $windowStart,
        string $accountingScope,
        ?CarbonImmutable $windowEnd
    ): Collection {
        $query = VirtualPositionRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('opened_at', '<=', $cutoff);

        if ($symbol !== 'ALL') {
            $query->where('symbol', $symbol);
        }

        if ($accountingScope === 'WINDOW_FLOW' && $windowStart !== null) {
            // Half-open [window_start, window_end): a position is in scope when it was
            // open at any point inside the window.
            $query->where(function ($q) use ($windowStart): void {
                $q->whereNull('closed_at')
                    ->orWhere('closed_at', '>=', $windowStart);
            });
        }

        return $query->orderBy('opened_at')->get();
    }

    /**
     * @param  array<int, string>  $positionIds
     * @return Collection<int, VirtualExecutionRecord>
     */
    private function executionsInScope(
        array $positionIds,
        string $accountingScope,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): Collection {
        if ($positionIds === []) {
            return collect();
        }

        $query = VirtualExecutionRecord::query()
            ->whereIn('position_id', $positionIds)
            ->where('market_event_at', '<=', $accountingScope === 'WINDOW_FLOW' && $windowEnd !== null ? $windowEnd : $asOf);

        if ($accountingScope === 'WINDOW_FLOW' && $windowStart !== null) {
            $query->where('market_event_at', '>=', $windowStart);
        }

        $executions = $query->orderBy('market_event_at')->get();

        if ($accountingScope === 'PIT_KNOWN_AT') {
            // Both the market timestamp and the system registration timestamp must be
            // known at the cutoff.
            $executions = $executions->filter(
                fn (VirtualExecutionRecord $e): bool => $e->registered_at === null
                    || $e->registered_at->toDateTimeImmutable()->getTimestamp() <= $asOf->getTimestamp()
            )->values();
        }

        return $executions;
    }

    /**
     * @param  array<int, string>  $positionIds
     * @return Collection<int, PositionFundingEventRecord>
     */
    private function fundingsInScope(
        array $positionIds,
        string $accountingScope,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): Collection {
        if ($positionIds === []) {
            return collect();
        }

        $query = PositionFundingEventRecord::query()
            ->whereIn('position_id', $positionIds)
            ->where('funding_at', '<=', $accountingScope === 'WINDOW_FLOW' && $windowEnd !== null ? $windowEnd : $asOf);

        if ($accountingScope === 'WINDOW_FLOW' && $windowStart !== null) {
            $query->where('funding_at', '>=', $windowStart);
        }

        return $query->orderBy('funding_at')->get();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, Collection<int, mixed>>
     */
    private function groupBy(Collection $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->{$key}][] = $row;
        }

        return array_map(fn (array $items): Collection => collect($items), $grouped);
    }

    /**
     * @param  array<int, BigDecimal>  $values
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function meanMedian(array $values): array
    {
        if ($values === []) {
            return [null, null, 'UNDEFINED_NO_ELIGIBLE_POSITIONS'];
        }

        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus($value);
        }
        $mean = (string) $sum->dividedBy((string) count($values), 8, RoundingMode::HalfEven);

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        $median = $count % 2 === 1
            ? (string) $values[$mid]
            : (string) $values[$mid - 1]->plus($values[$mid])->dividedBy('2', 8, RoundingMode::HalfEven);

        return [$mean, $median, null];
    }

    /**
     * @param  array<int, BigDecimal>  $wins
     * @param  array<int, BigDecimal>  $losses
     * @return array{0: string|null, 1: string|null}
     */
    private function payoff(array $wins, array $losses): array
    {
        if ($wins === []) {
            return [null, 'UNDEFINED_NO_WINNING_POSITIONS'];
        }
        if ($losses === []) {
            return [null, 'UNDEFINED_NO_LOSING_POSITIONS'];
        }

        $winSum = BigDecimal::zero();
        foreach ($wins as $win) {
            $winSum = $winSum->plus($win);
        }
        $lossSum = BigDecimal::zero();
        foreach ($losses as $loss) {
            $lossSum = $lossSum->plus($loss);
        }

        $meanWin = $winSum->dividedBy((string) count($wins), 12, RoundingMode::HalfEven);
        $meanLoss = $lossSum->dividedBy((string) count($losses), 12, RoundingMode::HalfEven);
        if ($meanLoss->isZero()) {
            return [null, 'UNDEFINED_ZERO_MEAN_LOSS'];
        }

        return [(string) $meanWin->dividedBy($meanLoss, 8, RoundingMode::HalfEven), null];
    }

    /**
     * cost_to_risk_ratio = (fees + slippage_drag - signed_funding) / initial risk capital.
     *
     * Diagnostic only: slippage drag is already embedded in the fill prices, so this
     * ratio is never subtracted from ledger PnL (PROFIT-1 Rev 2.1 §8.2).
     *
     * @param  array<int, array<string, mixed>>  $population
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function costToRisk(
        array $population,
        BigDecimal $totalFees,
        BigDecimal $totalSignedFunding,
        BigDecimal $totalRiskCapital,
        string $accountingScope
    ): array {
        if ($population === []) {
            return [null, 'UNDEFINED_NO_ELIGIBLE_POSITIONS', null];
        }
        if (! $totalRiskCapital->isGreaterThan(BigDecimal::zero())) {
            return [null, 'UNDEFINED_ZERO_RISK_CAPITAL_DENOMINATOR', null];
        }
        if ($accountingScope === 'WINDOW_FLOW') {
            // P-1 attribution covers the whole position lifecycle and is not window-scoped;
            // mixing it with window flows would break the matched-scope requirement.
            return [null, 'P1_ATTRIBUTION_NOT_WINDOW_SCOPED', null];
        }

        $positionIds = array_values(array_map(fn (array $row): string => (string) $row['position_id'], $population));
        $attributions = SlippageAttributionRecord::query()
            ->whereIn('position_id', $positionIds)
            ->get()
            ->keyBy('position_id');

        $missing = [];
        $drag = BigDecimal::zero();
        $provenanceStatuses = [];
        foreach ($positionIds as $positionId) {
            $attribution = $attributions->get($positionId);
            if ($attribution === null) {
                $missing[] = $positionId;

                continue;
            }
            $drag = $drag->plus(BigDecimal::of((string) ($attribution->total_slippage_drag_usdt ?? '0')));
            $status = (string) ($attribution->provenance_status ?? 'UNKNOWN');
            $provenanceStatuses[$status] = ($provenanceStatuses[$status] ?? 0) + 1;
        }

        if ($missing !== []) {
            return [
                null,
                'INCOMPLETE_P1_MATCHED_SCOPE: '.count($missing).' of '.count($positionIds).' positions have no matched P-1 slippage attribution.',
                null,
            ];
        }

        $friction = $totalFees->plus($drag)->minus($totalSignedFunding);

        return [
            (string) $friction->dividedBy($totalRiskCapital, 8, RoundingMode::HalfEven),
            null,
            implode('+', array_map(fn (string $k): string => $k.'='.($provenanceStatuses[$k] ?? 0), array_keys($provenanceStatuses))),
        ];
    }

    /**
     * @return array{observations: int, intervals: array<int, array<string, mixed>>}
     */
    private function equitySeries(
        PaperAccountRecord $account,
        string $accountingScope,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): array {
        $query = EquitySnapshotRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('recorded_at', '<=', $accountingScope === 'WINDOW_FLOW' && $windowEnd !== null ? $windowEnd : $asOf);

        if ($accountingScope === 'WINDOW_FLOW' && $windowStart !== null) {
            $query->where('recorded_at', '>=', $windowStart);
        }

        $snapshots = $query->orderBy('recorded_at')->get();
        $intervals = [];
        $count = $snapshots->count();
        for ($i = 0; $i < $count - 1; $i++) {
            $current = $snapshots[$i];
            $next = $snapshots[$i + 1];
            $intervals[] = [
                'from' => $current->recorded_at->toDateTimeImmutable(),
                'to' => $next->recorded_at->toDateTimeImmutable(),
                'equity' => (string) $current->equity,
            ];
        }

        return ['observations' => $count, 'intervals' => $intervals, 'snapshots' => $snapshots];
    }

    /**
     * @param  array{observations: int, intervals: array<int, array<string, mixed>>}  $equity
     * @return array<string, mixed>
     */
    private function equityMetrics(array $equity, int $maxCarryForwardAge): array
    {
        $snapshots = $equity['snapshots'];
        $intervals = $equity['intervals'];

        if ($snapshots->isEmpty()) {
            return [
                'max_drawdown_pct' => null,
                'time_underwater_pct' => null,
                'twa_equity' => null,
                'coverage_gap_seconds' => 0,
                'coverage_gap_ratio' => null,
            ];
        }

        $runningPeak = BigDecimal::of((string) $snapshots->first()->equity);
        $maxDrawdown = BigDecimal::zero();
        foreach ($snapshots as $snapshot) {
            $value = BigDecimal::of((string) $snapshot->equity);
            if ($value->isGreaterThan($runningPeak)) {
                $runningPeak = $value;
            }
            if (! $runningPeak->isZero()) {
                $drawdown = $runningPeak->minus($value)->dividedBy($runningPeak, 12, RoundingMode::HalfEven)->multipliedBy(100);
                if ($drawdown->isGreaterThan($maxDrawdown)) {
                    $maxDrawdown = $drawdown;
                }
            }
        }

        $validSeconds = 0;
        $underwaterSeconds = 0;
        $twaNumerator = BigDecimal::zero();
        $coverageGapSeconds = 0;

        foreach ($intervals as $interval) {
            $delta = max(0, $interval['to']->getTimestamp() - $interval['from']->getTimestamp());
            $validDelta = min($delta, $maxCarryForwardAge);
            $coverageGapSeconds += max(0, $delta - $maxCarryForwardAge);
            if ($validDelta <= 0) {
                continue;
            }
            $value = BigDecimal::of((string) $interval['equity']);
            $validSeconds += $validDelta;
            $twaNumerator = $twaNumerator->plus($value->multipliedBy((string) $validDelta));
            if ($value->isLessThan($runningPeak)) {
                // Equality with the high-water mark is NOT underwater (declared in versioned_config).
                $underwaterSeconds += $validDelta;
            }
        }

        $totalSpan = $validSeconds + $coverageGapSeconds;

        return [
            'max_drawdown_pct' => (string) $maxDrawdown->toScale(6, RoundingMode::HalfEven),
            'time_underwater_pct' => $validSeconds > 0
                ? (string) BigDecimal::of((string) $underwaterSeconds)->dividedBy((string) $validSeconds, 8, RoundingMode::HalfEven)->multipliedBy(100)->toScale(4, RoundingMode::HalfEven)
                : null,
            'twa_equity' => $validSeconds > 0
                ? (string) $twaNumerator->dividedBy((string) $validSeconds, 8, RoundingMode::HalfEven)
                : null,
            'coverage_gap_seconds' => $coverageGapSeconds,
            'coverage_gap_ratio' => $totalSpan > 0 ? $coverageGapSeconds / $totalSpan : null,
        ];
    }

    /**
     * Time-weighted exposure metrics over the valid equity observation intervals.
     *
     * @param  Collection<int, VirtualPositionRecord>  $positions
     * @param  array<string, Collection<int, mixed>>  $executionsByPosition
     * @param  array<int, array<string, mixed>>  $intervals
     * @return array<string, string|null>
     */
    private function exposureMetrics(
        Collection $positions,
        array $executionsByPosition,
        array $intervals,
        ?string $twaEquity,
        int $maxCarryForwardAge
    ): array {
        if ($intervals === [] || $twaEquity === null) {
            return [
                'average_gross_notional_exposure_pct' => null,
                'average_open_risk_capital_pct' => null,
                'time_with_open_exposure_pct' => null,
            ];
        }

        $twa = BigDecimal::of($twaEquity);
        if (! $twa->isGreaterThan(BigDecimal::zero())) {
            return [
                'average_gross_notional_exposure_pct' => null,
                'average_open_risk_capital_pct' => null,
                'time_with_open_exposure_pct' => null,
            ];
        }

        // Build the per-position quantity-reduction timeline used for the mark.
        $timelines = [];
        foreach ($positions as $position) {
            $initialQuantity = BigDecimal::of((string) ($position->initial_quantity ?? '0'));
            $entryPrice = BigDecimal::of((string) ($position->entry_price ?? '0'));
            $riskCapital = BigDecimal::of((string) ($position->risk_capital ?? '0'));
            $reductions = [];
            foreach ($executionsByPosition[$position->id] ?? [] as $execution) {
                if (strtoupper((string) $execution->type) === 'ENTRY') {
                    continue;
                }
                $reductions[] = [
                    'at' => $execution->market_event_at->toDateTimeImmutable(),
                    'quantity' => BigDecimal::of((string) $execution->quantity),
                ];
            }
            usort($reductions, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
            $timelines[] = [
                'opened_at' => $position->opened_at->toDateTimeImmutable(),
                'closed_at' => $position->closed_at?->toDateTimeImmutable(),
                'initial' => $initialQuantity,
                'entry_price' => $entryPrice,
                'risk_capital' => $riskCapital,
                'reductions' => $reductions,
            ];
        }

        $validSeconds = 0;
        $exposureSeconds = 0;
        $notionalWeighted = BigDecimal::zero();
        $riskWeighted = BigDecimal::zero();

        foreach ($intervals as $interval) {
            $delta = max(0, $interval['to']->getTimestamp() - $interval['from']->getTimestamp());
            $validDelta = min($delta, $maxCarryForwardAge);
            if ($validDelta <= 0) {
                continue;
            }
            $at = $interval['to'];
            $openCount = 0;
            $notional = BigDecimal::zero();
            $openRisk = BigDecimal::zero();

            foreach ($timelines as $timeline) {
                if ($timeline['opened_at']->getTimestamp() > $at->getTimestamp()) {
                    continue;
                }
                if ($timeline['closed_at'] !== null && $timeline['closed_at']->getTimestamp() <= $at->getTimestamp()) {
                    continue;
                }
                $remaining = $timeline['initial'];
                foreach ($timeline['reductions'] as $reduction) {
                    if ($reduction['at']->getTimestamp() <= $at->getTimestamp()) {
                        $remaining = $remaining->minus($reduction['quantity']);
                    }
                }
                if ($remaining->isLessThanOrEqualTo(BigDecimal::zero())) {
                    continue;
                }
                $openCount++;
                $notional = $notional->plus($timeline['entry_price']->abs()->multipliedBy($remaining));
                if (! $timeline['initial']->isZero()) {
                    $openRisk = $openRisk->plus($timeline['risk_capital']->multipliedBy($remaining)->dividedBy($timeline['initial'], 24, RoundingMode::HalfEven));
                }
            }

            $validSeconds += $validDelta;
            $notionalWeighted = $notionalWeighted->plus($notional->multipliedBy((string) $validDelta));
            $riskWeighted = $riskWeighted->plus($openRisk->multipliedBy((string) $validDelta));
            if ($openCount > 0) {
                $exposureSeconds += $validDelta;
            }
        }

        if ($validSeconds <= 0) {
            return [
                'average_gross_notional_exposure_pct' => null,
                'average_open_risk_capital_pct' => null,
                'time_with_open_exposure_pct' => null,
            ];
        }

        return [
            'average_gross_notional_exposure_pct' => (string) $notionalWeighted
                ->dividedBy((string) $validSeconds, 12, RoundingMode::HalfEven)
                ->dividedBy($twa, 12, RoundingMode::HalfEven)
                ->multipliedBy(100)->toScale(8, RoundingMode::HalfEven),
            'average_open_risk_capital_pct' => (string) $riskWeighted
                ->dividedBy((string) $validSeconds, 12, RoundingMode::HalfEven)
                ->dividedBy($twa, 12, RoundingMode::HalfEven)
                ->multipliedBy(100)->toScale(8, RoundingMode::HalfEven),
            'time_with_open_exposure_pct' => (string) BigDecimal::of((string) $exposureSeconds)
                ->dividedBy((string) $validSeconds, 8, RoundingMode::HalfEven)
                ->multipliedBy(100)->toScale(4, RoundingMode::HalfEven),
        ];
    }

    /**
     * Daily realized net PnL / daily realized net R series.
     *
     * Realized PnL is booked on the day the accounting event was registered
     * (`registered_at`, fallback `market_event_at`). Risk capital is allocated to
     * the quantity actually closed, proportionally (PROFIT-1 Rev 2.1 §8.2).
     *
     * @param  Collection<int, VirtualExecutionRecord>  $executions
     * @param  Collection<int, PositionFundingEventRecord>  $fundings
     * @param  Collection<string, VirtualPositionRecord>  $positionsById
     * @param  array<string, Collection<int, mixed>>  $executionsByPosition
     * @return array{series: array<int, float>, fill_notional: BigDecimal, days: array<string, array<string, string>>}
     */
    private function dailyRealizedSeries(
        Collection $executions,
        Collection $fundings,
        Collection $positionsById,
        array $executionsByPosition
    ): array {
        $dailyNet = [];
        $dailyRisk = [];
        $fillNotional = BigDecimal::zero();

        foreach ($executions as $execution) {
            $position = $positionsById->get($execution->position_id);
            if ($position === null) {
                continue;
            }
            $bookedAt = $execution->registered_at ?? $execution->market_event_at;
            if ($bookedAt === null) {
                continue;
            }
            $day = CarbonImmutable::parse($bookedAt, 'UTC')->toDateString();
            $quantity = BigDecimal::of((string) $execution->quantity);
            $gross = BigDecimal::of((string) ($execution->gross_pnl ?? '0'));
            $commission = BigDecimal::of((string) ($execution->commission ?? '0'));

            $dailyNet[$day] = ($dailyNet[$day] ?? BigDecimal::zero())->plus($gross)->minus($commission);
            $fillNotional = $fillNotional->plus(BigDecimal::of((string) $execution->price)->multipliedBy($quantity));

            if (strtoupper((string) $execution->type) === 'ENTRY') {
                continue;
            }

            $initialQuantity = BigDecimal::of((string) ($position->initial_quantity ?? '0'));
            $riskCapital = BigDecimal::of((string) ($position->risk_capital ?? '0'));
            if ($initialQuantity->isZero()) {
                continue;
            }
            $allocated = $riskCapital->multipliedBy($quantity)->dividedBy($initialQuantity, 24, RoundingMode::HalfEven);
            $dailyRisk[$day] = ($dailyRisk[$day] ?? BigDecimal::zero())->plus($allocated);
        }

        foreach ($fundings as $funding) {
            $position = $positionsById->get($funding->position_id);
            if ($position === null) {
                continue;
            }
            $day = CarbonImmutable::parse($funding->funding_at, 'UTC')->toDateString();
            $dailyNet[$day] = ($dailyNet[$day] ?? BigDecimal::zero())->plus(BigDecimal::of((string) ($funding->amount ?? '0')));
        }

        ksort($dailyNet);
        $series = [];
        $days = [];
        foreach ($dailyNet as $day => $net) {
            $risk = $dailyRisk[$day] ?? BigDecimal::zero();
            $days[$day] = [
                'realized_net_pnl' => (string) $net,
                'risk_capital_exited' => (string) $risk,
            ];
            if ($risk->isGreaterThan(BigDecimal::zero())) {
                $series[] = (float) (string) $net->dividedBy($risk, 8, RoundingMode::HalfEven);
            }
        }

        return ['series' => $series, 'fill_notional' => $fillNotional, 'days' => $days];
    }
}
