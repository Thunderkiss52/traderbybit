<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Application\StrategyEngine;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;

final readonly class BaselineStrategy implements StrategyInterface
{
    public function __construct(private array $config) {}

    public function id(): string
    {
        return 'baseline';
    }

    public function version(): string
    {
        return (string) $this->config['version'];
    }

    public function description(): string
    {
        return 'Existing production multi-timeframe strategy, unchanged.';
    }

    public function parameters(): array
    {
        return $this->config;
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        return (new StrategyEngine($this->config))->evaluate($input);
    }
}
