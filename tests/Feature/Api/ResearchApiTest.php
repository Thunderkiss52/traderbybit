<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Backtest\Jobs\RunBacktestJob;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ResearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_contract_and_empty_state(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('schema_version', '1.0.0')->assertJsonPath('research_mode', true)
            ->assertJsonCount(0, 'data.market')->assertJsonCount(0, 'data.recent_decisions')->assertJsonPath('data.skip_reasons_24h', []);
    }

    public function test_invalid_symbol_and_series_limit_are_rejected(): void
    {
        $this->getJson('/api/v1/market/DOGEUSDT/candles')->assertNotFound();
        $this->getJson('/api/v1/market/BTCUSDT/candles?limit=1001')->assertUnprocessable();
    }

    public function test_empty_indicator_series_is_a_valid_missing_data_response(): void
    {
        $this->getJson('/api/v1/market/BTCUSDT/indicators?interval=15')
            ->assertOk()
            ->assertJsonPath('data.times', [])
            ->assertJsonPath('data.series', null);
    }

    public function test_market_analysis_combines_bounded_initial_payload(): void
    {
        $this->getJson('/api/v1/market/BTCUSDT/analysis?interval=15&limit=300')
            ->assertOk()
            ->assertJsonPath('data.candles', [])
            ->assertJsonPath('data.indicators.times', [])
            ->assertJsonPath('data.indicators.series', null)
            ->assertJsonPath('data.signals', []);
        $this->getJson('/api/v1/market/BTCUSDT/analysis?limit=301')->assertUnprocessable();
    }

    public function test_backtest_creation_requires_token_and_dispatches_only_research_job(): void
    {
        config()->set('ui.backtest_token', 'secret');
        $payload = ['symbols' => ['BTCUSDT'], 'from' => '2025-01-01', 'to' => '2025-03-01', 'mode' => 'split'];
        $this->postJson('/api/v1/backtests', $payload)->assertUnauthorized();
        Bus::fake();
        $this->withHeader('X-Research-Token', 'secret')->postJson('/api/v1/backtests', $payload)->assertAccepted()->assertJsonPath('data.status', 'QUEUED');
        Bus::assertDispatched(RunBacktestJob::class, fn (RunBacktestJob $job): bool => $job->queue === 'backtests');
    }

    public function test_backtest_validation_rejects_unknown_symbol(): void
    {
        config()->set('ui.backtest_token', 'secret');
        $this->withToken('secret')->postJson('/api/v1/backtests', ['symbols' => ['DOGEUSDT'], 'from' => '2025-01-01', 'to' => '2025-03-01', 'mode' => 'split'])->assertUnprocessable();
    }

    public function test_backtest_rejects_client_side_model_overrides(): void
    {
        config()->set('ui.backtest_token', 'secret');
        $payload = [
            'symbols' => ['BTCUSDT'],
            'from' => '2025-01-01',
            'to' => '2025-03-01',
            'mode' => 'split',
            'starting_balance' => '999999',
        ];

        $this->withToken('secret')->postJson('/api/v1/backtests', $payload)->assertUnprocessable();
    }

    public function test_api_routes_are_rate_limited(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/dashboard');

        self::assertNotNull($route);
        self::assertContains('throttle:api', $route->gatherMiddleware());
    }

    public function test_server_errors_are_structured_and_have_request_ids(): void
    {
        Route::middleware('api')->get('/api/v1/_test-server-error', static fn () => throw new RuntimeException('sensitive detail'));

        $response = $this->getJson('/api/v1/_test-server-error')->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonPath('error.message', 'The server could not complete the request.');
        self::assertNotEmpty($response->json('error.request_id'));
        self::assertStringNotContainsString('sensitive detail', $response->getContent());
    }

    public function test_signal_list_is_paginated_and_excludes_heavy_json_snapshots(): void
    {
        for ($i = 0; $i < 12; $i++) {
            SignalRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'timeframe' => '15', 'side' => 'SKIP', 'score' => 0,
                'decision_time' => now('UTC')->subMinutes($i * 15),
                'candle_close_time' => now('UTC')->subMinutes($i * 15),
                'expires_at' => now('UTC')->addMinutes(30),
                'maximum_holding_minutes' => 240,
                'reasons' => ['test'], 'risks' => [],
                'indicator_snapshot' => ['large' => str_repeat('X', 15000)],
                'contract' => ['large' => str_repeat('Y', 15000)],
                'strategy_version' => 'pagination-test', 'schema_version' => '1.0.0',
            ]);
        }

        $response = $this->getJson('/api/v1/signals');
        $response->assertOk()->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.total', 12)->assertJsonCount(10, 'data.data');
        self::assertArrayNotHasKey('indicator_snapshot', $response->json('data.data.0'));
        self::assertArrayNotHasKey('contract', $response->json('data.data.0'));
        $response2 = $this->getJson('/api/v1/signals?page=2');
        $response2->assertOk()->assertJsonCount(2, 'data.data');

        $id = $response->json('data.data.0.id');
        $this->getJson('/api/v1/signals/'.$id)->assertOk()
            ->assertJsonPath('data.contract.large', str_repeat('Y', 15000));
    }

    public function test_other_ledgers_have_small_default_pages(): void
    {
        $this->getJson('/api/v1/backtests')->assertOk()->assertJsonPath('data.per_page', 10);
        $this->getJson('/api/v1/positions')->assertOk()->assertJsonPath('data.per_page', 10);
        $this->getJson('/api/v1/journal')->assertOk()->assertJsonPath('data.per_page', 10);
        $this->getJson('/api/v1/risk')->assertOk()->assertJsonPath('data.decisions.last_page', 1);
    }

    public function test_list_page_size_is_capped_at_one_hundred(): void
    {
        $this->getJson('/api/v1/signals?per_page=10000')->assertOk()->assertJsonPath('data.per_page', 100);
        $this->getJson('/api/v1/backtests?per_page=10000')->assertOk()->assertJsonPath('data.per_page', 100);
        $this->getJson('/api/v1/journal?per_page=10000')->assertOk()->assertJsonPath('data.per_page', 100);
    }

    public function test_no_trade_or_order_routes_exist(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->pluck('uri');
        self::assertFalse($routes->contains(fn (string $route): bool => preg_match('#api/v1/(?:orders?|trades?)(?:/|$)#', $route) === 1));
    }
}
