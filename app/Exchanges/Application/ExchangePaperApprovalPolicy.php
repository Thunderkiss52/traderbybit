<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\Domain\ExchangeLifecycleStatus;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;

/**
 * One authoritative operator-approval predicate shared by provisioning,
 * scheduled/event-driven entry dispatch and the account-level entry gate.
 * A missing catalog record, configured flag or ACTIVE account alone never approves a venue.
 */
final class ExchangePaperApprovalPolicy
{
    public static function allowsEntries(?ExchangeRecord $exchange): bool
    {
        return $exchange !== null
            && $exchange->paper_approved === true
            && $exchange->lifecycle_status === ExchangeLifecycleStatus::ActivePaper->value;
    }
}
