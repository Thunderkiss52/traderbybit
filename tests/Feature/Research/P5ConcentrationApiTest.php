<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROFIT-1 / P-5 endpoint verification.
 */
final class P5ConcentrationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-research-token-secret-12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.research_api_token' => $this->validToken]);
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
    }

    public function test_read_endpoint_reports_no_generation_before_any_run(): void
    {
        $response = $this->getJson('/api/v1/research/profitability/concentration-dependence');

        $response->assertOk()
            ->assertJsonPath('status', 'NO_GENERATION_AVAILABLE')
            ->assertJsonPath('data.provenance', 'INSUFFICIENT_DATA');
    }

    public function test_generation_endpoint_requires_the_research_token(): void
    {
        $response = $this->postJson('/api/v1/research/profitability/generations/p5', [
            'idempotency_key' => 'p5-api-unauthorized',
            'accounting_scope' => 'FULL_LIFECYCLE',
        ]);

        $response->assertUnauthorized();
    }

    public function test_generation_endpoint_is_idempotent_and_exposes_the_read_model(): void
    {
        $payload = [
            'idempotency_key' => 'p5-api-generation',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ];

        $created = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p5', $payload);

        $created->assertCreated()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('generation.package_code', 'P-5')
            ->assertJsonPath('generation.status', 'INSUFFICIENT_DATA');

        $repeat = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p5', $payload);

        $repeat->assertCreated();
        self::assertSame(
            $created->json('generation.id'),
            $repeat->json('generation.id'),
            'The same idempotency key and payload must return the same generation.'
        );

        $conflict = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p5', array_merge($payload, [
                'as_of' => '2026-01-03T00:00:00Z',
            ]));

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $read = $this->getJson('/api/v1/research/profitability/concentration-dependence');
        $read->assertOk()
            ->assertJsonPath('status', 'INSUFFICIENT_DATA')
            ->assertJsonPath('data.generation_id', $created->json('generation.id'))
            ->assertJsonPath('data.metadata.netting_applied', false);
    }
}
