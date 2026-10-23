<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F07 — every registered API GET handler must be read-only. Repeated reads may
 * not emit INSERT/UPDATE/DELETE/REPLACE statements.
 */
final class ReadOnlyGetEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_registered_get_endpoints_are_read_only(): void
    {
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();

        $writes = [];
        $current = null;
        DB::listen(static function ($event) use (&$writes, &$current): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = ['endpoint' => $current, 'sql' => $event->sql];
            }
        });

        $endpoints = $this->getEndpoints();

        self::assertNotEmpty($endpoints);

        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ($endpoints as $endpoint) {
            $current = $endpoint;
            $this->getJson($endpoint);
        }

        self::assertSame([], $writes, 'GET endpoints must not mutate persisted state.');
    }

    /**
     * @return list<string>
     */
    private function getEndpoints(): array
    {
        $endpoints = [];
        $placeholder = '00000000-0000-0000-0000-000000000000';

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $endpoints[] = '/'.str_replace(['{id}', '{symbol}', '{strategyId}', '{positionId}', '{evaluationId}'], [$placeholder, 'BTCUSDT', 'baseline', $placeholder, $placeholder], $uri);
        }

        return array_values(array_unique($endpoints));
    }
}
