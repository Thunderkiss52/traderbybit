<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\Research\Application\AutonomyPermissionService;
use App\Strategy\Application\StrategyInputFactory;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\ShadowService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class ShadowTest extends TestCase
{
    use RefreshDatabase, SeedsDslHistory;

    public function test_shadow_is_forward_only_idempotent_and_kill_switch_gated_without_accounts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15T00:00:00Z'));
        $this->seedDslHistory('2026-01-14T00:00:00Z', '2026-01-15T00:00:00Z');
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $row = app(DefinitionStore::class)->save($d, 'owner');
        $shadows = app(ShadowService::class);
        $shadows->set($row->id, true);
        $factory = app(StrategyInputFactory::class);
        self::assertSame([], $shadows->observe($factory->make('bybit', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-01-14T23:45:00Z'))));
        $input = $factory->make('bybit', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-01-15T00:00:00Z'));
        $observed = $shadows->observe($input);
        self::assertCount(1, $observed);
        self::assertSame('OBSERVED', $observed[0]['status']);
        self::assertSame([], $shadows->observe($input));
        app(AutonomyPermissionService::class)->toggleKillSwitch(true);
        self::assertSame([], $shadows->observe($factory->make('bybit', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-01-15T00:15:00Z'))));
        self::assertSame(1, DB::table('strategy_shadow_evaluations')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
    }
}
