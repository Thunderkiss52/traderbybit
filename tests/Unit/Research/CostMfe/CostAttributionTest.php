<?php

declare(strict_types=1);

namespace Tests\Unit\Research\CostMfe;

use App\Research\Domain\CostMfe\CostAttribution;
use Tests\TestCase;

/**
 * PROFIT-1 / P-4 — cost attribution by strategy, symbol, account and fill
 * role (§9.1).
 */
final class CostAttributionTest extends TestCase
{
    public function test_cost_is_attributed_by_strategy_symbol_account_and_fill_role(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [
                $this->execution('e1', 'p1', 's1', 'a1', 'BTCUSDT', 'ENTRY', 'BUY', '1.000000000000', '100.000000000000', '0.500000000000', '0.100000000000', '0.000000000000'),
                $this->execution('e2', 'p1', 's1', 'a1', 'BTCUSDT', 'STOP_LOSS', 'SELL', '1.000000000000', '90.000000000000', '0.500000000000', '0.200000000000', '-10.000000000000'),
            ],
            fundingEvents: [
                ['position_id' => 'p1', 'account_id' => 'a1', 'strategy_id' => 's1', 'symbol' => 'BTCUSDT', 'amount' => '0.300000000000'],
            ],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_SL',
                    'gross_pnl' => '-10.000000000000',
                    'net_pnl' => '-10.000000000000',
                    'fees' => '0.300000000000',
                ],
            ],
        );

        self::assertSame(['s1'], array_keys($result['strategy']));
        self::assertSame(['BTCUSDT'], array_keys($result['symbol']));
        self::assertSame(['a1'], array_keys($result['account']));
        self::assertSame(['ENTRY', 'FINAL_EXIT'], array_keys($result['fill_role']));

        $strategy = $result['strategy']['s1'];
        self::assertSame(2, $strategy['execution_count']);
        self::assertSame('-10.000000000000', $strategy['gross_pnl']);
        self::assertSame('0.300000000000', $strategy['commission_total']);
        self::assertSame('1.000000000000', $strategy['slippage_drag_usdt']);
        self::assertSame('0.300000000000', $strategy['funding_signed']);
        self::assertSame('-10.000000000000', $strategy['net_pnl']);
        self::assertTrue($strategy['reconciles_gross_minus_fees_plus_funding']);
    }

    public function test_slippage_drag_uses_quantity_and_reference_notional_without_double_deduction(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [
                $this->execution('e1', 'p1', 's1', 'a1', 'BTCUSDT', 'ENTRY', 'BUY', '2.000000000000', '100.000000000000', '0.500000000000', '0.100000000000', '0.000000000000'),
            ],
            fundingEvents: [],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_SL',
                    'gross_pnl' => '0.000000000000',
                    'net_pnl' => '-0.100000000000',
                    'fees' => '0.100000000000',
                ],
            ],
        );

        $strategy = $result['strategy']['s1'];
        self::assertSame('1.000000000000', $strategy['slippage_drag_usdt']);
        self::assertSame('199.000000000000', $strategy['reference_notional_total']);
        self::assertSame('50.2513', $strategy['weighted_slippage_bps']);
        self::assertSame('-0.100000000000', $strategy['net_pnl']);
        self::assertSame('0.100000000000', $strategy['fees_total']);
    }

    public function test_undefined_ratios_are_null_with_a_reason_instead_of_zero(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [
                $this->execution('e1', 'p1', 's1', 'a1', 'BTCUSDT', 'ENTRY', 'BUY', '1.000000000000', '0.000000000000', '0.000000000000', '0.100000000000', '-5.000000000000'),
            ],
            fundingEvents: [],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_SL',
                    'gross_pnl' => '-5.000000000000',
                    'net_pnl' => '-5.100000000000',
                    'fees' => '0.100000000000',
                ],
            ],
        );

        $strategy = $result['strategy']['s1'];
        self::assertNull($strategy['commission_share_of_gross_pnl']);
        self::assertSame('GROSS_PNL_NOT_POSITIVE', $strategy['commission_share_of_gross_pnl_reason']);
        self::assertNull($strategy['weighted_slippage_bps']);
        self::assertSame('ZERO_REFERENCE_NOTIONAL', $strategy['weighted_slippage_bps_reason']);
    }

    public function test_gross_positive_positions_that_end_net_negative_are_counted(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [],
            fundingEvents: [],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_TP',
                    'gross_pnl' => '10.000000000000',
                    'net_pnl' => '-0.500000000000',
                    'fees' => '10.500000000000',
                ],
                [
                    'id' => 'p2',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'OPEN',
                    'gross_pnl' => '1.000000000000',
                    'net_pnl' => '1.000000000000',
                    'fees' => '0.000000000000',
                ],
            ],
        );

        $strategy = $result['strategy']['s1'];
        self::assertSame(1, $strategy['gross_positive_net_negative_count']);
        self::assertSame(2, $strategy['position_count']);
        self::assertSame(1, $strategy['open_position_count']);
        self::assertSame(1, $strategy['closed_position_count']);
    }

    public function test_ambiguous_executions_are_counted_and_partial_exits_use_their_own_fill_role(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [
                $this->execution('e1', 'p1', 's1', 'a1', 'BTCUSDT', 'ENTRY', 'BUY', '1.000000000000', '100.000000000000', '0.100000000000', '0.100000000000', '0.000000000000'),
                $this->execution('e2', 'p1', 's1', 'a1', 'BTCUSDT', 'PARTIAL_TAKE_PROFIT_1', 'SELL', '0.400000000000', '110.000000000000', '0.100000000000', '0.100000000000', '4.000000000000', ambiguous: true),
            ],
            fundingEvents: [],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_TP',
                    'gross_pnl' => '4.000000000000',
                    'net_pnl' => '3.800000000000',
                    'fees' => '0.200000000000',
                ],
            ],
        );

        self::assertSame(1, $result['strategy']['s1']['ambiguous_execution_count']);
        self::assertArrayHasKey('PARTIAL_EXIT', $result['fill_role']);
        self::assertSame(1, $result['fill_role']['PARTIAL_EXIT']['execution_count']);
        self::assertSame('0.040000000000', $result['fill_role']['PARTIAL_EXIT']['slippage_drag_usdt']);
        self::assertSame('0.100000000000', $result['fill_role']['ENTRY']['commission_total']);
    }

    public function test_positions_without_execution_rows_are_reported_as_missing_cost_records(): void
    {
        $attribution = new CostAttribution;

        $result = $attribution->aggregate(
            executions: [],
            fundingEvents: [],
            positions: [
                [
                    'id' => 'p1',
                    'account_id' => 'a1',
                    'strategy_id' => 's1',
                    'symbol' => 'BTCUSDT',
                    'status' => 'CLOSED_TIMEOUT',
                    'gross_pnl' => '3.073882000000',
                    'net_pnl' => '-1.951542200940',
                    'fees' => '4.763323971340',
                ],
            ],
        );

        $strategy = $result['strategy']['s1'];
        self::assertSame(1, $strategy['positions_with_missing_execution_rows']);
        self::assertSame('-4.763323971340', $strategy['execution_commission_minus_position_fees_delta']);
        self::assertSame(0, $strategy['execution_count']);
        self::assertSame(1, $strategy['positions_missing_entry_execution']);
        self::assertSame(['p1'], $strategy['position_ids_missing_entry_execution']);
    }

    /**
     * @return array<string, mixed>
     */
    private function execution(
        string $id,
        string $positionId,
        string $strategyId,
        string $accountId,
        string $symbol,
        string $type,
        string $side,
        string $quantity,
        string $price,
        string $slippage,
        string $commission,
        string $grossPnl,
        bool $ambiguous = false,
    ): array {
        return [
            'id' => $id,
            'position_id' => $positionId,
            'strategy_id' => $strategyId,
            'account_id' => $accountId,
            'symbol' => $symbol,
            'type' => $type,
            'side' => $side,
            'quantity' => $quantity,
            'price' => $price,
            'slippage' => $slippage,
            'commission' => $commission,
            'gross_pnl' => $grossPnl,
            'ambiguous_execution' => $ambiguous,
        ];
    }
}
