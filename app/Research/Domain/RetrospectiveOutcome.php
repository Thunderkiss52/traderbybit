<?php

declare(strict_types=1);

namespace App\Research\Domain;

enum RetrospectiveOutcome: string
{
    case Good = 'GOOD';
    case Bad = 'BAD';
    case Unproven = 'UNPROVEN';
}
