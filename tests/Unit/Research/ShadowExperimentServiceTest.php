<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\ShadowExperimentService;
use App\Strategy\Infrastructure\Persistence\ResearchExperimentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ShadowExperimentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_experiment_lifecycle_and_capacity_limits(): void
    {
        $service = new ShadowExperimentService;

        $exp1 = ResearchExperimentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_1',
            'parameters' => ['adx_threshold' => 25],
            'symbols' => ['BTCUSDT'],
            'from_at' => now('UTC')->subDays(7),
            'to_at' => now('UTC'),
            'evaluation_mode' => 'split',
            'status' => 'DRAFT',
            'shadow_status' => 'DRAFT',
            'is_shadow' => true,
            'resource_budget' => ['timeout_seconds' => 3600],
        ]);

        $exp2 = ResearchExperimentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_2',
            'parameters' => ['adx_threshold' => 30],
            'symbols' => ['BTCUSDT'],
            'from_at' => now('UTC')->subDays(7),
            'to_at' => now('UTC'),
            'evaluation_mode' => 'split',
            'status' => 'DRAFT',
            'shadow_status' => 'DRAFT',
            'is_shadow' => true,
            'resource_budget' => ['timeout_seconds' => 3600],
        ]);

        $exp3 = ResearchExperimentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_3',
            'parameters' => ['adx_threshold' => 35],
            'symbols' => ['BTCUSDT'],
            'from_at' => now('UTC')->subDays(7),
            'to_at' => now('UTC'),
            'evaluation_mode' => 'split',
            'status' => 'DRAFT',
            'shadow_status' => 'DRAFT',
            'is_shadow' => true,
            'resource_budget' => ['timeout_seconds' => 3600],
        ]);

        // Start 1st and 2nd for trend_pullback (both should succeed)
        $started1 = $service->startExperiment($exp1->id);
        self::assertSame('RUNNING', $started1->shadow_status);

        $started2 = $service->startExperiment($exp2->id);
        self::assertSame('RUNNING', $started2->shadow_status);

        // 3rd for same strategy should fail with ValidationException (max 2 per strategy)
        $this->expectException(ValidationException::class);
        $service->startExperiment($exp3->id);
    }

    public function test_shadow_experiment_stop_and_idempotent_restart(): void
    {
        $service = new ShadowExperimentService;

        $exp = ResearchExperimentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_stop_test',
            'parameters' => ['adx_threshold' => 25],
            'symbols' => ['BTCUSDT'],
            'from_at' => now('UTC')->subDays(7),
            'to_at' => now('UTC'),
            'evaluation_mode' => 'split',
            'status' => 'DRAFT',
            'shadow_status' => 'DRAFT',
            'is_shadow' => true,
            'resource_budget' => ['timeout_seconds' => 3600],
        ]);

        $started = $service->startExperiment($exp->id);
        self::assertSame('RUNNING', $started->shadow_status);

        // Calling start on already RUNNING returns existing record idempotently
        $repeat = $service->startExperiment($exp->id);
        self::assertSame('RUNNING', $repeat->shadow_status);

        // Stop experiment transitions to PAUSED
        $stopped = $service->stopExperiment($exp->id);
        self::assertSame('PAUSED', $stopped->shadow_status);
        self::assertSame('PAUSED', $stopped->status);
        self::assertNotNull($stopped->completed_at);
    }
}
