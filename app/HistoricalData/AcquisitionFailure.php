<?php

declare(strict_types=1);

namespace App\HistoricalData;

final class AcquisitionFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly ?int $retryAfter = null, public readonly array $diagnostics = [])
    {
        parent::__construct($reason);
    }
}
