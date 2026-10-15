<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Domain\CandleQuery;

final class GapDetector
{
    /** @param list<int> $actualOpenTimesMs @return list<int> */
    public function missing(CandleQuery $query, array $actualOpenTimesMs, int $closedBeforeMs): array
    {
        $actual = array_fill_keys($actualOpenTimesMs, true);
        $step = $query->interval->milliseconds();
        $first = $query->interval->floor($query->fromMs);
        if ($first < $query->fromMs) {
            $first += $step;
        }
        $lastExclusive = min($query->toMs, $query->interval->floor($closedBeforeMs));
        $missing = [];

        for ($cursor = $first; $cursor < $lastExclusive; $cursor += $step) {
            if (! isset($actual[$cursor])) {
                $missing[] = $cursor;
            }
        }

        return $missing;
    }

    /** @param list<int> $missingOpenTimesMs @return list<array{0:int,1:int}> */
    public function contiguousRanges(array $missingOpenTimesMs, int $stepMs): array
    {
        if ($missingOpenTimesMs === []) {
            return [];
        }

        sort($missingOpenTimesMs, SORT_NUMERIC);
        $ranges = [];
        $start = $previous = $missingOpenTimesMs[0];

        foreach (array_slice($missingOpenTimesMs, 1) as $timestamp) {
            if ($timestamp !== $previous + $stepMs) {
                $ranges[] = [$start, $previous + $stepMs];
                $start = $timestamp;
            }
            $previous = $timestamp;
        }
        $ranges[] = [$start, $previous + $stepMs];

        return $ranges;
    }
}
