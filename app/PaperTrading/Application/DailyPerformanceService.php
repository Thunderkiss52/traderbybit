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
use DateTimeImmutable;
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
        $now = new DateTimeImmutable('now', $zone);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        [$selected, $total] = $this->snapshotDates($accountId, $zone, $page, $perPage);

        $days = array_map(fn (string $date): array => $this->day($account, $date, $zone, $symbol, $now), $selected);

        return [
            'account_id' => $account->id,
            'mode' => 'FORWARD_PAPER',
            'timezone' => $zone->getName(),
            'symbol' => $symbol ?? 'ALL',
            'summary' => [
                'today' => $this->period($account, $now->setTime(0, 0), $now, $symbol),
                'yesterday' => $this->period($account, $now->setTime(0, 0)->modify('-1 day'), $now->setTime(0, 0)->modify('-1 microsecond'), $symbol),
                'last_7_days' => $this->period($account, $now->setTime(0, 0)->modify('-6 days'), $now, $symbol),
                'last_30_days' => $this->period($account, $now->setTime(0, 0)->modify('-29 days'), $now, $symbol),
                'all_time' => $this->period($account, null, $now, $symbol),
            ],
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
        $base = EquitySnapshotRecord::query()->where('paper_account_id', $accountId)
            ->selectRaw($expression.' AS local_date', $bindings)->groupBy('local_date');
        $total = DB::query()->fromSub(clone $base, 'snapshot_days')->count();
        $dates = (clone $base)->orderByDesc('local_date')->offset(($page - 1) * $perPage)->limit($perPage)->pluck('local_date')->map(fn ($date): string => (string) $date)->all();

        return [$dates, $total];
    }

    private function day(PaperAccountRecord $account, string $date, DateTimeZone $zone, ?string $symbol, DateTimeImmutable $now): array
    {
        $start = new DateTimeImmutable($date.' 00:00:00', $zone);
        $end = $start->modify('+1 day')->modify('-1 microsecond');
        $period = $this->period($account, $start, $end > $now ? $now : $end, $symbol);

        return ['date' => $date] + $period;
    }

    private function period(PaperAccountRecord $account, ?DateTimeImmutable $localStart, DateTimeImmutable $localEnd, ?string $symbol): array
    {
        $startUtc = $localStart?->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $localEnd->setTimezone(new DateTimeZone('UTC'));
        $isAll = $symbol === null;
        $closing = $isAll ? $this->lastSnapshot($account->id, $endUtc) : null;
        $opening = $isAll && $startUtc !== null ? $this->lastSnapshot($account->id, $startUtc->modify('-1 microsecond')) : null;

        $baseEquity = $startUtc === null || $opening === null ? BigDecimal::of($account->starting_balance) : BigDecimal::of($opening->equity);
        $baseRealized = $opening === null ? BigDecimal::zero() : BigDecimal::of($opening->realized_pnl);
        $baseUnrealized = $opening === null ? BigDecimal::zero() : BigDecimal::of($opening->unrealized_pnl);
        $closeEquity = $closing === null ? null : BigDecimal::of($closing->equity);

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

        $realizedChange = $isAll && $closing !== null
            ? BigDecimal::of($closing->realized_pnl)->minus($baseRealized)
            : $grossRealized->minus($commissions)->plus($funding);
        $unrealizedChange = $isAll && $closing !== null
            ? BigDecimal::of($closing->unrealized_pnl)->minus($baseUnrealized)
            : null;
        $net = $closeEquity?->minus($baseEquity);
        $return = $net === null || $baseEquity->isZero() ? null : $net->dividedBy($baseEquity, 12, RoundingMode::HalfEven)->multipliedBy(100);
        $cumulative = $closeEquity === null || BigDecimal::of($account->starting_balance)->isZero()
            ? null
            : $closeEquity->minus($account->starting_balance)->dividedBy($account->starting_balance, 12, RoundingMode::HalfEven)->multipliedBy(100);

        $fresh = $closing !== null && max(0, $endUtc->getTimestamp() - $closing->recorded_at->getTimestamp()) <= self::CLOSE_FRESHNESS_SECONDS;
        $complete = $isAll && $closing !== null && $fresh;

        return [
            'net_pnl' => $net === null ? null : (string) $net,
            'return_percent' => $return === null ? null : (string) $return,
            'realized_pnl' => (string) $realizedChange,
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
                ! $isAll ? 'symbol_mark_to_market_history_unavailable' : null,
                $isAll && $closing !== null && ! $complete ? 'closing_equity_snapshot_not_near_period_end' : null,
            ])),
            'closing_snapshot_at' => $closing?->recorded_at?->toISOString(),
        ];
    }

    private function lastSnapshot(string $accountId, DateTimeImmutable $at): ?EquitySnapshotRecord
    {
        return EquitySnapshotRecord::query()->where('paper_account_id', $accountId)->where('recorded_at', '<=', $at)
            ->latest('recorded_at')->latest('id')->first(['id', 'recorded_at', 'equity', 'realized_pnl', 'unrealized_pnl']);
    }
}
