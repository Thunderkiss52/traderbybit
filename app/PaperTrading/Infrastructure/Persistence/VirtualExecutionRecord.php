<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VirtualExecutionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'virtual_executions';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Virtual executions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Virtual executions are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'market_event_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'ambiguous_execution' => 'boolean',
            'quantity' => 'string', 'price' => 'string', 'gross_pnl' => 'string', 'commission' => 'string', 'slippage' => 'string',
        ];
    }
}
