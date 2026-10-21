<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — equity synchronization and simultaneous drawdown (§10.3).
 *
 * Rules:
 *  - a common UTC grid is used only when the alignment is deterministic;
 *  - a carry-forward value is allowed only inside a fixed maximum age that is
 *    recorded in the generation configuration;
 *  - missing snapshots are never interpolated and never replaced by zero;
 *  - when the alignment is not reliable the cross-account comparison is
 *    reported as NOT_COMPARABLE instead of fabricated synchronization;
 *  - running peak (drawdown reference) is causal: it only uses observations up
 *    to the grid point.
 */
final class EquityAlignment
{
    public const STATUS_COMPARABLE = 'COMPARABLE';

    public const STATUS_NOT_COMPARABLE = 'NOT_COMPARABLE';

    public const REASON_MISSING_SNAPSHOTS = 'MISSING_SNAPSHOTS_BEYOND_MAX_CARRY_FORWARD_AGE';

    public const REASON_NO_SERIES = 'NO_EQUITY_SNAPSHOTS';

    public const REASON_SINGLE_ACCOUNT = 'SINGLE_ACCOUNT_NO_CROSS_ACCOUNT_COMPARISON';

    public const SCALE = 12;

    /**
     * @param  array<string, list<array{ts: int, equity: string}>>  $seriesByAccount
     * @return array<string, mixed>
     */
    public function align(array $seriesByAccount, int $gridIntervalSeconds, int $maxCarryForwardAgeSeconds, int $asOfTimestamp): array
    {
        ksort($seriesByAccount);
        $accountIds = array_keys($seriesByAccount);

        if ($accountIds === []) {
            return $this->notComparable(self::REASON_NO_SERIES, 0, 0, []);
        }
        if (count($accountIds) < 2) {
            return $this->notComparable(self::REASON_SINGLE_ACCOUNT, 0, 0, []);
        }

        $sorted = [];
        $minTs = null;
        $maxTs = null;
        foreach ($accountIds as $accountId) {
            $points = $seriesByAccount[$accountId];
            usort($points, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
            $sorted[$accountId] = $points;
            foreach ($points as $point) {
                $minTs = $minTs === null ? $point['ts'] : min($minTs, $point['ts']);
                $maxTs = $maxTs === null ? $point['ts'] : max($maxTs, $point['ts']);
            }
        }
        $minTs ??= 0;
        $maxTs = min((int) $maxTs, $asOfTimestamp);

        $gridStart = intdiv($minTs, $gridIntervalSeconds) * $gridIntervalSeconds;
        $gridEnd = intdiv($maxTs, $gridIntervalSeconds) * $gridIntervalSeconds;

        $grid = [];
        for ($ts = $gridStart; $ts <= $gridEnd; $ts += $gridIntervalSeconds) {
            $grid[] = $ts;
        }

        $missing = 0;
        $aligned = [];
        $peaks = [];
        foreach ($accountIds as $accountId) {
            $peaks[$accountId] = null;
        }

        foreach ($grid as $ts) {
            $row = [];
            $inDrawdown = [];
            foreach ($accountIds as $accountId) {
                $latest = $this->latestAtOrBefore($sorted[$accountId], $ts);
                if ($latest === null) {
                    $missing++;
                    $row[$accountId] = null;

                    continue;
                }
                $age = $ts - $latest['ts'];
                if ($age > $maxCarryForwardAgeSeconds) {
                    $missing++;
                    $row[$accountId] = null;

                    continue;
                }
                $equity = BigDecimal::of($latest['equity']);
                $peak = $peaks[$accountId];
                if ($peak === null || $equity->isGreaterThan($peak)) {
                    $peak = $equity;
                    $peaks[$accountId] = $peak;
                }
                $isDrawdown = $equity->isLessThan($peak);
                $inDrawdown[$accountId] = $isDrawdown;
                $row[$accountId] = [
                    'equity' => $equity->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                    'peak' => $peak->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                    'age_seconds' => $age,
                    'in_drawdown' => $isDrawdown,
                ];
            }
            $drawdownAccounts = array_keys(array_filter($inDrawdown, static fn (bool $v): bool => $v));
            $aligned[$ts] = [
                'values' => $row,
                'drawdown_accounts' => $drawdownAccounts,
            ];
        }

        $periods = $this->simultaneousDrawdownPeriods($grid, $aligned, 2);

        if ($missing > 0) {
            return $this->notComparable(self::REASON_MISSING_SNAPSHOTS, count($grid), $missing, $periods, $aligned, $grid);
        }

        return [
            'status' => self::STATUS_COMPARABLE,
            'reason' => null,
            'grid_points' => count($grid),
            'missing_points' => 0,
            'grid_interval_seconds' => $gridIntervalSeconds,
            'max_carry_forward_age_seconds' => $maxCarryForwardAgeSeconds,
            'account_count' => count($accountIds),
            'aligned' => $aligned,
            'simultaneous_drawdown_periods' => $periods,
        ];
    }

    /**
     * @param  list<array{ts: int, equity: string}>  $points
     * @return array{ts: int, equity: string}|null
     */
    private function latestAtOrBefore(array $points, int $ts): ?array
    {
        $found = null;
        foreach ($points as $point) {
            if ($point['ts'] <= $ts) {
                $found = $point;
            } else {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $grid
     * @param  array<int, array{values: array<string, mixed>, drawdown_accounts: list<string>}>  $aligned
     * @return list<array<string, mixed>>
     */
    private function simultaneousDrawdownPeriods(array $grid, array $aligned, int $minAccounts): array
    {
        $periods = [];
        $current = null;
        foreach ($grid as $ts) {
            $accounts = $aligned[$ts]['drawdown_accounts'];
            if (count($accounts) >= $minAccounts) {
                if ($current === null) {
                    $current = [
                        'start_at' => CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String(),
                        'end_at' => CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String(),
                        'max_concurrent_accounts' => count($accounts),
                        'accounts' => $accounts,
                    ];
                } else {
                    $current['end_at'] = CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String();
                    $current['max_concurrent_accounts'] = max($current['max_concurrent_accounts'], count($accounts));
                    $current['accounts'] = array_values(array_unique(array_merge($current['accounts'], $accounts)));
                }
            } elseif ($current !== null) {
                $periods[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $periods[] = $current;
        }

        return $periods;
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @param  array<int, mixed>  $aligned
     * @param  list<int>  $grid
     * @return array<string, mixed>
     */
    private function notComparable(string $reason, int $gridPoints, int $missing, array $periods, array $aligned = [], array $grid = []): array
    {
        return [
            'status' => self::STATUS_NOT_COMPARABLE,
            'reason' => $reason,
            'grid_points' => $gridPoints,
            'missing_points' => $missing,
            'account_count' => 0,
            'aligned' => $aligned,
            'simultaneous_drawdown_periods' => $periods,
        ];
    }
}
