<?php

declare(strict_types=1);

namespace Tests\Feature\QuoteIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QimApiStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_endpoints_return_503_feature_disabled_when_flag_is_off(): void
    {
        config(['quote_integrity.enabled' => false]);

        $routes = [
            '/api/v1/quote-integrity/status',
            '/api/v1/quote-integrity/summary',
            '/api/v1/quote-integrity/comparisons',
            '/api/v1/quote-integrity/incidents',
            '/api/v1/quote-integrity/incidents/1',
            '/api/v1/quote-integrity/alerts',
        ];

        foreach ($routes as $route) {
            $response = $this->getJson($route);
            $response->assertStatus(503);
            $response->assertJson([
                'error' => [
                    'code' => 'FEATURE_DISABLED',
                ],
            ]);
        }
    }

    public function test_status_endpoint_returns_expected_structure_when_enabled(): void
    {
        config(['quote_integrity.enabled' => true]);

        $response = $this->getJson('/api/v1/quote-integrity/status');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'feature_enabled',
                'measurement_mode',
                'measurement_cap_reached',
                'level_1_status',
                'level_2_status',
                'clock_sync_state',
                'rules_version',
                'cross_source_reason',
                'disclaimers',
                'timestamp',
            ],
        ]);

        $response->assertJsonPath('data.feature_enabled', true);
        $response->assertJsonPath('data.level_1_status', 'ACTIVE');
        $response->assertJsonPath('data.level_2_status', 'CROSS_SOURCE_UNAVAILABLE');
    }

    public function test_summary_endpoint_returns_aggregates_when_enabled(): void
    {
        config(['quote_integrity.enabled' => true]);

        $response = $this->getJson('/api/v1/quote-integrity/summary');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'from',
                'to',
                'total_comparisons',
                'matched_comparisons',
                'channel_inconsistencies',
                'duplicate_counts' => [
                    'exact',
                    'conflicting',
                    'identity_config',
                ],
                'gap_events_count',
                'stale_observations_count',
                'incidents_count' => [
                    'total',
                    'new',
                    'investigating',
                    'explained',
                    'unresolved',
                    'closed',
                ],
                'cross_source_status',
                'calculated_at',
            ],
        ]);
        $response->assertJsonPath('data.cross_source_status', 'CROSS_SOURCE_UNAVAILABLE');
    }

    public function test_incidents_detail_returns_404_when_not_found(): void
    {
        config(['quote_integrity.enabled' => true]);

        $response = $this->getJson('/api/v1/quote-integrity/incidents/non-existent-id');
        $response->assertStatus(404);
        $response->assertJson([
            'error' => [
                'code' => 'NOT_FOUND',
            ],
        ]);
    }
}
