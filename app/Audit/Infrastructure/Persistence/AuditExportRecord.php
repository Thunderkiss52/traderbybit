<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AuditExportRecord extends Model
{
    use HasUuids;

    protected $table = 'audit_export_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_days' => 'integer', 'progress_percent' => 'integer',
            'row_counts' => 'array', 'archive_bytes' => 'integer',
            'from_at' => 'immutable_datetime', 'to_at' => 'immutable_datetime',
            'as_of' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
