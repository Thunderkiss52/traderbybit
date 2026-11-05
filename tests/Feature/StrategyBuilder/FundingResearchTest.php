<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\StrategyBuilder\AuthoringParser;
use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\ForwardEvaluator;
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

    public function test_equivalent_english_and_russian_rules_produce_identical_actual_go_signals(): void
    {
        $this->seedDslHistory();
        $parser = app(AuthoringParser::class);
        $en = $parser->parse('Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.')['definition'];
        $ru = $parser->parse('Создай стратегию для BTCUSDT. Войти в лонг когда EMA 20 пересекает снизу вверх EMA 50 и RSI ниже 65. Стоп-лосс 1.5 ATR. Тейк-профит 3 ATR. Риск 0.5%. Таймфрейм 15m.')['definition'];
        $row = app(DefinitionStore::class)->save($en, 'owner');
        $run = app(HistoricalResearch::class)->run($row->id, ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'from' => '2026-01-15T00:00:00Z', 'to' => '2026-01-16T00:00:00Z', 'capital' => '10000']);
        $signals = json_decode($run->result, true)['signals'];
        self::assertNotEmpty($signals);
        $at = new \DateTimeImmutable($signals[0]['decision_time']);
        $forward = app(ForwardEvaluator::class);
        $english = $forward->evaluate($en, 'bybit', 'BTCUSDT', $at, $row->engine_checksum);
        $russian = $forward->evaluate($ru, 'bybit', 'BTCUSDT', $at, $row->engine_checksum);
        self::assertNotEmpty($english['signals']);
        self::assertSame($english, $russian);
    }
}
