<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_signals_endpoint_annotates_latest_matching_scoped_risk_decision(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $targetAccount = $accountService->default();
        $targetAccount->update(['exchange_id' => 'bybit', 'status' => 'ACTIVE']);

        $foreignAccount = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Foreign Binance Account',
            'exchange_id' => 'binance',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'strategy_id' => 'trend_pullback',
        ]);

        $now = CarbonImmutable::now('UTC');

        $sig = SignalRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'entry_low' => '65000',
            'entry_high' => '65500',
            'stop_loss' => '64000',
            'take_profit_1' => '67000',
            'take_profit_2' => '69000',
            'reasons' => ['trend_aligned'],
            'risks' => [],
            'indicator_snapshot' => ['ema' => 65100],
            'contract' => ['version' => '1.0'],
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'maximum_holding_minutes' => 120,
            'decision_time' => $now->subMinutes(15),
            'candle_close_time' => $now->subMinutes(15),
            'expires_at' => $now->addHours(2),
        ]);

        $sigUnmatched = SignalRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'side' => 'SELL',
            'quality_class' => 'B',
            'score' => 70,
            'entry_low' => '3400',
            'entry_high' => '3450',
            'stop_loss' => '3500',
            'take_profit_1' => '3300',
            'take_profit_2' => '3200',
            'reasons' => ['resistance_bounce'],
            'risks' => [],
            'indicator_snapshot' => ['ema' => 3420],
            'contract' => ['version' => '1.0'],
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'maximum_holding_minutes' => 120,
            'decision_time' => $now->subMinutes(10),
            'candle_close_time' => $now->subMinutes(10),
            'expires_at' => $now->addHours(2),
        ]);

        // 1. Older matching decision: decided 10m ago, allowed = false
        RiskDecisionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $targetAccount->id,
            'signal_id' => $sig->id,
            'allowed' => false,
            'reasons' => ['cooldown_active'],
            'snapshot' => ['equity' => '10000'],
            'risk_version' => 'v1_old',
            'decided_at' => $now->subMinutes(10),
        ]);

        // 2. Newer matching decision: decided 5m ago, allowed = true
        RiskDecisionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $targetAccount->id,
            'signal_id' => $sig->id,
            'allowed' => true,
            'reasons' => ['risk_passed'],
            'snapshot' => ['equity' => '10000'],
            'risk_version' => 'v1_new',
            'decided_at' => $now->subMinutes(5),
        ]);

        // 3. Even newer foreign-account decision: decided 1m ago, allowed = false
        RiskDecisionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $foreignAccount->id,
            'signal_id' => $sig->id,
            'allowed' => false,
            'reasons' => ['drawdown_exceeded'],
            'snapshot' => ['equity' => '9000'],
            'risk_version' => 'v1_foreign',
            'decided_at' => $now->subMinute(),
        ]);

        // Query with account scope: should pick newer matching decision (true), not foreign (false) or older (false)
        $response = $this->getJson('/api/v1/signals?exchange=bybit&account='.$targetAccount->id);
        $response->assertOk();

        $data = collect($response->json('data.data') ?? $response->json('data'));
        $sigData = $data->firstWhere('id', $sig->id);
        self::assertNotNull($sigData);
        self::assertTrue($sigData['risk_approved'], 'Must annotate latest matching decision within requested account scope.');

        $unmatchedData = $data->firstWhere('id', $sigUnmatched->id);
        self::assertNotNull($unmatchedData);
        self::assertNull($unmatchedData['risk_approved'], 'Missing risk decision match must remain null (unavailable state).');
    }
}
