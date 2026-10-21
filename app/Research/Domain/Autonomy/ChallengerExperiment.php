<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A1 — the state machine of a versioned paper challenger experiment.
 *
 * The controller is fail-closed by construction: an experiment may never skip
 * the data check, may never be registered after the out-of-sample result was
 * looked at, and may never reach a promotion state without a positive A2
 * verdict. Every attempted experiment — including invalidated and failed ones —
 * is a tracked state, never a deleted row.
 */
final class ChallengerExperiment
{
    public const PROPOSED = 'PROPOSED';

    public const DATA_CHECK = 'DATA_CHECK';

    public const REGISTERED = 'REGISTERED';

    public const EXPERIMENTING = 'EXPERIMENTING';

    public const EVALUATED = 'EVALUATED';

    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const EVIDENCE_NEGATIVE = 'EVIDENCE_NEGATIVE';

    public const ELIGIBLE_FOR_PAPER_PROMOTION = 'ELIGIBLE_FOR_PAPER_PROMOTION';

    public const INVALIDATED = 'INVALIDATED';

    public const REJECTED = 'REJECTED';

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::PROPOSED,
            self::DATA_CHECK,
            self::REGISTERED,
            self::EXPERIMENTING,
            self::EVALUATED,
            self::INSUFFICIENT_DATA,
            self::EVIDENCE_NEGATIVE,
            self::ELIGIBLE_FOR_PAPER_PROMOTION,
            self::INVALIDATED,
            self::REJECTED,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::PROPOSED => [self::DATA_CHECK, self::REJECTED, self::INVALIDATED],
            self::DATA_CHECK => [self::REGISTERED, self::INSUFFICIENT_DATA, self::REJECTED, self::INVALIDATED],
            self::REGISTERED => [self::EXPERIMENTING, self::INVALIDATED, self::REJECTED],
            self::EXPERIMENTING => [self::EVALUATED, self::INVALIDATED, self::REJECTED],
            self::EVALUATED => [
                self::INSUFFICIENT_DATA,
                self::EVIDENCE_NEGATIVE,
                self::ELIGIBLE_FOR_PAPER_PROMOTION,
                self::REJECTED,
                self::INVALIDATED,
            ],
            self::INSUFFICIENT_DATA => [self::DATA_CHECK, self::INVALIDATED, self::REJECTED],
            self::EVIDENCE_NEGATIVE => [self::DATA_CHECK, self::INVALIDATED],
            self::ELIGIBLE_FOR_PAPER_PROMOTION => [self::INVALIDATED, self::REJECTED],
            self::INVALIDATED => [],
            self::REJECTED => [],
        ];
    }

    /**
     * Decide whether a transition is allowed.
     *
     * @param  array<string, mixed>  $context
     * @return array{allowed: bool, reason: string}
     */
    public static function canTransition(string $from, string $to, array $context = []): array
    {
        if (! in_array($to, self::transitions()[$from] ?? [], true)) {
            return ['allowed' => false, 'reason' => 'ILLEGAL_TRANSITION:'.$from.'->'.$to];
        }

        if ($to === self::DATA_CHECK) {
            $candidateId = (string) ($context['candidate_id'] ?? '');
            if (CandidateRegistry::lookup($candidateId) === null) {
                return ['allowed' => false, 'reason' => 'CANDIDATE_NOT_IN_REGISTRY:'.$candidateId];
            }
        }

        if ($to === self::REGISTERED) {
            $preregistrationHash = (string) ($context['preregistration_hash'] ?? '');
            if ($preregistrationHash === '') {
                return ['allowed' => false, 'reason' => 'PREREGISTRATION_HASH_MISSING'];
            }
            if (($context['data_check_done'] ?? false) !== true) {
                return ['allowed' => false, 'reason' => 'DATA_CHECK_NOT_COMPLETED'];
            }
            if (($context['oos_viewed'] ?? false) === true) {
                return ['allowed' => false, 'reason' => 'PREREGISTRATION_AFTER_OOS_INSPECTION'];
            }
        }

        if ($to === self::ELIGIBLE_FOR_PAPER_PROMOTION) {
            if (($context['promotion_verdict'] ?? null) !== 'ELIGIBLE') {
                return ['allowed' => false, 'reason' => 'PROMOTION_VERDICT_NOT_ELIGIBLE'];
            }
        }

        return ['allowed' => true, 'reason' => 'ALLOWED'];
    }
}
