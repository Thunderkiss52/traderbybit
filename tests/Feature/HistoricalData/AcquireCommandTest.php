<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\OfficialTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AcquireCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_240_archives_without_database_mutations(): void
    {
        Http::preventStrayRequests();

        $code = Artisan::call('historical:acquire', ['--preset' => 'binance-2024-2025', '--dry-run' => true]);
        self::assertSame(0, $code);

        $output = Artisan::output();
        self::assertStringContainsString('BINANCE_VISION', $output);
        self::assertStringContainsString('240', $output);
        self::assertStringContainsString('2,771,952', $output);
        self::assertSame(0, DB::table('historical_download_batches')->count());
        self::assertSame(0, DB::table('historical_download_entries')->count());
    }

    public function test_acquire_creates_batch_and_dispatches_entries_with_limit(): void
    {
        Http::preventStrayRequests();

        $http = $this->createStub(OfficialTransport::class);
        $http->method('assertUrl');
        $http->method('fetch')->willReturnCallback(function ($url, $target, $maximum, $tick): array {
            $tick();
            if (str_contains($url, 'fapi.binance.com')) {
                $content = json_encode([['symbol' => 'BTCUSDT', 'fundingTime' => 1705276800000, 'fundingRate' => '-0.000123456789', 'markPrice' => '101.123456789012']]);
            } else {
                $name = basename(str_replace('.CHECKSUM', '', $url));
                $file = base_path('tests/Fixtures/historical/'.$name);
                $content = str_ends_with($url, '.CHECKSUM') ? hash_file('sha256', $file).'  '.$name : file_get_contents($file);
            }
            file_put_contents($target, $content);

            return ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        });
        $this->app->instance(OfficialTransport::class, $http);

        $code = Artisan::call('historical:acquire', [
            '--preset' => 'binance-2024-2025',
            '--limit' => 2,
            '--reference' => 'TEST-ACQUIRE-LIMIT',
        ]);
        self::assertSame(0, $code);

        self::assertSame(1, DB::table('historical_download_batches')->count());
        self::assertSame(336, DB::table('historical_download_entries')->count());
        $batch = DB::table('historical_download_batches')->first();
        self::assertContains($batch->status, ['PAUSED', 'FAILED', 'IMPORTED', 'VALIDATED']);
    }
}
