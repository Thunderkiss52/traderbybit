<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\Application\AuditExportService;
use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\Audit\Jobs\GenerateAuditExportJob;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_state_export_uses_half_open_adjacent_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:00Z'));
        Queue::fake();
        $service = app(AuditExportService::class);
        $account = app(PaperAccountService::class)->default();
        $job = $service->request(1);
        foreach ([$job->from_at, $job->to_at->subSecond(), $job->to_at] as $index => $at) {
            $signal = SignalRecord::query()->create([
                'id' => (string) Str::uuid(), 'exchange' => 'bybit', 'category' => 'linear',
                'symbol' => 'BTCUSDT', 'timeframe' => '15', 'side' => 'SKIP', 'score' => 0,
                'decision_time' => $at, 'candle_close_time' => $at, 'expires_at' => $at->addMinutes(15),
                'maximum_holding_minutes' => 240, 'reasons' => ['fixture'], 'risks' => [],
                'indicator_snapshot' => [], 'contract' => [], 'strategy_version' => '1.0.0',
                'schema_version' => '1.0.0', 'strategy_id' => 'baseline',
            ]);
            PaperSignalStateRecord::query()->create([
                'id' => (string) Str::uuid(), 'paper_account_id' => $account->id,
                'signal_id' => $signal->id, 'status' => 'SKIPPED', 'processed_at' => $at, 'created_at' => $at,
            ]);
        }
        $method = new \ReflectionMethod(AuditExportService::class, 'writeDataset');
        $file = tempnam(sys_get_temp_dir(), 'vector-boundary-');
        try {
            self::assertSame(2, $method->invoke($service, $job, 'paper_signal_states', $file));
            $checksum = hash_file('sha256', $file);
            self::assertSame(2, $method->invoke($service, $job, 'paper_signal_states', $file));
            self::assertSame($checksum, hash_file('sha256', $file));
            $job->from_at = $job->to_at;
            $job->to_at = $job->to_at->addDay();
            self::assertSame(1, $method->invoke($service, $job, 'paper_signal_states', $file));
            $job->from_at = $job->to_at;
            $job->to_at = $job->to_at->addDay();
            self::assertSame(0, $method->invoke($service, $job, 'paper_signal_states', $file));
        } finally {
            unlink($file);
        }
    }

    public function test_audit_window_uses_real_request_time_without_quarter_hour_rounding(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23T12:07:34Z'));
        Queue::fake();
        $export = app(AuditExportService::class)->request(7);
        self::assertSame('2026-10-23T12:07:34+00:00', $export->to_at->toIso8601String());
        self::assertSame('2026-10-16T12:07:34+00:00', $export->from_at->toIso8601String());
        $this->travel(20)->seconds();
        // Idempotent repeat retains the first timestamp, not the later one.
        $duplicate = app(AuditExportService::class)->request(7);
        self::assertSame($export->id, $duplicate->id);
        self::assertSame($export->to_at->getTimestamp(), $duplicate->to_at->getTimestamp());
    }

    public function test_exports_deduplicate_repeated_clicks(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/audit/exports/quick', ['days' => 7])->assertStatus(202);
        $this->postJson('/api/v1/audit/exports/quick', ['days' => 7])->assertStatus(202);
        $this->assertDatabaseCount('audit_export_jobs', 1);
        Queue::assertPushed(GenerateAuditExportJob::class, 1);
    }

    public function test_export_contains_manifest_csv_and_rejects_anonymous_download(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension required on the production PHP image.');
        }
        $this->app->make(PaperAccountService::class)->default();
        Queue::fake();
        $service = $this->app->make(AuditExportService::class);
        $job = $service->request(1);
        try {
            $service->generate($job->id);
            $job = AuditExportRecord::query()->findOrFail($job->id);
            self::assertSame('COMPLETED', $job->status);
            $zip = new ZipArchive;
            self::assertTrue($zip->open($service->archivePath($job->id)) === true);
            self::assertNotFalse($zip->locateName('manifest.csv'));
            self::assertNotFalse($zip->locateName('risk_decisions.csv'));
            self::assertNotFalse($zip->locateName('data_quality.csv'));
            $zip->close();
            $this->get('/api/v1/audit/exports/'.$job->id.'/download')->assertForbidden();
        } finally {
            @unlink($service->archivePath($job->id));
        }
    }

    public function test_all_four_export_periods_generate_successfully_with_learning_and_health_datasets(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension required on the production PHP image.');
        }

        $this->app->make(PaperAccountService::class)->default();
        Queue::fake();
        $service = $this->app->make(AuditExportService::class);

        foreach ([1, 7, 14, 30] as $days) {
            $job = $service->request($days);
            try {
                $service->generate($job->id);
                $record = AuditExportRecord::query()->findOrFail($job->id);
                self::assertSame('COMPLETED', $record->status);

                $zip = new ZipArchive;
                self::assertTrue($zip->open($service->archivePath($job->id)) === true);
                self::assertNotFalse($zip->locateName('manifest.csv'));
                self::assertNotFalse($zip->locateName('checksums.csv'));
                self::assertNotFalse($zip->locateName('export_metadata.csv'));
                $manifest = $zip->getFromName('manifest.csv');
                self::assertIsString($manifest);
                self::assertStringContainsString('source_scope', $manifest);
                $checksums = $zip->getFromName('checksums.csv');
                self::assertIsString($checksums);
                self::assertStringContainsString('manifest.csv', $checksums);
                self::assertStringNotContainsString('checksums.csv,', $checksums);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    self::assertStringEndsWith('.csv', (string) $zip->getNameIndex($i));
                }
                self::assertNotFalse($zip->locateName('learning_candidate_strategies.csv'));
                self::assertNotFalse($zip->locateName('learning_model_artifacts.csv'));
                self::assertNotFalse($zip->locateName('autonomy_approval_events.csv'));
                self::assertNotFalse($zip->locateName('primary_strategy_coverage.csv'));
                $coverage = $zip->getFromName('primary_strategy_coverage.csv');
                self::assertIsString($coverage);
                $lines = array_values(array_filter(explode("\n", trim($coverage)), 'strlen'));
                self::assertCount(27, $lines, 'Coverage export must include a header and 13 strategies on each of two exchanges.');
                self::assertStringContainsString('btc_eth_lead_lag', $coverage);
                self::assertStringContainsString('PAPER_EXECUTION', $coverage);
                self::assertStringContainsString('coverage_status', $lines[0]);
                $binanceFunding = collect(array_slice($lines, 1))
                    ->map(fn (string $line): array => str_getcsv($line))
                    ->first(fn (array $cells): bool => ($cells[0] ?? null) === 'binance'
                        && ($cells[1] ?? null) === 'funding_open_interest_squeeze');
                self::assertIsArray($binanceFunding);
                self::assertSame('PAPER_EXECUTION', $binanceFunding[4], 'Binance funding/OI execution is now eligible for simulated paper execution.');
                self::assertNotFalse($zip->locateName('strategy_health_snapshots.csv'));
                self::assertNotFalse($zip->locateName('strategy_degradation_snapshots.csv'));
                self::assertNotFalse($zip->locateName('portfolio_correlation_snapshots.csv'));
                self::assertNotFalse($zip->locateName('research_recommendations.csv'));
                $zip->close();
            } finally {
                @unlink($service->archivePath($job->id));
            }
        }
    }
}
