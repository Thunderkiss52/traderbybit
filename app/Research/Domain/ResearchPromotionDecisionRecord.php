<?php

declare(strict_types=1);

namespace App\Research\Domain;

use App\Research\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A0-A3 — append-only research record: a paper-only promotion decision (A2).
 */
final class ResearchPromotionDecisionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_promotion_decisions';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new AppendOnlyRecordException('research_promotion_decisions is append-only: updates are prohibited.');
        });

        self::deleting(static function (): void {
            throw new AppendOnlyRecordException('research_promotion_decisions is append-only: deletes are prohibited.');
        });
    }

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
            'failed_gates' => 'array',
            'evidence' => 'array',
            'approval_content' => 'array',
            'rollback_triggers' => 'array',
            'feature_flag_enabled' => 'boolean',
            'applied' => 'boolean',
            'kill_switch_active' => 'boolean',
            'reversible' => 'boolean',
            'rollback_applied' => 'boolean',
        ];
    }
}
