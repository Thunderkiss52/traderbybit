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

        // Verify parameter bounds
        $params = (array) $c1->parameters;
        self::assertGreaterThanOrEqual(15.0, (float) $params['adx_threshold']);
        self::assertLessThanOrEqual(30.0, (float) $params['adx_threshold']);
        self::assertGreaterThanOrEqual(1.0, (float) $params['atr_multiplier']);
        self::assertLessThanOrEqual(3.0, (float) $params['atr_multiplier']);
    }
}
