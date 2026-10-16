<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\PatternDiscovery\Jobs\DiscoverPatternsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PatternResearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pattern_list_and_runs_are_empty_and_paginated(): void
    {
        $this->getJson('/api/v1/research/patterns')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v1/research/patterns/runs')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v1/research/patterns/latest-matches?symbol=BTCUSDT')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.point_in_time', null);
    }

    public function test_pattern_detail_returns_the_persisted_research_definition(): void
    {
        $run = PatternRunRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'status' => 'COMPLETED',
            'symbol' => 'BTCUSDT', 'from_at' => '2026-01-01', 'to_at' => '2026-02-01',
            'configuration_hash' => str_repeat('a', 64), 'parameters' => [],
        ]);
        $pattern = PatternRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'pattern_run_id' => $run->id,
            'direction' => 'BUY', 'status' => 'VALIDATED', 'terms' => ['trend' => 'up'],
            'metrics' => ['observations' => 25], 'rule_hash' => str_repeat('b', 64), 'version' => '1.0.0',
        ]);

        $this->getJson("/api/v1/research/patterns/{$pattern->id}")->assertOk()
            ->assertJsonPath('data.id', $pattern->id)
            ->assertJsonPath('data.status', 'VALIDATED');
    }

    public function test_unauthorized_research_cannot_queue_discovery(): void
    {
        config()->set('ui.backtest_token', 'research-secret');
        $payload = ['symbol' => 'BTCUSDT', 'from' => '2026-01-01', 'to' => '2026-02-01'];
        $this->postJson('/api/v1/research/patterns/runs', $payload)->assertUnauthorized();
        self::assertSame(0, PatternRunRecord::query()->count());
    }

    public function test_discovery_is_disabled_when_research_token_is_not_configured(): void
    {
        config()->set('ui.backtest_token', null);
        $payload = ['symbol' => 'BTCUSDT', 'from' => '2026-01-01', 'to' => '2026-02-01'];
        $this->postJson('/api/v1/research/patterns/runs', $payload)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'research_write_disabled');
        self::assertSame(0, PatternRunRecord::query()->count());
    }

    public function test_authorized_research_queues_one_bounded_job(): void
    {
        config()->set('ui.backtest_token', 'research-secret');
        Bus::fake();
        $payload = ['symbol' => 'BTCUSDT', 'from' => '2026-01-01', 'to' => '2026-02-01'];
        $this->withHeader('X-Research-Token', 'research-secret')->postJson('/api/v1/research/patterns/runs', $payload)
            ->assertAccepted()->assertJsonPath('data.status', 'QUEUED');
        self::assertSame(1, PatternRunRecord::query()->count());
        Bus::assertDispatched(DiscoverPatternsJob::class, fn ($job) => $job->queue === 'research');
    }

    public function test_invalid_symbol_and_future_range_are_rejected(): void
    {
        config()->set('ui.backtest_token', 'research-secret');
        $this->withHeader('X-Research-Token', 'research-secret')
            ->postJson('/api/v1/research/patterns/runs', ['symbol' => 'DOGEUSDT', 'from' => '2026-01-01', 'to' => '2026-02-01'])
            ->assertUnprocessable();
        $this->withHeader('X-Research-Token', 'research-secret')
            ->postJson('/api/v1/research/patterns/runs', ['symbol' => 'BTCUSDT', 'from' => '2026-10-01', 'to' => '2028-01-01'])
            ->assertUnprocessable();
    }
}
