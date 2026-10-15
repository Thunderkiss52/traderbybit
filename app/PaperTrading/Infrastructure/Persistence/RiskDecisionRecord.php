<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RiskDecisionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'risk_decisions';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Risk decisions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Risk decisions are immutable.'));
    }

    protected function casts(): array
    {
        return ['allowed' => 'boolean', 'reasons' => 'array', 'snapshot' => 'array', 'decided_at' => 'immutable_datetime'];
    }
}
