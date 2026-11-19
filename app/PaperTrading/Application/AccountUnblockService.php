<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Research\Infrastructure\Persistence\AutonomyApprovalEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AccountUnblockService
{
    public function __construct(
        private readonly TradingJournalService $journal,
    ) {}

    public function unblock(string $accountId, ?string $reason = null, ?string $operator = 'operator'): PaperAccountRecord
    {
        return DB::transaction(function () use ($accountId, $reason, $operator): PaperAccountRecord {
            /** @var PaperAccountRecord $account */
            $account = PaperAccountRecord::query()->lockForUpdate()->findOrFail($accountId);

            $previousStatus = $account->status;
            $previousOrigin = $account->execution_block_origin;
            $currentEquity = $account->current_equity;

            // Update account state to ACTIVE, reset drawdown baseline to current equity
            $account->update([
                'status' => AccountStatus::Active->value,
                'execution_block_origin' => null,
                'cooldown_until' => null,
                'drawdown_override' => true,
                'peak_equity' => $currentEquity,
                'current_drawdown_percent' => '0.0000',
            ]);

            $now = CarbonImmutable::now('UTC');

            // Audit in trading journal
            $this->journal->append(
                $account->id,
                null,
                null,
                'OPERATOR_MANUAL_UNBLOCK',
                $now->toDateTimeImmutable(),
                [
                    'operator' => $operator ?? 'operator',
                    'reason' => $reason ?? 'Operator manual unblock request',
                    'from_status' => $previousStatus,
                    'to_status' => AccountStatus::Active->value,
                    'previous_block_origin' => $previousOrigin,
                    'current_equity' => (string) $currentEquity,
                    'historical_max_drawdown' => (string) $account->maximum_drawdown_percent,
                ],
                'operator-unblock:'.$account->id.':'.$now->format('U.u'),
            );

            // Audit in autonomy approval events
            AutonomyApprovalEventRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'actor' => 'USER_OPERATOR',
                'action' => 'OPERATOR_UNBLOCK_STRATEGY',
                'target_type' => 'PAPER_ACCOUNT',
                'target_id' => $account->id,
                'previous_state' => $previousStatus,
                'new_state' => AccountStatus::Active->value,
                'reason' => $reason ?? 'Operator manual unblock to resume strategy paper evaluation',
                'evidence' => [
                    'exchange_id' => $account->exchange_id,
                    'strategy_id' => $account->strategy_id,
                    'current_equity' => (string) $currentEquity,
                    'historical_max_drawdown' => (string) $account->maximum_drawdown_percent,
                ],
                'created_at' => $now,
            ]);

            return $account->fresh();
        });
    }
}
