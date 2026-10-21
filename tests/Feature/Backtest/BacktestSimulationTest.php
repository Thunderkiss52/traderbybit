<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestSimulationEngine;
use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Backtest\Domain\Segment;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\RiskEngine;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Brick\Math\BigDecimal;
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

    public function test_expired_pending_position_does_not_crash_or_reprocess(): void
    {
        // Seed 5m bars whose CLOSE times land on :00/:15/:30/:45 so the strategy
        // clock (close time % 15 === 0) evaluates. Opens are therefore 5 minutes earlier.
        $bars = [
            ['2026-01-01 09:55:00+00:00', '100', '101', '99', '100'],
            ['2026-01-01 10:10:00+00:00', '100', '101', '99', '100'],
            ['2026-01-01 10:25:00+00:00', '100', '101', '99', '100'],
            ['2026-01-01 10:40:00+00:00', '100', '101', '99', '100'],
            ['2026-01-01 10:55:00+00:00', '100', '101', '99', '100'],
            ['2026-01-01 11:10:00+00:00', '100', '101', '99', '100'],
        ];
        foreach ($bars as [$time, $open, $high, $low, $close]) {
            CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5', 'open_time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true]);
        }
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => '2026-01-01 10:00:00+00:00',
            'bid' => '99.9', 'ask' => '100', 'turnover_24h' => '100000000', 'tick_size' => '0.1', 'qty_step' => '0.001',
            'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
        ]);
        $paper = config('paper');
        // Evaluator returns BUY only at 10:00 with a limit entry zone (50-51) far below the
        // market price of ~100, and expires 10 minutes later so the order can never fill.
        // This forces the position into the EXPIRED state while later bars are processed.
        $evaluator = new class implements HistoricalSignalEvaluator
        {
            public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
            {
                $isBuy = $at->format('H:i') === '10:00';
                $side = $isBuy ? SignalSide::Buy : SignalSide::Skip;

                return new SignalDecision(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, $symbol.$at->format('c'))->toString(), 'bybit', 'linear', $symbol, '15', $at, $at, $side,
                    $isBuy ? QualityClass::A : null, $isBuy ? 90 : 0,
                    $isBuy ? new PricePlan('50', '51', '40', '60', '70', '10', '20') : null,
                    $isBuy ? '0.50' : null, $at->modify('+10 minutes'), 240,
                    [$isBuy ? 'synthetic_buy_far' : 'no_trigger'], [], [], 'test-strategy', '1.0.0',
                );
            }
        };
        $engine = new BacktestSimulationEngine($evaluator, new RiskEngine($paper), new CorrelationService($paper), $paper);
        $segment = new Segment('test', new DateTimeImmutable('2026-01-01T09:55:00Z'), new DateTimeImmutable('2026-01-01T11:20:00Z'));
        $result = $engine->simulate(['BTCUSDT'], $segment, config('strategy'), $paper, '10000');

        self::assertCount(0, $result->trades, 'An un-fillable expired signal must not produce trades.');
    }

    public function test_apply_funding_never_builds_a_nullable_comparison_operand(): void
    {
        // Negative control for the audit error
        // "InvalidArgumentException: Illegal operator and value combination."
        // That Laravel error is raised by `where('funding_at', '>', null)`. The engine must
        // therefore never reach the funding query with an unset `opened_at`.
        $paper = config('paper');
        $engine = new BacktestSimulationEngine(
            new class implements HistoricalSignalEvaluator
            {
                public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
                {
                    return new SignalDecision(
                        Uuid::uuid5(Uuid::NAMESPACE_URL, $symbol.$at->format('c'))->toString(), 'bybit', 'linear', $symbol, '15', $at, $at, SignalSide::Skip,
                        null, 0, null, null, $at->modify('+30 minutes'), 240, ['no_trigger'], [], [], 'test-strategy', '1.0.0',
                    );
                }
            },
            new RiskEngine($paper),
            new CorrelationService($paper),
            $paper
        );

        $method = new \ReflectionMethod($engine, 'applyFunding');
        $method->setAccessible(true);
        $execution = new PaperExecutionEngine($paper);
        $equity = BigDecimal::of('1000');
        $position = [
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'remaining' => '1', 'funding' => '0', 'funding_ids' => [],
            // `opened_at` is intentionally absent: an unfilled/expired order never has one.
        ];

        $method->invokeArgs($engine, [&$position, new DateTimeImmutable('2026-01-01T00:00:00Z'), $execution, &$equity]);

        self::assertSame('0', $position['funding'], 'No funding may be booked for a position without an open timestamp.');
        self::assertSame('1000', (string) $equity);
    }

    public function test_no_signals_produces_zero_trades_and_no_writes(): void
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
                return new SignalDecision(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, $symbol.$at->format('c'))->toString(), 'bybit', 'linear', $symbol, '15', $at, $at, SignalSide::Skip,
                    null, 0, null, null, $at->modify('+30 minutes'), 240, ['no_trigger'], [], [], 'test-strategy', '1.0.0',
                );
            }
        };
        $engine = new BacktestSimulationEngine($evaluator, new RiskEngine($paper), new CorrelationService($paper), $paper);
        $segment = new Segment('test', new DateTimeImmutable('2026-01-01T09:55:00Z'), new DateTimeImmutable('2026-01-01T10:20:00Z'));
        $result = $engine->simulate(['BTCUSDT'], $segment, config('strategy'), $paper, '10000');

        self::assertCount(0, $result->trades);
        self::assertNotEmpty($result->equity);
        self::assertSame('10000', (string) $result->equity[array_key_last($result->equity)]['equity']);
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    public function test_open_position_is_closed_at_segment_boundary_without_lookahead(): void
    {
        // Entry fills at the 10:00 close, price then stays flat so neither stop nor target is
        // hit; the position must be force-closed at the segment boundary.
        $bars = [
            ['2026-01-01 09:55:00+00:00', '100', '102', '99', '100'],
            ['2026-01-01 10:10:00+00:00', '100', '102', '99', '100'],
            ['2026-01-01 10:25:00+00:00', '100', '102', '99', '100'],
            ['2026-01-01 10:40:00+00:00', '100', '102', '99', '100'],
        ];
        foreach ($bars as [$time, $open, $high, $low, $close]) {
            CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5', 'open_time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true]);
        }
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
                $isBuy = $at->format('H:i') === '10:00';
                $side = $isBuy ? SignalSide::Buy : SignalSide::Skip;

                return new SignalDecision(
                    Uuid::uuid5(Uuid::NAMESPACE_URL, $symbol.$at->format('c'))->toString(), 'bybit', 'linear', $symbol, '15', $at, $at, $side,
                    $isBuy ? QualityClass::A : null, $isBuy ? 90 : 0,
                    $isBuy ? new PricePlan('100', '102', '50', '150', '200', '50', '100') : null,
                    $isBuy ? '0.50' : null, $at->modify('+30 minutes'), 240,
                    [$isBuy ? 'synthetic_buy' : 'no_trigger'], [], [], 'test-strategy', '1.0.0',
                );
            }
        };
        $engine = new BacktestSimulationEngine($evaluator, new RiskEngine($paper), new CorrelationService($paper), $paper);
        $segment = new Segment('test', new DateTimeImmutable('2026-01-01T09:55:00Z'), new DateTimeImmutable('2026-01-01T10:45:00Z'));
        $result = $engine->simulate(['BTCUSDT'], $segment, config('strategy'), $paper, '10000');

        self::assertCount(1, $result->trades);
        self::assertSame('SEGMENT_BOUNDARY', $result->trades[0]['close_reason']);
        self::assertTrue($result->trades[0]['opened_at'] < $result->trades[0]['closed_at']);
        // The boundary close must be timestamped at the last available bar, not the segment end.
        self::assertSame('2026-01-01T10:45:00+00:00', $result->trades[0]['closed_at']->format(DATE_ATOM));
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
