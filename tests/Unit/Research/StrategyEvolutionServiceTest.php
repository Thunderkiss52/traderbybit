<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\StrategyEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StrategyEvolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_bounded_candidate_with_reproducible_seed(): void
    {
        $service = new StrategyEvolutionService;

        $c1 = $service->generateCandidate('trend_pullback', 12345);
        $c2 = $service->generateCandidate('trend_pullback', 12345);

        self::assertSame($c1->parameters, $c2->parameters);
        self::assertSame($c1->configuration_hash, $c2->configuration_hash);
        self::assertSame('RESEARCH_ONLY', $c1->status);
        self::assertNotEmpty($c1->parameter_diff);
        self::assertNotEmpty($c1->hypothesis);

        // Verify every mutated value stays inside its configured bound.
        $params = (array) $c1->parameters;
        self::assertSame($c1->candidate_version, $params['version']);
        foreach ((array) $c1->parameter_diff as $key => $change) {
            $spec = (array) config('research.evolution.spaces.trend_pullback.'.$key);
            self::assertGreaterThanOrEqual((float) $spec['min'], (float) $change['to']);
            self::assertLessThanOrEqual((float) $spec['max'], (float) $change['to']);
            self::assertSame((string) $c1->candidate_version, (string) $params['version']);
        }
    }

    public function test_generates_candidates_for_every_family(): void
    {
        $service = new StrategyEvolutionService;

        foreach ($service->families() as $index => $family) {
            $candidate = $service->generateCandidate($family, 20000 + $index);
            self::assertSame($family, $candidate->parent_strategy_id);
            self::assertSame('RESEARCH_ONLY', $candidate->status);
            self::assertNotEmpty($candidate->parameter_diff);
            $config = $service->strategyConfig($candidate);
            self::assertSame($candidate->candidate_version, $config['version']);
        }
    }
}
