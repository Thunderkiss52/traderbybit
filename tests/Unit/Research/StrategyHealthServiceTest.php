<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\StrategyHealthService;
use App\Research\Infrastructure\Persistence\StrategyHealthSnapshotRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StrategyHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_strategy_health_and_builds_funnel(): void
    {
        $this->app->make(PaperAccountService::class)->default();
        $registry = $this->app->make(StrategyRegistry::class);
        $service = new StrategyHealthService($registry);

        // Seed 10 strategy observations
        for ($i = 0; $i < 10; $i++) {
            StrategyObservationRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => 'baseline',
                'strategy_version' => '1.0.0',
                'configuration_hash' => 'hash_test',
                'side' => $i === 0 ? 'BUY' : 'SKIP',
                'quality_class' => 'A',
                'score' => 80,
                'decision_time' => now('UTC')->subMinutes($i * 15),
                'reasons' => ['trend_aligned', 'volume_confirmed'],
            ]);
        }

        $overview = $service->overview('24h');

        self::assertIsArray($overview);
        self::assertArrayHasKey('strategies', $overview);
        self::assertArrayHasKey('system_status', $overview);
        self::assertNotEmpty($overview['strategies']);

        $baseline = collect($overview['strategies'])->firstWhere('strategy_id', 'baseline');
        self::assertNotNull($baseline);
        self::assertSame(10, $baseline['funnel']['evaluations']);
        self::assertSame(1, $baseline['funnel']['buy']);
        self::assertSame(9, $baseline['funnel']['skip']);
        self::assertSame('HEALTHY', $baseline['status']);
        self::assertGreaterThanOrEqual(1, StrategyHealthSnapshotRecord::query()->where('strategy_id', 'baseline')->count());
    }

    public function test_identifies_insufficient_data_status(): void
    {
        $registry = $this->app->make(StrategyRegistry::class);
        $service = new StrategyHealthService($registry);

        $overview = $service->overview('24h');
        $baseline = collect($overview['strategies'])->firstWhere('strategy_id', 'baseline');

        self::assertNotNull($baseline);
        self::assertSame(0, $baseline['funnel']['evaluations']);
        self::assertSame('INSUFFICIENT_DATA', $baseline['status']);
    }
}
