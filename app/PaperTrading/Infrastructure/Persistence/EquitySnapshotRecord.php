<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

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
