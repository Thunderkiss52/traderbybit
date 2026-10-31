<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

final class BinanceBackfillCommandSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_command_is_a_real_dry_run_without_market_or_paper_writes(): void
    {
        Http::preventStrayRequests();
        $before = [
            DB::table('market_candles')->count(),
            DB::table('trading_pipeline_events')->count(),
            DB::table('virtual_executions')->count(),
        ];
        self::assertSame(0, Artisan::call('binance:backfill-candles', [
            'symbol' => 'BTCUSDT', 'interval' => '30m', '--days' => 1,
        ]));
        self::assertStringContainsString('DRY-RUN', Artisan::output());
        Http::assertNothingSent();
        self::assertSame($before, [
            DB::table('market_candles')->count(),
            DB::table('trading_pipeline_events')->count(),
            DB::table('virtual_executions')->count(),
        ]);
    }

    public function test_automatic_historical_recovery_is_disabled_by_default(): void
    {
        self::assertFalse(config('exchanges.binance.automatic_candle_recovery_enabled'));
        self::assertNotContains('binance:market-gap-recovery', array_map(
            static fn ($event): ?string => $event->description,
            Schedule::events(),
        ));
        Http::preventStrayRequests();
        Queue::fake();
        config(['research.automatic.enabled' => false]);
        (new EnsureStrategyHistoryJob)->handle();
        Http::assertNothingSent();
    }
}
