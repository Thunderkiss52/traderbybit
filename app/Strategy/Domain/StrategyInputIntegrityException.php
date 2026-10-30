<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use DomainException;

final class StrategyInputIntegrityException extends DomainException
{
    public function __construct(public readonly string $reasonCode, public readonly string $integrityStatus, public readonly array $scope)
    {
        parent::__construct($reasonCode.': strategy input integrity is not established.');
    }
}
