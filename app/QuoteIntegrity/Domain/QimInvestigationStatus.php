<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimInvestigationStatus: string
{
    case INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case OPERATOR_CONFIRMED_DISCREPANCY = 'OPERATOR_CONFIRMED_DISCREPANCY';
}
