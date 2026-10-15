<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use DateTimeImmutable;

/** Only uses persisted values with an explicit availability time <= decision time. */
final class MarketFeatureExtractor
{
    /** @return array{features:list<string>,regime:string} */
    public function extract(array $snapshot, int $decisionTimestamp, ?array $previous = null, ?array $context = null): array
    {
        $features = [];
        $regime = 'UNCERTAIN';
        foreach (['1h', '15m', '5m'] as $frame) {
            $bar = $snapshot[$frame] ?? null;
            if (! is_array($bar) || ! isset($bar['close_time'])) {
                continue;
            }
            try {
                $seen = (new DateTimeImmutable((string) $bar['close_time']))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if ($seen > $decisionTimestamp) {
                throw new \InvalidArgumentException('future_feature_not_allowed');
            }
            foreach (['rsi_14' => [30, 50, 70], 'adx_14' => [18, 25], 'relative_volume' => [1, 1.5]] as $field => $cuts) {
                $value = $bar[$field] ?? null;
                if (! is_numeric($value)) {
                    continue;
                }
                $bucket = 0;
                foreach ($cuts as $cut) {
                    if ((float) $value >= $cut) {
                        $bucket++;
                    }
                }
                $features[] = "$frame:$field:bucket$bucket";
            }
            if (is_numeric($bar['close'] ?? null) && is_numeric($bar['ema_50'] ?? null) && is_numeric($bar['ema_200'] ?? null)) {
                $features[] = "$frame:ema_trend:".((float) $bar['ema_50'] > (float) $bar['ema_200'] ? 'UP' : 'DOWN');
            }
            if (is_numeric($bar['macd_histogram'] ?? null)) {
                $features[] = "$frame:macd_hist:".((float) $bar['macd_histogram'] >= 0 ? 'POS' : 'NEG');
            }
            if ($frame === '1h' && is_numeric($bar['adx_14'] ?? null)) {
                $regime = (float) $bar['adx_14'] >= 25 ? 'TREND' : ((float) $bar['adx_14'] < 18 ? 'RANGE' : 'UNCERTAIN');
            }
        }
        if ($previous !== null && isset($previous['decision_timestamp'], $previous['reason'])
            && $previous['decision_timestamp'] < $decisionTimestamp
            && $decisionTimestamp - $previous['decision_timestamp'] <= 900) {
            $reason = (string) $previous['reason'];
            if (preg_match('/^[a-z0-9_]{1,70}$/', $reason) === 1) {
                $features[] = 'prior15m:reason:'.$reason;
            }
        }
        if ($previous !== null && isset($previous['snapshot'], $previous['decision_timestamp'])
            && $previous['decision_timestamp'] < $decisionTimestamp
            && $decisionTimestamp - $previous['decision_timestamp'] <= 900) {
            $a = $previous['snapshot']['15m'] ?? null;
            $b = $snapshot['15m'] ?? null;
            if (is_array($a) && is_array($b)) {
                foreach (['rsi_14' => 'rsi', 'macd_histogram' => 'macd', 'relative_volume' => 'volume'] as $metric => $tag) {
                    if (is_numeric($a[$metric] ?? null) && is_numeric($b[$metric] ?? null)) {
                        $features[] = 'sequence:15m:'.$tag.':'.((float) $b[$metric] > (float) $a[$metric] ? 'RISING' : 'NOT_RISING');
                    }
                }
                if (is_numeric($a['macd_histogram'] ?? null) && is_numeric($b['macd_histogram'] ?? null)
                    && (float) $a['macd_histogram'] <= 0 && (float) $b['macd_histogram'] > 0) {
                    $features[] = 'sequence:15m:macd_bullish_zero_cross';
                }
            }
        }
        if ($context !== null && isset($context['observed_at'])
            && $context['observed_at'] <= $decisionTimestamp
            && $decisionTimestamp - $context['observed_at'] <= 900) {
            foreach (['funding_rate' => 'funding', 'open_interest_change' => 'open_interest_change'] as $key => $label) {
                if (is_numeric($context[$key] ?? null)) {
                    $features[] = 'context:'.$label.':'.((float) $context[$key] >= 0 ? 'POS' : 'NEG');
                }
            }
        }
        $features[] = 'regime:'.$regime;
        $features = array_values(array_unique($features));
        sort($features, SORT_STRING);

        return ['features' => $features, 'regime' => $regime];
    }
}
