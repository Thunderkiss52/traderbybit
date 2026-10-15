<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Application\GapDetector;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use PHPUnit\Framework\TestCase;

final class GapDetectorTest extends TestCase
{
    public function test_it_detects_missing_closed_slots_and_groups_ranges(): void
    {
        $query = new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 0, 1_500_000);
        $detector = new GapDetector;

        $missing = $detector->missing($query, [0, 600_000, 1_200_000], 1_500_000);

        self::assertSame([300_000, 900_000], $missing);
        self::assertSame([[300_000, 600_000], [900_000, 1_200_000]], $detector->contiguousRanges($missing, 300_000));
    }

    public function test_it_never_marks_the_current_open_candle_as_a_gap(): void
    {
        $query = new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 0, 900_000);

        self::assertSame([], (new GapDetector)->missing($query, [0], 450_000));
    }
}
