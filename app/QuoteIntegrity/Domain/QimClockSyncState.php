<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimClockSyncState: string
{
    case SYNCHRONIZED = 'SYNCHRONIZED';
    case CLOCK_SYNC_UNKNOWN = 'CLOCK_SYNC_UNKNOWN';
    case TIMESTAMP_UNRELIABLE = 'TIMESTAMP_UNRELIABLE';
}
