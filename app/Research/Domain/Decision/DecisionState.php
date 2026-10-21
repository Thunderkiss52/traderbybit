<?php

declare(strict_types=1);

namespace App\Research\Domain\Decision;

/**
 * PROFIT-1 / P-6 — research decision states (§14.2).
 *
 * The decision record is append-only: a transition is inserted, never updated
 * in place, and the previous state is always carried forward.
 */
final class DecisionState
{
    public const PROPOSED = 'PROPOSED';

    public const COLLECT_MORE_DATA = 'COLLECT_MORE_DATA';

    public const INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';

    public const EVIDENCE_NEGATIVE = 'EVIDENCE_NEGATIVE';

    public const ELIGIBLE_FOR_OWNER_REVIEW = 'ELIGIBLE_FOR_OWNER_REVIEW';

    public const OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT = 'OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT';

    public const REJECTED = 'REJECTED';

    public const ROLLED_BACK = 'ROLLED_BACK';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PROPOSED,
            self::COLLECT_MORE_DATA,
            self::INSUFFICIENT_EVIDENCE,
            self::EVIDENCE_NEGATIVE,
            self::ELIGIBLE_FOR_OWNER_REVIEW,
            self::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT,
            self::REJECTED,
            self::ROLLED_BACK,
        ];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * Allowed append-only transitions. A state that is absent from this map is
     * terminal.
     *
     * @return array<string, list<string>>
     */
    public static function allowedTransitions(): array
    {
        return [
            self::PROPOSED => [
                self::COLLECT_MORE_DATA,
                self::INSUFFICIENT_EVIDENCE,
                self::EVIDENCE_NEGATIVE,
                self::ELIGIBLE_FOR_OWNER_REVIEW,
                self::REJECTED,
                self::ROLLED_BACK,
            ],
            self::COLLECT_MORE_DATA => [
                self::COLLECT_MORE_DATA,
                self::INSUFFICIENT_EVIDENCE,
                self::EVIDENCE_NEGATIVE,
                self::ELIGIBLE_FOR_OWNER_REVIEW,
                self::REJECTED,
                self::ROLLED_BACK,
            ],
            self::INSUFFICIENT_EVIDENCE => [
                self::COLLECT_MORE_DATA,
                self::INSUFFICIENT_EVIDENCE,
                self::EVIDENCE_NEGATIVE,
                self::ELIGIBLE_FOR_OWNER_REVIEW,
                self::REJECTED,
                self::ROLLED_BACK,
            ],
            self::ELIGIBLE_FOR_OWNER_REVIEW => [
                self::COLLECT_MORE_DATA,
                self::EVIDENCE_NEGATIVE,
                self::REJECTED,
                self::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT,
                self::ROLLED_BACK,
            ],
            self::EVIDENCE_NEGATIVE => [
                self::COLLECT_MORE_DATA,
                self::REJECTED,
                self::ROLLED_BACK,
            ],
            self::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT => [
                self::ROLLED_BACK,
            ],
            self::REJECTED => [self::ROLLED_BACK],
            self::ROLLED_BACK => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            // A self transition is only meaningful as an explicit re-evaluation.
            return in_array($from, [self::COLLECT_MORE_DATA, self::INSUFFICIENT_EVIDENCE], true);
        }

        return in_array($to, self::allowedTransitions()[$from] ?? [], true);
    }
}
