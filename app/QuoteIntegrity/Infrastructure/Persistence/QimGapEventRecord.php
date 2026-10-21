<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class QimGapEventRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_gap_events';

    protected $guarded = [];

    protected $casts = [
        'gap_from' => 'immutable_datetime',
        'gap_to' => 'immutable_datetime',
        'detected_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'remaining_missing_count' => 'integer',
        'metadata' => 'array',
    ];
}
