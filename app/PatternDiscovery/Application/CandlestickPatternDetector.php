<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

final readonly class CandlestickPatternDetector
{
    public function __construct(private array $config) {}

    /** @param list<array{open:numeric-string,high:numeric-string,low:numeric-string,close:numeric-string}> $bars */
    public function detect(array $bars): array
    {
        if ($bars === []) {
            return [];
        }
        $bars = array_values($bars);
        $current = $this->geometry($bars[array_key_last($bars)]);
        if ($current['range'] <= 0.0) {
            return [];
        }
        $patterns = [];
        $bodyRatio = $current['body'] / $current['range'];
        if ($bodyRatio <= (float) $this->config['doji_body_ratio_max']) {
            $patterns[] = $this->pattern('DOJI', 'NEUTRAL', 1);
        }
        if ($bodyRatio <= (float) $this->config['spinning_body_ratio_max'] && $current['upper'] >= $current['body'] && $current['lower'] >= $current['body']) {
            $patterns[] = $this->pattern('SPINNING_TOP', 'NEUTRAL', 1);
        }
        if ($bodyRatio >= (float) $this->config['marubozu_body_ratio_min']) {
            $patterns[] = $this->pattern('MARUBOZU', $current['bullish'] ? 'BULLISH' : 'BEARISH', 1);
        }
        $wickMultiple = (float) $this->config['long_wick_body_multiple'];
        if ($current['lower'] >= $current['body'] * $wickMultiple && $current['upper'] <= max($current['body'], $current['range'] * 0.1)) {
            $patterns[] = $this->pattern('HAMMER', 'BULLISH', 1);
        }
        if ($current['upper'] >= $current['body'] * $wickMultiple && $current['lower'] <= max($current['body'], $current['range'] * 0.1)) {
            $patterns[] = $this->pattern($current['bullish'] ? 'INVERTED_HAMMER' : 'SHOOTING_STAR', $current['bullish'] ? 'BULLISH' : 'BEARISH', 1);
        }

        if (count($bars) >= 2) {
            $previous = $this->geometry($bars[count($bars) - 2]);
            if ($current['bullish'] !== $previous['bullish'] && $current['bodyLow'] <= $previous['bodyLow'] && $current['bodyHigh'] >= $previous['bodyHigh']) {
                $patterns[] = $this->pattern($current['bullish'] ? 'BULLISH_ENGULFING' : 'BEARISH_ENGULFING', $current['bullish'] ? 'BULLISH' : 'BEARISH', 2);
            }
            if ($current['bullish'] !== $previous['bullish'] && $current['bodyLow'] >= $previous['bodyLow'] && $current['bodyHigh'] <= $previous['bodyHigh']) {
                $patterns[] = $this->pattern($current['bullish'] ? 'BULLISH_HARAMI' : 'BEARISH_HARAMI', $current['bullish'] ? 'BULLISH' : 'BEARISH', 2);
            }
            if ($current['high'] < $previous['high'] && $current['low'] > $previous['low']) {
                $patterns[] = $this->pattern('INSIDE_BAR', 'NEUTRAL', 2);
            }
            if ($current['high'] > $previous['high'] && $current['low'] < $previous['low']) {
                $patterns[] = $this->pattern('OUTSIDE_BAR', 'NEUTRAL', 2);
            }
            $tolerance = max($current['range'], $previous['range']) * (float) $this->config['equal_price_tolerance_ratio'];
            if (abs($current['high'] - $previous['high']) <= $tolerance && ! $current['bullish'] && $previous['bullish']) {
                $patterns[] = $this->pattern('TWEEZER_TOP', 'BEARISH', 2);
            }
            if (abs($current['low'] - $previous['low']) <= $tolerance && $current['bullish'] && ! $previous['bullish']) {
                $patterns[] = $this->pattern('TWEEZER_BOTTOM', 'BULLISH', 2);
            }
        }

        if (count($bars) >= 3) {
            $first = $this->geometry($bars[count($bars) - 3]);
            $middle = $this->geometry($bars[count($bars) - 2]);
            $large = (float) $this->config['star_outer_body_ratio_min'];
            $small = (float) $this->config['star_middle_body_ratio_max'];
            if (! $first['bullish'] && $large <= $first['body'] / max($first['range'], PHP_FLOAT_EPSILON)
                && $small >= $middle['body'] / max($middle['range'], PHP_FLOAT_EPSILON) && $current['bullish'] && $current['close'] > ($first['open'] + $first['close']) / 2) {
                $patterns[] = $this->pattern('MORNING_STAR', 'BULLISH', 3);
            }
            if ($first['bullish'] && $large <= $first['body'] / max($first['range'], PHP_FLOAT_EPSILON)
                && $small >= $middle['body'] / max($middle['range'], PHP_FLOAT_EPSILON) && ! $current['bullish'] && $current['close'] < ($first['open'] + $first['close']) / 2) {
                $patterns[] = $this->pattern('EVENING_STAR', 'BEARISH', 3);
            }
            if ($first['bullish'] && $middle['bullish'] && $current['bullish'] && $first['close'] < $middle['close'] && $middle['close'] < $current['close']) {
                $patterns[] = $this->pattern('THREE_WHITE_SOLDIERS', 'BULLISH', 3);
            }
            if (! $first['bullish'] && ! $middle['bullish'] && ! $current['bullish'] && $first['close'] > $middle['close'] && $middle['close'] > $current['close']) {
                $patterns[] = $this->pattern('THREE_BLACK_CROWS', 'BEARISH', 3);
            }
        }

        return array_values(array_reduce($patterns, static function (array $carry, array $pattern): array {
            $carry[$pattern['name']] = $pattern;

            return $carry;
        }, []));
    }

    private function geometry(array $bar): array
    {
        $open = (float) $bar['open'];
        $high = (float) $bar['high'];
        $low = (float) $bar['low'];
        $close = (float) $bar['close'];
        $bodyHigh = max($open, $close);
        $bodyLow = min($open, $close);

        return ['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
            'range' => max(0.0, $high - $low), 'body' => abs($close - $open),
            'upper' => max(0.0, $high - $bodyHigh), 'lower' => max(0.0, $bodyLow - $low),
            'bodyHigh' => $bodyHigh, 'bodyLow' => $bodyLow, 'bullish' => $close >= $open];
    }

    private function pattern(string $name, string $direction, int $bars): array
    {
        return ['name' => $name, 'direction' => $direction, 'bars' => $bars, 'version' => '1.0.0'];
    }
}
