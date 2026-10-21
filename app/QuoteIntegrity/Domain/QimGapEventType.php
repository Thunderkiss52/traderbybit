<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimGapEventType: string
{
    case DETECTED = 'DETECTED';
    case RECOVERED = 'RECOVERED';
    case RECOVERY_FAILED = 'RECOVERY_FAILED';
}
