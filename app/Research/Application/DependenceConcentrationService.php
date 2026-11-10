<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Domain\Concentration\ConcentrationAnalyzer;
use App\Research\Domain\Concentration\CrossSymbolExposure;
use App\Research\Domain\Concentration\EquityAlignment;
use App\Research\Domain\Concentration\OverlapCluster;
use App\Research\Domain\Concentration\OverlapClusterBuilder;
use App\Research\Domain\Concentration\PositionInterval;
use App\Research\Domain\Concentration\PositionIntervalFactory;
use App\Research\Domain\ResearchConcentrationClusterMemberRecord;
use App\Research\Domain\ResearchConcentrationClusterRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * PROFIT-1 / P-5 — concentration, overlapping positions and dependence.
 *
 * Normative source: VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md §10 (§6 contracts).
 *
 * Design rules enforced here:
 *  - position intervals are half-open [opened_at, end_at) and an open position
 *    ends at the generation `as_of` (§10.1);
 *  - a partial close never ends the interval while a non-zero quantity
 *    remains (§10.1);
 *  - SAME_SIDE and OPPOSITE_SIDE clusters are distinct views; the
 *    opposite-side view is a bipartite overlap graph per symbol (§10.2);
 *  - no cross-symbol or cross-account ledger netting ever happens (§10.2/§10.5);
 *  - a cluster is a dependence group, not a set of independent observations;
 *  - missing or misaligned equity snapshots are visible and never become
 *    zero-return observations (§10.3);
 *  - a share of a non-positive total net PnL is NULL with an explicit reason
 *    (§10.4);
 *  - the paper ledger is read-only: research code never creates, merges or
 *    deletes paper accounts or positions (§3 safety).
 */
final class DependenceConcentrationService
{
    public function __construct(
        private readonly PositionIntervalFactory $intervalFactory = new PositionIntervalFactory,
    ) {}

    public const PACKAGE_CODE = 'P-5';

    public const METRIC_SCHEMA_VERSION = '1.1.0';

    public const ALGORITHM_VERSION = '1.1.0';

    public const SUPPORTED_SCOPES = ['FULL_LIFECYCLE', 'PIT_EVENT_TIME', 'PIT_KNOWN_AT', 'WINDOW_FLOW'];

    public const DEFAULT_MAX_CARRY_FORWARD_AGE_SECONDS = 86400;

    public const DEFAULT_ALIGNMENT_INTERVAL_SECONDS = 3600;

    public const MAX_STORED_EXPOSURE_POINTS = 500;

    public const SCALE = 12;

