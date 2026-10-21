<?php

declare(strict_types=1);

namespace App\Research\Domain;

use App\Research\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A0-A3 — append-only research record: a registered research candidate hypothesis (A0).
 */
final class ResearchCandidateHypothesisRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_candidate_hypotheses';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new AppendOnlyRecordException('research_candidate_hypotheses is append-only: updates are prohibited.');
        });

        self::deleting(static function (): void {
            throw new AppendOnlyRecordException('research_candidate_hypotheses is append-only: deletes are prohibited.');
        });
    }

    protected function casts(): array
    {
        return [
            'preregistered_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }
}
