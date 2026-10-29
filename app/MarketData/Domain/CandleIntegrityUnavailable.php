<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use RuntimeException;
use Throwable;

final class CandleIntegrityUnavailable extends RuntimeException
{
    /** @param array<string, mixed>|null $ingestionReport */
    public function __construct(string $message, ?Throwable $previous = null, public readonly ?array $ingestionReport = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
