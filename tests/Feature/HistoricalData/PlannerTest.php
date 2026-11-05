<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\BatchRepository;
use App\HistoricalData\DownloadPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PlannerTest extends TestCase
{
    use RefreshDatabase;

    public static function preset(): array
    {
        return ['provider' => 'BINANCE_VISION', 'market' => 'futures/um', 'symbols' => ['BTCUSDT', 'ETHUSDT'], 'intervals' => ['1m', '5m', '15m', '30m', '1h'], 'from' => '2024-01-01T00:00:00Z', 'to' => '2025-12-31T23:59:59.999Z', 'funding' => true, 'intended_use' => 'NONCOMMERCIAL_RESEARCH'];
    }

    public function test_default_plan_has_240_candle_archives_and_leap_day_exact_counts_without_downloads(): void
    {
        Http::preventStrayRequests();
        $plan = app(DownloadPlanner::class)->plan(self::preset());
        self::assertSame(240, $plan['estimate']['candle_archives']);
        self::assertSame(2771952, $plan['estimate']['expected_candles']);
        self::assertEquals(731, $plan['estimate']['utc_days']);
        self::assertSame(0, DB::table('historical_download_batches')->count());
        self::assertSame(0, DB::table('market_candles')->count());
        Http::assertNothingSent();
        $batch = app(BatchRepository::class)->create(self::preset(), 'request-one');
        self::assertSame($batch->id, app(BatchRepository::class)->create(self::preset(), 'request-one')->id);
        self::assertSame(336, DB::table('historical_download_entries')->count());
        self::assertSame(1, DB::table('historical_acquisition_events')->count());
    }

    public function test_unsafe_urls_or_namespaces_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(DownloadPlanner::class)->plan(self::preset() + ['url' => 'http://127.0.0.1/secret']);
    }
}
