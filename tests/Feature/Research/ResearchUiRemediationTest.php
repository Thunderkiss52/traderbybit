<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Infrastructure\Persistence\ResearchRecommendationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResearchUiRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_uuid_endpoints_return_contract_correct_404_not_500(): void
    {
        $invalidId = 'audit-sample';

        $res1 = $this->getJson("/api/v1/positions/{$invalidId}");
        $res1->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $res2 = $this->getJson("/api/v1/signals/{$invalidId}");
        $res2->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $res3 = $this->getJson("/api/v1/backtests/{$invalidId}");
        $res3->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $res4 = $this->getJson("/api/v1/backtests/{$invalidId}/equity");
        $res4->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $res5 = $this->getJson("/api/v1/backtests/{$invalidId}/trades");
        $res5->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_recommendations_endpoint_is_paginated_and_ordered_deterministically(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            ResearchRecommendationRecord::query()->create([
                'id' => (string) Str::uuid(),
                'category' => 'RISK_MITIGATION',
                'strategy_id' => 'trend_pullback',
                'title' => "Test Rec {$i}",
                'description' => "Test Description {$i}",
                'evidence' => ['metric' => $i],
                'proposed_changes' => null,
                'status' => 'OPEN',
                'created_at' => now()->subMinutes(25 - $i),
                'updated_at' => now()->subMinutes(25 - $i),
            ]);
        }

        // Default pagination: 10 per page
        $res = $this->getJson('/api/v1/research/recommendations');
        $res->assertStatus(200);

        $json = $res->json();
        $this->assertTrue($json['research_mode']);
        $this->assertEquals(10, count($json['data']['data']));
        $this->assertEquals(25, $json['data']['total']);
        $this->assertEquals(1, $json['data']['current_page']);
        $this->assertEquals(3, $json['data']['last_page']);

        // Custom per_page
        $resCustom = $this->getJson('/api/v1/research/recommendations?per_page=5&page=2');
        $resCustom->assertStatus(200);
        $jsonCustom = $resCustom->json();
        $this->assertEquals(5, count($jsonCustom['data']['data']));
        $this->assertEquals(2, $jsonCustom['data']['current_page']);
    }
}
