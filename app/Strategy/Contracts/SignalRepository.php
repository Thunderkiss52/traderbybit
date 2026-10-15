<?php

declare(strict_types=1);

namespace App\Strategy\Contracts;

use App\Strategy\Domain\SignalDecision;

interface SignalRepository
{
    public function createIfAbsent(SignalDecision $decision): SignalDecision;
}
