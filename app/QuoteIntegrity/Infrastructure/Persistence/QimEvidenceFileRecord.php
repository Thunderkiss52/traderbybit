<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class QimEvidenceFileRecord extends Model
{
    protected $table = 'qim_evidence_files';

    protected $guarded = [];

    protected $casts = [
        'uploaded_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'missing_metadata' => 'array',
        'file_size_bytes' => 'integer',
    ];
}
