<?php

declare(strict_types=1);

namespace Tests\Feature\PatternDiscovery;

use App\PatternDiscovery\Application\PatternMatchService;
use App\PatternDiscovery\Infrastructure\Persistence\PatternMatchRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class PatternMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validated_current_pattern_is_persisted_once_without_trading_action(): void
    {
        $configuration = (array) config('patterns');
        $run = PatternRunRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'status' => 'COMPLETED', 'symbol' => 'BTCUSDT',
            'from_at' => '2026-01-01', 'to_at' => '2026-02-01', 'parameters' => $configuration,
            'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
        ]);
        $pattern = PatternRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'pattern_run_id' => $run->id, 'direction' => 'BUY',
            'status' => 'VALIDATED', 'terms' => ['regime:TREND'], 'metrics' => [],
            'rule_hash' => hash('sha256', 'regime:TREND'), 'version' => (string) config('patterns.version'),
        ]);
        $decision = app(StrategyRegistry::class)->get('baseline')->evaluate(StrategyFixture::input());
        $service = app(PatternMatchService::class);

        self::assertCount(1, $service->process($decision));
        self::assertCount(1, $service->process($decision));
        $this->assertDatabaseCount('research_pattern_matches', 1);
        $match = PatternMatchRecord::query()->sole();
        self::assertSame($pattern->id, $match->pattern_id);
        self::assertSame('PENDING', $match->status);
        $this->getJson('/api/v1/research/pattern-matches?symbol=BTCUSDT')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.trading_action', false);
    }
}
