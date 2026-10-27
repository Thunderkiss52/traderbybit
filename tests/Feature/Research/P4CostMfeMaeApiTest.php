<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PROFIT-1 / P-4 endpoint verification.
 */
final class P4CostMfeMaeApiTest extends TestCase
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
        $response = $this->getJson('/api/v1/research/profitability/cost-mfe-mae');

        $response->assertOk()
            ->assertJsonPath('status', 'NO_GENERATION_AVAILABLE')
            ->assertJsonPath('data.provenance', 'INSUFFICIENT_DATA');
    }

    public function test_generation_endpoint_requires_the_research_token(): void
    {
        $response = $this->postJson('/api/v1/research/profitability/generations/p4', [
            'idempotency_key' => 'p4-api-unauthorized',
            'accounting_scope' => 'FULL_LIFECYCLE',
        ]);

        $response->assertUnauthorized();
    }

    public function test_generation_endpoint_is_idempotent_and_the_read_endpoint_is_purely_read_only(): void
    {
        $payload = [
            'idempotency_key' => 'p4-api-generation',
            'accounting_scope' => 'FULL_LIFECYCLE',
            'as_of' => '2026-01-02T00:00:00Z',
        ];

        $created = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p4', $payload);

        $created->assertCreated()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.generation.package_code', 'P-4')
            ->assertJsonPath('data.generation.status', 'SUCCEEDED');

        $repeat = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p4', $payload);

        $repeat->assertCreated();
        self::assertSame(
            $created->json('data.generation.id'),
            $repeat->json('data.generation.id'),
            'The same idempotency key and payload must return the same generation.'
        );

        $conflict = $this->withHeaders(['X-Research-Token' => $this->validToken])
            ->postJson('/api/v1/research/profitability/generations/p4', array_merge($payload, [
                'as_of' => '2026-01-03T00:00:00Z',
            ]));

        $conflict->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $read = $this->getJson('/api/v1/research/profitability/cost-mfe-mae');
        $read->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('data.generation_id', $created->json('data.generation.id'))
            ->assertJsonPath('data.versioned_config.algorithm_version', '1.1.0');

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(trim((string) $query->sql));
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writes[] = (string) $query->sql;
            }
        });

        $this->getJson('/api/v1/research/profitability/cost-mfe-mae')->assertOk();

        self::assertSame([], $writes, 'The read endpoint must not write to the database.');
    }
}
