<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

final readonly class TimeframeState
{
    /** @param list<BarState> $bars */
    public function __construct(
        public string $interval,
        public array $bars,
        public bool $warmupReady,
    ) {}

    public function latest(): ?BarState
    {
        return $this->bars === [] ? null : $this->bars[array_key_last($this->bars)];
    }

    public function previous(): ?BarState
    {
        return count($this->bars) < 2 ? null : $this->bars[count($this->bars) - 2];
    }
}
