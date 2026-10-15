<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

enum ExecutionType: string
{
    case Entry = 'ENTRY';
    case TakeProfit1 = 'TAKE_PROFIT_1';
    case TakeProfit2 = 'TAKE_PROFIT_2';
    case StopLoss = 'STOP_LOSS';
    case Timeout = 'TIMEOUT';
    case RiskClosure = 'RISK_CLOSURE';
}
