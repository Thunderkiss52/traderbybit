<?php

declare(strict_types=1);

namespace App\Research\Domain;

use App\Research\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A0-A3 — append-only research record: a challenger experiment state transition (A1).
 */
final class ResearchChallengerExperimentRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_challenger_experiments';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new AppendOnlyRecordException('research_challenger_experiments is append-only: updates are prohibited.');
        });

        self::deleting(static function (): void {
            throw new AppendOnlyRecordException('research_challenger_experiments is append-only: deletes are prohibited.');
        });
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }
}
