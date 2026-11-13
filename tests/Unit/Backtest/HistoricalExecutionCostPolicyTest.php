<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Backtest\Application\HistoricalExecutionCostPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HistoricalExecutionCostPolicyTest extends TestCase
{
    public function test_independent_exchange_costs_replace_common_defaults(): void
    {
        $paper = ['fees' => ['taker_rate' => '0.00055'], 'slippage_rate' => '0.0002'];
        $actual = HistoricalExecutionCostPolicy::apply('okx', $paper, 'linear_perpetual', [
            'maker_rate' => '0', 'taker_rate' => '0.0004', 'slippage_rate' => '0.0003',
        ]);
        self::assertSame('0.0004', $actual['fees']['taker_rate']);
        self::assertSame('0.0003', $actual['slippage_rate']);
        self::assertSame('0.00055', $paper['fees']['taker_rate']);
    }

    public function test_missing_or_invalid_market_costs_fail_closed(): void
    {
        $paper = ['fees' => ['taker_rate' => '0.00055'], 'slippage_rate' => '0.0002'];
        $cases = [
            [null, null],
            ['spot', ['maker_rate' => '0', 'taker_rate' => '0', 'slippage_rate' => '0']],
            ['linear_perpetual', ['taker_rate' => '0.001', 'slippage_rate' => '0.002']],
            ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => '0.01', 'slippage_rate' => '-1']],
            ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => 'NaN', 'slippage_rate' => '0']],
            ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => 0.01, 'slippage_rate' => '0']],
            ['linear_perpetual', ['maker_rate' => '0', 'taker_rate' => '0.1', 'slippage_rate' => '0']],
        ];
        foreach ($cases as [$type, $costs]) {
            try {
                HistoricalExecutionCostPolicy::apply('okx', $paper, $type, $costs);
                self::fail('Unverified exchange costs were accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
