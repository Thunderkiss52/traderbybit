<?php

declare(strict_types=1);

namespace App\MarketData\Exceptions;

use RuntimeException;
use Throwable;

final class BybitApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retCode = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
