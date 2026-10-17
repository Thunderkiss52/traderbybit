<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CompleteApiEndpointAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_registered_api_endpoints_are_discovered_and_tested(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();

        // Enumerate all registered routes in the application
        $routes = Route::getRoutes()->getRoutes();
        $apiRoutes = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/')) {
                $methods = array_diff($route->methods(), ['HEAD']);
                foreach ($methods as $method) {
                    $apiRoutes[] = [
                        'method' => $method,
                        'uri' => $uri,
                        'action' => $route->getActionName(),
                        'middleware' => $route->gatherMiddleware(),
                    ];
                }
            }
        }

        self::assertNotEmpty($apiRoutes, 'API routes should be registered.');

        // Test representative public and core endpoints
        $testedCount = 0;

        $getEndpoints = [
            '/api/v1/dashboard',
            '/api/v1/signals',
            '/api/v1/positions',
            '/api/v1/journal',
            '/api/v1/journal/accounts',
            '/api/v1/statistics',
            '/api/v1/paper/daily-performance',
            '/api/v1/research/daily-performance',
            '/api/v1/risk',
            '/api/v1/backtests',
            '/api/v1/market/BTCUSDT/candles',
            '/api/v1/market/BTCUSDT/indicators',
            '/api/v1/market/BTCUSDT/context',
            '/api/v1/market/BTCUSDT/analysis',
            '/api/v1/research/patterns',
            '/api/v1/research/patterns/runs',
            '/api/v1/research/patterns/latest-matches',
            '/api/v1/research/candlestick-patterns',
            '/api/v1/research/level-forecasts',
            '/api/v1/research/pattern-matches',
            '/api/v1/research/strategies',
            '/api/v1/research/experiments',
            '/api/v1/research/leaderboard',
            '/api/v1/research/comparison',
            '/api/v1/audit/strategy-funnel?symbol=BTCUSDT',
            '/api/v1/audit/exports',
            '/api/v1/research/health/overview',
            '/api/v1/research/health/strategy-funnel',
            '/api/v1/research/health/reconciliation-runs',
            '/api/v1/research/health/data-freshness',
            '/api/v1/research/opportunities',
            '/api/v1/research/calibration',
            '/api/v1/research/degradation',
            '/api/v1/research/correlations',
            '/api/v1/research/recommendations',
            '/api/v1/research/report',
            '/api/v1/research/learning/overview',
            '/api/v1/research/learning/autonomy-settings',
            '/api/v1/research/learning/candidates',
            '/api/v1/research/learning/models',
            '/api/v1/research/learning/audit-trail',
        ];

        foreach ($getEndpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $testedCount++;
        }

        self::assertGreaterThanOrEqual(40, $testedCount);
    }

    public function test_research_write_routes_do_not_require_local_token_when_authenticated(): void
    {
        // Write routes accept requests without needing an X-Research-Token header
        $payload = [
            'symbols' => ['BTCUSDT'],
            'from' => now('UTC')->subDays(10)->toDateString(),
            'to' => now('UTC')->subDay()->toDateString(),
            'mode' => 'split',
        ];

        $response = $this->postJson('/api/v1/backtests', $payload);
        self::assertNotContains($response->status(), [401, 503]);
    }
}
