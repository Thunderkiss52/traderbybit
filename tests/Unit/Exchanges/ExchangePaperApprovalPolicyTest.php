<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Application\ExchangePaperApprovalPolicy;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use Tests\TestCase;

final class ExchangePaperApprovalPolicyTest extends TestCase
{
    public function test_only_explicitly_approved_active_paper_exchange_can_open_entries(): void
    {
        self::assertFalse(ExchangePaperApprovalPolicy::allowsEntries(null));

        foreach (['PAPER_ELIGIBLE', 'PAUSED', 'DEACTIVATED', 'RESEARCH_ENABLED'] as $status) {
            $venue = new ExchangeRecord(['paper_approved' => true, 'lifecycle_status' => $status]);
            self::assertFalse(ExchangePaperApprovalPolicy::allowsEntries($venue), $status);
        }

        $unapproved = new ExchangeRecord(['paper_approved' => false, 'lifecycle_status' => 'ACTIVE_PAPER']);
        self::assertFalse(ExchangePaperApprovalPolicy::allowsEntries($unapproved));

        $approved = new ExchangeRecord(['paper_approved' => true, 'lifecycle_status' => 'ACTIVE_PAPER']);
        self::assertTrue(ExchangePaperApprovalPolicy::allowsEntries($approved));
    }
}
