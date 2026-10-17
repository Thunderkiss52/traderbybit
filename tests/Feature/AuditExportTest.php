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

    public function test_exports_require_authorization_and_deduplicate_repeated_clicks(): void
    {
        config()->set('ui.backtest_token', 'test-audit-secret');
        Queue::fake();
        $this->postJson('/api/v1/audit/exports/quick', ['days' => 7])->assertUnauthorized();
        $this->withHeader('X-Research-Token', 'test-audit-secret')
            ->postJson('/api/v1/audit/exports/quick', ['days' => 7])->assertStatus(202);
        $this->withHeader('X-Research-Token', 'test-audit-secret')
            ->postJson('/api/v1/audit/exports/quick', ['days' => 7])->assertStatus(202);
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
}
