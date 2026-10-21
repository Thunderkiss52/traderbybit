<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Domain;

enum QimEvidenceProvenance: string
{
    case ORIGINAL_EXPORT = 'ORIGINAL_EXPORT';
    case SCREENSHOT = 'SCREENSHOT';
    case OPERATOR_TRANSCRIBED = 'OPERATOR_TRANSCRIBED';
    case SYNTHETIC_TEST_FIXTURE = 'SYNTHETIC_TEST_FIXTURE';
}
