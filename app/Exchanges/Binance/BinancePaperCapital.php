<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Explicit owner-defined virtual capital — never deposits real money. */
final class BinancePaperCapital
{
    public function allocate(string $amount, string $approvalReference): array
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,12})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo('0')) {
            throw new DomainException('Owner-approved positive paper capital amount required.');
        }
        if (! preg_match('/^[a-zA-Z0-9._:-]{8,160}$/D', $approvalReference)) {
            throw new DomainException('Unique owner approval reference required (8-160 safe characters).');
        }
        if (config('exchanges.binance_paper_provisioning_approved') !== true) {
            throw new DomainException('Binance paper provisioning must be explicitly authorized.');
        }

        return DB::transaction(static function () use ($amount, $approvalReference): array {
            $strategies = (array) config('paper.primary_strategies', []);
            $accounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)
                ->lockForUpdate()->get()->keyBy('primary_strategy_id');
            if (count($strategies) !== 13 || $accounts->count() !== 13) {
                throw new DomainException('Exactly 13 canonical Binance PRIMARY paper accounts are required.');
            }
            $allocated = 0;
            $existing = 0;
            foreach ($strategies as $strategyId) {
                $account = $accounts->get($strategyId);
                if ($account === null) {
                    throw new DomainException('Binance primary paper account is missing.');
                }
                BinancePaperSafety::assertProvenance($account);
                $event = DB::table('paper_capital_events')->where('paper_account_id', $account->id)
                    ->where('approval_reference', $approvalReference)->first();
                if ($event !== null) {
                    if (! BigDecimal::of($event->amount)->isEqualTo(BigDecimal::of($amount))) {
                        throw new DomainException('Owner approval reference reused with a different paper amount.');
                    }
                    $existing++;

                    continue;
                }
                if ($account->status !== 'BLOCKED') {
                    throw new DomainException('Binance paper allocation requires a blocked canonical account.');
                }
                // Never top up a previously traded account or alter a Bybit account.
                if (DB::table('virtual_positions')->where('paper_account_id', $account->id)->exists()
                    || DB::table('virtual_executions')->whereIn('position_id', DB::table('virtual_positions')->select('id')
                        ->where('paper_account_id', $account->id))->exists()
                    || ! BigDecimal::of($account->starting_balance)->isZero()
                    || ! BigDecimal::of($account->current_equity)->isZero()
                    || ! BigDecimal::of($account->realized_pnl)->isZero()
                    || ! BigDecimal::of($account->reserved_risk)->isZero()) {
                    throw new DomainException('Only fresh, zero-balance Binance accounts may receive initial paper capital.');
                }
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:binance:paper-allocation:'.$account->id.':'.$approvalReference)->toString();
                $now = now('UTC');
                DB::table('paper_capital_events')->insert([
                    'id' => $id, 'paper_account_id' => $account->id, 'exchange_id' => 'binance',
                    'amount' => $amount, 'approval_reference' => $approvalReference,
                    'reason' => 'OWNER_APPROVED_PAPER_ALLOCATION', 'occurred_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                // The original account:initial snapshot remains zero and immutable.
                $account->update(['starting_balance' => $amount, 'current_equity' => $amount, 'peak_equity' => $amount]);
                DB::table('equity_snapshots')->insertOrIgnore([
                    'paper_account_id' => $account->id, 'exchange_id' => 'binance',
                    'recorded_at' => $now, 'equity' => $amount, 'realized_pnl' => '0',
                    'unrealized_pnl' => '0', 'drawdown_percent' => '0',
                    'source_event_key' => 'owner-paper-capital:'.$id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $allocated++;
            }

            return ['exchange_id' => 'binance', 'mode' => 'PAPER_ONLY', 'accounts_allocated' => $allocated,
                'idempotent_repeats' => $existing, 'amount_per_account' => $amount,
                'status' => $allocated > 0 ? 'CAPITAL_ALLOCATED_ACCOUNTS_REMAIN_BLOCKED' : 'CAPITAL_ALREADY_ALLOCATED'];
        });
    }
}
