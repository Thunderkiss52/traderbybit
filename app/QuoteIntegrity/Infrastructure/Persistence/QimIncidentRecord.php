<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QimIncidentRecord extends Model
{
    protected $table = 'qim_incidents';

    protected $guarded = [];

    protected $casts = [
        'first_detected_at' => 'immutable_datetime',
        'last_detected_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'occurrence_count' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(QimIncidentEventRecord::class, 'incident_id')->orderBy('id');
    }
}
