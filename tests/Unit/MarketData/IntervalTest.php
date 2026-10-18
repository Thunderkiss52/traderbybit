<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Domain\Interval;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IntervalTest extends TestCase
{
    public function test_all_supported_intervals_parse_correctly(): void
    {
        self::assertSame(Interval::OneMinute, Interval::parse('1'));
        self::assertSame(Interval::FiveMinutes, Interval::parse('5'));
        self::assertSame(Interval::FifteenMinutes, Interval::parse('15'));
        self::assertSame(Interval::ThirtyMinutes, Interval::parse('30'));
        self::assertSame(Interval::OneHour, Interval::parse('60'));
        self::assertSame(Interval::FourHours, Interval::parse('240'));
        self::assertSame(Interval::OneDay, Interval::parse('D'));
    }

    public function test_milliseconds_and_seconds_match_timeframe_durations(): void
    {
        self::assertSame(60_000, Interval::OneMinute->milliseconds());
        self::assertSame(60, Interval::OneMinute->seconds());

        self::assertSame(300_000, Interval::FiveMinutes->milliseconds());
        self::assertSame(300, Interval::FiveMinutes->seconds());

        self::assertSame(900_000, Interval::FifteenMinutes->milliseconds());
        self::assertSame(900, Interval::FifteenMinutes->seconds());

        self::assertSame(1_800_000, Interval::ThirtyMinutes->milliseconds());
        self::assertSame(1800, Interval::ThirtyMinutes->seconds());

        self::assertSame(3_600_000, Interval::OneHour->milliseconds());
        self::assertSame(3600, Interval::OneHour->seconds());

        self::assertSame(14_400_000, Interval::FourHours->milliseconds());
        self::assertSame(14400, Interval::FourHours->seconds());

        self::assertSame(86_400_000, Interval::OneDay->milliseconds());
        self::assertSame(86400, Interval::OneDay->seconds());
    }

    public function test_floor_aligns_to_interval_boundaries(): void
    {
        // 1700000045000 ms -> floor on 1m
        $ms = 1700000045000;
        self::assertSame(1700000040000, Interval::OneMinute->floor($ms));

        // floor on 15m (900_000 ms)
        self::assertSame(1699999200000, Interval::FifteenMinutes->floor($ms));
    }

    public function test_unsupported_interval_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Interval::parse('999');
    }
}
