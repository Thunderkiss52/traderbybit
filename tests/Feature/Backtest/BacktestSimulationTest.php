<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestSimulationEngine;
use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Backtest\Domain\Segment;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\RiskEngine;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BacktestSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_buy_tp1_tp2_is_deterministic_and_isolated_from_paper_tables(): void
    {
        $this->seedBars();
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => '2026-01-01 10:00:00+00:00',
            'bid' => '100.9', 'ask' => '101', 'turnover_24h' => '100000000', 'tick_size' => '0.1', 'qty_step' => '0.001',
            'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
        ]);
        $paper = config('paper');
        $evaluator = new class implements HistoricalSignalEvaluator
        {
            public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
            {
                $side = $at->format('H:i') === '10:00' ? SignalSide::Buy : SignalSide::Skip;

                return new SignalDecision(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, $symbol.$at->format('c'))->toString(), 'bybit', 'linear', $symbol, '15', $at, $at, $side,
                    $side === SignalSide::Buy ? QualityClass::A : null, $side === SignalSide::Buy ? 90 : 0,
                    $side === SignalSide::Buy ? new PricePlan('100', '101', '95', '105', '110', '1.5', '2.5') : null,
                    $side === SignalSide::Buy ? '0.50' : null, $at->modify('+30 minutes'), 240,
                    [$side === SignalSide::Buy ? 'synthetic_buy' : 'no_trigger'], [], [], 'test-strategy', '1.0.0',
                );
            }
        };
        $engine = new BacktestSimulationEngine($evaluator, new RiskEngine($paper), new CorrelationService($paper), $paper);
        $segment = new Segment('test', new DateTimeImmutable('2026-01-01T09:55:00Z'), new DateTimeImmutable('2026-01-01T10:20:00Z'));
        $first = $engine->simulate(['BTCUSDT'], $segment, config('strategy'), $paper, '10000');
        $second = $engine->simulate(['BTCUSDT'], $segment, config('strategy'), $paper, '10000');

        self::assertCount(1, $first->trades);
        self::assertSame('TAKE_PROFIT_2', $first->trades[0]['close_reason']);
        self::assertSame('51.59905156581000000000000000000', $first->trades[0]['net_pnl']);
        self::assertSame('1.0319810313', $first->trades[0]['r_multiple']);
        self::assertSame('8.134', $first->trades[0]['quantity']);
        self::assertSame('52.531812200000000000000000', $first->trades[0]['gross_pnl']);
        self::assertSame('0.93276063419000000000000000000', $first->trades[0]['fees']);
        self::assertSame(['ENTRY', 'TAKE_PROFIT_1', 'TAKE_PROFIT_2'], array_column($first->trades[0]['executions'], 'type'));
        self::assertEquals($first->trades, $second->trades);
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    private function seedBars(): void
    {
        $bars = [
            ['2026-01-01 09:55:00+00:00', '100', '102', '99', '100'],
            ['2026-01-01 10:00:00+00:00', '100', '102', '100', '101'],
            ['2026-01-01 10:05:00+00:00', '101', '106', '100', '105'],
            ['2026-01-01 10:10:00+00:00', '105', '111', '104', '110'],
            ['2026-01-01 10:15:00+00:00', '110', '111', '109', '110'],
        ];
        foreach ($bars as [$time, $open, $high, $low, $close]) {
            CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5', 'open_time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true]);
        }
    }
}
