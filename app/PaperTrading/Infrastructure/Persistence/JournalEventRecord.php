<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class JournalEventRecord extends Model
{
    protected $table = 'trading_journal_events';

    protected $primaryKey = 'sequence';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Trading journal is append-only.'));
        self::deleting(fn () => throw new \LogicException('Trading journal is append-only.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'payload' => 'array'];
    }
}
