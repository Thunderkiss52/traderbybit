<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimIncidentStatus: string
{
    case NEW = 'NEW';
    case INVESTIGATING = 'INVESTIGATING';
    case EXPLAINED = 'EXPLAINED';
    case UNRESOLVED = 'UNRESOLVED';
    case CLOSED = 'CLOSED';
}
