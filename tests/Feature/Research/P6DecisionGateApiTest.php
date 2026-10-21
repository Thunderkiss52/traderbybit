<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PROFIT-1 / P-6 endpoint verification.
 */
final class P6DecisionGateApiTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-research-token-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.research_api_token' => $this->validToken]);
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_read_endpoint_reports_no_decision_before_any_run(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/decision-gate');

        $response->assertOk()
            ->assertJsonPath('status', 'NO_DECISION_AVAILABLE');
    }

    public function test_generation_endpoint_requires_the_research_token(): void
    {
        $response = $this->postJson('/api/v1/research/profitability/generations/p6', [
            'idempotency_key' => 'p6-api-unauthorized',
            'claim_type' => 'PROFITABILITY_CLAIM',
        ]);

        $response->assertUnauthorized();
    }

    public function test_generation_endpoint_appends_a_fail_closed_decision_and_is_idempotent(): void
    {
        $payload = [
            'idempotency_key' => 'p6-api-generation',
            'claim_type' => 'PROFITABILITY_CLAIM',
            'preregistration_hash' => 'prereg-1',
            'evidence_bundle_hash' => 'bundle-1',
        ];

        $created = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p6', $payload);

        $created->assertCreated()
            ->assertJsonPath('status', 'SUCCESS')
            // No accepted measurement generation exists, so the gate must fail closed.
            ->assertJsonPath('decision.decision_state', 'INSUFFICIENT_EVIDENCE')
            ->assertJsonPath('decision.reason', 'MISSING_INPUT_PACKAGE');

        $repeat = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p6', $payload);

        $repeat->assertCreated();
        self::assertSame(
            $created->json('generation_id'),
            $repeat->json('generation_id'),
            'The same idempotency key and payload must return the same generation.'
        );
        self::assertSame(1, DB::table('research_decision_records')->count());

        $conflict = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p6', array_merge($payload, [
                'evidence_bundle_hash' => 'bundle-2',
            ]));

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_read_endpoint_returns_the_decision_after_a_generation(): void
    {
        $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p6', [
                'idempotency_key' => 'p6-api-read',
                'claim_type' => 'PROFITABILITY_CLAIM',
                'preregistration_hash' => 'prereg-1',
                'evidence_bundle_hash' => 'bundle-1',
            ])->assertCreated();

        $response = $this->getJson('/api/v1/research/profitability/decision-gate');

        $response->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.decision.decision_state', 'INSUFFICIENT_EVIDENCE');
    }
}
