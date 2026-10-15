<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class ExecutionQuote
{
    public function __construct(
        public ExecutionType $type,
        public string $price,
        public string $referencePrice,
        public string $slippage,
        public string $priceSource,
        public bool $ambiguous,
    ) {}
}
