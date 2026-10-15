<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Backtest\Jobs\RunBacktestJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_no_trade_or_order_routes_exist(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->pluck('uri')->implode('\n');
        self::assertStringNotContainsString('/order', $routes);
        self::assertStringNotContainsString('/trade', $routes);
    }
}
