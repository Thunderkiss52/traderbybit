<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\Research\Application\AutonomyPermissionService;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\OptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class OptimizationTest extends TestCase
{
    use RefreshDatabase, SeedsDslHistory;

    public function test_bounded_walk_forward_preserves_original_and_records_experimental_versions(): void
    {
        $this->seedDslHistory();
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['timeout_bars'] = 2;
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $d['parameters'] = ['indicators.fast.period' => ['default' => 20, 'min' => 19, 'max' => 20, 'step' => 1]];
        $base = app(DefinitionStore::class)->save($d, 'owner');
        $scope = ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000'];
        $service = app(OptimizationService::class);
        $experiment = $service->create($base->id, $scope, ['train_hours' => 6, 'validation_hours' => 3, 'test_hours' => 3, 'step_hours' => 6, 'max_windows' => 2, 'max_candidates' => 2]);
        $service->run($experiment->id);
        $done = DB::table('strategy_optimization_experiments')->find($experiment->id);
        self::assertSame('COMPLETED', $done->status);
        self::assertCount(2, json_decode($done->results, true)['windows']);
        self::assertSame($base->definition, DB::table('strategy_definitions')->find($base->id)->definition);
        self::assertSame('VALIDATED', DB::table('strategy_definitions')->find($base->id)->status);
        self::assertSame(2, DB::table('strategy_optimization_candidates')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
        $service->run($experiment->id);
        self::assertSame($done->results, DB::table('strategy_optimization_experiments')->find($experiment->id)->results);
    }

    public function test_kill_switch_blocks_new_optimization(): void
    {
        app(AutonomyPermissionService::class)->updateSettings([], ['kill_switch_active' => true]);
        // The service checks the persistent kill switch before creating a run or experiment.
        app(AutonomyPermissionService::class)->toggleKillSwitch(true);
        $this->expectException(HttpException::class);
        app(OptimizationService::class)->create(999, [], []);
    }
}
