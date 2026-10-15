<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PositionFundingEventRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'position_funding_events';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Applied funding events are immutable.'));
        self::deleting(fn () => throw new \LogicException('Applied funding events are immutable.'));
    }

    protected function casts(): array
    {
        return ['funding_at' => 'immutable_datetime', 'quantity' => 'string', 'mark_price' => 'string', 'rate' => 'string', 'amount' => 'string'];
    }
}
