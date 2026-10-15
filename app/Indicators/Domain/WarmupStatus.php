<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class WarmupStatus
{
    public function __construct(
        public int $requiredCandles,
        public int $availableCandles,
    ) {}

    public function isReady(): bool
    {
        return $this->availableCandles >= $this->requiredCandles;
    }

    public function missingCandles(): int
    {
        return max(0, $this->requiredCandles - $this->availableCandles);
    }
}
