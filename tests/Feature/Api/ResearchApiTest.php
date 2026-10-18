<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Backtest\Jobs\RunBacktestJob;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ResearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_contract_and_empty_state(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('schema_version', '1.0.0')->assertJsonPath('research_mode', true)
            ->assertJsonCount(0, 'data.market')->assertJsonCount(0, 'data.recent_decisions')->assertJsonPath('data.skip_reasons_24h', [])
            ->assertJsonCount(8, 'data.strategy_observations_24h')
            ->assertJsonPath('data.strategy_observations_24h.0.strategy_id', 'baseline')
            ->assertJsonPath('data.strategy_observations_24h.0.total', 0);
    }

    public function test_dashboard_reports_each_strategy_observation_stream(): void
    {
        $metadata = app(StrategyRegistry::class)->metadata();
        foreach ($metadata as $index => $strategy) {
            StrategyObservationRecord::query()->create([
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'strategy_id' => $strategy['id'], 'strategy_version' => $strategy['version'],
                'configuration_hash' => $strategy['configuration_hash'], 'side' => $index === 0 ? 'SELL' : 'SKIP',
                'score' => 0, 'decision_time' => now('UTC')->subMinutes(15), 'reasons' => ['test_reason'],
                'analysis' => ['level_interaction' => ['momentum_side' => 'SELL', 'selected_event' => 'support_breakdown', 'selected_evidence_score' => 70]],
            ]);
        }

        $response = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(8, 'data.strategy_observations_24h');
        $response->assertJsonPath('data.strategy_observations_24h.0.sell', 1)
            ->assertJsonPath('data.strategy_observations_24h.0.symbols.0.symbol', 'BTCUSDT')
            ->assertJsonPath('data.strategy_observations_24h.0.symbols.0.latest.analysis.level_interaction.momentum_side', 'SELL')
            ->assertJsonPath('data.strategy_observations_24h.0.symbols.1.symbol', 'ETHUSDT')
            ->assertJsonPath('data.strategy_observations_24h.1.skip', 1)
            ->assertJsonPath('data.strategy_observations_24h.1.top_skip_reasons.test_reason', 1);
    }

    public function test_dashboard_returns_performance_for_an_existing_paper_account(): void
    {
        $account = app(PaperAccountService::class)->default();

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.portfolio.account_id', $account->id)
            ->assertJsonPath('data.performance.closed_positions', 0);
    }

    public function test_dashboard_exposes_bounded_level_and_momentum_analysis(): void
    {
        SignalRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'timeframe' => '15', 'side' => 'SKIP', 'score' => 0,
            'decision_time' => now('UTC'), 'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addMinutes(30), 'maximum_holding_minutes' => 240,
            'reasons' => ['neutral_hourly_trend'], 'risks' => [],
            'indicator_snapshot' => ['level_interaction' => [
                'support' => '100', 'resistance' => '110',
                'bullish_directional_evidence_score' => 70,
                'bearish_directional_evidence_score' => 30,
                'momentum_side' => 'BUY', 'momentum_strength' => 35,
            ]],
            'contract' => [], 'strategy_version' => '1.2.0', 'schema_version' => '1.0.0',
        ]);

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.recent_decisions.0.level_interaction.bullish_directional_evidence_score', 70)
            ->assertJsonPath('data.recent_decisions.0.level_interaction.momentum_side', 'BUY');
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

        $this->getJson('/api/v1/market/BTCUSDT/context')
            ->assertOk()
            ->assertJsonPath('data', null);
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

    public function test_backtest_creation_dispatches_only_research_job(): void
    {
        Bus::fake();
        $payload = ['symbols' => ['BTCUSDT'], 'from' => '2025-01-01', 'to' => '2025-03-01', 'mode' => 'split'];
        $this->postJson('/api/v1/backtests', $payload)->assertAccepted()->assertJsonPath('data.status', 'QUEUED');
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

    public function test_statistics_cache_contains_portable_arrays_not_domain_objects(): void
    {
        $account = app(PaperAccountService::class)->default();

        $this->getJson('/api/v1/statistics')->assertOk()->assertJsonPath('data.metrics.closed_positions', 0);

        $cached = Cache::get('paper:statistics:report:v5:'.$account->id);
        self::assertIsArray($cached);
        self::assertIsArray($cached['metrics']);
        self::assertIsArray($cached['data_quality']);

        $responseCache = Cache::get("paper:statistics:response:v5:{$account->id}:1:1:1");
        self::assertIsArray($responseCache);
        self::assertIsArray($responseCache['equity']);
        self::assertIsArray($responseCache['breakdowns']);
        self::assertIsArray($responseCache['breakdowns']['symbols']);
        self::assertIsArray($responseCache['breakdowns']['sides']);
        self::assertIsArray($responseCache['breakdowns']['quality_classes']);
        self::assertIsArray($responseCache['breakdowns']['r_distribution']);
        self::assertIsArray($responseCache['period_pnl']);
        self::assertIsArray($responseCache['period_pnl']['daily']['data']);
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

    public function test_system_version_endpoint_returns_valid_metadata(): void
    {
        $this->getJson('/api/v1/system/version')
            ->assertOk()
            ->assertJsonStructure([
                'git_sha',
                'build_timestamp',
                'version',
                'environment',
                'market_category',
                'symbols',
                'active_strategies_count',
                'paper_only',
            ])
            ->assertJsonPath('market_category', 'linear')
            ->assertJsonPath('active_strategies_count', 8)
            ->assertJsonPath('paper_only', true);
    }

    public function test_dashboard_cold_and_warm_cache_performance(): void
    {
        Cache::flush();
        $startCold = microtime(true);
        $resCold = $this->getJson('/api/v1/dashboard');
        $coldMs = round((microtime(true) - $startCold) * 1000, 2);
        $resCold->assertOk();

        $samples = 30;
        $warmLatencies = [];
        for ($i = 0; $i < $samples; $i++) {
            $startWarm = microtime(true);
            $resWarm = $this->getJson('/api/v1/dashboard');
            $dur = round((microtime(true) - $startWarm) * 1000, 2);
            $resWarm->assertOk();
            $warmLatencies[] = $dur;
        }

        sort($warmLatencies);
        $minMs = $warmLatencies[0];
        $maxMs = $warmLatencies[$samples - 1];
        $p50Ms = $warmLatencies[(int) floor($samples * 0.50)];
        $p95Ms = $warmLatencies[(int) floor($samples * 0.95)];
        $avgMs = round(array_sum($warmLatencies) / $samples, 2);

        $benchmark = [
            'database_size' => [
                'accounts' => PaperAccountRecord::query()->count(),
                'signals' => SignalRecord::query()->count(),
                'positions' => VirtualPositionRecord::query()->count(),
                'observations' => StrategyObservationRecord::query()->count(),
                'contexts' => MarketContextRecord::query()->count(),
            ],
            'cold_ms' => $coldMs,
            'warm_samples' => $samples,
            'warm_min_ms' => $minMs,
            'warm_p50_ms' => $p50Ms,
            'warm_p95_ms' => $p95Ms,
            'warm_max_ms' => $maxMs,
            'warm_avg_ms' => $avgMs,
            'speedup_ratio' => round($coldMs / max(0.01, $p50Ms), 1),
            'measured_at' => now('UTC')->toISOString(),
            'sla_compliance' => [
                'cold_under_1500ms' => $coldMs < 1500.0,
                'warm_p95_under_200ms' => $p95Ms < 200.0,
            ],
        ];

        if (! file_exists(storage_path('latency_benchmark.json')) || ($benchmark['database_size']['signals'] > 0)) {
            file_put_contents(storage_path('latency_benchmark.json'), json_encode($benchmark, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        self::assertLessThan(1500.0, $coldMs, "Cold dashboard response took {$coldMs}ms, exceeding 1500ms");
        self::assertLessThan(200.0, $p95Ms, "Warm dashboard p95 response took {$p95Ms}ms, exceeding 200ms");
    }
}
