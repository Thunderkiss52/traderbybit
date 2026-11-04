<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\Backtest\BacktestAuditArchiveBuilder;
use App\Audit\Backtest\BacktestAuditService;
use App\Audit\Backtest\Jobs\BuildBacktestAuditJob;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BacktestHistoricalAuditR8Test extends TestCase
{
    use RefreshDatabase;

    public function test_only_completed_simulations_can_enqueue_idempotent_zip_audits(): void
    {
        Queue::fake();
        $run = $this->runFixture('COMPLETED');
        $url = '/api/v1/audit/backtests/'.$run->id.'/exports';
        $first = $this->postJson($url)->assertStatus(202)->json('data');
        self::assertSame('QUEUED', $first['status']);
        self::assertSame($run->id, $first['backtest_run_id']);

        $second = $this->postJson($url)->assertStatus(202)->json('data');
        self::assertSame($first['id'], $second['id']);

        $this->assertDatabaseCount('backtest_audit_jobs', 1);
        Queue::assertPushed(BuildBacktestAuditJob::class, 1);

        $this->getJson('/api/v1/audit/backtests/exports/'.$first['id'])
            ->assertOk()->assertJsonPath('data.status', 'QUEUED');

        $this->get('/api/v1/audit/backtests/exports/'.$first['id'].'/download')
            ->assertForbidden(); // Download requires a relative signed URL.
    }

    public function test_pending_backtest_cannot_export_as_completed_history(): void
    {
        Queue::fake();
        $run = $this->runFixture('RUNNING');
        $this->postJson('/api/v1/audit/backtests/'.$run->id.'/exports')->assertStatus(422);
        $this->assertDatabaseCount('backtest_audit_jobs', 0);
        Queue::assertNotPushed(BuildBacktestAuditJob::class);
    }

    public function test_audit_job_builds_archive_and_signed_download_streams_zip(): void
    {
        $run = $this->runFixture('COMPLETED');
        $service = app(BacktestAuditService::class);
        $builder = app(BacktestAuditArchiveBuilder::class);

        $requested = $service->request($run->id);
        $jobId = $requested['id'];

        $job = new BuildBacktestAuditJob($jobId);
        $job->handle($service, $builder);

        $status = $service->status($jobId);
        self::assertSame('READY', $status['status']);
        self::assertNotNull($status['download_url']);
        self::assertFileExists($service->path($jobId));

        // Open ZIP and inspect members
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($service->path($jobId)));
        self::assertNotFalse($zip->locateName('manifest.json'));
        self::assertNotFalse($zip->locateName('trades.csv'));
        self::assertNotFalse($zip->locateName('segments.csv'));
        self::assertNotFalse($zip->locateName('candles.csv'));
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = json_decode((string) $manifestJson, true);
        self::assertSame('VECTOR_BACKTEST_AUDIT_R8_1', $manifest['schema_version']);
        self::assertSame('HISTORICAL_SIMULATION_NOT_EXCHANGE_EXECUTION', $manifest['source']);
        $zip->close();

        // Test downloading through signed route
        $response = $this->get($status['download_url']);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');

        // Clean up exported file
        @unlink($service->path($jobId));
    }

    private function runFixture(string $status): BacktestRunRecord
    {
        return BacktestRunRecord::query()->create([
            'id' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', Str::random(16)),
            'type' => 'split',
            'status' => $status,
            'from_at' => '2025-01-01T00:00:00Z',
            'to_at' => '2026-01-01T00:00:00Z',
            'symbols' => ['BTCUSDT'],
            'strategy_config' => ['version' => '1.0.0'],
            'risk_config' => ['max_risk' => '0.5'],
            'execution_config' => ['paper' => true],
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'backtest_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'dataset_checksum' => hash('sha256', 'fixture'),
            'seed' => 42,
        ]);
    }
}
