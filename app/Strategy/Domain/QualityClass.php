<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

enum QualityClass: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
}
