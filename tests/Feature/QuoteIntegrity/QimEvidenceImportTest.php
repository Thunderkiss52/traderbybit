<?php

declare(strict_types=1);

namespace Tests\Feature\QuoteIntegrity;

use App\QuoteIntegrity\Domain\QimInvestigationStatus;
use App\QuoteIntegrity\Infrastructure\Persistence\QimEvidenceFileRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QimEvidenceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'quote_integrity.enabled' => true,
            'services.research_api_token' => 'valid-research-token-123',
            'quote_integrity.evidence.max_upload_size_bytes' => 1024 * 1024, // 1MB for test
            'quote_integrity.evidence.storage_disk' => 'local',
        ]);
        Storage::fake('local');
    }

    public function test_rejects_file_exceeding_size_limit_with_413(): void
    {
        $oversizedFile = UploadedFile::fake()->create('large.csv', 1024 * 1024 + 500, 'text/csv');

        $response = $this->withHeaders(['X-Research-Token' => 'valid-research-token-123'])
            ->postJson('/api/v1/quote-integrity/evidence', [
                'file' => $oversizedFile,
                'provenance_class' => 'ORIGINAL_EXPORT',
                'declared_source' => 'bybit',
            ]);

        $response->assertStatus(413);
        $this->assertSame(0, QimEvidenceFileRecord::count());
    }

    public function test_rejects_disallowed_extension_with_415(): void
    {
        $scriptFile = UploadedFile::fake()->create('malicious.sh', 100, 'application/x-sh');

        $response = $this->withHeaders(['X-Research-Token' => 'valid-research-token-123'])
            ->postJson('/api/v1/quote-integrity/evidence', [
                'file' => $scriptFile,
                'provenance_class' => 'ORIGINAL_EXPORT',
                'declared_source' => 'bybit',
            ]);

        $response->assertStatus(415);
        $this->assertSame(0, QimEvidenceFileRecord::count());
    }

    public function test_successful_import_does_not_auto_promote_status(): void
    {
        $csvContent = "timestamp,symbol,price\n1700000000,BTCUSDT,65000\n";
        $file = UploadedFile::fake()->createWithContent('bybit_export.csv', $csvContent);
        $expectedSha256 = hash('sha256', $csvContent);

        $response = $this->withHeaders(['X-Research-Token' => 'valid-research-token-123'])
            ->postJson('/api/v1/quote-integrity/evidence', [
                'file' => $file,
                'provenance_class' => 'ORIGINAL_EXPORT',
                'declared_source' => 'bybit_rest',
                'declared_timezone' => 'UTC',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.sha256_hash', $expectedSha256);
        $response->assertJsonPath('data.investigation_status', QimInvestigationStatus::INSUFFICIENT_EVIDENCE->value);

        $record = QimEvidenceFileRecord::first();
        $this->assertNotNull($record);
        $this->assertSame($expectedSha256, $record->sha256_hash);
        // Strict invariant: status must remain INSUFFICIENT_EVIDENCE
        $this->assertSame(QimInvestigationStatus::INSUFFICIENT_EVIDENCE->value, $record->investigation_status);
    }

    public function test_download_evidence_requires_research_token(): void
    {
        $csvContent = "timestamp,symbol,price\n1700000000,BTCUSDT,65000\n";
        $file = UploadedFile::fake()->createWithContent('bybit_export.csv', $csvContent);

        $uploadResponse = $this->withHeaders(['X-Research-Token' => 'valid-research-token-123'])
            ->postJson('/api/v1/quote-integrity/evidence', [
                'file' => $file,
                'provenance_class' => 'ORIGINAL_EXPORT',
                'declared_source' => 'bybit_rest',
            ]);

        $fileUuid = $uploadResponse->json('data.file_uuid');

        // Unauthenticated download must be rejected with 401
        $this->flushHeaders();
        $getUnauth = $this->getJson("/api/v1/quote-integrity/evidence/{$fileUuid}");
        $getUnauth->assertStatus(401);

        // Authenticated download succeeds
        $getAuth = $this->withHeaders(['X-Research-Token' => 'valid-research-token-123'])
            ->get("/api/v1/quote-integrity/evidence/{$fileUuid}");
        $getAuth->assertStatus(200);
    }
}
