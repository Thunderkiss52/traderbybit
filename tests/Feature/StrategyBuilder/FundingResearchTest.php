<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\HistoricalResearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsDslHistory;
use Tests\TestCase;

final class FundingResearchTest extends TestCase
{
    use RefreshDatabase, SeedsDslHistory;

    public function test_funding_costs_are_pinned_and_missing_event_prevents_backtest(): void
    {
        $this->seedDslHistory();
        $d = DefinitionTest::example();
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $definition = app(DefinitionStore::class)->save($d, 'owner');
        $scope = ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000'];
        $run = app(HistoricalResearch::class)->run($definition->id, $scope);
        self::assertTrue(json_decode($run->metadata, true)['funding_complete']);
        self::assertArrayHasKey('executions', json_decode($run->result, true)['report']['metadata']);
        DB::table('market_funding_events')->delete();
        $this->expectException(ValidationException::class);
        app(HistoricalResearch::class)->run($definition->id, $scope);
    }
}
