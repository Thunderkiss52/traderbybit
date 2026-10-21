<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A0 — one registered research candidate hypothesis (overnight spec §8, master
 * spec §4.8 and §5.1).
 *
 * The object is immutable and fully describes the hypothesis *before* any
 * result is looked at: the population, the null hypothesis, the primary metric,
 * the splits, the multiple-testing family, the precommitted thresholds and the
 * data-sufficiency statement. It never contains a result and it never
 * describes a strategy that is allowed to trade.
 */
final class CandidateHypothesis
{
    /**
     * @param  array<string, mixed>  $precommittedThresholds
     * @param  array<string, mixed>  $proposedVersionedRules
     * @param  list<string>  $negativeOutcomesToWatch
     * @param  list<string>  $instruments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $family,
        public readonly string $kind,
        public readonly string $hypothesisType,
        public readonly string $problemStatement,
        public readonly string $evidencePopulation,
        public readonly string $expectedMechanism,
        public readonly string $currentWeakness,
        public readonly string $primaryMetric,
        public readonly string $nullHypothesis,
        public readonly string $expectedEffectSign,
        public readonly string $affectedPopulation,
        public readonly string $timeKnownCutoffRule,
        public readonly string $trainSplit,
        public readonly string $oosSplit,
        public readonly string $forwardSplit,
        public readonly int $familywiseExperimentCount,
        public readonly string $dataSufficiencyStatement,
        public readonly array $precommittedThresholds,
        public readonly string $riskBudget,
        public readonly array $proposedVersionedRules,
        public readonly array $negativeOutcomesToWatch,
        public readonly array $instruments,
        public readonly bool $executable,
        public readonly string $sourceReference,
    ) {}

    /**
     * Content hash of the registration. The hash is frozen before the
     * intended out-of-sample result is looked at.
     */
    public function registrationHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hypothesis_id' => $this->id,
            'version' => $this->version,
            'family' => $this->family,
            'kind' => $this->kind,
            'hypothesis_type' => $this->hypothesisType,
            'problem_statement' => $this->problemStatement,
            'evidence_population' => $this->evidencePopulation,
            'expected_mechanism' => $this->expectedMechanism,
            'current_weakness' => $this->currentWeakness,
            'primary_metric' => $this->primaryMetric,
            'null_hypothesis' => $this->nullHypothesis,
            'expected_effect_sign' => $this->expectedEffectSign,
            'affected_population' => $this->affectedPopulation,
            'time_known_cutoff_rule' => $this->timeKnownCutoffRule,
            'train_split' => $this->trainSplit,
            'oos_split' => $this->oosSplit,
            'forward_split' => $this->forwardSplit,
            'familywise_experiment_count' => $this->familywiseExperimentCount,
            'data_sufficiency_statement' => $this->dataSufficiencyStatement,
            'precommitted_thresholds' => $this->precommittedThresholds,
            'risk_budget' => $this->riskBudget,
            'proposed_versioned_rules' => $this->proposedVersionedRules,
            'negative_outcomes_to_watch' => $this->negativeOutcomesToWatch,
            'instruments' => $this->instruments,
            'executable' => $this->executable,
            'source_reference' => $this->sourceReference,
        ];
    }
}
