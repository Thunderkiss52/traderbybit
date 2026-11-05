<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\AcquisitionFailure;
use App\HistoricalData\AcquisitionWorker;
use App\HistoricalData\BatchControl;
use App\HistoricalData\BatchRepository;
use App\HistoricalData\CoverageValidator;
use App\HistoricalData\OfficialTransport;
use App\HistoricalData\SourcePolicy;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AcquisitionTest extends TestCase
{
    use RefreshDatabase;

    public static function smallPlan(): array
    {
        return array_replace(PlannerTest::preset(), ['symbols' => ['BTCUSDT'], 'intervals' => ['5m'], 'from' => '2024-01-15T00:00:00Z', 'to' => '2024-01-15T00:59:59.999Z']);
    }

    private function fixtureTransport(): void
    {
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
    }

    public function test_official_zip_to_postgres_is_resumable_and_does_not_touch_any_paper_records(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['exchanges.binance_paper_provisioning_approved' => true, 'historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
        app(PaperTopologySynchronizer::class)->sync(true);
        DB::table('paper_accounts')->update(['status' => 'BLOCKED']);
        $accounts = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        $risk = config('paper.global_capital');
        $this->fixtureTransport();
        $batch = app(BatchRepository::class)->create(self::smallPlan(), 'fixture-import');
        $control = app(BatchControl::class);
        $control->change($batch->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        $worker = app(AcquisitionWorker::class);
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($worker->once($batch->id));
        }
        self::assertSame('IMPORTED', DB::table('historical_download_batches')->find($batch->id)->status);
        $quality = app(CoverageValidator::class)->validate($batch->id);
        self::assertSame('VALIDATED', $quality['status']);
        self::assertTrue($quality['funding_complete']);
        self::assertTrue($quality['series'][0]['complete']);
        try {
            app(SourcePolicy::class)->assertMarketUse('binance', 'BTCUSDT', new \DateTimeImmutable('2024-01-15T00:00:00Z'), new \DateTimeImmutable('2024-01-15T01:00:00Z'), 'FORWARD_PAPER');
            self::fail('Restricted source reached forward PAPER');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        DB::table('market_candles')->where('exchange', 'binance')->where('interval', '5')->where('open_time', '2024-01-15T00:30:00Z')->delete();
        $gap = app(CoverageValidator::class)->validate($batch->id);
        self::assertFalse($gap['research_ready']);
        self::assertSame(1, $gap['series'][0]['missing']);

        self::assertSame(11, DB::table('market_candles')->where('exchange', 'binance')->count());
        self::assertSame(2, DB::table('historical_funding_observations')->count());
        self::assertSame(0, DB::table('market_funding_events')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
        self::assertSame(0, DB::table('trading_pipeline_events')->count());
        self::assertSame($accounts, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        self::assertSame($risk, config('paper.global_capital'));
        $other = app(BatchRepository::class)->create(self::smallPlan(), 'second-import');
        $control->change($other->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        for ($i = 0; $i < 3; $i++) {
            $worker->once($other->id);
        }
        self::assertSame(12, DB::table('market_candles')->where('exchange', 'binance')->count());
        self::assertSame(11, (int) DB::table('historical_download_entries')->where('batch_id', $other->id)->where('family', 'klines')->value('duplicate_rows'));
        Http::assertNothingSent();
    }

    public function test_claims_are_fenced_and_pause_prevents_claiming_or_downloading(): void
    {
        config(['historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
        $batch = app(BatchRepository::class)->create(self::smallPlan(), 'claim-test');
        $control = app(BatchControl::class);
        $control->change($batch->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        $worker = app(AcquisitionWorker::class);
        $first = $worker->claim($batch->id);
        self::assertNotNull($first);
        self::assertNull($worker->claim($batch->id));
        $control->change($batch->id, 'pause');
        self::assertNull($worker->claim($batch->id));
        $this->travel(10)->minutes();
        $control->change($batch->id, 'resume');
        $reclaimed = $worker->claim($batch->id);
        self::assertNotNull($reclaimed);
        self::assertSame($first->id, $reclaimed->id);
        self::assertGreaterThan($first->fence, $reclaimed->fence);
    }

    public function test_rate_limit_retries_are_bounded_and_missing_monthly_files_plan_daily_fallback(): void
    {
        config(['historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
        $plan = self::smallPlan();
        $plan['funding'] = false;
        $batch = app(BatchRepository::class)->create($plan, 'retry-budget');
        app(BatchControl::class)->change($batch->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        $http = $this->createStub(OfficialTransport::class);
        $http->method('assertUrl');
        $http->method('fetch')->willThrowException(new AcquisitionFailure('RATE_LIMITED', true, 60));
        $this->app->instance(OfficialTransport::class, $http);
        $worker = app(AcquisitionWorker::class);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $worker->once($batch->id);
            self::assertSame($attempt, (int) DB::table('historical_download_entries')->where('batch_id', $batch->id)->value('attempts'));
            $this->travel(2)->minutes();
        }
        self::assertSame('FAILED', DB::table('historical_download_batches')->find($batch->id)->status);
        self::assertSame(0, DB::table('market_candles')->count());
        $missing = app(BatchRepository::class)->create($plan, 'missing-month');
        app(BatchControl::class)->change($missing->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        $http = $this->createStub(OfficialTransport::class);
        $http->method('assertUrl');
        $http->method('fetch')->willThrowException(new AcquisitionFailure('SOURCE_UNAVAILABLE'));
        $this->app->instance(OfficialTransport::class, $http);
        app(AcquisitionWorker::class)->once($missing->id);
        self::assertSame(1, DB::table('historical_download_entries')->where('batch_id', $missing->id)->where('status', 'SUPERSEDED_DAILY')->count());
        self::assertSame(1, DB::table('historical_download_entries')->where('batch_id', $missing->id)->where('granularity', 'daily')->where('status', 'PLANNED')->count());
    }
}
