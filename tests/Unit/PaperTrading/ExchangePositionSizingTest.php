<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\InstrumentConstraints;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class ExchangePositionSizingTest extends TestCase
{
    public function test_explicit_execution_costs_reduce_quantity_without_changing_legacy_defaults(): void
    {
        $config = require dirname(__DIR__, 3).'/config/paper.php';
        $sizing = new PositionSizingService($config);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
        $legacy = $sizing->calculate('10000', '0.50', '100', '95', $limits);
        $costs = ['taker_rate' => '0.01', 'slippage_rate' => '0.02'];
        $other = $sizing->calculate('10000', '0.50', '100', '95', $limits, $costs);

        self::assertTrue($other->allowed);
        self::assertTrue(BigDecimal::of($other->quantity)->isLessThan($legacy->quantity));
        self::assertTrue(BigDecimal::of($other->effectiveLossPerUnit)->isEqualTo('10.85'));
        self::assertTrue(BigDecimal::of($other->quantity)->multipliedBy('10.85')->isLessThanOrEqualTo('50'));
        self::assertEquals($legacy, $sizing->calculate('10000', '0.50', '100', '95', $limits));
    }

    public function test_incomplete_or_inexact_explicit_costs_never_fall_back_to_bybit(): void
    {
        $config = require dirname(__DIR__, 3).'/config/paper.php';
        $sizing = new PositionSizingService($config);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
        foreach ([[], ['taker_rate' => '0.01'], ['taker_rate' => 0.01, 'slippage_rate' => '0.02'],
            ['taker_rate' => '-0.01', 'slippage_rate' => '0.02'],
            ['taker_rate' => '0.01', 'slippage_rate' => 'NaN']] as $costs) {
            $size = $sizing->calculate('10000', '0.50', '100', '95', $limits, $costs);
            self::assertFalse($size->allowed);
            self::assertSame('invalid_execution_costs', $size->reason);
        }
    }
}
