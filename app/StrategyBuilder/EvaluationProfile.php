<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

final class EvaluationProfile
{
    public static function window(array $definition): int
    {
        $window = 220;
        foreach ($definition['indicators'] as $name => $indicator) {
            $period = max($indicator['period'], $definition['parameters']["indicators.{$name}.period"]['max'] ?? 0);
            $slow = max($indicator['slow_period'] ?? 0, $definition['parameters']["indicators.{$name}.slow_period"]['max'] ?? 0);
            $signal = max($indicator['signal_period'] ?? 9, $definition['parameters']["indicators.{$name}.signal_period"]['max'] ?? 0);
            $window = max($window, (int) max(2 * $period, $slow) + (int) $signal + 2);
        }

        return $window;
    }

    public static function paperBlockers(array $definition): array
    {
        $reasons = [];
        if ($definition['schema_version'] !== 2) {
            $reasons[] = 'PAPER_REQUIRES_SCHEMA_V2';
        }
        if ($definition['timeframe'] !== '15') {
            $reasons[] = 'PAPER_REQUIRES_15M';
        }
        if (! empty($definition['exit'])) {
            $reasons[] = 'CUSTOM_EXIT_PAPER_PARITY_UNVERIFIED';
        }
        if (isset($definition['partial_exit'])) {
            $reasons[] = 'PARTIAL_EXIT_PAPER_PARITY_UNVERIFIED';
        }
        if ($definition['cooldown_bars'] !== 0) {
            $reasons[] = 'COOLDOWN_PAPER_PARITY_UNVERIFIED';
        }
        if (($definition['funding']['enabled'] ?? false) !== true) {
            $reasons[] = 'FUNDING_NOT_MODELED';
        } elseif ($definition['funding']['interval_hours'] !== 8) {
            $reasons[] = 'PAPER_FUNDING_SCHEDULE_UNVERIFIED';
        }

        return $reasons;
    }
}
