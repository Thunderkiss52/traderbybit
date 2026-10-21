<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class QimObservationRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_observations';

    protected $guarded = [];

    protected $casts = [
        'candle_open_time' => 'immutable_datetime',
        'candle_close_time' => 'immutable_datetime',
        'request_sent_at' => 'immutable_datetime',
        'response_received_at' => 'immutable_datetime',
        'provider_event_time' => 'immutable_datetime',
        'local_received_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'is_closed' => 'boolean',
        'revision' => 'integer',
        'clock_offset_estimate_ms' => 'integer',
    ];
}
