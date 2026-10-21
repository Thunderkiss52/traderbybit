<?php

declare(strict_types=1);

namespace App\Research\Domain\CostMfe;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-4 — pre-entry expected-MFE distribution (§9.2).
 *
 * The estimator may only use observations that had already matured before the
 * candidate entry: an observation counts only when the position close time
 * AND the last source candle close time are strictly before the candidate
 * entry time. Anything else is excluded with an explicit reason, and an empty
 * population yields INSUFFICIENT_DATA instead of a fabricated estimate.
 */
final class PreEntryMfeEstimator
{
    public const STATUS_COMPUTED = 'COMPUTED';

    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const REASON_NOT_MATURED = 'NOT_MATURED_BEFORE_CANDIDATE_ENTRY';

    public const REASON_FUTURE_SOURCE = 'SOURCE_CANDLES_AFTER_CANDIDATE_ENTRY';

    public const REASON_NO_CANDLES = 'NO_ELIGIBLE_CANDLES';

    private const SCALE = 12;

    /**
     * @param  list<array{result: MfeMaeResult, matured_at: CarbonImmutable}>  $observations
     * @return array<string, mixed>
     */
    public function estimate(
        CarbonImmutable $candidateEntryAt,
        array $observations,
        ?CarbonImmutable $lookbackStart = null,
    ): array {
        $included = [];
        $excluded = [
            self::REASON_NOT_MATURED => 0,
            self::REASON_FUTURE_SOURCE => 0,
            self::REASON_NO_CANDLES => 0,
        ];

        $maxMaturedAt = null;
        $maxSourceClose = null;

        foreach ($observations as $observation) {
            $result = $observation['result'];
            $maturedAt = $observation['matured_at'];

            if ($result->status !== MfeMaeResult::STATUS_COMPUTED) {
                $excluded[self::REASON_NO_CANDLES]++;

                continue;
            }

            if (! $maturedAt->lessThan($candidateEntryAt)) {
                $excluded[self::REASON_NOT_MATURED]++;

                continue;
            }

            if ($lookbackStart !== null && $maturedAt->lessThan($lookbackStart)) {
                $excluded[self::REASON_NOT_MATURED]++;

                continue;
            }

            $sourceTimes = $result->sourceCandleCloseTimes;
            $lastSource = $sourceTimes === []
                ? null
                : CarbonImmutable::parse((string) $sourceTimes[count($sourceTimes) - 1], 'UTC');

            if ($lastSource === null || ! $lastSource->lessThan($candidateEntryAt)) {
                $excluded[self::REASON_FUTURE_SOURCE]++;

                continue;
            }

            $included[] = $result;

            $maxMaturedAt = $maxMaturedAt === null || $maturedAt->greaterThan($maxMaturedAt) ? $maturedAt : $maxMaturedAt;
            $maxSourceClose = $maxSourceClose === null || $lastSource->greaterThan($maxSourceClose) ? $lastSource : $maxSourceClose;
        }

        $base = [
            'status' => self::STATUS_INSUFFICIENT_DATA,
            'reason' => null,
            'sample_count' => count($included),
            'excluded_counts' => $excluded,
            'candidate_entry_at' => $candidateEntryAt->toIso8601String(),
            'lookback_start' => $lookbackStart?->toIso8601String(),
            'cutoff' => $candidateEntryAt->toIso8601String(),
            'max_included_matured_at' => $maxMaturedAt?->toIso8601String(),
            'max_included_source_candle_close_time' => $maxSourceClose?->toIso8601String(),
            'no_future_data_proof' => $maxMaturedAt !== null && $maxSourceClose !== null
                && $maxMaturedAt->lessThan($candidateEntryAt)
                && $maxSourceClose->lessThan($candidateEntryAt),
            'distribution' => null,
        ];

        if ($included === []) {
            $base['reason'] = 'NO_MATURED_PRIOR_OBSERVATIONS';

            return $base;
        }

        $mfeR = [];
        $mfePct = [];
        foreach ($included as $result) {
            if ($result->mfeR !== null) {
                $mfeR[] = BigDecimal::of($result->mfeR);
            }

            $entry = BigDecimal::of($result->entryReference);
            if (! $entry->isZero()) {
                $mfePct[] = BigDecimal::of($result->mfePrice)->dividedBy($entry, self::SCALE, RoundingMode::HalfEven)->multipliedBy(100);
            }
        }

        $base['status'] = self::STATUS_COMPUTED;
        $base['reason'] = null;
        $base['distribution'] = [
            'method' => 'NEAREST_RANK_PERCENTILE',
            'mfe_r' => $this->distribution($mfeR),
            'mfe_percent_of_entry' => $this->distribution($mfePct),
        ];

        return $base;
    }

    /**
     * @param  list<BigDecimal>  $values
     * @return array<string, string|int|null>
     */
    private function distribution(array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'min' => null, 'p25' => null, 'p50' => null, 'p75' => null, 'max' => null, 'mean' => null];
        }

        usort($values, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));
        $count = count($values);

        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus($value);
        }

        return [
            'count' => $count,
            'min' => (string) $values[0],
            'p25' => (string) $this->percentile($values, 0.25),
            'p50' => (string) $this->percentile($values, 0.50),
            'p75' => (string) $this->percentile($values, 0.75),
            'max' => (string) $values[$count - 1],
            'mean' => (string) $sum->dividedBy($count, self::SCALE, RoundingMode::HalfEven),
        ];
    }

    /**
     * Nearest-rank percentile: no interpolation, so every reported quantile is an
     * actually observed value.
     *
     * @param  list<BigDecimal>  $sorted
     */
    private function percentile(array $sorted, float $quantile): BigDecimal
    {
        $count = count($sorted);
        $rank = (int) ceil($quantile * $count);
        $index = max(1, min($count, $rank)) - 1;

        return $sorted[$index];
    }
}
