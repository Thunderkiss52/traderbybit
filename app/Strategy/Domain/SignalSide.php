<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

enum SignalSide: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
    case Skip = 'SKIP';
}
