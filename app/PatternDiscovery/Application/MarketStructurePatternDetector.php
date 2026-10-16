<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

final readonly class MarketStructurePatternDetector
{
    public function __construct(private array $config) {}

    /**
     * Detects only patterns confirmed by the final closed bar. Formation indexes
     * identify earlier bars, while callers persist the final bar as detected_at.
     */
    public function detect(array $bars): array
    {
        $bars = array_values(array_map(fn (array $bar): array => $this->normalize($bar), $bars));
        $count = count($bars);
        $lookback = (int) ($this->config['range_lookback'] ?? 12);
        if ($count < max(7, $lookback + 2)) {
            return [];
        }

        $last = $count - 1;
        $current = $bars[$last];
        $averageRange = $this->averageRange(array_slice($bars, -min(20, $count)));
        if ($averageRange <= 0.0) {
            return [];
        }

        $patterns = [];
        $span = (int) ($this->config['pivot_span'] ?? 2);
        $highs = $this->pivots($bars, 'high', $span);
        $lows = $this->pivots($bars, 'low', $span);
        $confirmedNow = static fn (array $pivot): bool => $last === $pivot['index'] + $span;
        $tolerance = $averageRange * (float) ($this->config['level_tolerance_range_multiple'] ?? 0.5);
        $minimumSwing = $averageRange * (float) ($this->config['minimum_swing_range_multiple'] ?? 1.0);

        if (count($highs) >= 2) {
            [$first, $second] = array_slice($highs, -2);
            $betweenLow = $this->minimum($bars, 'low', $first['index'], $second['index']);
            if ($confirmedNow($second) && abs($first['price'] - $second['price']) <= $tolerance
                && min($first['price'], $second['price']) - $betweenLow >= $minimumSwing) {
                $patterns[] = $this->pattern('DOUBLE_TOP', 'BEARISH', $first['index'], $last, ['pivots' => [$first, $second], 'neckline' => $betweenLow]);
            }
        }
        if (count($lows) >= 2) {
            [$first, $second] = array_slice($lows, -2);
            $betweenHigh = $this->maximum($bars, 'high', $first['index'], $second['index']);
            if ($confirmedNow($second) && abs($first['price'] - $second['price']) <= $tolerance
                && $betweenHigh - max($first['price'], $second['price']) >= $minimumSwing) {
                $patterns[] = $this->pattern('DOUBLE_BOTTOM', 'BULLISH', $first['index'], $last, ['pivots' => [$first, $second], 'neckline' => $betweenHigh]);
            }
        }
        if (count($highs) >= 2 && count($lows) >= 2) {
            [$highA, $highB] = array_slice($highs, -2);
            [$lowA, $lowB] = array_slice($lows, -2);
            $formation = min($highA['index'], $lowA['index']);
            if (($confirmedNow($highB) || $confirmedNow($lowB)) && $highB['price'] > $highA['price'] + $tolerance && $lowB['price'] > $lowA['price'] + $tolerance) {
                $patterns[] = $this->pattern('HIGHER_HIGH_HIGHER_LOW_SEQUENCE', 'BULLISH', $formation, $last, ['highs' => [$highA, $highB], 'lows' => [$lowA, $lowB]]);
            }
            if (($confirmedNow($highB) || $confirmedNow($lowB)) && $highB['price'] < $highA['price'] - $tolerance && $lowB['price'] < $lowA['price'] - $tolerance) {
                $patterns[] = $this->pattern('LOWER_HIGH_LOWER_LOW_SEQUENCE', 'BEARISH', $formation, $last, ['highs' => [$highA, $highB], 'lows' => [$lowA, $lowB]]);
            }
        }

        $prior = array_slice($bars, -($lookback + 1), $lookback);
        $rangeHigh = max(array_column($prior, 'high'));
        $rangeLow = min(array_column($prior, 'low'));
        $buffer = $averageRange * (float) ($this->config['breakout_buffer_range_multiple'] ?? 0.1);
        $priorWidth = $rangeHigh - $rangeLow;
        $compressionMaximum = $averageRange * (float) ($this->config['compression_width_range_multiple'] ?? 4.0);

        if ($current['close'] > $rangeHigh + $buffer && $priorWidth <= $compressionMaximum) {
            $patterns[] = $this->pattern('CONSOLIDATION_BREAKOUT', 'BULLISH', $last - $lookback, $last, ['boundary' => $rangeHigh, 'range_low' => $rangeLow]);
        } elseif ($current['close'] < $rangeLow - $buffer && $priorWidth <= $compressionMaximum) {
            $patterns[] = $this->pattern('CONSOLIDATION_BREAKOUT', 'BEARISH', $last - $lookback, $last, ['boundary' => $rangeLow, 'range_high' => $rangeHigh]);
        }

        $previous = $bars[$last - 1];
        $prePrevious = array_slice($bars, -($lookback + 2), $lookback);
        $oldHigh = max(array_column($prePrevious, 'high'));
        $oldLow = min(array_column($prePrevious, 'low'));
        if ($previous['close'] > $oldHigh + $buffer && $current['close'] <= $oldHigh) {
            $patterns[] = $this->pattern('FALSE_BREAKOUT', 'BEARISH', $last - 1, $last, ['boundary' => $oldHigh]);
        } elseif ($previous['close'] < $oldLow - $buffer && $current['close'] >= $oldLow) {
            $patterns[] = $this->pattern('FALSE_BREAKOUT', 'BULLISH', $last - 1, $last, ['boundary' => $oldLow]);
        }

        if ($current['high'] > $rangeHigh + $buffer && $current['close'] < $rangeHigh) {
            $patterns[] = $this->pattern('LIQUIDITY_SWEEP', 'BEARISH', $last - $lookback, $last, ['boundary' => $rangeHigh]);
        }
        if ($current['low'] < $rangeLow - $buffer && $current['close'] > $rangeLow) {
            $patterns[] = $this->pattern('LIQUIDITY_SWEEP', 'BULLISH', $last - $lookback, $last, ['boundary' => $rangeLow]);
        }

        $body = abs($current['close'] - $current['open']);
        $upperWick = $current['high'] - max($current['open'], $current['close']);
        $lowerWick = min($current['open'], $current['close']) - $current['low'];
        $rejectionMultiple = (float) ($this->config['rejection_wick_body_multiple'] ?? 1.5);
        if ($current['high'] >= $rangeHigh - $buffer && $current['close'] < $rangeHigh && $upperWick >= max($body, PHP_FLOAT_EPSILON) * $rejectionMultiple) {
            $patterns[] = $this->pattern('SUPPORT_RESISTANCE_REJECTION', 'BEARISH', $last - $lookback, $last, ['boundary' => $rangeHigh]);
        }
        if ($current['low'] <= $rangeLow + $buffer && $current['close'] > $rangeLow && $lowerWick >= max($body, PHP_FLOAT_EPSILON) * $rejectionMultiple) {
            $patterns[] = $this->pattern('SUPPORT_RESISTANCE_REJECTION', 'BULLISH', $last - $lookback, $last, ['boundary' => $rangeLow]);
        }

        $retestLookback = (int) ($this->config['retest_lookback'] ?? 6);
        for ($index = max(1, $last - $retestLookback); $index < $last; $index++) {
            $history = array_slice($bars, max(0, $index - $lookback), min($lookback, $index));
            if ($history === []) {
                continue;
            }
            $boundaryHigh = max(array_column($history, 'high'));
            $boundaryLow = min(array_column($history, 'low'));
            if ($bars[$index]['close'] > $boundaryHigh + $buffer && $current['low'] <= $boundaryHigh + $buffer && $current['close'] > $boundaryHigh) {
                $patterns[] = $this->pattern('BREAKOUT_RETEST', 'BULLISH', $index, $last, ['boundary' => $boundaryHigh, 'breakout_index' => $index]);
                break;
            }
            if ($bars[$index]['close'] < $boundaryLow - $buffer && $current['high'] >= $boundaryLow - $buffer && $current['close'] < $boundaryLow) {
                $patterns[] = $this->pattern('BREAKOUT_RETEST', 'BEARISH', $index, $last, ['boundary' => $boundaryLow, 'breakout_index' => $index]);
                break;
            }
        }

        return array_values(array_reduce($patterns, static function (array $carry, array $pattern): array {
            $carry[$pattern['name'].'|'.$pattern['direction']] = $pattern;

            return $carry;
        }, []));
    }

    private function pivots(array $bars, string $field, int $span): array
    {
        $pivots = [];
        for ($index = $span; $index < count($bars) - $span; $index++) {
            $value = $bars[$index][$field];
            $neighbors = [];
            for ($offset = -$span; $offset <= $span; $offset++) {
                if ($offset !== 0) {
                    $neighbors[] = $bars[$index + $offset][$field];
                }
            }
            $confirmed = $field === 'high' ? $value > max($neighbors) : $value < min($neighbors);
            if ($confirmed) {
                $pivots[] = ['index' => $index, 'price' => $value];
            }
        }

        return $pivots;
    }

    private function normalize(array $bar): array
    {
        return ['open' => (float) $bar['open'], 'high' => (float) $bar['high'], 'low' => (float) $bar['low'], 'close' => (float) $bar['close']];
    }

    private function averageRange(array $bars): float
    {
        return array_sum(array_map(static fn (array $bar): float => $bar['high'] - $bar['low'], $bars)) / max(1, count($bars));
    }

    private function minimum(array $bars, string $field, int $from, int $to): float
    {
        return min(array_column(array_slice($bars, $from, $to - $from + 1), $field));
    }

    private function maximum(array $bars, string $field, int $from, int $to): float
    {
        return max(array_column(array_slice($bars, $from, $to - $from + 1), $field));
    }

    private function pattern(string $name, string $direction, int $formationIndex, int $detectionIndex, array $evidence): array
    {
        return ['name' => $name, 'direction' => $direction, 'formation_index' => max(0, $formationIndex),
            'detection_index' => $detectionIndex, 'bars' => $detectionIndex - max(0, $formationIndex) + 1,
            'version' => '1.0.0', 'evidence' => $evidence];
    }
}
