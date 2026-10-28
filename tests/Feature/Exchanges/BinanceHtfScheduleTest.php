<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;
use Tests\TestCase;

final class BinanceHtfScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_failing_fifteen_minute_scope_does_not_prevent_other_scheduled_intervals(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => function ($request) {
            if ($request['symbol'] === 'BTCUSDT' && $request['interval'] === '15m') {
                return Http::response(['malformed' => true]);
            }
            $duration = ['5m' => 300000, '15m' => 900000, '30m' => 1800000, '1h' => 3600000, '4h' => 14400000][$request['interval']];
            $open = 1767225600000;

            return Http::response([[$open, '100', '105', '99', '102', '10', $open + $duration - 1, '1010']]);
        }]);
        $events = collect(Schedule::events())->filter(fn ($event): bool => str_starts_with($event->getSummaryForDisplay(), 'binance:htf:') || str_starts_with($event->getSummaryForDisplay(), 'binance:5m:'));
        self::assertCount(10, $events);
        self::assertCount(10, $events->map(fn ($event): string => $event->mutexName())->unique());
        $failures = [];
        foreach ($events as $event) {
            try {
                $event->run(app());
            } catch (RuntimeException $exception) {
                $failures[] = $event->getSummaryForDisplay();
            }
        }
        self::assertSame(['binance:htf:BTCUSDT:15m'], $failures);
        self::assertSame(9, DB::table('market_candles')->where('exchange', 'binance')->count());
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach (['5', '15', '30', '60', '240'] as $interval) {
                $expected = $symbol === 'BTCUSDT' && $interval === '15' ? 0 : 1;
                self::assertSame($expected, DB::table('market_candles')->where(['exchange' => 'binance', 'symbol' => $symbol, 'interval' => $interval])->count());
            }
        }
        foreach ($events as $event) {
            try {
                $event->run(app());
            } catch (RuntimeException) {
                // The same bad scope remains failed; successful scopes replay identically.
            }
        }
        self::assertSame(9, DB::table('market_candles')->count());
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }
}
