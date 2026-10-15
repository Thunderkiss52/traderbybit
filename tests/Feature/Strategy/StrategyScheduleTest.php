<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

final class StrategyScheduleTest extends TestCase
{
    public function test_market_context_is_captured_before_candle_close_and_signal_generation(): void
    {
        $events = collect(Schedule::events());
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $capture = $events->first(fn ($event): bool => $event->getSummaryForDisplay() === "market-context:capture:{$symbol}");
            $signal = $events->first(fn ($event): bool => $event->getSummaryForDisplay() === "signals:generate:{$symbol}");
            self::assertNotNull($capture);
            self::assertNotNull($signal);
            self::assertSame('13,28,43,58 * * * *', $capture->expression);
            self::assertSame('2,17,32,47 * * * *', $signal->expression);
        }
        self::assertNotNull($events->first(fn ($event): bool => $event->getSummaryForDisplay() === 'market:ensure-strategy-history'));
    }
}
