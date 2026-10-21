<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Concentration;

use App\Research\Domain\Concentration\EquityAlignment;
use Tests\TestCase;

/**
 * PROFIT-1 / P-5 — equity synchronization and simultaneous drawdown (§10.3).
 */
final class EquityAlignmentTest extends TestCase
{
    public function test_alignment_is_not_comparable_when_a_snapshot_is_missing_beyond_the_max_carry_forward_age(): void
    {
        $alignment = new EquityAlignment;

        $result = $alignment->align([
            'a1' => [
                ['ts' => 1000, 'equity' => '100'],
                ['ts' => 2000, 'equity' => '90'],
            ],
            'a2' => [
                ['ts' => 1000, 'equity' => '100'],
            ],
        ], 1000, 500, 2000);

        self::assertSame(EquityAlignment::STATUS_NOT_COMPARABLE, $result['status']);
        self::assertSame(EquityAlignment::REASON_MISSING_SNAPSHOTS, $result['reason']);
        self::assertGreaterThan(0, $result['missing_points']);
    }

    public function test_simultaneous_drawdown_periods_are_detected_without_netting_accounts(): void
    {
        $alignment = new EquityAlignment;

        $result = $alignment->align([
            'a1' => [
                ['ts' => 1000, 'equity' => '100'],
                ['ts' => 2000, 'equity' => '90'],
                ['ts' => 3000, 'equity' => '95'],
            ],
            'a2' => [
                ['ts' => 1000, 'equity' => '100'],
                ['ts' => 2000, 'equity' => '80'],
                ['ts' => 3000, 'equity' => '85'],
            ],
        ], 1000, 2000, 3000);

        self::assertSame(EquityAlignment::STATUS_COMPARABLE, $result['status']);
        self::assertCount(1, $result['simultaneous_drawdown_periods']);
        $period = $result['simultaneous_drawdown_periods'][0];
        self::assertSame(2, $period['max_concurrent_accounts']);
        self::assertSame(['a1', 'a2'], $period['accounts']);
    }

    public function test_missing_snapshots_never_become_zero_return_observations(): void
    {
        $alignment = new EquityAlignment;

        $result = $alignment->align([
            'a1' => [
                ['ts' => 1000, 'equity' => '100'],
            ],
            'a2' => [
                ['ts' => 1000, 'equity' => '100'],
                ['ts' => 2000, 'equity' => '50'],
            ],
        ], 1000, 100, 2000);

        self::assertSame(EquityAlignment::STATUS_NOT_COMPARABLE, $result['status']);
        self::assertGreaterThan(0, $result['missing_points']);
        self::assertNull(
            $result['aligned'][2000]['values']['a1'],
            'A missing snapshot stays missing; it is never interpolated and never replaced by a zero equity.'
        );
        self::assertSame('50.000000000000', $result['aligned'][2000]['values']['a2']['equity']);
    }

    public function test_single_account_population_is_not_comparable_for_cross_account_comparison(): void
    {
        $alignment = new EquityAlignment;

        $result = $alignment->align([
            'a1' => [['ts' => 1000, 'equity' => '100']],
        ], 1000, 1000, 1000);

        self::assertSame(EquityAlignment::STATUS_NOT_COMPARABLE, $result['status']);
        self::assertSame(EquityAlignment::REASON_SINGLE_ACCOUNT, $result['reason']);
    }
}
