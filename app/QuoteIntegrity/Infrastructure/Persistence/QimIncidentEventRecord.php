<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QimIncidentEventRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_incident_events';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'immutable_datetime',
        'evidence_references' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(QimIncidentRecord::class, 'incident_id');
    }
}
