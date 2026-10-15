<?php

declare(strict_types=1);

namespace App\Strategy\Contracts;

use App\Strategy\Domain\MarketContext;
use DateTimeImmutable;

interface MarketContextRepository
{
    public function latestAt(string $exchange, string $category, string $symbol, DateTimeImmutable $at): ?MarketContext;

    public function store(string $exchange, string $category, string $symbol, MarketContext $context): void;
}
