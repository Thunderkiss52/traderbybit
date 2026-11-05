<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Initial virtual allocation only; existing financial history is never topped up or reset. */
final readonly class AccountAuthorization
{
    public function __construct(private GlobalPaperCapitalGuard $capital, private PaperExecutionEligibilityService $entry, private PaperGovernance $governance) {}

    public function fund(int $definitionId, string $accountId, string $amount, string $reference): PaperAccountRecord
    {
        abort_unless(preg_match('/^[0-9]{1,9}(?:\\.[0-9]{1,12})?$/D', $amount) && BigDecimal::of($amount)->isPositive(), 422, 'A positive virtual capital amount is required.');

        return DB::transaction(function () use ($definitionId, $accountId, $amount, $reference): PaperAccountRecord {
            $this->capital->lockEntryDecision();
            $account = $this->account($definitionId, $accountId);
            $event = DB::table('paper_capital_events')->where('paper_account_id', $accountId)->where('approval_reference', $reference)->first();
            if ($event !== null) {
                abort_unless(BigDecimal::of($event->amount)->isEqualTo($amount), 409, 'Approval reference belongs to a different allocation.');

                return $account;
            }
            abort_unless($account->status === 'BLOCKED', 409, 'Initial allocation requires a BLOCKED account.');
            foreach (['starting_balance', 'current_equity', 'peak_equity', 'realized_pnl', 'unrealized_pnl', 'reserved_risk', 'maximum_drawdown_percent', 'current_drawdown_percent'] as $field) {
                abort_unless(BigDecimal::of($account->{$field})->isZero(), 409, 'Only a fresh zero-capital account can receive initial allocation.');
            }
            abort_if(DB::table('virtual_positions')->where('paper_account_id', $accountId)->exists() || DB::table('paper_capital_events')->where('paper_account_id', $accountId)->exists(), 409, 'Trading or allocation history cannot be reset.');
            $eventId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:strategy-capital:'.$accountId.':'.$reference)->toString();
            $at = now('UTC');
            DB::table('paper_capital_events')->insert(['id' => $eventId, 'paper_account_id' => $accountId, 'exchange_id' => $account->exchange_id, 'amount' => $amount, 'approval_reference' => $reference, 'reason' => 'OWNER_APPROVED_STRATEGY_ALLOCATION', 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
            $account->update(['starting_balance' => $amount, 'current_equity' => $amount, 'peak_equity' => $amount]);
            DB::table('equity_snapshots')->insert(['paper_account_id' => $accountId, 'exchange_id' => $account->exchange_id, 'recorded_at' => $at, 'equity' => $amount, 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0', 'valued_through' => $at, 'valuation_fresh' => true, 'source_event_key' => 'owner-strategy-capital:'.$eventId, 'created_at' => $at, 'updated_at' => $at]);
            $this->audit($definitionId, 'OWNER_INITIAL_PAPER_CAPITAL', ['account_id' => $accountId, 'exchange' => $account->exchange_id, 'amount' => $amount, 'reference' => $reference, 'risk_budget_expansion' => false]);

            return $account->refresh();
        }, 3);
    }

    public function authorize(int $definitionId, string $accountId, string $reference): PaperAccountRecord
    {
        return DB::transaction(function () use ($definitionId, $accountId, $reference): PaperAccountRecord {
            $this->capital->lockEntryDecision();
            $account = $this->account($definitionId, $accountId);
            if ($account->status === 'ACTIVE') {
                return $account;
            }
            abort_unless($account->status === 'BLOCKED' && BigDecimal::of($account->starting_balance)->isPositive() && BigDecimal::of($account->current_equity)->isPositive(), 409, 'A separately funded, fresh BLOCKED account is required.');
            abort_if(DB::table('virtual_positions')->where('paper_account_id', $accountId)->exists() || ! BigDecimal::of($account->maximum_drawdown_percent)->isZero() || ! BigDecimal::of($account->current_drawdown_percent)->isZero(), 409, 'Existing risk-blocked trading accounts cannot be reactivated through initial authorization.');
            abort_unless($this->governance->eligibility($definitionId)['paper_eligible'], 409, 'PAPER strategy eligibility is incomplete.');
            $prospective = clone $account;
            $prospective->status = 'ACTIVE';
            $reason = $this->entry->accountEntryRejectionReason($prospective);
            abort_unless($reason === null, 409, 'PAPER risk/exchange authorization blocked: '.($reason ?? 'unknown'));
            $account->update(['status' => 'ACTIVE']);
            $this->audit($definitionId, 'OWNER_INITIAL_ACCOUNT_AUTHORIZATION', ['account_id' => $accountId, 'exchange' => $account->exchange_id, 'reference' => $reference, 'risk_guards' => 'UNCHANGED']);

            return $account->refresh();
        }, 3);
    }

    private function account(int $definitionId, string $accountId): PaperAccountRecord
    {
        $definition = DB::table('strategy_definitions')->find($definitionId);
        abort_if($definition === null, 404);
        $account = PaperAccountRecord::query()->where('id', $accountId)->lockForUpdate()->first();
        abort_if($account === null, 404);
        abort_unless(str_starts_with($account->strategy_id, 'user_') && $account->is_primary && $account->research_experiment_id === null && $account->primary_strategy_id === $definition->strategy_id && $account->strategy_id === $definition->strategy_id && $account->strategy_version === (string) $definition->version && DB::table('strategy_definition_approvals')->where('definition_id', $definitionId)->exists(), 403, 'Allocation/authorization is restricted to this approved custom strategy account.');

        return $account;
    }

    private function audit(int $id, string $action, array $details): void
    {
        DB::table('strategy_workflow_events')->insert(['definition_id' => $id, 'action' => $action, 'actor' => 'configured-research-owner', 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
