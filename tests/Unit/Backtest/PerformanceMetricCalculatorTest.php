<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Backtest\Application\PerformanceMetricCalculator;
use PHPUnit\Framework\TestCase;

final class PerformanceMetricCalculatorTest extends TestCase
{
    public function test_known_trade_and_equity_metrics_are_exact(): void
    {
        $trades = [
            ['closed_at' => 'x', 'net_pnl' => '100', 'fees' => '2', 'funding' => '-1', 'r_multiple' => '2', 'holding_seconds' => 600, 'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A', 'strategy_version' => '3'],
            ['closed_at' => 'y', 'net_pnl' => '-50', 'fees' => '2', 'funding' => '0', 'r_multiple' => '-1', 'holding_seconds' => 1200, 'symbol' => 'BTCUSDT', 'side' => 'SELL', 'quality_class' => 'B', 'strategy_version' => '3'],
        ];
        $snapshots = [['equity' => '10000', 'exposed' => false], ['equity' => '10100', 'exposed' => true], ['equity' => '10050', 'exposed' => false]];
        $metrics = (new PerformanceMetricCalculator)->calculate($trades, $snapshots, ['interval_minutes' => 5, 'periods_per_year' => 105120, 'risk_free_rate' => '0']);
        self::assertSame(2, $metrics['total_trades']);
        self::assertSame('50.00000000', $metrics['win_rate']);
        self::assertSame('50', $metrics['net_pnl']);
        self::assertSame('2.000000000000', $metrics['profit_factor']);
        self::assertSame('0.500000000000', $metrics['average_r']);
        self::assertSame('900.000000000000', $metrics['average_holding_seconds']);
        self::assertSame(1, $metrics['maximum_consecutive_losses']);
        self::assertNotNull($metrics['sharpe_ratio']);
    }

    public function test_profit_factor_is_null_when_there_are_no_losses(): void
    {
        $trade = ['closed_at' => 'x', 'net_pnl' => '1', 'fees' => '0', 'funding' => '0', 'r_multiple' => '1', 'holding_seconds' => 1, 'symbol' => 'ETHUSDT', 'side' => 'BUY', 'quality_class' => 'A', 'strategy_version' => '3'];
        $metrics = (new PerformanceMetricCalculator)->calculate([$trade], [], ['interval_minutes' => 5, 'periods_per_year' => 105120, 'risk_free_rate' => '0']);
        self::assertNull($metrics['profit_factor']);
        self::assertSame('no_negative_net_pnl', $metrics['profit_factor_reason']);
    }
}
