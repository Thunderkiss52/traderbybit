<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DailyPerformanceService
{
    private const CLOSE_FRESHNESS_SECONDS = 900;

    public function report(string $accountId, string $timezone, ?string $symbol, int $page, int $perPage): array
    {
        $account = PaperAccountRecord::query()->findOrFail($accountId);
        $zone = new DateTimeZone($timezone);
        $now = CarbonImmutable::now($zone);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        [$selected, $total] = $this->snapshotDates($accountId, $zone, $page, $perPage);

        $definitions = $this->definitions($now, $selected);
        $periods = $this->periods($account, $definitions, $symbol);

        $summary = [];
        foreach (['today', 'yesterday', 'last_7_days', 'last_30_days', 'all_time'] as $key) {
            $summary[$key] = $periods[$key];
        }
        $days = [];
        foreach ($selected as $date) {
            $days[] = ['date' => $date] + $periods['day:'.$date];
        }

        return [
            'account_id' => $account->id,
            'mode' => 'FORWARD_PAPER',
            'timezone' => $zone->getName(),
            'symbol' => $symbol ?? 'ALL',
            'summary' => $summary,
            'days' => [
                'data' => $days,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ],
            'data_quality' => [
                'uses_equity_snapshots' => $symbol === null,
                'cash_flow_adjustments_supported' => false,
                'limitations' => array_values(array_filter([
                    null, // No external cash-flow operations exist in Paper Trading.
                    $symbol === null ? null : 'historical_symbol_mark_to_market_snapshots_unavailable',
                ])),
            ],
        ];
    }

    /** Daily CSV and UI share calendar boundaries and completeness semantics. */
    public function calendarDays(string $accountId, string $timezone, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = PaperAccountRecord::query()->findOrFail($accountId);
        $dates = [];
        for ($day = $from->setTimezone($timezone)->startOfDay(); $day < $to; $day = $day->addDay()) {
            if (count($dates) >= 366) {
                throw new \InvalidArgumentException('Daily export exceeds 366 calendar days.');
            }
            $dates[] = $day->format('Y-m-d');
        }
        $definitions = array_values(array_filter($this->definitions($to->subMicrosecond()->setTimezone($timezone), $dates), fn (array $row): bool => str_starts_with($row['key'], 'day:')));
        $periods = $this->periods($account, $definitions, null);

        return array_map(fn (string $date): array => ['date' => $date, 'timezone' => $timezone] + $periods['day:'.$date], $dates);
    }

    /** @return array{list<string>, int} Newest local dates and exact day count. */
    private function snapshotDates(string $accountId, DateTimeZone $zone, int $page, int $perPage): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $expression = '(recorded_at AT TIME ZONE ?)::date';
            $bindings = [$zone->getName()];
        } else {
            $offset = (new DateTimeImmutable('now', $zone))->getOffset();
            $modifier = sprintf('%+d seconds', $offset);
            $expression = 'date(recorded_at, ?)';
            $bindings = [$modifier];
        }
        $base = EquitySnapshotRecord::query()->performance()->where('paper_account_id', $accountId)
            ->selectRaw($expression.' AS local_date', $bindings)->groupBy('local_date');
        $total = DB::query()->fromSub(clone $base, 'snapshot_days')->count();
        $dates = (clone $base)->orderByDesc('local_date')->offset(($page - 1) * $perPage)->limit($perPage)->pluck('local_date')->map(fn ($date): string => (string) $date)->all();

        return [$dates, $total];
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{key: string, start: ?DateTimeImmutable, end: DateTimeImmutable}>
     */
    private function definitions(DateTimeImmutable $now, array $dates): array
    {
        $today = $now->setTime(0, 0);
        $definitions = [
            ['key' => 'today', 'start' => $today, 'end' => $now],
            ['key' => 'yesterday', 'start' => $today->modify('-1 day'), 'end' => $today->modify('-1 microsecond')],
            ['key' => 'last_7_days', 'start' => $today->modify('-6 days'), 'end' => $now],
            ['key' => 'last_30_days', 'start' => $today->modify('-29 days'), 'end' => $now],
            ['key' => 'all_time', 'start' => null, 'end' => $now],
        ];

        foreach ($dates as $date) {
            $start = new DateTimeImmutable($date.' 00:00:00', $now->getTimezone());
            $end = $start->modify('+1 day')->modify('-1 microsecond');
            $definitions[] = ['key' => 'day:'.$date, 'start' => $start, 'end' => $end > $now ? $now : $end];
        }

        return array_map(static fn (array $definition): array => [
            'key' => $definition['key'],
            'start' => $definition['start']?->setTimezone(new DateTimeZone('UTC')),
            'end' => $definition['end']->setTimezone(new DateTimeZone('UTC')),
        ], $definitions);
    }

    /**
     * @param  list<array{key: string, start: ?DateTimeImmutable, end: DateTimeImmutable}>  $definitions
     * @return array<string, array<string, mixed>>
     */
    private function periods(PaperAccountRecord $account, array $definitions, ?string $symbol): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $result = [];
            foreach ($definitions as $definition) {
                $result[$definition['key']] = $this->period($account, $definition['start'], $definition['end'], $symbol);
            }

            return $result;
        }

        $metrics = $this->postgresMetrics($account->id, $definitions, $symbol);
        $snapshots = $this->postgresSnapshots($account->id, $definitions);

        $result = [];
        foreach ($definitions as $index => $definition) {
            $wholeAccount = $symbol === null;
            $opening = $wholeAccount && $definition['start'] !== null ? ($snapshots[$index.':open'] ?? null) : null;
            $closing = $wholeAccount ? ($snapshots[$index.':close'] ?? null) : null;
            $result[$definition['key']] = $this->formatPeriod(
                $account,
                $definition['start'],
                $definition['end'],
                $symbol,
                $opening,
                $closing,
                $metrics[$index],
            );
        }

        return $result;
    }

    private function period(PaperAccountRecord $account, ?DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, ?string $symbol): array
    {
        $isAll = $symbol === null;
        $closing = $isAll ? $this->snapshotData($this->lastSnapshot($account->id, $endUtc)) : null;
        $opening = $isAll && $startUtc !== null ? $this->snapshotData($this->lastSnapshot($account->id, $startUtc->modify('-1 microsecond'))) : null;

        $positions = VirtualPositionRecord::query()->where('paper_account_id', $account->id);
        if ($symbol !== null) {
            $positions->where('symbol', $symbol);
        }
        $opened = (clone $positions)->when($startUtc, fn (Builder $q) => $q->where('opened_at', '>=', $startUtc))->where('opened_at', '<=', $endUtc)->count();
        $closedQuery = (clone $positions)->whereNotNull('closed_at')->when($startUtc, fn (Builder $q) => $q->where('closed_at', '>=', $startUtc))->where('closed_at', '<=', $endUtc);
        $closed = $closedQuery->count();
        $wins = (clone $closedQuery)->where('net_pnl', '>', 0)->count();
        $averageR = (clone $closedQuery)->whereNotNull('r_multiple')->avg('r_multiple');

        $executionQuery = VirtualExecutionRecord::query()->join('virtual_positions', 'virtual_positions.id', '=', 'virtual_executions.position_id')
            ->where('virtual_positions.paper_account_id', $account->id)
            ->when($symbol, fn ($q) => $q->where('virtual_positions.symbol', $symbol))
            ->when($startUtc, fn ($q) => $q->where('virtual_executions.market_event_at', '>=', $startUtc))
            ->where('virtual_executions.market_event_at', '<=', $endUtc);
        $commissions = BigDecimal::of((string) ((clone $executionQuery)->sum('virtual_executions.commission') ?: '0'));
        $grossRealized = BigDecimal::of((string) ((clone $executionQuery)->sum('virtual_executions.gross_pnl') ?: '0'));

        $fundingQuery = PositionFundingEventRecord::query()->join('virtual_positions', 'virtual_positions.id', '=', 'position_funding_events.position_id')
            ->where('virtual_positions.paper_account_id', $account->id)
            ->when($symbol, fn ($q) => $q->where('virtual_positions.symbol', $symbol))
            ->when($startUtc, fn ($q) => $q->where('position_funding_events.funding_at', '>=', $startUtc))
            ->where('position_funding_events.funding_at', '<=', $endUtc);
        $funding = BigDecimal::of((string) ($fundingQuery->sum('position_funding_events.amount') ?: '0'));

        return $this->formatPeriod($account, $startUtc, $endUtc, $symbol, $opening, $closing, [
            'opened_count' => $opened,
            'closed_count' => $closed,
            'win_count' => $wins,
            'average_r' => $averageR,
            'commissions' => (string) $commissions,
            'gross_realized' => (string) $grossRealized,
            'funding' => (string) $funding,
        ]);
    }

    /**
     * @param  list<array{key: string, start: ?DateTimeImmutable, end: DateTimeImmutable}>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function postgresMetrics(string $accountId, array $definitions, ?string $symbol): array
    {
        $values = [];
        $bindings = [];
        foreach ($definitions as $index => $definition) {
            $values[] = '(?::int, ?::timestamptz, ?::timestamptz)';
            $bindings[] = $index;
            $bindings[] = $definition['start']?->format('Y-m-d H:i:s.uP');
            $bindings[] = $definition['end']->format('Y-m-d H:i:s.uP');
        }
        array_push($bindings, $accountId, $symbol);

        $mutual = '(s.symbol IS NULL OR vp.symbol = s.symbol)';
        $opened = '(p.start_at IS NULL OR vp.opened_at >= p.start_at) AND vp.opened_at <= p.end_at';
        $closed = 'vp.closed_at IS NOT NULL AND (p.start_at IS NULL OR vp.closed_at >= p.start_at) AND vp.closed_at <= p.end_at';
        $execution = "vp.paper_account_id = s.account_id AND {$mutual} AND (p.start_at IS NULL OR ve.market_event_at >= p.start_at) AND ve.market_event_at <= p.end_at";
        $funding = "vp.paper_account_id = s.account_id AND {$mutual} AND (p.start_at IS NULL OR pfe.funding_at >= p.start_at) AND pfe.funding_at <= p.end_at";

        $sql = 'WITH periods(idx, start_at, end_at) AS (VALUES '.implode(', ', $values).'), '
            .'scope AS (SELECT ?::uuid AS account_id, ?::text AS symbol) '
            .'SELECT p.idx, '
            ."(SELECT count(*) FROM virtual_positions vp WHERE vp.paper_account_id = s.account_id AND {$mutual} AND {$opened}) AS opened_count, "
            ."(SELECT count(*) FROM virtual_positions vp WHERE vp.paper_account_id = s.account_id AND {$mutual} AND {$closed}) AS closed_count, "
            ."(SELECT count(*) FROM virtual_positions vp WHERE vp.paper_account_id = s.account_id AND {$mutual} AND {$closed} AND vp.net_pnl > 0) AS win_count, "
            ."(SELECT avg(vp.r_multiple) FROM virtual_positions vp WHERE vp.paper_account_id = s.account_id AND {$mutual} AND {$closed} AND vp.r_multiple IS NOT NULL) AS average_r, "
            .'(SELECT COALESCE(sum(ve.commission), 0) FROM virtual_executions ve JOIN virtual_positions vp ON vp.id = ve.position_id WHERE '.$execution.') AS commissions, '
            .'(SELECT COALESCE(sum(ve.gross_pnl), 0) FROM virtual_executions ve JOIN virtual_positions vp ON vp.id = ve.position_id WHERE '.$execution.') AS gross_realized, '
            ."(SELECT COALESCE(sum(pfe.amount), 0) FROM position_funding_events pfe JOIN virtual_positions vp ON vp.id = pfe.position_id WHERE {$funding}) AS funding "
            .'FROM periods p CROSS JOIN scope s ORDER BY p.idx';

        $rows = DB::select($sql, $bindings);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->idx] = [
                'opened_count' => (int) $row->opened_count,
                'closed_count' => (int) $row->closed_count,
                'win_count' => (int) $row->win_count,
                'average_r' => $row->average_r === null ? null : (string) $row->average_r,
                'commissions' => (string) $row->commissions,
                'gross_realized' => (string) $row->gross_realized,
                'funding' => (string) $row->funding,
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{key: string, start: ?DateTimeImmutable, end: DateTimeImmutable}>  $definitions
     * @return array<string, array{equity: string, realized_pnl: string, unrealized_pnl: string, recorded_at: DateTimeImmutable}>
     */
    private function postgresSnapshots(string $accountId, array $definitions): array
    {
        $values = [];
        $bindings = [];
        foreach ($definitions as $index => $definition) {
            $values[] = '(?::text, ?::timestamptz)';
            $bindings[] = $index.':close';
            $bindings[] = $definition['end']->format('Y-m-d H:i:s.uP');
            if ($definition['start'] !== null) {
                $values[] = '(?::text, ?::timestamptz)';
                $bindings[] = $index.':open';
                $bindings[] = $definition['start']->modify('-1 microsecond')->format('Y-m-d H:i:s.uP');
            }
        }
        array_push($bindings, $accountId);

        $sql = 'WITH boundaries(idx, at) AS (VALUES '.implode(', ', $values).') '
            .'SELECT b.idx, s.id, s.recorded_at, s.equity, s.realized_pnl, s.unrealized_pnl, s.valued_through, s.valuation_fresh '
            .'FROM boundaries b LEFT JOIN LATERAL ('
            .'SELECT es.id, es.recorded_at, es.equity, es.realized_pnl, es.unrealized_pnl, es.valued_through, es.valuation_fresh '
            .'FROM equity_snapshots es WHERE es.paper_account_id = ?::uuid AND es.recorded_at <= b.at '
            .'AND '.EquitySnapshotRecord::performancePredicate('es').' '
            .'ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1'
            .') s ON true';
        $rows = DB::select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            if ($row->id === null) {
                continue;
            }
            $result[(string) $row->idx] = $this->snapshotData($row);
        }

        return $result;
    }

    /**
     * @param  array{equity: string, realized_pnl: string, unrealized_pnl: string, recorded_at: DateTimeImmutable}|null  $opening
     * @param  array{equity: string, realized_pnl: string, unrealized_pnl: string, recorded_at: DateTimeImmutable}|null  $closing
     * @param  array<string, mixed>  $metrics
     */
    private function formatPeriod(
        PaperAccountRecord $account,
        ?DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        ?string $symbol,
        ?array $opening,
        ?array $closing,
        array $metrics,
    ): array {
        $isAll = $symbol === null;
        $inceptionBoundary = $startUtc === null || ($account->created_at !== null && $account->created_at >= $startUtc && $account->created_at <= $endUtc);
        $hasOpening = $opening !== null || $inceptionBoundary;
        $baseEquity = $startUtc === null || $opening === null ? BigDecimal::of($account->starting_balance) : BigDecimal::of($opening['equity']);
        $baseRealized = $opening === null ? BigDecimal::zero() : BigDecimal::of($opening['realized_pnl']);
        $baseUnrealized = $opening === null ? BigDecimal::zero() : BigDecimal::of($opening['unrealized_pnl']);
        $closeEquity = $closing === null ? null : BigDecimal::of($closing['equity']);

        $opened = (int) $metrics['opened_count'];
        $closed = (int) $metrics['closed_count'];
        $wins = (int) $metrics['win_count'];
        $averageR = $metrics['average_r'];
        $commissions = BigDecimal::of((string) $metrics['commissions']);
        $grossRealized = BigDecimal::of((string) $metrics['gross_realized']);
        $funding = BigDecimal::of((string) $metrics['funding']);

        $realizedChange = $isAll
            ? ($closing !== null && $hasOpening ? BigDecimal::of($closing['realized_pnl'])->minus($baseRealized) : null)
            : $grossRealized->minus($commissions)->plus($funding);
        $unrealizedChange = $isAll && $closing !== null && $hasOpening
            ? BigDecimal::of($closing['unrealized_pnl'])->minus($baseUnrealized)
            : null;
        $net = $hasOpening ? $closeEquity?->minus($baseEquity) : null;
        $return = $net === null || $baseEquity->isZero() ? null : $net->dividedBy($baseEquity, 12, RoundingMode::HalfEven)->multipliedBy(100);
        $cumulative = $closeEquity === null || BigDecimal::of($account->starting_balance)->isZero()
            ? null
            : $closeEquity->minus($account->starting_balance)->dividedBy($account->starting_balance, 12, RoundingMode::HalfEven)->multipliedBy(100);

        $fresh = $closing !== null && max(0, $endUtc->getTimestamp() - $closing['recorded_at']->getTimestamp()) <= self::CLOSE_FRESHNESS_SECONDS;
        $valuationFresh = $closing['valuation_fresh'] ?? null;
        if ($valuationFresh === false) {
            $fresh = false;
        }
        $complete = $isAll && $hasOpening && $closing !== null && $fresh;

        $valuationStatus = match (true) {
            $closing === null => 'MISSING',
            $valuationFresh === false => 'STALE',
            BigDecimal::of($closing['unrealized_pnl'])->isZero() => 'FLAT_VERIFIED',
            $valuationFresh === true => 'FRESH',
            default => 'LEGACY_UNKNOWN',
        };

        return [
            'net_pnl' => $net === null ? null : (string) $net,
            'return_percent' => $return === null ? null : (string) $return,
            'realized_pnl' => $realizedChange === null ? null : (string) $realizedChange,
            'unrealized_pnl_change' => $unrealizedChange === null ? null : (string) $unrealizedChange,
            'commissions' => (string) $commissions,
            'funding' => (string) $funding,
            'external_cash_flows' => null,
            'closing_equity' => $closeEquity === null ? null : (string) $closeEquity,
            'cumulative_return_percent' => $cumulative === null ? null : (string) $cumulative,
            'opened_positions' => $opened,
            'closed_positions' => $closed,
            'win_rate' => $closed === 0 ? null : number_format($wins * 100 / $closed, 8, '.', ''),
            'average_r' => $averageR === null ? null : (string) $averageR,
            'complete' => $complete,
            'incomplete_reasons' => array_values(array_filter([
                $isAll && $closing === null ? 'equity_snapshot_unavailable' : null,
                $isAll && ! $hasOpening ? 'opening_snapshot_unavailable' : null,
                ! $isAll ? 'symbol_mark_to_market_history_unavailable' : null,
                $isAll && $closing !== null && ! $fresh ? 'closing_equity_snapshot_not_near_period_end' : null,
                $isAll && $closing !== null && $valuationFresh === false ? 'open_positions_market_valuation_stale' : null,
            ])),
            'valuation_status' => $valuationStatus,
            'valuation_fresh' => $valuationFresh,
            'opening_boundary' => ! $isAll ? 'UNAVAILABLE' : ($opening !== null ? 'SNAPSHOT' : ($inceptionBoundary ? 'ACCOUNT_INCEPTION' : 'UNAVAILABLE')),
            'opening_snapshot_at' => $opening === null ? null : $opening['recorded_at']->format(DATE_ATOM),
            'closing_snapshot_at' => $closing === null ? null : $closing['recorded_at']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function lastSnapshot(string $accountId, DateTimeImmutable $at): ?EquitySnapshotRecord
    {
        return EquitySnapshotRecord::query()->performance()->where('paper_account_id', $accountId)->where('recorded_at', '<=', $at)
            ->latest('recorded_at')->latest('id')->first(['id', 'recorded_at', 'equity', 'realized_pnl', 'unrealized_pnl', 'valued_through', 'valuation_fresh']);
    }

    /**
     * @return array{equity: string, realized_pnl: string, unrealized_pnl: string, recorded_at: DateTimeImmutable, valued_through: ?DateTimeImmutable, valuation_fresh: ?bool}|null
     */
    private function snapshotData(?object $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $recordedAt = $snapshot->recorded_at;
        if (! $recordedAt instanceof DateTimeInterface) {
            $recordedAt = new DateTimeImmutable((string) $recordedAt);
        }

        return [
            'equity' => (string) $snapshot->equity,
            'realized_pnl' => (string) $snapshot->realized_pnl,
            'unrealized_pnl' => (string) $snapshot->unrealized_pnl,
            'recorded_at' => new DateTimeImmutable($recordedAt->format('Y-m-d H:i:s.uP')),
            'valued_through' => isset($snapshot->valued_through) && $snapshot->valued_through !== null
                ? new DateTimeImmutable((string) $snapshot->valued_through)
                : null,
            'valuation_fresh' => isset($snapshot->valuation_fresh) && $snapshot->valuation_fresh !== null ? (bool) $snapshot->valuation_fresh : null,
        ];
    }
}
