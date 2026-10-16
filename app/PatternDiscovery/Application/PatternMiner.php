<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use App\PatternDiscovery\Domain\MarketObservation;

/** Bounded interpretable frequent-itemset mining with forward-only holdouts. */
final class PatternMiner
{
    /** @param list<MarketObservation> $observations */
    public function discover(array $observations, array $config): array
    {
        usort($observations, static fn ($a, $b) => $a->decisionTimestamp <=> $b->decisionTimestamp ?: strcmp($a->signalId, $b->signalId));
        $n = count($observations);
        if ($n < 10) {
            return [];
        }
        // Chronological boundaries; events whose outcome crosses into a later split are excluded.
        $trainEnd = $observations[max(0, (int) floor($n * 0.6) - 1)]->decisionTimestamp;
        $validationEnd = $observations[max(0, (int) floor($n * 0.8) - 1)]->decisionTimestamp;
        $segments = ['train' => [], 'validation' => [], 'test' => []];
        $lastEnd = [];
        foreach ($observations as $item) {
            $segment = $item->decisionTimestamp <= $trainEnd ? 'train' : ($item->decisionTimestamp <= $validationEnd ? 'validation' : 'test');
            if (($segment === 'train' && $item->outcomeTimestamp > $trainEnd) || ($segment === 'validation' && $item->outcomeTimestamp > $validationEnd)) {
                continue;
            }
            $key = $segment.'|'.$item->symbol.'|'.$item->direction;
            if ($item->decisionTimestamp < ($lastEnd[$key] ?? 0)) {
                continue; // De-overlap correlated outcomes for independence.
            }
            $segments[$segment][] = $item;
            $lastEnd[$key] = $item->outcomeTimestamp;
        }
        $candidates = [];
        foreach ($segments['train'] as $observation) {
            $features = $observation->features;
            $count = count($features);
            for ($i = 0; $i < $count; $i++) {
                $this->accumulate($candidates, $observation->direction, [$features[$i]]);
                if ((int) $config['maximum_terms'] >= 2) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $this->accumulate($candidates, $observation->direction, [$features[$i], $features[$j]]);
                    }
                }
            }
        }
        uasort($candidates, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $tested = 0;
        $results = [];
        $totalCandidates = count($candidates);
        foreach ($candidates as $candidate) {
            if ($candidate['count'] < (int) $config['minimum_train']) {
                continue;
            }
            if ($tested++ >= (int) $config['maximum_patterns']) {
                break;
            }
            $stats = [];
            foreach ($segments as $name => $items) {
                $matching = array_values(array_filter($items, static fn (MarketObservation $o) => $o->direction === $candidate['direction'] && array_diff($candidate['terms'], $o->features) === []));
                $stats[$name] = $this->summarize($matching, (float) $config['confidence_level_z']);
            }
            $minimum = [(int) $config['minimum_train'], (int) $config['minimum_validation'], (int) $config['minimum_test']];
            $enough = $stats['train']['count'] >= $minimum[0] && $stats['validation']['count'] >= $minimum[1] && $stats['test']['count'] >= $minimum[2];
            // Strong familywise, multiple-testing-aware bound on the OOS win-rate improvement.
            $margin = $stats['test']['count'] > 0 ? sqrt(log(2 * max(1, $totalCandidates) / max(0.000001, (float) $config['multiple_testing_alpha'])) / (2 * $stats['test']['count'])) : 1.0;
            $baseline = $this->summarize(array_values(array_filter($segments['test'], static fn ($o) => $o->direction === $candidate['direction'])), (float) $config['confidence_level_z']);
            $passes = $enough && $stats['validation']['mean_r'] > 0
                && $stats['test']['mean_r'] > (float) $config['minimum_out_of_sample_expectancy_r']
                && ($stats['test']['win_rate'] - $margin) > $baseline['win_rate'];
            $status = ! $enough ? 'VALIDATING' : ($passes ? 'VALIDATED' : 'REJECTED');
            $walkForward = $this->walkForward(
                array_merge($segments['train'], $segments['validation'], $segments['test']),
                $candidate,
                max(2, (int) ($config['walk_forward_folds'] ?? 3)),
                (float) $config['confidence_level_z'],
            );
            $testTotal = count(array_filter($segments['test'], static fn (MarketObservation $o): bool => $o->direction === $candidate['direction']));
            $testSupport = $testTotal === 0 ? null : $stats['test']['count'] / $testTotal;
            $testLift = $stats['test']['win_rate'] === null || empty($baseline['win_rate'])
                ? null : $stats['test']['win_rate'] / $baseline['win_rate'];
            $results[] = [
                'direction' => $candidate['direction'], 'terms' => $candidate['terms'], 'status' => $status,
                'train' => $stats['train'], 'validation' => $stats['validation'], 'test' => $stats['test'],
                'baseline_test' => $baseline, 'multiple_testing_margin' => $margin,
                'support' => $testSupport, 'confidence' => $stats['test']['win_rate'], 'lift' => $testLift,
                'frequency' => $testSupport, 'walk_forward' => $walkForward,
            ];
        }

        return $results;
    }

    private function accumulate(array &$candidates, string $direction, array $terms): void
    {
        $key = $direction.'|'.implode('|', $terms);
        if (! isset($candidates[$key])) {
            $candidates[$key] = ['direction' => $direction, 'terms' => $terms, 'count' => 0];
        }
        $candidates[$key]['count']++;
    }

    /** @param list<MarketObservation> $items */
    private function summarize(array $items, float $z): array
    {
        $n = count($items);
        $wins = count(array_filter($items, static fn ($x) => $x->won));
        $p = $n === 0 ? 0.0 : $wins / $n;
        $denom = 1 + $z * $z / max(1, $n);
        $center = ($p + $z * $z / (2 * max(1, $n))) / $denom;
        $half = $n === 0 ? 0.0 : $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $n)) / $n) / $denom;

        $positive = array_sum(array_map(static fn (MarketObservation $item): float => max(0.0, $item->netR), $items));
        $negative = abs(array_sum(array_map(static fn (MarketObservation $item): float => min(0.0, $item->netR), $items)));
        $counts = static function (string $field) use ($items): array {
            $values = [];
            foreach ($items as $item) {
                $value = $item->{$field};
                $values[$value] = ($values[$value] ?? 0) + 1;
            }
            ksort($values);

            return $values;
        };

        return ['count' => $n, 'wins' => $wins, 'win_rate' => $n ? $p : null,
            'wilson_low' => $n ? max(0, $center - $half) : null, 'wilson_high' => $n ? min(1, $center + $half) : null,
            'mean_r' => $n ? array_sum(array_map(static fn ($x) => $x->netR, $items)) / $n : null,
            'expectancy_r' => $n ? array_sum(array_map(static fn ($x) => $x->netR, $items)) / $n : null,
            'profit_factor' => $negative > 0 ? $positive / $negative : ($positive > 0 ? null : 0.0),
            'average_mfe_r' => $n ? array_sum(array_map(static fn ($x) => $x->mfeR, $items)) / $n : null,
            'average_mae_r' => $n ? array_sum(array_map(static fn ($x) => $x->maeR, $items)) / $n : null,
            'anchor_decisions' => $counts('anchorDecision'), 'risk_outcomes' => $counts('riskOutcome'),
            'paper_outcomes' => $counts('paperOutcome'),
            'ambiguous_count' => count(array_filter($items, static fn ($x) => $x->ambiguous))];
    }

    private function walkForward(array $items, array $candidate, int $folds, float $z): array
    {
        usort($items, static fn (MarketObservation $a, MarketObservation $b): int => $a->decisionTimestamp <=> $b->decisionTimestamp);
        $count = count($items);
        if ($count < $folds + 2) {
            return [];
        }
        $window = max(1, (int) floor($count / ($folds + 1)));
        $results = [];
        for ($fold = 1; $fold <= $folds; $fold++) {
            $validationStart = $window * $fold;
            $validationEnd = $fold === $folds ? $count : min($count, $validationStart + $window);
            $cutoff = $items[$validationStart]->decisionTimestamp;
            $training = array_values(array_filter(array_slice($items, 0, $validationStart),
                static fn (MarketObservation $item): bool => $item->outcomeTimestamp <= $cutoff));
            $validation = array_slice($items, $validationStart, $validationEnd - $validationStart);
            $matchingTraining = array_values(array_filter($training, static fn (MarketObservation $item): bool => $item->direction === $candidate['direction'] && array_diff($candidate['terms'], $item->features) === []));
            $matchingValidation = array_values(array_filter($validation, static fn (MarketObservation $item): bool => $item->direction === $candidate['direction'] && array_diff($candidate['terms'], $item->features) === []));
            $results[] = ['fold' => $fold, 'train_through' => $cutoff, 'train' => $this->summarize($matchingTraining, $z),
                'validation' => $this->summarize($matchingValidation, $z)];
        }

        return $results;
    }
}
