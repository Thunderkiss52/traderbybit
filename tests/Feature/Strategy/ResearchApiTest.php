<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use App\Strategy\Jobs\RunResearchExperimentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ResearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_catalog_and_empty_leaderboard_are_bounded_and_do_not_fabricate_metrics(): void
    {
        $this->getJson('/api/v1/research/strategies?per_page=10')->assertOk()->assertJsonPath('data.meta.total', 5)->assertJsonPath('data.data.0.id', 'baseline');
        $response = $this->getJson('/api/v1/research/leaderboard')->assertOk();
        $response->assertJsonPath('data.meta.total', 5)->assertJsonPath('data.data.0.metrics', null)->assertJsonPath('data.data.0.ranking.status', 'NOT_EVALUATED');
    }

    public function test_authorized_experiment_is_persisted_and_queued_on_dedicated_worker(): void
    {
        Bus::fake();
        config()->set('ui.backtest_token', 'secret');
        $response = $this->withHeader('X-Research-Token', 'secret')->postJson('/api/v1/research/experiments', ['strategy_id' => 'trend_pullback', 'symbols' => ['BTCUSDT', 'ETHUSDT'], 'from' => '2025-01-01', 'to' => '2026-01-01', 'evaluation_mode' => 'split']);
        $response->assertAccepted()->assertJsonPath('data.strategy_id', 'trend_pullback')->assertJsonPath('data.status', 'QUEUED');
        $this->assertDatabaseHas('research_experiments', ['strategy_id' => 'trend_pullback', 'status' => 'QUEUED', 'tested_combinations' => 0]);
        Bus::assertDispatched(RunResearchExperimentJob::class);
    }

    public function test_lists_default_to_ten_rows_and_reject_unknown_strategy(): void
    {
        $this->getJson('/api/v1/research/experiments')->assertOk()->assertJsonPath('data.per_page', 10);
        $this->getJson('/api/v1/research/strategies/missing')->assertNotFound();
    }

    public function test_completed_experiment_requires_explicit_approval_and_creates_an_isolated_forward_portfolio(): void
    {
        config()->set('ui.backtest_token', 'secret');
        $id = Uuid::uuid4()->toString();
        $selected = array_replace(config('research.strategies.trend_pullback'), ['adx_min' => '24']);
        ResearchExperimentRecord::query()->create(['id' => $id, 'strategy_id' => 'trend_pullback', 'strategy_version' => '1.0.0', 'configuration_hash' => str_repeat('a', 64), 'parameters' => config('research.strategies.trend_pullback'), 'selected_parameters' => $selected, 'parameter_stability' => ['stable' => true], 'symbols' => ['BTCUSDT', 'ETHUSDT'], 'from_at' => '2025-01-01', 'to_at' => '2026-01-01', 'evaluation_mode' => 'split', 'status' => 'COMPLETED', 'resource_budget' => config('research.queue'), 'tested_combinations' => 1, 'completed_at' => now('UTC')]);
        $response = $this->withHeader('X-Research-Token', 'secret')->postJson("/api/v1/research/experiments/{$id}/approve-forward");
        $response->assertOk()->assertJsonPath('data.mode', 'RESEARCH_FORWARD_PAPER')->assertJsonPath('data.real_trading', false)->assertJsonPath('data.account.strategy_id', 'trend_pullback');
        $this->assertDatabaseHas('paper_accounts', ['research_experiment_id' => $id, 'mode' => 'RESEARCH_FORWARD_PAPER', 'strategy_id' => 'trend_pullback']);
        self::assertSame('24', $response->json('data.account.strategy_parameters.adx_min'));
        $this->assertDatabaseHas('equity_snapshots', ['source_event_key' => 'research-portfolio-created']);
        $this->getJson('/api/v1/research/daily-performance?timezone=UTC')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.account.strategy_id', 'trend_pullback');
    }
}
