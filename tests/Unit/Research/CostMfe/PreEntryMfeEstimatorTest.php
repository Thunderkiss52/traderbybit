<?php

declare(strict_types=1);

namespace Tests\Unit\Research\CostMfe;

use App\Research\Domain\CostMfe\MfeMaeResult;
use App\Research\Domain\CostMfe\PreEntryMfeEstimator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * PROFIT-1 / P-4 — pre-entry expected-MFE estimator (§9.2).
 *
 * The decisive property is causal: only observations that had already matured
 * before the candidate entry may enter the distribution.
 */
final class PreEntryMfeEstimatorTest extends TestCase
{
    public function test_estimator_reports_insufficient_data_when_no_observation_had_matured(): void
    {
        $estimator = new PreEntryMfeEstimator;

        $estimate = $estimator->estimate(
            CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            [
                $this->observation('p1', '2026-10-17T01:00:00Z', '2026-10-17T00:55:00Z', '1.000000000000'),
            ]
        );

        self::assertSame(PreEntryMfeEstimator::STATUS_INSUFFICIENT_DATA, $estimate['status']);
        self::assertSame('NO_MATURED_PRIOR_OBSERVATIONS', $estimate['reason']);
        self::assertSame(0, $estimate['sample_count']);
        self::assertSame(1, $estimate['excluded_counts'][PreEntryMfeEstimator::REASON_NOT_MATURED]);
        self::assertNull($estimate['distribution']);
    }

    public function test_observations_that_matured_after_the_candidate_entry_are_excluded(): void
    {
        $estimator = new PreEntryMfeEstimator;

        $estimate = $estimator->estimate(
            CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            [
                $this->observation('p1', '2026-10-16T23:00:00Z', '2026-10-16T22:55:00Z', '1.000000000000'),
                $this->observation('p2', '2026-10-17T00:30:00Z', '2026-10-17T00:25:00Z', '2.000000000000'),
            ]
        );

        self::assertSame(PreEntryMfeEstimator::STATUS_COMPUTED, $estimate['status']);
        self::assertSame(1, $estimate['sample_count']);
        self::assertSame(1, $estimate['excluded_counts'][PreEntryMfeEstimator::REASON_NOT_MATURED]);
        self::assertTrue($estimate['no_future_data_proof']);
        self::assertSame('2026-10-16T23:00:00+00:00', $estimate['max_included_matured_at']);
        self::assertSame('2026-10-16T22:55:00+00:00', $estimate['max_included_source_candle_close_time']);
    }

    public function test_observations_whose_source_candles_reach_past_the_entry_are_excluded(): void
    {
        $estimator = new PreEntryMfeEstimator;

        $estimate = $estimator->estimate(
            CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            [
                $this->observation('p1', '2026-10-16T23:00:00Z', '2026-10-17T00:05:00Z', '1.000000000000'),
            ]
        );

        self::assertSame(PreEntryMfeEstimator::STATUS_INSUFFICIENT_DATA, $estimate['status']);
        self::assertSame(1, $estimate['excluded_counts'][PreEntryMfeEstimator::REASON_FUTURE_SOURCE]);
        self::assertFalse($estimate['no_future_data_proof']);
    }

    public function test_distribution_uses_nearest_rank_percentiles_of_the_observed_mfe(): void
    {
        $estimator = new PreEntryMfeEstimator;

        $estimate = $estimator->estimate(
            CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            [
                $this->observation('p1', '2026-10-16T10:00:00Z', '2026-10-16T09:55:00Z', '0.500000000000'),
                $this->observation('p2', '2026-10-16T12:00:00Z', '2026-10-16T11:55:00Z', '1.000000000000'),
                $this->observation('p3', '2026-10-16T14:00:00Z', '2026-10-16T13:55:00Z', '1.500000000000'),
                $this->observation('p4', '2026-10-16T16:00:00Z', '2026-10-16T15:55:00Z', '2.000000000000'),
            ]
        );

        self::assertSame(PreEntryMfeEstimator::STATUS_COMPUTED, $estimate['status']);
        self::assertSame(4, $estimate['sample_count']);

        $distribution = $estimate['distribution'];
        self::assertSame('NEAREST_RANK_PERCENTILE', $distribution['method']);
        self::assertSame(4, $distribution['mfe_r']['count']);
        self::assertSame('0.500000000000', $distribution['mfe_r']['min']);
        self::assertSame('0.500000000000', $distribution['mfe_r']['p25']);
        self::assertSame('1.000000000000', $distribution['mfe_r']['p50']);
        self::assertSame('1.500000000000', $distribution['mfe_r']['p75']);
        self::assertSame('2.000000000000', $distribution['mfe_r']['max']);
        self::assertSame('1.250000000000', $distribution['mfe_r']['mean']);
    }

    public function test_lookback_start_excludes_observations_older_than_the_window(): void
    {
        $estimator = new PreEntryMfeEstimator;

        $estimate = $estimator->estimate(
            CarbonImmutable::parse('2026-10-17T00:00:00Z'),
            [
                $this->observation('p1', '2026-10-10T10:00:00Z', '2026-10-10T09:55:00Z', '1.000000000000'),
                $this->observation('p2', '2026-10-16T10:00:00Z', '2026-10-16T09:55:00Z', '2.000000000000'),
            ],
            CarbonImmutable::parse('2026-10-15T00:00:00Z')
        );

        self::assertSame(1, $estimate['sample_count']);
        self::assertSame(1, $estimate['excluded_counts'][PreEntryMfeEstimator::REASON_NOT_MATURED]);
        self::assertSame('2026-10-15T00:00:00+00:00', $estimate['lookback_start']);
    }

    /**
     * @return array{result: MfeMaeResult, matured_at: CarbonImmutable}
     */
    private function observation(string $positionId, string $maturedAt, string $lastCandleClose, string $mfeR): array
    {
        $matured = CarbonImmutable::parse($maturedAt, 'UTC');

        $result = new MfeMaeResult(
            positionId: $positionId,
            accountId: 'a1',
            symbol: 'BTCUSDT',
            side: 'BUY',
            entryReference: '100.000000000000',
            quantity: '1.000000000000',
            holdingStart: $matured->subHour(),
            holdingEnd: $matured,
            status: MfeMaeResult::STATUS_COMPUTED,
            mfePrice: $mfeR,
            maePrice: '0.000000000000',
            mfeUsdt: $mfeR,
            maeUsdt: '0.000000000000',
            mfeR: $mfeR,
            maeR: '0.000000000000',
            mfeCandleId: 'c1',
            mfeCandleCloseTime: $lastCandleClose,
            maeCandleId: 'c1',
            maeCandleCloseTime: $lastCandleClose,
            candleCount: 1,
            ambiguousPath: false,
            ambiguityReason: null,
            sourceCandleIds: ['c1'],
            sourceCandleCloseTimes: [CarbonImmutable::parse($lastCandleClose, 'UTC')->toIso8601String()],
            riskReason: null,
        );

        return ['result' => $result, 'matured_at' => $matured];
    }
}
