<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\Exchanges\Contracts\ExchangeAdapter;
use InvalidArgumentException;

final readonly class ExchangeRegistry
{
    /** @param array<string, ExchangeAdapter> $adapters */
    public function __construct(private array $adapters) {}

    public function resolve(string $id): ExchangeAdapter
    {
        return $this->adapters[$id] ?? throw new InvalidArgumentException('Unsupported exchange.');
    }

    /** @return list<string> */
    public function supported(): array
    {
        return array_keys($this->adapters);
    }
}
