<?php

declare(strict_types=1);

namespace App\Research\Domain;

use App\Research\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A0-A3 — append-only research record: a promotion control event (A2).
 *
 * A control event is a kill switch activation, a kill switch release or a
 * rollback of a paper challenger. The record is append-only: the history of
 * the control is never rewritten.
 */
final class ResearchPromotionControlRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'research_promotion_controls';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new AppendOnlyRecordException('research_promotion_controls is append-only: updates are prohibited.');
        });

        self::deleting(static function (): void {
            throw new AppendOnlyRecordException('research_promotion_controls is append-only: deletes are prohibited.');
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }
}
