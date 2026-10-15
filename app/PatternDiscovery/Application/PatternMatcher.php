<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

final class PatternMatcher
{
    /** @param list<string> $features @param list<string> $terms */
    public function matches(array $terms, array $features): bool
    {
        return $terms !== [] && array_diff($terms, $features) === [];
    }
}
