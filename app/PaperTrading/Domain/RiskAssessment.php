<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class RiskAssessment
{
    public function __construct(
        public bool $allowed,
        public array $reasons,
        public array $snapshot,
    ) {}
}
