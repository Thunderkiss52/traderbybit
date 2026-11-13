<?php

declare(strict_types=1);

namespace App\Trading\Application;

use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\PrimaryStrategies;

/** An extra candidate or obsolete version cannot substitute for a missing primary observation. */
final class CanonicalObservationCompleteness
{
    /** @param iterable<object> $observations */
    public static function isComplete(iterable $observations, string $exchange): bool
    {
        $canonical = array_fill_keys(PrimaryStrategies::ids($exchange), true);
        $expected = [];
        foreach (app(StrategyRegistry::class)->all() as $strategy) {
            if (isset($canonical[$strategy->id()])) {
                $expected[$strategy->id().'|'.$strategy->version().'|'.StrategyRegistry::hash($strategy->parameters())] = true;
            }
        }
        if (count($expected) !== count($canonical) || $expected === []) {
            return false;
        }
        $seen = [];
        foreach ($observations as $observation) {
            $identity = (string) $observation->strategy_id.'|'.(string) $observation->strategy_version.'|'.(string) $observation->configuration_hash;
            if (isset($expected[$identity])) {
                $seen[$identity] = true;
            }
        }

        return count($seen) === count($expected);
    }
}
