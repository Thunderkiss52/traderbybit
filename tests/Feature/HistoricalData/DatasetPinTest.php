<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\AcquisitionDataset;
use App\HistoricalData\AcquisitionFailure;
use App\HistoricalData\BatchRepository;
use App\HistoricalData\SourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class DatasetPinTest extends TestCase
{
    use RefreshDatabase,SeedsDslHistory;

    public function test_acquisition_pin_rejects_later_supplemental_mutation_and_disallowed_use(): void
    {
        $this->seedDslHistory(exchange: 'binance');
        $plan = array_replace(PlannerTest::preset(), ['symbols' => ['BTCUSDT'], 'intervals' => ['5m', '15m', '30m', '1h'], 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-01-16T23:59:59.999Z']);
        $batch = app(BatchRepository::class)->create($plan, 'pin-test');
        DB::table('historical_download_batches')->where('id', $batch->id)->update(['status' => 'VALIDATED', 'acknowledged_at' => now(), 'quality' => json_encode(['research_ready' => true])]);
        $scope = ['exchange' => 'binance', 'symbols' => ['BTCUSDT'], 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'intended_use' => 'NONCOMMERCIAL_RESEARCH'];
        $datasets = app(AcquisitionDataset::class);
        $pin = $datasets->pin($batch->id, $scope, 221);
        self::assertSame($pin->id, $datasets->pin($batch->id, $scope, 221)->id);
        self::assertSame($pin->id, $datasets->verify($pin->id)->id);
        DB::table('historical_funding_observations')->insert(['batch_id' => $batch->id, 'source_revision' => str_repeat('a', 64), 'symbol' => 'BTCUSDT', 'funding_at' => '2026-01-15T08:00:00Z', 'rate' => '0.0001', 'mark_price' => '100', 'created_at' => now()]);
        $this->expectException(AcquisitionFailure::class);
        $datasets->verify($pin->id);
    }

    public function test_source_policy_cannot_authorize_commercial_or_export_use_by_default(): void
    {
        $this->expectException(ValidationException::class);
        app(SourcePolicy::class)->assertUse('COMMERCIAL_TRADING', config('historical_data.license'), true);
    }
}
