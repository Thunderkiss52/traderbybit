<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\AccountUnblockService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountUnblockTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_unblocks_account_resets_drawdown_baseline_and_records_audit_events(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update([
            'status' => AccountStatus::Blocked->value,
            'execution_block_origin' => 'RISK_DRAWDOWN',
            'current_equity' => '9400.00000000',
            'peak_equity' => '10000.00000000',
            'current_drawdown_percent' => '6.0000',
            'maximum_drawdown_percent' => '6.0000',
        ]);

        $service = app(AccountUnblockService::class);
        $unblocked = $service->unblock($account->id, 'Operator manual unblock for strategy evolution', 'lead_quant');

        self::assertSame(AccountStatus::Active->value, $unblocked->status);
        self::assertNull($unblocked->execution_block_origin);
        self::assertNull($unblocked->cooldown_until);
        self::assertTrue($unblocked->drawdown_override);
        self::assertDecimalSame('9400.00000000', (string) $unblocked->peak_equity);
        self::assertDecimalSame('0.0000', (string) $unblocked->current_drawdown_percent);
        self::assertDecimalSame('6.0000', (string) $unblocked->maximum_drawdown_percent);

        // Verify Journal Event
        $journalEvent = JournalEventRecord::query()
            ->where('paper_account_id', $account->id)
            ->where('event_type', 'OPERATOR_MANUAL_UNBLOCK')
            ->first();
        self::assertNotNull($journalEvent);
        $payload = is_array($journalEvent->payload) ? $journalEvent->payload : json_decode($journalEvent->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('lead_quant', $payload['operator']);
        self::assertSame('BLOCKED', $payload['from_status']);
        self::assertSame('ACTIVE', $payload['to_status']);
        self::assertSame('RISK_DRAWDOWN', $payload['previous_block_origin']);

        // Verify Autonomy Approval Event
        $autonomyEvent = AutonomyApprovalEventRecord::query()
            ->where('target_id', $account->id)
            ->where('action', 'OPERATOR_UNBLOCK_STRATEGY')
            ->first();
        self::assertNotNull($autonomyEvent);
        self::assertSame('USER_OPERATOR', $autonomyEvent->actor);
        self::assertSame('ACTIVE', $autonomyEvent->new_state);
        self::assertSame('BLOCKED', $autonomyEvent->previous_state);
    }

    public function test_api_endpoint_unblocks_account(): void
    {
        $account = app(PaperAccountService::class)->default();
        $account->update([
            'status' => AccountStatus::Blocked->value,
            'execution_block_origin' => 'RISK_DRAWDOWN',
            'current_equity' => '9450.00000000',
            'current_drawdown_percent' => '5.5000',
        ]);

        $response = $this->postJson("/api/v1/paper/accounts/{$account->id}/unblock", [
            'reason' => 'Evolving parameters via learning optimization',
            'operator' => 'test_operator',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.account.status', 'ACTIVE');
        $response->assertJsonPath('data.account.execution_block_origin', null);
        self::assertDecimalSame('0.0000', (string) $response->json('data.account.current_drawdown_percent'));

        $fresh = $account->fresh();
        self::assertSame('ACTIVE', $fresh->status);
        self::assertNull($fresh->execution_block_origin);
        self::assertDecimalSame('0.0000', (string) $fresh->current_drawdown_percent);
    }
}
