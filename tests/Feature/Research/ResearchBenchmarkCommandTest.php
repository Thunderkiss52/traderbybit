<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use Tests\TestCase;

final class ResearchBenchmarkCommandTest extends TestCase
{
    public function test_can_run_research_benchmark_command(): void
    {
        $this->artisan('research:benchmark', ['--candles' => 500])
            ->expectsOutputToContain('=== VECTOR Quant Research Comparative Benchmark')
            ->expectsOutputToContain('PHP Reference:')
            ->expectsOutputToContain('=== Comparative Parity and Gate Summary ===')
            ->assertSuccessful();
    }
}
