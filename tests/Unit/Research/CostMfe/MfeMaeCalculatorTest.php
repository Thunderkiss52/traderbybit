<?php

declare(strict_types=1);

namespace Tests\Unit\Research\CostMfe;

use App\Research\Domain\CostMfe\MfeMaeCalculator;
use App\Research\Domain\CostMfe\MfeMaeResult;
use App\Research\Domain\CostMfe\PriceCandle;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * PROFIT-1 / P-4 — observed-position MFE/MAE diagnostics (§9.2).
 */
final class MfeMaeCalculatorTest extends TestCase
{
    private const ENTRY = '100.000000000000';

    public function test_buy_position_mfe_and_mae_use_only_candles_inside_the_holding_interval(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p1',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '2.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: '120.000000000000',
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T00:30:00Z'),
            candles: [
                $this->candle('c0', '2026-10-16T23:55:00Z', '100', '101', '99', '100'),
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '110', '99', '105'),
                $this->candle('c2', '2026-10-17T00:25:00Z', '105', '108', '95', '106'),
                $this->candle('c3', '2026-10-17T00:30:00Z', '106', '130', '80', '120'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
        );

        self::assertSame(MfeMaeResult::STATUS_COMPUTED, $result->status);
        self::assertSame(2, $result->candleCount);
        self::assertSame(['c1', 'c2'], $result->sourceCandleIds);
        self::assertSame('10.000000000000', $result->mfePrice);
        self::assertSame('5.000000000000', $result->maePrice);
        self::assertSame('20.000000000000', $result->mfeUsdt);
        self::assertSame('10.000000000000', $result->maeUsdt);
        self::assertSame('1.000000000000', $result->mfeR);
        self::assertSame('0.500000000000', $result->maeR);
        self::assertSame('c1', $result->mfeCandleId);
        self::assertSame('c2', $result->maeCandleId);
        self::assertSame('2026-10-17T00:05:00+00:00', $result->mfeCandleCloseTime);
        self::assertFalse($result->ambiguousPath);
    }

    public function test_sell_position_mirrors_the_excursion_directions(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p2',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'SELL',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '110.000000000000',
            takeProfit: '80.000000000000',
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '112', '88', '90'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        );

        self::assertSame('12.000000000000', $result->mfePrice);
        self::assertSame('12.000000000000', $result->maePrice);
        self::assertSame('1.200000000000', $result->mfeR);
        self::assertSame('1.200000000000', $result->maeR);
    }

    public function test_excursions_are_clamped_at_zero_when_the_price_never_moved_against_the_position(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p3',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: null,
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '104', '100', '103'),
                $this->candle('c2', '2026-10-17T00:10:00Z', '100', '99', '98', '99'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        );

        self::assertSame('4.000000000000', $result->mfePrice);
        self::assertSame('2.000000000000', $result->maePrice);
        self::assertSame('4.000000000000', $result->mfeUsdt);
        self::assertSame('2.000000000000', $result->maeUsdt);
    }

    public function test_a_favourable_excursion_never_becomes_negative_when_the_price_never_reached_the_entry(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p3b',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: null,
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '99', '99', '98', '98.5'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        );

        self::assertSame('0.000000000000', $result->mfePrice);
        self::assertSame('2.000000000000', $result->maePrice);
    }

    public function test_no_eligible_candles_yield_null_metrics_and_never_zero(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p4',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: null,
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '104', '99', '103'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T00:00:30Z'),
        );

        self::assertSame(MfeMaeResult::STATUS_NO_CANDLES, $result->status);
        self::assertNull($result->mfePrice);
        self::assertNull($result->maePrice);
        self::assertNull($result->mfeUsdt);
        self::assertNull($result->mfeR);
        self::assertSame(0, $result->candleCount);
        self::assertSame([], $result->sourceCandleIds);
    }

    public function test_r_multiples_are_null_with_a_reason_when_the_stop_is_missing_or_invalid(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p5',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: null,
            takeProfit: null,
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '104', '99', '103'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        );

        self::assertSame(MfeMaeResult::STATUS_COMPUTED, $result->status);
        self::assertNull($result->mfeR);
        self::assertNull($result->maeR);
        self::assertSame('MISSING_OR_INVALID_STOP', $result->riskReason);
        self::assertSame('4.000000000000', $result->mfePrice);
    }

    public function test_a_candle_spanning_stop_and_target_is_flagged_as_ambiguous(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p6',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: '120.000000000000',
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '125', '85', '110'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        );

        self::assertTrue($result->ambiguousPath);
        self::assertSame('CANDLE_SPANS_STOP_AND_TARGET_INTRACANDLE_SEQUENCE_UNKNOWN', $result->ambiguityReason);
    }

    public function test_candles_after_the_as_of_boundary_are_never_used(): void
    {
        $calculator = new MfeMaeCalculator;

        $result = $calculator->calculate(
            positionId: 'p7',
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: self::ENTRY,
            quantity: '1.000000000000',
            stopLoss: '90.000000000000',
            takeProfit: null,
            holdingStart: CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            holdingEnd: CarbonImmutable::parse('2026-10-17T02:00:00Z'),
            candles: [
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '104', '99', '103'),
                $this->candle('c2', '2026-10-17T01:05:00Z', '103', '200', '99', '190'),
            ],
            asOf: CarbonImmutable::parse('2026-10-17T01:00:00Z'),
        );

        self::assertSame(['c1'], $result->sourceCandleIds);
        self::assertSame('4.000000000000', $result->mfePrice);
    }

    public function test_calculation_is_deterministic_for_identical_input(): void
    {
        $calculator = new MfeMaeCalculator;

        $args = [
            'positionId' => 'p8',
            'accountId' => 'a1',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'entryReference' => self::ENTRY,
            'quantity' => '1.000000000000',
            'stopLoss' => '90.000000000000',
            'takeProfit' => '120.000000000000',
            'holdingStart' => CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            'holdingEnd' => CarbonImmutable::parse('2026-10-17T01:00:00Z'),
            'candles' => [
                $this->candle('c2', '2026-10-17T00:25:00Z', '100', '108', '95', '106'),
                $this->candle('c1', '2026-10-17T00:05:00Z', '100', '110', '99', '105'),
            ],
            'asOf' => CarbonImmutable::parse('2026-10-17T02:00:00Z'),
        ];

        $first = $calculator->calculate(...$args);
        $second = $calculator->calculate(...$args);

        self::assertSame($first->mfePrice, $second->mfePrice);
        self::assertSame($first->maePrice, $second->maePrice);
        self::assertSame($first->sourceCandleIds, $second->sourceCandleIds);
    }

    private function candle(string $id, string $closeTime, string $open, string $high, string $low, string $closePrice): PriceCandle
    {
        $close = CarbonImmutable::parse($closeTime, 'UTC');

        return new PriceCandle(
            candleId: $id,
            symbol: 'BTCUSDT',
            interval: '5',
            openTime: $close->subMinutes(5),
            closeTime: $close,
            open: $open,
            high: $high,
            low: $low,
            close: $closePrice,
        );
    }
}
