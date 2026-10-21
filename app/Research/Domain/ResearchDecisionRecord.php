<?php

declare(strict_types=1);

namespace App\Research\Domain;

use App\Research\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PROFIT-1 / P-6 — append-only research decision record (§14.2).
 *
 * A transition is inserted; an existing transition is never updated and never
 * deleted. The model enforces this at the application level (the database
 * constraint is added by the migration).
 */
final class ResearchDecisionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_decision_records';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new AppendOnlyRecordException('research_decision_records is append-only: updates are prohibited.');
        });

        self::deleting(static function (): void {
            throw new AppendOnlyRecordException('research_decision_records is append-only: deletes are prohibited.');
        });
    }

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime',
            'input_generation_ids' => 'array',
            'evidence' => 'array',
        ];
    }
}
