<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Decision;

use App\Research\Domain\Decision\HypothesisPriority;
use PHPUnit\Framework\TestCase;

/**
 * PROFIT-1 / P-6 — economic prioritisation of registered hypotheses (§14.4).
 */
final class HypothesisPriorityTest extends TestCase
{
    private HypothesisPriority $priority;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priority = new HypothesisPriority;
    }

    public function test_historical_recovery_is_scaled_to_one_hundred_trades_and_marked_hypothetical(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-1',
                'assumption_version' => 'v1',
                'status' => 'TESTED',
                'source_generation_ids' => ['gen-1'],
                'delta_net_pnl' => ['10', '20', '30'],
            ],
        ]);

        self::assertCount(1, $rows);
        // 100 * MEAN(10, 20, 30) = 2000 USDT per 100 position lifecycles.
        self::assertSame('2000.000000000000', $rows[0]['historical_counterfactual_recovery_usdt_per_100_trades']);
        self::assertSame(HypothesisPriority::HISTORICAL_STATUS_HYPOTHETICAL, $rows[0]['historical_estimate_status']);
        self::assertSame(3, $rows[0]['sample_size']);
    }

    public function test_an_untested_hypothesis_gets_no_point_estimate(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-UNTESTED',
                'assumption_version' => 'v1',
                'status' => 'REGISTERED',
                'delta_net_pnl' => [],
            ],
        ]);

        self::assertNull($rows[0]['historical_counterfactual_recovery_usdt_per_100_trades']);
        self::assertSame(HypothesisPriority::HISTORICAL_STATUS_NOT_ESTIMATED, $rows[0]['historical_estimate_status']);
        self::assertSame('NO_MATCHED_POPULATION', $rows[0]['historical_estimate_reason']);
        self::assertNull($rows[0]['expected_capital_recovery_usdt_per_100_trades']);
    }

    public function test_forward_estimate_requires_a_sufficient_oos_sample(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-1',
                'status' => 'TESTED',
                'delta_net_pnl' => ['5'],
                'oos_evaluation' => [
                    'expected_capital_recovery_usdt_per_100_trades' => '12.5',
                    'lower_confidence_bound' => '-3.2',
                    'upper_confidence_bound' => '28.1',
                    'sample_sufficiency' => 'INSUFFICIENT_DATA',
                    'block_method' => 'DAY_BLOCK_BOOTSTRAP',
                    'seed' => 1234,
                ],
            ],
        ]);

        self::assertNull($rows[0]['expected_capital_recovery_usdt_per_100_trades']);
        self::assertSame(HypothesisPriority::HISTORICAL_STATUS_NOT_ESTIMATED, $rows[0]['forward_estimate_status']);
        self::assertSame('INSUFFICIENT_DATA', $rows[0]['forward_estimate_reason']);
    }

    public function test_forward_estimate_is_reported_for_a_sufficient_oos_sample(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-1',
                'status' => 'TESTED',
                'delta_net_pnl' => ['5'],
                'oos_evaluation' => [
                    'expected_capital_recovery_usdt_per_100_trades' => '12.5',
                    'lower_confidence_bound' => '-3.2',
                    'upper_confidence_bound' => '28.1',
                    'sample_sufficiency' => 'SUFFICIENT',
                    'block_method' => 'DAY_BLOCK_BOOTSTRAP',
                    'seed' => 1234,
                ],
            ],
        ]);

        self::assertSame('12.5', $rows[0]['expected_capital_recovery_usdt_per_100_trades']);
        self::assertSame('FORWARD_OOS_ESTIMATED', $rows[0]['forward_estimate_status']);
        self::assertSame('-3.2', $rows[0]['lower_confidence_bound']);
    }

    public function test_rows_are_sorted_by_the_conservative_lower_bound_and_untested_ideas_come_last(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-UNTESTED',
                'status' => 'REGISTERED',
                'delta_net_pnl' => [],
            ],
            [
                'hypothesis_id' => 'H-LOW',
                'status' => 'TESTED',
                'delta_net_pnl' => ['1'],
                'oos_evaluation' => [
                    'expected_capital_recovery_usdt_per_100_trades' => '5',
                    'lower_confidence_bound' => '-10',
                    'upper_confidence_bound' => '20',
                    'sample_sufficiency' => 'SUFFICIENT',
                ],
            ],
            [
                'hypothesis_id' => 'H-HIGH',
                'status' => 'TESTED',
                'delta_net_pnl' => ['2'],
                'oos_evaluation' => [
                    'expected_capital_recovery_usdt_per_100_trades' => '9',
                    'lower_confidence_bound' => '4',
                    'upper_confidence_bound' => '14',
                    'sample_sufficiency' => 'SUFFICIENT',
                ],
            ],
        ]);

        self::assertSame(['H-HIGH', 'H-LOW', 'H-UNTESTED'], array_column($rows, 'hypothesis_id'));
        self::assertSame([1, 2, 3], array_column($rows, 'rank'));
    }

    public function test_the_dimensions_are_kept_separate_and_no_composite_score_is_produced(): void
    {
        $rows = $this->priority->build([
            [
                'hypothesis_id' => 'H-1',
                'status' => 'TESTED',
                'delta_net_pnl' => ['1'],
                'drawdown_impact' => '-2.5%',
                'cost_assumptions' => 'taker 0.055% / maker 0.02%',
                'ess' => '18.4',
                'sample_sufficiency' => 'SUFFICIENT',
                'concentration' => '2 overlap clusters',
            ],
        ]);

        self::assertSame('-2.5%', $rows[0]['drawdown_impact']);
        self::assertSame('18.4', $rows[0]['ess']);
        self::assertSame('2 overlap clusters', $rows[0]['concentration']);
        self::assertArrayNotHasKey('composite_score', $rows[0]);
        self::assertArrayNotHasKey('score', $rows[0]);
    }
}
