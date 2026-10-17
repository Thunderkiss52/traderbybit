<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\Application\AuditExportService;
use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\Audit\Jobs\GenerateAuditExportJob;
use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

final class AuditExportTest extends TestCase
{
    use RefreshDatabase;

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
            self::assertNotFalse($zip->locateName('manifest.json'));
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
                self::assertNotFalse($zip->locateName('manifest.json'));
                self::assertNotFalse($zip->locateName('learning_candidate_strategies.csv'));
                self::assertNotFalse($zip->locateName('learning_model_artifacts.csv'));
                self::assertNotFalse($zip->locateName('autonomy_approval_events.csv'));
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
