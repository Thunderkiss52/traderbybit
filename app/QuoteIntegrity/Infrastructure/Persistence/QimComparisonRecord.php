<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QimComparisonRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_comparisons';

    protected $guarded = [];

    protected $casts = [
        'candle_open_time' => 'immutable_datetime',
        'candle_close_time' => 'immutable_datetime',
        'compared_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'is_comparable' => 'boolean',
        'version' => 'integer',
        'timestamp_skew_ms' => 'integer',
    ];

    public function restObservation(): BelongsTo
    {
        return $this->belongsTo(QimObservationRecord::class, 'rest_observation_id');
    }

    public function wsObservation(): BelongsTo
    {
        return $this->belongsTo(QimObservationRecord::class, 'ws_observation_id');
    }
}
