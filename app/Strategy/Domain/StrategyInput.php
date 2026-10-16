<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use DateTimeImmutable;

final readonly class StrategyInput
{
    public function __construct(
        public string $exchange,
        public string $category,
        public string $symbol,
        public DateTimeImmutable $decisionTime,
        public TimeframeState $trend,
        public TimeframeState $setup,
        public TimeframeState $entry,
        public TimeframeState $confirmation,
        public ?MarketContext $marketContext,
        /** @var list<string> */
        public array $validationErrors = [],
    ) {}
}
