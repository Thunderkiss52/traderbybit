<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Domain\SlippageAttribution\SlippageAllocation;
use Tests\TestCase;

/**
 * PROFIT-1 / P-1 — realized-scope slippage allocation, partial-close sanity.
 *
 * The contract fixture of the partially closed position is the 0.021 entry with
 * 0.010 realized and 0.011 still open. The entry drag of that position must be
 * split proportionally and the full-fill figure must never be presented as the
 * realized counterfactual. A partial close leaves the realized-scope Net PnL
 * unresolved (fail closed), because the persisted net_pnl carries the entry fee
 * of the full initial quantity and the funding of the open remainder.
 */
final class SlippageAllocationTest extends TestCase
{
    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function position(array $overrides = []): array
    {
        return array_merge([
            'position_id' => 'pos-1',
            'initial_quantity' => '1',
            'remaining_quantity' => '0',
            'net_pnl' => '100',
            'entry_slippage_usdt' => '10',
            'exit_slippage_usdt' => '0',
        ], $overrides);
    }

    public function test_fully_closed_position_realizes_its_whole_drag(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position(['net_pnl' => '980', 'entry_slippage_usdt' => '15', 'exit_slippage_usdt' => '5']),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_COMPUTED, $result['status']);
        $this->assertSame('HYPOTHETICAL', $result['provenance']);
        $this->assertSame(SlippageAllocation::SCOPE_ESTABLISHED, $result['realized_pnl_scope_status']);
        $this->assertSame('980', $result['realized_scope_net_pnl']);
        $this->assertSame('20', $result['realized_allocated_slippage_drag']);
        $this->assertSame('0', $result['open_unallocated_slippage_drag']);
        $this->assertSame('20', $result['full_fill_slippage_drag']);
        $this->assertSame('1000', $result['hypothetical_net_pnl_zero_slippage']);
        $this->assertSame(SlippageAllocation::RAW_STATUS_COMPUTED, $result['raw_counterfactual_status']);
    }

    public function test_partially_closed_position_allocates_entry_drag_proportionally_but_pnl_scope_stays_unresolved(): void
    {
        // Contract fixture: entry 0.021, realized 0.010, still open 0.011.
        $result = (new SlippageAllocation)->allocate([
            $this->position([
                'initial_quantity' => '0.021',
                'remaining_quantity' => '0.011',
                'net_pnl' => '5',
                'entry_slippage_usdt' => '0.021',
                'exit_slippage_usdt' => '0.003',
            ]),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_PARTIAL_CLOSE_UNRESOLVED, $result['status']);
        $this->assertSame('NOT_VERIFIED', $result['provenance']);
        $this->assertSame(SlippageAllocation::SCOPE_PARTIAL_CLOSE_UNRESOLVED, $result['realized_pnl_scope_status']);
        $this->assertNotNull($result['realized_pnl_scope_reason']);
        $this->assertSame(1, $result['partial_position_count']);

        // 0.021 entry drag * (0.010 / 0.021) realized = 0.010, plus 0.003 exit drag.
        $this->assertSame('0.013', $result['realized_allocated_slippage_drag']);
        // The open remainder 0.011 of the entry drag stays unallocated.
        $this->assertSame('0.011', $result['open_unallocated_slippage_drag']);
        $this->assertSame('0.024', $result['full_fill_slippage_drag']);
        $this->assertSame(SlippageAllocation::RAW_STATUS_NOT_VERIFIED_SCOPE_MISMATCH, $result['raw_counterfactual_status']);

        // The whole net_pnl of a partial close is not assumed to be realized-scope PnL.
        $this->assertNull($result['realized_scope_net_pnl']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
    }

    public function test_open_position_does_not_realize_any_entry_drag(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position([
                'initial_quantity' => '1.72',
                'remaining_quantity' => '1.72',
                'net_pnl' => '-2.275753198312',
                'entry_slippage_usdt' => '5.049929',
            ]),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_COMPUTED, $result['status']);
        $this->assertSame(1, $result['open_position_count']);
        $this->assertSame('0', $result['realized_allocated_slippage_drag']);
        $this->assertSame('0', $result['realized_scope_net_pnl']);
        $this->assertSame('5.049929', $result['open_unallocated_slippage_drag']);
        $this->assertSame('-2.275753198312', $result['open_position_net_pnl']);
        $this->assertSame('0', $result['hypothetical_net_pnl_zero_slippage']);
        $this->assertSame(SlippageAllocation::RAW_STATUS_NOT_VERIFIED_SCOPE_MISMATCH, $result['raw_counterfactual_status']);
    }

    public function test_population_mismatch_is_not_verified(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position(),
        ], 2);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertSame('NOT_VERIFIED', $result['provenance']);
        $this->assertFalse($result['population_match']);
        $this->assertSame(SlippageAllocation::SCOPE_POPULATION_MISMATCH, $result['realized_pnl_scope_status']);
        $this->assertNull($result['realized_scope_net_pnl']);
        $this->assertNull($result['realized_allocated_slippage_drag']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
        $this->assertSame(SlippageAllocation::RAW_STATUS_INSUFFICIENT_DATA, $result['raw_counterfactual_status']);
    }

    public function test_empty_population_is_not_verified(): void
    {
        $result = (new SlippageAllocation)->allocate([], 0);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
    }

    public function test_invalid_quantities_are_not_verified(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position(['initial_quantity' => '0', 'remaining_quantity' => '0']),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertSame(SlippageAllocation::SCOPE_INVALID_POSITION_DATA, $result['realized_pnl_scope_status']);
        $this->assertSame(1, $result['invalid_position_count']);
        $this->assertNull($result['realized_scope_net_pnl']);
        $this->assertNull($result['realized_allocated_slippage_drag']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
        // An invalid position never contributes to any total.
        $this->assertSame('0', $result['full_fill_slippage_drag']);
    }

    public function test_remaining_quantity_above_initial_quantity_is_not_verified(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position(['initial_quantity' => '1', 'remaining_quantity' => '2']),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertSame(1, $result['invalid_position_count']);
    }

    public function test_negative_remaining_quantity_is_not_verified(): void
    {
        $result = (new SlippageAllocation)->allocate([
            $this->position(['initial_quantity' => '1', 'remaining_quantity' => '-0.5']),
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertSame(1, $result['invalid_position_count']);
        $this->assertSame(SlippageAllocation::SCOPE_INVALID_POSITION_DATA, $result['realized_pnl_scope_status']);
    }

    public function test_non_numeric_and_negative_drags_are_invalid(): void
    {
        $nonNumeric = (new SlippageAllocation)->allocate([
            $this->position(['net_pnl' => 'not-a-number']),
        ], 1);
        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $nonNumeric['status']);
        $this->assertSame(1, $nonNumeric['invalid_position_count']);

        $negativeDrag = (new SlippageAllocation)->allocate([
            $this->position(['entry_slippage_usdt' => '-1']),
        ], 1);
        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $negativeDrag['status']);
        $this->assertSame(1, $negativeDrag['invalid_position_count']);
    }

    public function test_missing_values_are_invalid_not_zero(): void
    {
        $result = (new SlippageAllocation)->allocate([
            [
                'position_id' => 'pos-missing',
                'initial_quantity' => '1',
                'remaining_quantity' => '0',
                'net_pnl' => '',
                'entry_slippage_usdt' => '10',
                'exit_slippage_usdt' => '0',
            ],
        ], 1);

        $this->assertSame(SlippageAllocation::STATUS_INSUFFICIENT_DATA, $result['status']);
        $this->assertSame(1, $result['invalid_position_count']);
        $this->assertNull($result['realized_scope_net_pnl']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
    }

    public function test_mixed_population_never_presents_a_partial_close_counterfactual(): void
    {
        $rows = [
            $this->position(['position_id' => 'closed', 'net_pnl' => '100', 'entry_slippage_usdt' => '10', 'exit_slippage_usdt' => '2']),
            $this->position([
                'position_id' => 'partial',
                'initial_quantity' => '0.021',
                'remaining_quantity' => '0.011',
                'net_pnl' => '7',
                'entry_slippage_usdt' => '0.021',
                'exit_slippage_usdt' => '0.003',
            ]),
            $this->position([
                'position_id' => 'open',
                'initial_quantity' => '1.72',
                'remaining_quantity' => '1.72',
                'net_pnl' => '-2.275753198312',
                'entry_slippage_usdt' => '5.049929',
            ]),
        ];

        $result = (new SlippageAllocation)->allocate($rows, 3);

        $this->assertSame(SlippageAllocation::STATUS_PARTIAL_CLOSE_UNRESOLVED, $result['status']);
        $this->assertSame(1, $result['closed_position_count']);
        $this->assertSame(1, $result['partial_position_count']);
        $this->assertSame(1, $result['open_position_count']);

        $this->assertSame('12.013', $result['realized_allocated_slippage_drag']);
        $this->assertSame('5.060929', $result['open_unallocated_slippage_drag']);
        $this->assertSame('17.073929', $result['full_fill_slippage_drag']);

        // The realized counterfactual is not available while a partial close
        // has an unresolved realized-PnL scope.
        $this->assertNull($result['realized_scope_net_pnl']);
        $this->assertNull($result['hypothetical_net_pnl_zero_slippage']);
        $this->assertNotSame($result['full_fill_slippage_drag'], $result['realized_allocated_slippage_drag']);
    }
}
