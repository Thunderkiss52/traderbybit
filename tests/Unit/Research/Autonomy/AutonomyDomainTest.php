<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Autonomy;

use App\Research\Domain\Autonomy\CandidateRegistry;
use App\Research\Domain\Autonomy\ChallengerExperiment;
use App\Research\Domain\Autonomy\DataSufficiencyPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A0/A1 — candidate registry, data sufficiency and the challenger state machine.
 */
final class AutonomyDomainTest extends TestCase
{
    public function test_the_registry_is_immutable_deterministic_and_complete(): void
    {
        $first = CandidateRegistry::all();
        $second = CandidateRegistry::all();

        self::assertCount(5, $first);
        self::assertSame(
            array_map(static fn ($h): string => $h->id, $first),
            array_map(static fn ($h): string => $h->id, $second)
        );
        self::assertSame(CandidateRegistry::hash(), CandidateRegistry::hash());
        self::assertNotNull(CandidateRegistry::lookup('liquidity_sweep_reversal'));
        self::assertNull(CandidateRegistry::lookup('does_not_exist'));
    }

    public function test_every_candidate_declares_the_required_registration_fields(): void
    {
        foreach (CandidateRegistry::all() as $hypothesis) {
            $payload = $hypothesis->toArray();

            foreach ([
                'hypothesis_id', 'problem_statement', 'evidence_population', 'null_hypothesis',
                'primary_metric', 'expected_effect_sign', 'time_known_cutoff_rule', 'train_split',
                'oos_split', 'forward_split', 'familywise_experiment_count', 'data_sufficiency_statement',
                'precommitted_thresholds', 'risk_budget', 'proposed_versioned_rules',
                'negative_outcomes_to_watch', 'instruments',
            ] as $field) {
                self::assertArrayHasKey($field, $payload);
                self::assertNotEmpty($payload[$field], $hypothesis->id.' must declare '.$field);
            }

            self::assertSame(5, $payload['familywise_experiment_count']);
            self::assertSame(64, strlen($hypothesis->registrationHash()));
        }
    }

    public function test_the_lead_lag_candidate_is_registered_but_not_executable(): void
    {
        $hypothesis = CandidateRegistry::lookup('btc_eth_lead_lag');

        self::assertNotNull($hypothesis);
        self::assertFalse($hypothesis->executable);
    }

