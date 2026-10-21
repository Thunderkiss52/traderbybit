<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class QimPurgeAuditRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_purge_audit';

    protected $guarded = [];

    protected $casts = [
        'purged_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'records_purged' => 'integer',
        'details' => 'array',
    ];
}