    /**
     * Retrieve the latest P-5 concentration/dependence generation.
     * Purely read-only; zero database writes.
     *
     * @return array<string, mixed>
     */
    public function getLatestConcentration(string $accountingScope = 'FULL_LIFECYCLE'): array
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
                'message' => 'No completed P-5 research generation exists yet for scope: '.$accountingScope,
                'provenance' => 'INSUFFICIENT_DATA',
            ];
        }

        return [
            'status' => $generation->status,
            'generation_id' => $generation->id,
            'accounting_scope' => $generation->accounting_scope,
            'as_of' => $generation->as_of?->toIso8601String(),
            'output_hash' => $generation->output_hash,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'cluster_definition_version' => OverlapClusterBuilder::CLUSTER_DEFINITION_VERSION,
            'versioned_config' => $generation->versioned_config,
            'row_counts' => $generation->row_counts,
            'metadata' => $generation->metadata,
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
        $accountingScope = strtoupper((string) ($parameters['accounting_scope'] ?? 'FULL_LIFECYCLE'));
        if (! in_array($accountingScope, self::SUPPORTED_SCOPES, true)) {
            throw new \InvalidArgumentException('Unsupported accounting scope ['.$accountingScope.'].');
        }

        $asOf = isset($parameters['as_of'])
            ? CarbonImmutable::parse($parameters['as_of'], 'UTC')
            : CarbonImmutable::now('UTC');
        $symbol = strtoupper((string) ($parameters['symbol'] ?? 'ALL'));
        $windowStart = isset($parameters['window_start']) ? CarbonImmutable::parse($parameters['window_start'], 'UTC') : null;
        $windowEnd = isset($parameters['window_end']) ? CarbonImmutable::parse($parameters['window_end'], 'UTC') : null;
        $exchange = $parameters['exchange'] ?? $parameters['exchange_id'] ?? null;
        $maxCarryForwardAge = (int) ($parameters['max_carry_forward_age_seconds'] ?? self::DEFAULT_MAX_CARRY_FORWARD_AGE_SECONDS);
        $alignmentInterval = (int) ($parameters['alignment_interval_seconds'] ?? self::DEFAULT_ALIGNMENT_INTERVAL_SECONDS);

        if ($accountingScope === 'WINDOW_FLOW' && ($windowStart === null || $windowEnd === null)) {
            throw new \InvalidArgumentException('WINDOW_FLOW scope requires both window_start and window_end.');
        }
        if ($windowStart !== null && $windowEnd !== null && $windowStart->greaterThanOrEqualTo($windowEnd)) {
            throw new \InvalidArgumentException('window_start must be strictly before window_end (half-open [start, end) interval).');
        }
        if ($maxCarryForwardAge < 0 || $alignmentInterval <= 0) {
            throw new \InvalidArgumentException('max_carry_forward_age_seconds must be >= 0 and alignment_interval_seconds must be > 0.');
        }

        $normalizedParams = [
            'package_code' => self::PACKAGE_CODE,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf->toIso8601String(),
            'symbol' => $symbol,
            'window_start' => $windowStart?->toIso8601String(),
            'window_end' => $windowEnd?->toIso8601String(),
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'alignment_interval_seconds' => $alignmentInterval,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'cluster_definition_version' => OverlapClusterBuilder::CLUSTER_DEFINITION_VERSION,
        ];
        if ($exchange !== null && $exchange !== 'all') {
            $normalizedParams['exchange'] = $exchange;
        }
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

        $generationId = Uuid::uuid4()->toString();
        $generation = ResearchGenerationRecord::query()->create([
            'id' => $generationId,
            'package_code' => self::PACKAGE_CODE,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'status' => 'RUNNING',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => $payloadHash,
            'versioned_config' => $normalizedParams,
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 300,
        ]);

        try {
            $result = $this->compute($generationId, $accountingScope, $asOf, $symbol, $windowStart, $windowEnd, $maxCarryForwardAge, $alignmentInterval, $exchange);

            $generation->update([
                'status' => $result['status'],
                'output_hash' => hash('sha256', json_encode($result['hash_payload'], JSON_THROW_ON_ERROR)),
                'row_counts' => $result['row_counts'],
                'metadata' => $result['metadata'],
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
     * @return array{status: string, row_counts: array<string, int>, metadata: array<string, mixed>, hash_payload: array<string, mixed>}
     */
    private function compute(
        string $generationId,
        string $accountingScope,
        CarbonImmutable $asOf,
        string $symbol,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        int $maxCarryForwardAge,
        int $alignmentInterval,
        ?string $exchange = null
    ): array {
        $builder = new OverlapClusterBuilder;
        $analyzer = new ConcentrationAnalyzer;

        $accounts = $this->primaryAccounts($exchange);
        $perAccount = [];
        $allIntervals = [];
        $exclusionReasons = [];
        foreach ($accounts as $account) {
            $intervals = $this->intervalsForAccount($account, $symbol, $asOf, $windowStart, $windowEnd, $accountingScope, $exclusionReasons);
            $perAccount[$account->id] = $intervals;
            foreach ($intervals as $interval) {
                $allIntervals[] = $interval;
            }
        }

        $views = [];
        $clusterRows = [];
        $memberRows = [];
        foreach ($perAccount as $accountId => $intervals) {
            $view = $this->buildView('ACCOUNT', (string) $accountId, $intervals, $builder, $analyzer);
            $views[] = $view['summary'];
            $this->collectRows($generationId, 'ACCOUNT', (string) $accountId, $view, $clusterRows, $memberRows);
        }
        $combined = $this->buildView('RESEARCH_COMBINED', null, $allIntervals, $builder, $analyzer);
        $views[] = $combined['summary'];
        $this->collectRows($generationId, 'RESEARCH_COMBINED', null, $combined, $clusterRows, $memberRows);

        $exposure = (new CrossSymbolExposure)->compute($allIntervals, $asOf->getTimestamp());
        $equity = $this->equityAlignment($accounts, $asOf, $windowStart, $alignmentInterval, $maxCarryForwardAge);

        $this->persistClusterRows($clusterRows, $memberRows);

        $status = $allIntervals === [] ? 'INSUFFICIENT_DATA' : 'SUCCEEDED';
        $reasons = [];
        if ($allIntervals === []) {
            $reasons[] = 'NO_ELIGIBLE_POSITIONS_IN_SCOPE';
        }
        if ($exclusionReasons !== []) {
            $reasons[] = 'POSITIONS_EXCLUDED';
        }

        $exposurePoints = $exposure['points'];
        $pointsTruncated = false;
        if (count($exposurePoints) > self::MAX_STORED_EXPOSURE_POINTS) {
            $exposurePoints = array_slice($exposurePoints, 0, self::MAX_STORED_EXPOSURE_POINTS);
            $pointsTruncated = true;
        }
        $exposureStored = $exposure;
        $exposureStored['points'] = $exposurePoints;
        $exposureStored['total_points'] = $exposure['grid_points'];
        $exposureStored['points_truncated'] = $pointsTruncated;

        $metadata = [
            'package_code' => self::PACKAGE_CODE,
            'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'cluster_definition_version' => OverlapClusterBuilder::CLUSTER_DEFINITION_VERSION,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf->toIso8601String(),
            'window_start' => $windowStart?->toIso8601String(),
            'window_end' => $windowEnd?->toIso8601String(),
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'alignment_interval_seconds' => $alignmentInterval,
            'primary_accounts_found' => $accounts->pluck('primary_strategy_id')->filter()->sort()->values()->all(),
            'primary_account_count' => $accounts->count(),
            'position_count' => count($allIntervals),
            'status' => $status,
            'status_reasons' => $reasons,
            'exclusion_reasons' => $exclusionReasons,
            'views' => $views,
            'cross_symbol_exposure' => $exposureStored,
            'equity_alignment' => $equity,
            'opposite_side_edges' => $combined['edges'],
            'netting_applied' => false,
            'independence_caveat' => 'A cluster is a dependence group, not a set of independent observations.',
        ];

        return [
            'status' => $status,
            'row_counts' => [
                'clusters' => count($clusterRows),
                'cluster_members' => count($memberRows),
                'positions' => count($allIntervals),
            ],
            'metadata' => $metadata,
            'hash_payload' => [
                'status' => $status,
                'row_counts' => $metadata === [] ? [] : [
                    'clusters' => count($clusterRows),
                    'cluster_members' => count($memberRows),
                    'positions' => count($allIntervals),
                ],
                'views' => array_map(static fn (array $v): array => [
                    'scope_code' => $v['scope_code'],
                    'paper_account_id' => $v['paper_account_id'],
                    'same_side' => $v['same_side_summary']['total_net_pnl'] ?? null,
                    'opposite_side' => $v['opposite_side_summary']['total_net_pnl'] ?? null,
                ], $views),
            ],
        ];
    }

    /**
     * @param  list<PositionInterval>  $intervals
     * @return array{summary: array<string, mixed>, clusters: list<OverlapCluster>, edges: list<array<string, mixed>>, same_side_summary: array<string, mixed>, opposite_side_summary: array<string, mixed>}
     */
    private function buildView(string $scopeCode, ?string $accountId, array $intervals, OverlapClusterBuilder $builder, ConcentrationAnalyzer $analyzer): array
    {
        $clusterScope = $scopeCode.($accountId !== null ? ':'.$accountId : '');
        $sameSideClusters = $builder->buildSameSide($intervals, $clusterScope);
        $opposite = $builder->buildOppositeSide($intervals, $clusterScope);
        $oppositeSideClusters = $opposite['clusters'];

        $sameSideSummary = $analyzer->summarize($sameSideClusters, $intervals);
        $oppositeSideSummary = $analyzer->summarize($oppositeSideClusters, $intervals);

        $summary = [
            'scope_code' => $scopeCode,
            'cluster_scope_code' => $clusterScope,
            'paper_account_id' => $accountId,
            'position_count' => count($intervals),
            'same_side_summary' => $sameSideSummary,
            'opposite_side_summary' => $oppositeSideSummary,
            'opposite_side_edge_count' => count($opposite['edges']),
        ];

        return [
            'summary' => $summary,
            'clusters' => array_merge($sameSideClusters, $oppositeSideClusters),
            'edges' => $opposite['edges'],
            'same_side_summary' => $sameSideSummary,
            'opposite_side_summary' => $oppositeSideSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $view
     * @param  list<array<string, mixed>>  $clusterRows
     * @param  list<array<string, mixed>>  $memberRows
     */
    private function collectRows(string $generationId, string $scopeCode, ?string $accountId, array $view, array &$clusterRows, array &$memberRows): void
    {
        foreach ($view['clusters'] as $cluster) {
            $clusterRowId = Uuid::uuid4()->toString();
            $clusterRows[] = [
                'id' => $clusterRowId,
                'generation_id' => $generationId,
                'view_code' => $cluster->viewCode,
                'scope_code' => $scopeCode,
                'paper_account_id' => $accountId,
                'symbol' => $cluster->symbol,
                'side_family' => $cluster->sideFamily,
                'cluster_id' => $cluster->clusterId,
                'member_count' => $cluster->memberCount(),
                'net_pnl' => $cluster->netPnl,
                'cluster_start_at' => $cluster->startAt,
                'cluster_end_at' => $cluster->endAt,
                'cluster_definition_version' => OverlapClusterBuilder::CLUSTER_DEFINITION_VERSION,
            ];

            foreach ($cluster->members as $member) {
                $memberRows[] = [
                    'id' => Uuid::uuid4()->toString(),
                    'cluster_row_id' => $clusterRowId,
                    'generation_id' => $generationId,
                    'cluster_id' => $cluster->clusterId,
                    'position_id' => $member->positionId,
                    'paper_account_id' => $member->accountId,
                    'symbol' => $member->symbol,
                    'side' => $member->side,
                    'opened_at' => $member->openedAt,
                    'ended_at' => $member->endAt,
                    'open_at_cutoff' => $member->openAtCutoff,
                    'partial_close_not_ending' => $member->partialCloseNotEnding,
                    'net_pnl' => $member->netPnl,
                    'unrealized_pnl' => $member->unrealizedPnl,
                    'entry_notional' => $member->entryNotional,
                ];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $clusterRows
     * @param  list<array<string, mixed>>  $memberRows
     */
    private function persistClusterRows(array $clusterRows, array $memberRows): void
    {
        foreach (array_chunk($clusterRows, 200) as $chunk) {
            ResearchConcentrationClusterRecord::query()->insert($chunk);
        }
        foreach (array_chunk($memberRows, 200) as $chunk) {
            ResearchConcentrationClusterMemberRecord::query()->insert($chunk);
        }
    }

    /**
     * @return Collection<int, PaperAccountRecord>
     */
    private function primaryAccounts(?string $exchange = null): Collection
    {
        return PaperAccountRecord::query()
            ->where('is_primary', true)
            ->when($exchange !== null && $exchange !== 'all', fn ($q) => $q->where('exchange_id', $exchange))
            ->whereNotNull('primary_strategy_id')
            ->orderBy('exchange_id')
            ->orderBy('primary_strategy_id')
            ->get();
    }

    /**
     * @param  array<string, int>  $exclusionReasons
     * @return list<PositionInterval>
     */
    private function intervalsForAccount(
        PaperAccountRecord $account,
        string $symbol,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        string $accountingScope,
        array &$exclusionReasons
    ): array {
        $query = VirtualPositionRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('opened_at', '<=', $asOf);

        if ($symbol !== 'ALL') {
            $query->where('symbol', $symbol);
        }

        if ($accountingScope === 'WINDOW_FLOW' && $windowStart !== null) {
            $query->where(function ($q) use ($windowStart): void {
                $q->whereNull('closed_at')->orWhere('closed_at', '>=', $windowStart);
            });
        }

        $intervals = [];
        foreach ($query->orderBy('opened_at')->get() as $position) {
            $openedAt = CarbonImmutable::instance($position->opened_at->toDateTimeImmutable());
            $closedAt = $position->closed_at !== null
                ? CarbonImmutable::instance($position->closed_at->toDateTimeImmutable())
                : null;
            if ($accountingScope === 'PIT_KNOWN_AT' && $position->last_processed_event_at !== null) {
                if ($position->last_processed_event_at->toDateTimeImmutable()->getTimestamp() > $asOf->getTimestamp()) {
                    $exclusionReasons['NOT_KNOWN_AT_CUTOFF'] = ($exclusionReasons['NOT_KNOWN_AT_CUTOFF'] ?? 0) + 1;

                    continue;
                }
            }

            $interval = $this->intervalFactory->make(
                positionId: (string) $position->id,
                accountId: (string) $account->id,
                symbol: (string) $position->symbol,
                side: (string) $position->side,
                openedAt: $openedAt,
                closedAt: $closedAt,
                initialQuantity: (string) ($position->initial_quantity ?? '0'),
                remainingQuantity: (string) ($position->remaining_quantity ?? '0'),
                netPnl: (string) ($position->net_pnl ?? '0'),
                unrealizedPnl: (string) ($position->unrealized_pnl ?? '0'),
                entryPrice: (string) ($position->entry_price ?? '0'),
                asOf: $asOf,
                windowEnd: $accountingScope === 'WINDOW_FLOW' ? $windowEnd : null,
            );

            if ($interval === null) {
                $exclusionReasons['EMPTY_INTERVAL'] = ($exclusionReasons['EMPTY_INTERVAL'] ?? 0) + 1;

                continue;
            }

            $intervals[] = $interval;
        }

        return $intervals;
    }

    /**
     * @param  Collection<int, PaperAccountRecord>  $accounts
     * @return array<string, mixed>
     */
    private function equityAlignment(
        Collection $accounts,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowStart,
        int $alignmentInterval,
        int $maxCarryForwardAge
    ): array {
        $series = [];
        foreach ($accounts as $account) {
            $query = EquitySnapshotRecord::query()->performance()
                ->where('paper_account_id', $account->id)
                ->where('recorded_at', '<=', $asOf)
                ->orderBy('recorded_at')->orderBy('id');
            if ($windowStart !== null) {
                $query->where('recorded_at', '>=', $windowStart);
            }
            $points = [];
            foreach ($query->get() as $snapshot) {
                $points[] = [
                    'ts' => $snapshot->recorded_at->toDateTimeImmutable()->getTimestamp(),
                    'equity' => (string) ($snapshot->equity ?? '0'),
                ];
            }
            if ($points !== []) {
                $series[(string) $account->id] = $points;
            }
        }

        $result = (new EquityAlignment)->align($series, $alignmentInterval, $maxCarryForwardAge, $asOf->getTimestamp());

        return [
            'status' => $result['status'],
            'reason' => $result['reason'] ?? null,
            'grid_points' => $result['grid_points'] ?? 0,
            'missing_points' => $result['missing_points'] ?? 0,
            'account_count' => $result['account_count'] ?? 0,
            'alignment_interval_seconds' => $alignmentInterval,
            'max_carry_forward_age_seconds' => $maxCarryForwardAge,
            'simultaneous_drawdown_periods' => $result['simultaneous_drawdown_periods'] ?? [],
            'interpolation_applied' => false,
            'zero_fill_applied' => false,
        ];
    }
}
