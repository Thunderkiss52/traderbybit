<?php

declare(strict_types=1);

namespace Tests\Feature\QuoteIntegrity;

use App\QuoteIntegrity\Infrastructure\Persistence\QimEvidenceFileRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class QimApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'quote_integrity.enabled' => true,
            'services.research_api_token' => 'secret-test-token-xyz',
        ]);
    }

    public function test_post_evidence_rejects_missing_token_with_no_side_effects(): void
    {
        $initialCount = QimEvidenceFileRecord::count();
        $file = UploadedFile::fake()->create('sample.csv', 100, 'text/csv');

        $response = $this->postJson('/api/v1/quote-integrity/evidence', [
            'file' => $file,
            'provenance_class' => 'ORIGINAL_EXPORT',
            'declared_source' => 'bybit',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => [
                'code' => 'TOKEN_MISSING',
            ],
        ]);

        // Prove no database side effects
        $this->assertSame($initialCount, QimEvidenceFileRecord::count());
    }

    public function test_post_evidence_rejects_invalid_token_with_no_side_effects(): void
    {
        $initialCount = QimEvidenceFileRecord::count();
        $file = UploadedFile::fake()->create('sample.csv', 100, 'text/csv');

        $response = $this->withHeaders(['X-Research-Token' => 'wrong-token-abc'])
            ->postJson('/api/v1/quote-integrity/evidence', [
                'file' => $file,
                'provenance_class' => 'ORIGINAL_EXPORT',
                'declared_source' => 'bybit',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'TOKEN_INVALID',
            ],
        ]);

        $this->assertSame($initialCount, QimEvidenceFileRecord::count());
    }

    public function test_incident_transition_rejects_missing_token_with_no_state_change(): void
    {
        $incident = QimIncidentRecord::create([
            'incident_key' => 'inc-auth-test-1',
            'status' => 'NEW',
            'severity' => 'MEDIUM',
            'priority' => 'P3',
            'title' => 'Test Incident',
            'provider' => 'bybit',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/quote-integrity/incidents/{$incident->id}/transitions", [
            'status' => 'INVESTIGATING',
            'explanation' => 'Unauthenticated attempt',
        ]);

        $response->assertStatus(401);
        $incident->refresh();
        $this->assertSame('NEW', $incident->status, 'Status must NOT change on unauthenticated transition');
    }

    public function test_incident_transition_succeeds_with_valid_token(): void
    {
        $incident = QimIncidentRecord::create([
            'incident_key' => 'inc-auth-test-2',
            'status' => 'NEW',
            'severity' => 'MEDIUM',
            'priority' => 'P3',
            'title' => 'Test Incident',
            'provider' => 'bybit',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Research-Token' => 'secret-test-token-xyz'])
            ->postJson("/api/v1/quote-integrity/incidents/{$incident->id}/transitions", [
                'status' => 'INVESTIGATING',
                'explanation' => 'Authorized operator forensic analysis',
            ]);

        $response->assertStatus(200);
        $incident->refresh();
        $this->assertSame('INVESTIGATING', $incident->status);
    }
}
