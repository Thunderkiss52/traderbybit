<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinanceCandleWindow;
use PHPUnit\Framework\TestCase;

final class BinanceCandleWindowTest extends TestCase
{
    private const INTERVAL = 300;

    public function test_contiguous_closed_history_is_ready(): void
    {
        $now = 1_800_000_000;
        $times = self::window($now);
        self::assertSame('READY', BinanceCandleWindow::status($times, self::INTERVAL, $now));
        self::assertSame('READY', BinanceCandleWindow::status(self::window($now, 600), self::INTERVAL, $now));
    }

    public function test_unclosed_or_future_dated_bars_are_rejected(): void
    {
        $now = 1_800_000_000;
        self::assertSame('CANDLE_HISTORY_STALE_OR_FUTURE', BinanceCandleWindow::status(self::window($now, 0), 300, $now));
        self::assertSame('CANDLE_HISTORY_STALE_OR_FUTURE', BinanceCandleWindow::status(self::window($now, -300), 300, $now));
        self::assertSame('CANDLE_HISTORY_STALE_OR_FUTURE', BinanceCandleWindow::status(self::window($now, 1200), 300, $now));
    }

    public function test_gaps_and_duplicate_timestamps_fail_closed(): void
    {
        $now = 1_800_000_000;
        $gapped = self::window($now);
        $gapped[14] -= 300;
        self::assertSame('CANDLE_HISTORY_GAPPED', BinanceCandleWindow::status($gapped, 300, $now));

        $duplicate = self::window($now);
        $duplicate[14] = $duplicate[13];
        self::assertSame('CANDLE_HISTORY_GAPPED', BinanceCandleWindow::status($duplicate, 300, $now));

        $untrusted = self::window($now);
        $untrusted[12] = '1800000000';
        self::assertSame('CANDLE_HISTORY_INVALID_TIMESTAMP', BinanceCandleWindow::status($untrusted, 300, $now));
    }

    public function test_off_grid_but_contiguous_candles_are_not_considered_ready(): void
    {
        $now = 1_800_000_000;
        $times = array_map(static fn (int $ts): int => $ts + 60, self::window($now));
        self::assertSame('CANDLE_HISTORY_MISALIGNED', BinanceCandleWindow::status($times, 300, $now));
    }

    public function test_219_candles_never_satisfy_220_candle_requirement(): void
    {
        $now = 1_800_000_000;
        $times = self::window($now);
        array_pop($times);
        self::assertSame('CANDLE_HISTORY_INSUFFICIENT', BinanceCandleWindow::status($times, 300, $now));
    }

    private static function window(int $now, int $age = 300): array
    {
        return array_map(static fn (int $i): int => $now - $age - ($i * self::INTERVAL), range(0, 219));
    }
}
