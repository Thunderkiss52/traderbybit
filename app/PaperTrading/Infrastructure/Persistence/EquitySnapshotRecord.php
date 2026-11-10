<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

final class EquitySnapshotRecord extends Model
{
    protected $table = 'equity_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'equity' => 'string', 'realized_pnl' => 'string', 'unrealized_pnl' => 'string', 'drawdown_percent' => 'string'];
    }

    /** Raw history remains available; only proven pre-capital bootstrap is excluded. */
    public function scopePerformance(Builder $query): Builder
    {
        return $query->whereRaw(self::performancePredicate('equity_snapshots'));
    }

    public function scopeOpeningCapital(Builder $query): Builder
    {
        return $query->where(function (Builder $opening): void {
            $opening->where('source_event_key', 'like', 'account:initial%')
                ->orWhereExists(function ($capital): void {
                    $capital->selectRaw('1')->from('paper_capital_events as pc')
                        ->whereColumn('pc.paper_account_id', 'equity_snapshots.paper_account_id')
                        ->whereColumn('pc.exchange_id', 'equity_snapshots.exchange_id')
                        ->whereColumn('pc.amount', 'equity_snapshots.equity')
                        ->whereColumn('pc.occurred_at', 'equity_snapshots.recorded_at')
                        ->where('pc.reason', 'OWNER_APPROVED_PAPER_ALLOCATION')
                        ->where('pc.amount', '>', 0)
                        ->whereRaw("equity_snapshots.source_event_key = 'owner-paper-capital:' || pc.id::text");
                });
        });
    }

    public static function performancePredicate(string $alias): string
    {
        if (! in_array($alias, ['equity_snapshots', 'es'], true)) {
            throw new \InvalidArgumentException('Unsupported snapshot alias.');
        }

        return "NOT ({$alias}.exchange_id = 'binance' AND {$alias}.equity = 0"
            ." AND {$alias}.realized_pnl = 0 AND {$alias}.unrealized_pnl = 0"
            .' AND EXISTS (SELECT 1 FROM paper_capital_events pc'
            .' JOIN equity_snapshots funded ON funded.paper_account_id = pc.paper_account_id'
            ." AND funded.source_event_key = 'owner-paper-capital:' || pc.id::text"
            ." WHERE pc.paper_account_id = {$alias}.paper_account_id AND pc.exchange_id = 'binance'"
            ." AND pc.reason = 'OWNER_APPROVED_PAPER_ALLOCATION' AND pc.amount > 0"
            ." AND funded.equity = pc.amount AND funded.exchange_id = 'binance'"
            ." AND funded.recorded_at = pc.occurred_at AND {$alias}.recorded_at <= pc.occurred_at"
            ." AND {$alias}.id < funded.id))";
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaperAccountRecord::class, 'paper_account_id');
    }

    protected static function booted(): void
    {
        self::creating(function (EquitySnapshotRecord $snapshot): void {
            if (empty($snapshot->exchange_id)) {
                $account = PaperAccountRecord::query()->find($snapshot->paper_account_id);
                if ($account === null || empty($account->exchange_id)) {
                    throw new RuntimeException("Cannot persist EquitySnapshotRecord: paper account [{$snapshot->paper_account_id}] is missing exchange_id.");
                }
                $snapshot->exchange_id = $account->exchange_id;
            }
        });
    }
}
