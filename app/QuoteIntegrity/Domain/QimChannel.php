<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimChannel: string
{
    case REST = 'REST';
    case WS = 'WS';
}
