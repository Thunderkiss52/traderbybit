<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface InstrumentMetadataProvider
{
    /** @return array<string, mixed> */
    public function instrumentInfo(string $category, string $symbol): array;
}
