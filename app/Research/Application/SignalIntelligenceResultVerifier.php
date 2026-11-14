<?php

declare(strict_types=1);

namespace App\Research\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;

/** Schema-1 hash contract shared with Go structs, covered by cross-language tests. */
final class SignalIntelligenceResultVerifier
{
    private const SCOPE = 'exchange category symbol strategy_id strategy_version source_timeframe_minutes horizon_minutes direction regime configuration_hash code_revision label_policy_version data_source horizon_anchor execution_hash';

    private const POLICY = 'version min_independent_samples min_distinct_days alpha multiple_tests embargo_ms return_lower_bound return_upper_bound neutral_band';

    public static function verifyManifest(array $metrics, ?string $manifest): void
    {
        if (array_diff(array_keys($metrics), ['research_only', 'signal_intelligence_json', 'signal_intelligence_sha256']) !== []) {
            throw new LogicException('Unsupported signal intelligence result metrics.');
        }
        ksort($metrics, SORT_STRING);
        if (! is_string($manifest) || ! hash_equals($manifest, self::hash($metrics))) {
            throw new LogicException('Signal intelligence worker manifest mismatch.');
        }
    }

    public static function evaluationInputHash(array $input): string
    {
        $input = self::ordered($input, 'signal as_of_ms horizons_minutes notional horizon_anchor instrument costs provenance candles');
        $input['signal'] = self::ordered($input['signal'], 'id exchange category symbol strategy_id strategy_version source_timeframe_minutes decision_ms available_ms feature_available_ms direction reference_price regime');
        $input['as_of_ms'] = 0;
        sort($input['horizons_minutes']);
        $input['notional'] = $input['notional'] ?: '5';
        $input['horizon_anchor'] = $input['horizon_anchor'] ?: 'AVAILABILITY';
        $input['signal']['regime'] = $input['signal']['regime'] ?: 'UNCERTAIN';
        $input['instrument'] = self::ordered($input['instrument'], 'exchange category symbol available_ms tick_size quantity_step min_quantity max_quantity min_notional');
        $input['costs'] = self::ordered($input['costs'], 'source_id taker_rate slippage_rate funding_complete funding_schedule_id funding_interval_minutes funding_offset_minutes funding_coverage_from_ms funding_coverage_to_ms funding_events');
        $input['costs']['funding_events'] = array_map(fn (array $event): array => self::ordered($event, 'exchange category symbol at_ms rate mark_price'), $input['costs']['funding_events']);
        $input['provenance'] = self::ordered($input['provenance'], 'dataset_id dataset_hash configuration_hash code_revision');
        $input['candles'] = array_map(fn (array $bar): array => self::ordered($bar, 'exchange category symbol open_ms close_ms closed open high low close'), $input['candles']);

        return self::hash($input);
    }

    public static function executionHash(array $input): string
    {
        $instrument = $input['instrument'];
        $costs = $input['costs'];
        $decimal = static function (string $text): string {
            return preg_match('/^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D', $text)
                ? (string) BigDecimal::of($text)->toScale(12, RoundingMode::HalfUp) : $text;
        };

        return self::hash([
            'notional' => $decimal($input['notional'] ?: '5'), 'tick_size' => $decimal($instrument['tick_size']),
            'quantity_step' => $decimal($instrument['quantity_step']), 'min_quantity' => $decimal($instrument['min_quantity']),
            'max_quantity' => $decimal($instrument['max_quantity']), 'min_notional' => $decimal($instrument['min_notional']),
            'cost_source_id' => $costs['source_id'], 'taker_rate' => $decimal($costs['taker_rate']), 'slippage_rate' => $decimal($costs['slippage_rate']),
            'funding_schedule_id' => $costs['funding_schedule_id'], 'funding_interval_minutes' => $costs['funding_interval_minutes'],
            'funding_offset_minutes' => $costs['funding_offset_minutes'], 'horizon_anchor' => $input['horizon_anchor'] ?: 'AVAILABILITY',
        ]);
    }

    public static function policyHash(array $policy): string
    {
        return self::hash(self::ordered($policy, self::POLICY));
    }

    public static function cohortInputHash(array $input): string
    {
        $ordered = ['scope' => self::ordered($input['scope'], self::SCOPE), 'as_of_ms' => $input['as_of_ms'],
            'policy' => self::ordered($input['policy'], self::POLICY),
            'observations' => array_map(static function (array $row): array {
                $optional = $row;
                $row = self::ordered($row, 'id input_hash scope status available_ms end_ms net_return');
                $row['scope'] = self::ordered($row['scope'], self::SCOPE);
                foreach (['directional_correct', 'mfe_ratio', 'mae_ratio'] as $field) {
                    if (isset($optional[$field])) {
                        $row[$field] = $optional[$field];
                    }
                }

                return $row;
            }, $input['observations'])];
        if (isset($input['baseline'])) {
            $baseline = self::ordered($input['baseline'], 'scope policy_version policy_hash input_hash classification independent_samples distinct_days end_ms lower_bound');
            $baseline['scope'] = self::ordered($baseline['scope'], self::SCOPE);
            $ordered['baseline'] = $baseline;
        }
        if (isset($input['provenance'])) {
            $ordered['provenance'] = self::ordered($input['provenance'], 'dataset_id dataset_hash configuration_hash code_revision');
        }

        return self::hash($ordered);
    }

    public static function cohortDatasetHash(array $observations): string
    {
        $sort = static function (array $value) use (&$sort): array {
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $child = $sort($child);
                }
            }
            unset($child);
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return $value;
        };

        return self::hash($sort($observations));
    }

    private static function ordered(array $value, string $fields): array
    {
        $result = [];
        foreach (explode(' ', $fields) as $key) {
            if (! array_key_exists($key, $value)) {
                throw new LogicException('Incomplete signal intelligence hash contract: '.$key);
            }
            $result[$key] = $value[$key];
        }

        return $result;
    }

    private static function hash(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        // Match encoding/json's shortest decimal/exponent presentation. Quoted
        // monetary strings and JSON embedded inside strings are never changed.
        $json = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?\d+(?:\.\d+)?[eE][+-]?\d+/', static function (array $match): string {
            $number = (float) $match[0];
            if (abs($number) >= 0.000001 && abs($number) < 1.0e21) {
                return (string) BigDecimal::of($match[0])->strippedOfTrailingZeros();
            }
            [$mantissa, $exponent] = explode('e', strtolower($match[0]));
            $exponent = (int) $exponent;

            return rtrim(rtrim($mantissa, '0'), '.').'e'.($exponent >= 0 ? '+' : '').$exponent;
        }, $json);

        return hash('sha256', $json);
    }
}
