<?php

declare(strict_types=1);

namespace App\Backtest\Domain;

final readonly class SimulationResult
{
    public function __construct(public array $trades, public array $equity, public array $decisions, public array $warnings = []) {}
}