    public function test_data_sufficiency_fails_closed_on_a_missing_observation(): void
    {
        $policy = new DataSufficiencyPolicy;

        $result = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
        ], [
            'min_valid_utc_days' => 20,
            'min_ess' => 20,
            'min_train_observations' => 300,
            'min_oos_observations' => 100,
            'min_shadow_observations' => 100,
            'min_shadow_days' => 14,
            'min_shadow_trades' => 50,
        ]);

        self::assertSame(DataSufficiencyPolicy::STATUS_INSUFFICIENT, $result['status']);
        self::assertContains('shadow_observations', $result['missing']);
        self::assertContains('shadow_days', $result['missing']);
        self::assertContains('shadow_trades', $result['missing']);
    }

    public function test_data_sufficiency_passes_only_when_every_requirement_is_met(): void
    {
        $policy = new DataSufficiencyPolicy;

        $result = $policy->evaluate([
            'valid_utc_days' => 30,
            'ess' => 25,
            'train_observations' => 400,
            'oos_observations' => 150,
            'shadow_observations' => 120,
            'shadow_days' => 15,
            'shadow_trades' => 60,
        ], [
            'min_valid_utc_days' => 20,
            'min_ess' => 20,
            'min_train_observations' => 300,
            'min_oos_observations' => 100,
            'min_shadow_observations' => 100,
            'min_shadow_days' => 14,
            'min_shadow_trades' => 50,
        ]);

        self::assertSame(DataSufficiencyPolicy::STATUS_SUFFICIENT, $result['status']);
        self::assertSame([], $result['missing']);
    }

    public function test_data_sufficiency_rejects_invalid_numbers_and_missing_gates(): void
    {
        $policy = new DataSufficiencyPolicy;
        $valid = [
            'valid_utc_days' => 30, 'ess' => 25, 'train_observations' => 400,
            'oos_observations' => 150, 'shadow_observations' => 120,
            'shadow_days' => 15, 'shadow_trades' => 60,
        ];
        $gates = [
            'min_valid_utc_days' => 20, 'min_ess' => 20,
            'min_train_observations' => 300, 'min_oos_observations' => 100,
            'min_shadow_observations' => 100, 'min_shadow_days' => 14,
            'min_shadow_trades' => 50,
        ];
        foreach ([true, INF, NAN, 'bad', []] as $bad) {
            $result = $policy->evaluate(array_replace($valid, ['ess' => $bad]), $gates);
            self::assertSame(DataSufficiencyPolicy::STATUS_INSUFFICIENT, $result['status']);
            self::assertContains('ess', $result['missing']);
        }
        unset($gates['min_shadow_trades']);
        $result = $policy->evaluate($valid, $gates);
        self::assertSame(DataSufficiencyPolicy::STATUS_INSUFFICIENT, $result['status']);
        self::assertContains('MISSING_GATE_THRESHOLD:min_shadow_trades', $result['reasons']);
    }

    public function test_the_experiment_cannot_skip_the_data_check(): void
    {
        $transition = ChallengerExperiment::canTransition(
            ChallengerExperiment::PROPOSED,
            ChallengerExperiment::REGISTERED
        );

        self::assertFalse($transition['allowed']);
        self::assertSame('ILLEGAL_TRANSITION:PROPOSED->REGISTERED', $transition['reason']);
    }

    public function test_the_experiment_cannot_be_registered_without_a_preregistration_hash(): void
    {
        $transition = ChallengerExperiment::canTransition(
            ChallengerExperiment::DATA_CHECK,
            ChallengerExperiment::REGISTERED,
            ['candidate_id' => 'liquidity_sweep_reversal', 'data_check_done' => true]
        );

        self::assertFalse($transition['allowed']);
        self::assertSame('PREREGISTRATION_HASH_MISSING', $transition['reason']);
    }

    public function test_the_experiment_cannot_be_registered_after_the_oos_result_was_seen(): void
    {
        $transition = ChallengerExperiment::canTransition(
            ChallengerExperiment::DATA_CHECK,
            ChallengerExperiment::REGISTERED,
            [
                'candidate_id' => 'liquidity_sweep_reversal',
                'data_check_done' => true,
                'preregistration_hash' => 'hash-1',
                'oos_viewed' => true,
            ]
        );

        self::assertFalse($transition['allowed']);
        self::assertSame('PREREGISTRATION_AFTER_OOS_INSPECTION', $transition['reason']);
    }

    public function test_the_data_check_requires_a_registered_candidate(): void
    {
        $transition = ChallengerExperiment::canTransition(
            ChallengerExperiment::PROPOSED,
            ChallengerExperiment::DATA_CHECK,
            ['candidate_id' => 'not_registered']
        );

        self::assertFalse($transition['allowed']);
        self::assertStringContainsString('CANDIDATE_NOT_IN_REGISTRY', $transition['reason']);
    }

    public function test_the_promotion_state_requires_a_positive_promotion_verdict(): void
    {
        $refused = ChallengerExperiment::canTransition(
            ChallengerExperiment::EVALUATED,
            ChallengerExperiment::ELIGIBLE_FOR_PAPER_PROMOTION,
            ['promotion_verdict' => 'NOT_ELIGIBLE']
        );
        self::assertFalse($refused['allowed']);

        $allowed = ChallengerExperiment::canTransition(
            ChallengerExperiment::EVALUATED,
            ChallengerExperiment::ELIGIBLE_FOR_PAPER_PROMOTION,
            ['promotion_verdict' => 'ELIGIBLE']
        );
        self::assertTrue($allowed['allowed']);
    }
}
