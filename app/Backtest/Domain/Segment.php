<?php

declare(strict_types=1);

namespace App\Backtest\Domain;

use DateTimeImmutable;

final readonly class Segment
{
    public function __construct(public string $kind, public DateTimeImmutable $from, public DateTimeImmutable $to, public int $windowIndex = 0) {}
}
