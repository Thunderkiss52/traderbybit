<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

enum AccountStatus: string
{
    case Active = 'ACTIVE';
    case Blocked = 'BLOCKED';
    case Halted = 'HALTED';
}
