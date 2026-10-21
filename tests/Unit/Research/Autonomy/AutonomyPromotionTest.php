<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Autonomy;

use App\Research\Domain\Autonomy\AllocationProposal;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A2/A3 — the paper-only promotion policy and the research-only allocation
 * proposals.
 */
final class AutonomyPromotionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function gates(): array
    {
        return [
            'min_valid_utc_days' => 20,
            'min_ess' => 20,
            'min_train_observations' => 300,
            'min_oos_observations' => 100,
            'min_shadow_observations' => 100,
            'min_shadow_days' => 14,
            'min_shadow_trades' => 50,
            'min_net_improvement_lcb' => '0',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ];
    }

    public function test_the_promotion_is_blocked_while_the_feature_flag_is_off(): void
    {
        $policy = new PaperPromotionPolicy;

        $verdict = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'shadow_observations' => 120,
            'shadow_days' => 15,
            'shadow_trades' => 60,
            'net_improvement_lcb' => '0.4',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ], $this->gates(), false);

        self::assertSame(PaperPromotionPolicy::VERDICT_BLOCKED_FLAG_OFF, $verdict['verdict']);
        self::assertFalse($verdict['applied']);
        self::assertTrue($verdict['research_only']);
    }

    public function test_the_missing_shadow_history_yields_not_eligible(): void
    {
        $policy = new PaperPromotionPolicy;

        $verdict = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'net_improvement_lcb' => '0.4',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ], $this->gates(), true);

        self::assertSame(PaperPromotionPolicy::VERDICT_NOT_ELIGIBLE, $verdict['verdict']);
        self::assertSame(PaperPromotionPolicy::REASON_SHADOW_HISTORY_ABSENT, $verdict['reason']);
        self::assertFalse($verdict['shadow_history_present']);
        self::assertContains('shadow_history', $verdict['failed_gates']);
        self::assertFalse($verdict['applied']);
    }

    public function test_a_negative_net_improvement_blocks_the_promotion(): void
    {
        $policy = new PaperPromotionPolicy;

        $verdict = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'shadow_observations' => 120,
            'shadow_days' => 15,
            'shadow_trades' => 60,
            'net_improvement_lcb' => '-0.2',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ], $this->gates(), true);

        self::assertSame(PaperPromotionPolicy::VERDICT_NOT_ELIGIBLE, $verdict['verdict']);
        self::assertContains('net_improvement_lcb', $verdict['failed_gates']);
    }

    public function test_a_drawdown_or_concentration_degradation_blocks_the_promotion(): void
    {
        $policy = new PaperPromotionPolicy;

        $verdict = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'shadow_observations' => 120,
            'shadow_days' => 15,
            'shadow_trades' => 60,
            'net_improvement_lcb' => '0.4',
            'max_drawdown_degradation_pct' => '1.5',
            'max_concentration_degradation' => '0.2',
        ], $this->gates(), true);

        self::assertSame(PaperPromotionPolicy::VERDICT_NOT_ELIGIBLE, $verdict['verdict']);
        self::assertContains('max_drawdown_degradation_pct', $verdict['failed_gates']);
        self::assertContains('max_concentration_degradation', $verdict['failed_gates']);
    }

    public function test_only_a_complete_and_positive_evidence_set_is_eligible(): void
    {
        $policy = new PaperPromotionPolicy;

        $verdict = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'shadow_observations' => 120,
            'shadow_days' => 15,
            'shadow_trades' => 60,
            'net_improvement_lcb' => '0.4',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ], $this->gates(), true);

        self::assertSame(PaperPromotionPolicy::VERDICT_ELIGIBLE, $verdict['verdict']);
        self::assertSame([], $verdict['failed_gates']);
        self::assertFalse($verdict['applied'], 'A promotion verdict is never applied by P-6/A2.');
    }

    public function test_the_allocation_proposal_never_increases_the_total_risk(): void
    {
        $proposal = (new AllocationProposal)->propose(
            ['alpha' => '100', 'beta' => '300'],
            ['alpha' => 0.2, 'beta' => 0.1],
            ['alpha|beta' => 0.8],
            ['alpha' => 0.6, 'beta' => 0.6],
            '1000'
        );

        self::assertTrue($proposal['research_only']);
        self::assertFalse($proposal['applied']);
        self::assertFalse($proposal['ledgers_merged']);
        self::assertFalse($proposal['total_risk_increased']);
        self::assertSame('400.000000000000', $proposal['total_risk_budget']);
        self::assertLessThanOrEqual(
            (float) $proposal['total_risk_budget'],
            (float) $proposal['allocated_risk_capital']
        );
    }

    public function test_the_allocation_proposal_respects_the_per_strategy_cap(): void
    {
        $proposal = (new AllocationProposal)->propose(
            ['alpha' => '100', 'beta' => '100'],
            ['alpha' => 0.01, 'beta' => 1.0],
            [],
            ['alpha' => 0.5, 'beta' => 0.5],
            '200'
        );

        $byStrategy = [];
        foreach ($proposal['proposals'] as $row) {
            $byStrategy[$row['strategy']] = $row;
        }

        self::assertTrue($byStrategy['alpha']['cap_binding']);
        self::assertSame('100.000000000000', $byStrategy['alpha']['proposed_risk_capital']);
        self::assertTrue($byStrategy['alpha']['proposed_risk_capital'] <= '100.000000000000');
    }
}
