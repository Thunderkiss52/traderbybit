<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A0 — the immutable registry of research candidate hypotheses.
 *
 * The registry mirrors the five research cohorts of the Go research runner
 * (`research-go/internal/candidates/registry.go`) and adds the structured
 * registration fields the overnight contract requires. It is a *research*
 * registry: an entry is not a strategy, it is not an account and it never
 * changes the primary paper accounts.
 */
final class CandidateRegistry
{
    public const KIND_RESEARCH_COHORT = 'RESEARCH_COHORT';

    public const METRIC_NET_AFTER_COST = 'NET_PNL_AFTER_COST_PER_POSITION_LIFECYCLE_USDT';

    public const SPLIT_TRAIN = 'CHRONOLOGICAL_TRAIN_60_PERCENT';

    public const SPLIT_OOS = 'CHRONOLOGICAL_VALIDATION_20_PERCENT';

    public const SPLIT_FORWARD = 'PROSPECTIVE_FORWARD_ONLY_NOT_AVAILABLE_OFFLINE';

    public const FAMILYWISE_COUNT = 5;

    public const TIME_KNOWN_RULE = 'CLOSED_BARS_ONLY_AND_KNOWN_AT_NOT_AFTER_DECISION_TIME';

    /**
     * @return list<CandidateHypothesis>
     */
    public static function all(): array
    {
        return [
            new CandidateHypothesis(
                id: 'funding_open_interest_squeeze',
                version: '1.0.0',
                family: 'FUNDING_AND_OPEN_INTEREST_CROWDING',
                kind: self::KIND_RESEARCH_COHORT,
                hypothesisType: 'CROWWDING_REVERSAL',
                problemStatement: 'Extreme funding combined with rising open interest may indicate a crowded side whose unwind dominates the short-horizon return.',
                evidencePopulation: 'BTCUSDT and ETHUSDT linear perpetuals, 5m decision bars, published funding events and open-interest snapshots.',
                expectedMechanism: 'Crowded positioning is unwound once the funding extreme persists, which pushes the price back through the pre-event range.',
                currentWeakness: 'The published archive contains very few funding events, so the funding arm of the hypothesis is almost never active.',
                primaryMetric: self::METRIC_NET_AFTER_COST,
                nullHypothesis: 'The net-after-cost expectation of the squeeze events is not different from zero.',
                expectedEffectSign: 'POSITIVE_FOR_SHORT_AFTER_POSITIVE_FUNDING',
                affectedPopulation: 'Isolated research cohort, never the primary paper accounts.',
                timeKnownCutoffRule: self::TIME_KNOWN_RULE,
                trainSplit: self::SPLIT_TRAIN,
                oosSplit: self::SPLIT_OOS,
                forwardSplit: self::SPLIT_FORWARD,
                familywiseExperimentCount: self::FAMILYWISE_COUNT,
                dataSufficiencyStatement: 'Requires at least 100 published funding events with a known_at not after the decision time; the current archive does not reach this.',
                precommittedThresholds: [
                    'funding_rate_absolute_min' => '0.0001',
                    'open_interest_change_min' => '0.10',
                    'horizon_bars' => 12,
                ],
                riskBudget: 'Research only; no paper risk is allocated to this cohort.',
                proposedVersionedRules: ['squeeze_entry' => '1.0.0'],
                negativeOutcomesToWatch: [
                    'The funding extreme persists and the crowd is not unwound.',
                    'The open-interest increase is a new entry rather than a crowded short.',
                ],
                instruments: ['BTCUSDT', 'ETHUSDT'],
                executable: true,
                sourceReference: 'research-go/internal/candidates/funding_open_interest_squeeze.go',
            ),
            new CandidateHypothesis(
                id: 'liquidity_sweep_reversal',
                version: '1.0.0',
                family: 'LIQUIDITY_SWEEP_AND_LEVEL_RECAPTURE',
                kind: self::KIND_RESEARCH_COHORT,
                hypothesisType: 'FALSE_BREAK_RECAPTURE',
                problemStatement: 'A sweep beyond a prior-known level that is recaptured on the close may be a failed break rather than a breakout.',
                evidencePopulation: 'BTCUSDT and ETHUSDT linear perpetuals, 5m decision bars, 14-period ATR and 20-bar prior levels.',
                expectedMechanism: 'Stops clustered behind a visible level are triggered by the sweep and the price reverts once the break is not confirmed by the close.',
                currentWeakness: 'The archived 24-hour window contains too few sweeps to separate the effect from noise.',
                primaryMetric: self::METRIC_NET_AFTER_COST,
                nullHypothesis: 'The net-after-cost expectation of sweep recaptures is not different from zero.',
                expectedEffectSign: 'POSITIVE',
                affectedPopulation: 'Isolated research cohort, never the primary paper accounts.',
                timeKnownCutoffRule: self::TIME_KNOWN_RULE,
                trainSplit: self::SPLIT_TRAIN,
                oosSplit: self::SPLIT_OOS,
                forwardSplit: self::SPLIT_FORWARD,
                familywiseExperimentCount: self::FAMILYWISE_COUNT,
                dataSufficiencyStatement: 'Requires at least 300 swept events in the training split and 100 in the out-of-sample split; the current archive reaches neither.',
                precommittedThresholds: [
                    'min_bars' => 60,
                    'atr_period' => 14,
                    'stop_atr_multiple' => '1.5',
                    'target_atr_multiple' => '3.0',
                    'level_lookback' => 20,
                ],
                riskBudget: 'Research only; no paper risk is allocated to this cohort.',
                proposedVersionedRules: ['sweep_reversal_entry' => '1.0.0'],
                negativeOutcomesToWatch: [
                    'The sweep continues into a genuine trend.',
                    'The level recapture is an artefact of the 5-minute sampling.',
                ],
                instruments: ['BTCUSDT', 'ETHUSDT'],
                executable: true,
                sourceReference: 'research-go/internal/candidates/liquidity_sweep_reversal.go',
            ),
            new CandidateHypothesis(
                id: 'volatility_compression_breakout',
                version: '1.0.0',
                family: 'VOLATILITY_COMPRESSION_BREAKOUT',
                kind: self::KIND_RESEARCH_COHORT,
                hypothesisType: 'COMPRESSION_BREAKOUT',
                problemStatement: 'A precommitted volatility compression followed by a close beyond the previous breakout window may continue in the direction of the compression.',
                evidencePopulation: 'BTCUSDT and ETHUSDT linear perpetuals, 5m decision bars with 20-bar and 50-bar ranges.',
                expectedMechanism: 'Compressed ranges store unrealised intent; the first close beyond the range resolves the compression in the direction of the stored energy.',
                currentWeakness: 'The archived window contains a small number of compressions and no prospective confirmation.',
                primaryMetric: self::METRIC_NET_AFTER_COST,
                nullHypothesis: 'The net-after-cost expectation of compression breakouts is not different from zero.',
                expectedEffectSign: 'DIRECTIONAL',
                affectedPopulation: 'Isolated research cohort, never the primary paper accounts.',
                timeKnownCutoffRule: self::TIME_KNOWN_RULE,
                trainSplit: self::SPLIT_TRAIN,
                oosSplit: self::SPLIT_OOS,
                forwardSplit: self::SPLIT_FORWARD,
                familywiseExperimentCount: self::FAMILYWISE_COUNT,
                dataSufficiencyStatement: 'Requires at least 300 compression events in the training split and 100 in the out-of-sample split; the current archive reaches neither.',
                precommittedThresholds: [
                    'compression_lookback' => 20,
                    'breakout_buffer_bps' => '0',
                    'stop_atr_multiple' => '1.5',
                ],
                riskBudget: 'Research only; no paper risk is allocated to this cohort.',
                proposedVersionedRules: ['compression_breakout_entry' => '1.0.0'],
                negativeOutcomesToWatch: [
                    'The compression resolves in the opposite direction.',
                    'The breakout is a liquidity event without continuation.',
                ],
                instruments: ['BTCUSDT', 'ETHUSDT'],
                executable: true,
                sourceReference: 'research-go/internal/candidates/volatility_compression_breakout.go',
            ),
            new CandidateHypothesis(
                id: 'multi_timeframe_trend_following',
                version: '1.0.0',
                family: 'HIGHER_TIMEFRAME_TREND_LOWER_TIMEFRAME_ENTRY',
                kind: self::KIND_RESEARCH_COHORT,
                hypothesisType: 'MULTI_TIMEFRAME_TREND',
                problemStatement: 'A higher-timeframe trend that agrees with a lower-timeframe entry confirmation may continue.',
                evidencePopulation: 'BTCUSDT and ETHUSDT linear perpetuals, 5m entry bars and fully closed 1h trend bars.',
                expectedMechanism: 'A persistent higher-timeframe drift provides the direction, the lower-timeframe pullback provides the entry.',
                currentWeakness: 'The archived window is too short to separate the higher-timeframe drift from the entry timing.',
                primaryMetric: self::METRIC_NET_AFTER_COST,
                nullHypothesis: 'The net-after-cost expectation of the multi-timeframe continuation is not different from zero.',
                expectedEffectSign: 'DIRECTIONAL',
                affectedPopulation: 'Isolated research cohort, never the primary paper accounts.',
                timeKnownCutoffRule: self::TIME_KNOWN_RULE,
                trainSplit: self::SPLIT_TRAIN,
                oosSplit: self::SPLIT_OOS,
                forwardSplit: self::SPLIT_FORWARD,
                familywiseExperimentCount: self::FAMILYWISE_COUNT,
                dataSufficiencyStatement: 'Requires at least 300 entries in the training split and 100 in the out-of-sample split; the current archive reaches neither.',
                precommittedThresholds: [
                    'entry_interval' => '5',
                    'decision_interval' => '60',
                    'ema_fast' => 20,
                    'ema_slow' => 50,
                ],
                riskBudget: 'Research only; no paper risk is allocated to this cohort.',
                proposedVersionedRules: ['mtf_trend_entry' => '1.0.0'],
                negativeOutcomesToWatch: [
                    'The higher-timeframe trend flips inside the holding period.',
                    'The entry confirmation adds cost without adding information.',
                ],
                instruments: ['BTCUSDT', 'ETHUSDT'],
                executable: true,
                sourceReference: 'research-go/internal/candidates/multi_timeframe_trend_following.go',
            ),
            new CandidateHypothesis(
                id: 'btc_eth_lead_lag',
                version: '1.0.0',
                family: 'CROSS_SYMBOL_LEAD_LAG',
                kind: self::KIND_RESEARCH_COHORT,
                hypothesisType: 'CROSS_SYMBOL_CATCH_UP',
                problemStatement: 'A peer move that the local symbol has not yet followed may continue into the local symbol.',
                evidencePopulation: 'BTCUSDT and ETHUSDT linear perpetuals, 5m bars, peer bars shifted by one closed bar.',
                expectedMechanism: 'A common information shock is incorporated into the more liquid symbol first.',
                currentWeakness: 'The execution assumption of the catch-up leg was never validated, so the cohort is not executable.',
                primaryMetric: self::METRIC_NET_AFTER_COST,
                nullHypothesis: 'The net-after-cost expectation of the catch-up leg is not different from zero.',
                expectedEffectSign: 'DIRECTIONAL',
                affectedPopulation: 'Isolated research cohort, never the primary paper accounts.',
                timeKnownCutoffRule: self::TIME_KNOWN_RULE,
                trainSplit: self::SPLIT_TRAIN,
                oosSplit: self::SPLIT_OOS,
                forwardSplit: self::SPLIT_FORWARD,
                familywiseExperimentCount: self::FAMILYWISE_COUNT,
                dataSufficiencyStatement: 'Requires a validated execution assumption for the catch-up leg before any evaluation; the cohort is currently EXECUTION_NOT_VALIDATED.',
                precommittedThresholds: [
                    'peer_lag_bars' => 1,
                    'minimum_peer_move_bps' => '5',
                ],
                riskBudget: 'Research only; no paper risk is allocated to this cohort.',
                proposedVersionedRules: ['lead_lag_entry' => '1.0.0'],
                negativeOutcomesToWatch: [
                    'Both symbols move together and the catch-up leg never triggers.',
                    'The catch-up leg cannot be filled at the assumed price.',
                ],
                instruments: ['BTCUSDT', 'ETHUSDT'],
                executable: false,
                sourceReference: 'research-go/internal/candidates/btc_eth_lead_lag.go',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(static fn (CandidateHypothesis $h): string => $h->id, self::all());
    }

    public static function lookup(string $id): ?CandidateHypothesis
    {
        foreach (self::all() as $hypothesis) {
            if ($hypothesis->id === $id) {
                return $hypothesis;
            }
        }

        return null;
    }

    /**
     * A deterministic hash of the whole registry.
     */
    public static function hash(): string
    {
        $payload = array_map(
            static fn (CandidateHypothesis $h): array => $h->toArray(),
            self::all()
        );

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
