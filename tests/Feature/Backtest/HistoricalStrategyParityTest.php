<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\StrategySignalEvaluator;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalStrategyParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_thirteen_match_registered_logic_on_closed_warm_history_for_both_exchanges(): void
    {
        $at = new DateTimeImmutable('2026-01-01T12:00:00Z');
        foreach (['bybit' => 10000, 'binance' => 20000] as $exchange => $base) {
            foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
                foreach ([5, 15, 30, 60] as $minutes) {
                    $rows = [];
                    for ($i = 220; $i >= 1; $i--) {
                        $price = $base + (220 - $i) * 2;
                        $rows[] = ['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'interval' => (string) $minutes,
                            'open_time' => $at->modify('-'.($i * $minutes).' minutes'), 'open' => (string) $price,
                            'high' => (string) ($price + 10), 'low' => (string) ($price - 10), 'close' => (string) $price,
                            'volume' => '150', 'turnover' => '9000000', 'is_closed' => true];
                    }
                    DB::table('market_candles')->insert($rows);
                }
                MarketContextRecord::query()->create(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol,
                    'observed_at' => $at, 'bid' => (string) ($base + 438), 'ask' => (string) ($base + 439),
                    'turnover_24h' => '500000000', 'tick_size' => '0.1', 'funding_rate' => '0.0001',
                    'open_interest' => '2500000', 'open_interest_change' => '0.02']);
            }
        }
        $evaluator = app(StrategySignalEvaluator::class);
        $registry = app(StrategyRegistry::class);
        self::assertCount(13, config('paper.primary_strategies'));
        foreach (['bybit' => 10438, 'binance' => 20438] as $exchange => $expectedClose) {
            foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
                $input = app(StrategyInputFactory::class)->make($exchange, 'linear', $symbol, $at, StrategyInputFactory::peerSymbol($symbol));
                self::assertSame([], $input->validationErrors);
                self::assertTrue($input->entry->warmupReady);
                self::assertDecimalSame((string) $expectedClose, $input->entry->latest()->close);
                self::assertNotNull($input->peerEntry);
                foreach (config('paper.primary_strategies') as $id) {
                    $configuration = config('strategy');
                    $configuration['_exchange'] = $exchange;
                    $configuration['_research_strategy_id'] = $id;
                    $parameters = config("research.strategies.{$id}", []);
                    $configuration['_research_parameters'] = $parameters;
                    $expected = $registry->make($id, $id === 'baseline' ? config('strategy') : $parameters)->evaluate($input);
                    $actual = $evaluator->evaluate($symbol, $at, $configuration);
                    self::assertSame($exchange, $actual->exchange);
                    self::assertSame($expected->side, $actual->side, $exchange.':'.$id);
                    self::assertSame($expected->reasons, $actual->reasons, $exchange.':'.$id);
                    self::assertEquals($expected->pricePlan, $actual->pricePlan, $exchange.':'.$id);
                    self::assertEquals($actual, $evaluator->evaluate($symbol, $at, $configuration));
                }
            }
        }
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    public function test_all_primary_strategies_fail_closed_without_history(): void
    {
        $evaluator = app(StrategySignalEvaluator::class);
        $at = new DateTimeImmutable('2026-01-01T12:00:00Z');
        foreach (['bybit', 'binance'] as $exchange) {
            foreach (config('paper.primary_strategies') as $id) {
                $configuration = config('strategy');
                $configuration['_research_strategy_id'] = $id;
                $configuration['_exchange'] = $exchange;
                $configuration['_research_parameters'] = config("research.strategies.{$id}", []);
                $first = $evaluator->evaluate('BTCUSDT', $at, $configuration);
                $second = $evaluator->evaluate('BTCUSDT', $at, $configuration);
                self::assertSame($exchange, $first->exchange);
                self::assertSame(SignalSide::Skip, $first->side, $id);
                self::assertSame($first->reasons, $second->reasons, $id);
            }
        }
    }
}
