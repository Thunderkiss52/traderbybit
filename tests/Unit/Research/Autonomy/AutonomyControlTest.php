<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Autonomy;

use App\Research\Domain\Autonomy\AllocationProposal;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Domain\Autonomy\PromotionControlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A2/A3 — the promotion control (rollback triggers, kill switch) and the
 * correlation-aware budgeting proposal.
 */
final class AutonomyControlTest extends TestCase
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

    public function test_the_rollback_triggers_are_precommitted_and_immutable(): void
    {
        $triggers = PromotionControlPolicy::rollbackTriggers();

        self::assertCount(4, $triggers);
        self::assertSame($triggers, PromotionControlPolicy::rollbackTriggers());

        foreach ($triggers as $trigger) {
            self::assertArrayHasKey('id', $trigger);
            self::assertArrayHasKey('metric', $trigger);
            self::assertArrayHasKey('operator', $trigger);
            self::assertArrayHasKey('threshold', $trigger);
            self::assertArrayHasKey('source', $trigger);
            self::assertSame('ROLLBACK_TO_PREVIOUS_PAPER_VERSION', $trigger['action']);
        }
    }

    public function test_a_breached_trigger_requires_a_rollback(): void
    {
        $evaluation = PromotionControlPolicy::evaluateTriggers([
            'max_drawdown_pct' => 6.0,
            'net_r_multiple' => 0.5,
            'concentration_ratio' => 0.4,
        ]);

        self::assertSame('ROLLBACK_REQUIRED', $evaluation['status']);
        self::assertContains('ROLLBACK_MAX_DRAWDOWN', $evaluation['breached']);
        self::assertNotContains('ROLLBACK_NET_PERFORMANCE', $evaluation['breached']);
        self::assertContains('ROLLBACK_EVIDENCE_REVOKED', $evaluation['unobserved']);
    }

    public function test_an_unobserved_metric_never_counts_as_a_breach(): void
    {
        $evaluation = PromotionControlPolicy::evaluateTriggers([]);

        self::assertSame('NO_TRIGGER_BREACHED', $evaluation['status']);
        self::assertSame([], $evaluation['breached']);
        self::assertCount(4, $evaluation['unobserved']);
    }

    public function test_the_approval_content_carries_the_source_ids_and_the_rollback_triggers(): void
    {
        $approval = PromotionControlPolicy::approvalContent([
            'approved_at' => '2026-10-21T10:00:00Z',
            'source_generation_ids' => ['gen-1'],
            'source_hashes' => ['s7' => 'abc'],
            'rule_sha' => 'rulesha',
            'old_paper_version' => '1.0.0',
            'new_paper_version' => '1.1.0',
            'cohort_id' => 'research_cohort_demo',
            'safety_verdict' => 'PASSED',
            'monitoring_thresholds' => ['max_drawdown_pct' => '5.0'],
        ]);

        self::assertSame(['gen-1'], $approval['source_generation_ids']);
        self::assertSame('rulesha', $approval['rule_sha']);
        self::assertSame('1.0.0', $approval['old_paper_version']);
        self::assertSame('1.1.0', $approval['new_paper_version']);
        self::assertCount(4, $approval['rollback_triggers']);
        self::assertTrue($approval['reversible']);
        self::assertFalse($approval['applied']);
        self::assertTrue($approval['research_only']);
    }

    public function test_the_kill_switch_blocks_an_otherwise_eligible_promotion(): void
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
        ], $this->gates(), true, true);

        self::assertSame(PaperPromotionPolicy::VERDICT_BLOCKED_KILL_SWITCH, $verdict['verdict']);
        self::assertSame(PaperPromotionPolicy::REASON_KILL_SWITCH, $verdict['reason']);
        self::assertTrue($verdict['kill_switch_active']);
        self::assertFalse($verdict['applied']);
    }

    public function test_the_correlation_cluster_cap_limits_a_duplicated_exposure(): void
    {
        $proposal = (new AllocationProposal)->propose(
            ['alpha' => '100', 'beta' => '100'],
            ['alpha' => 0.1, 'beta' => 0.4],
            ['alpha|beta' => 0.9],
            ['alpha' => 1.0, 'beta' => 1.0],
            '200',
            0.8,
            0.6
        );

        $byStrategy = [];
        foreach ($proposal['proposals'] as $row) {
            $byStrategy[$row['strategy']] = $row;
        }

        self::assertTrue($byStrategy['alpha']['cluster_cap_binding']);
        self::assertTrue($byStrategy['beta']['cluster_cap_binding']);
        self::assertSame('120.000000000000', $proposal['allocated_risk_capital']);
        self::assertSame('80.000000000000', $proposal['unallocated_risk_capital']);
        self::assertFalse($proposal['total_risk_increased']);
        self::assertTrue($proposal['diversification_improved']);
        self::assertTrue($proposal['portfolio_volatility_after'] < $proposal['portfolio_volatility_before']);
    }

    public function test_an_uncorrelated_book_is_not_cluster_capped(): void
    {
        $proposal = (new AllocationProposal)->propose(
            ['alpha' => '100', 'beta' => '100'],
            ['alpha' => 0.1, 'beta' => 0.4],
            ['alpha|beta' => 0.2],
            ['alpha' => 1.0, 'beta' => 1.0],
            '200',
            0.8,
            0.6
        );

        foreach ($proposal['proposals'] as $row) {
            self::assertFalse($row['cluster_cap_binding']);
        }

        self::assertSame('200.000000000000', $proposal['allocated_risk_capital']);
    }

    public function test_the_cluster_cap_never_increases_the_total_risk(): void
    {
        $proposal = (new AllocationProposal)->propose(
            ['alpha' => '100', 'beta' => '100', 'gamma' => '100'],
            ['alpha' => 0.1, 'beta' => 0.2, 'gamma' => 0.3],
            ['alpha|beta' => 0.95, 'beta|gamma' => 0.1, 'alpha|gamma' => 0.1],
            ['alpha' => 1.0, 'beta' => 1.0, 'gamma' => 1.0],
            '300',
            0.8,
            0.6
        );

        self::assertFalse($proposal['total_risk_increased']);
        self::assertLessThanOrEqual(300.0, (float) $proposal['allocated_risk_capital']);
        self::assertTrue($proposal['unallocated_risk_capital'] !== '0.000000000000');
    }
}
