<?php

declare(strict_types=1);

namespace App\Strategy\Contracts;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;

interface StrategyInterface
{
    public function id(): string;

    public function version(): string;

    public function description(): string;

    public function parameters(): array;

    public function evaluate(StrategyInput $input): SignalDecision;
}
