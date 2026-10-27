<?php

declare(strict_types=1);

namespace App\Trading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_key
 * @property string $exchange
 * @property string $symbol
 * @property string $timeframe
 * @property string $stage
 * @property Carbon $candle_close_at
 * @property string $status
 * @property array|null $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TradingPipelineEventRecord extends Model
{
    public const STAGE_CANDLE_CLOSED = 'CANDLE_CLOSED';

    public const STAGE_SIGNALS_EVALUATED = 'SIGNALS_EVALUATED';

    public const STAGE_PAPER_PROCESSED = 'PAPER_PROCESSED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DISPATCHED = 'DISPATCHED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_STALE = 'STALE';

    public const STATUS_FAILED = 'FAILED';

    protected $table = 'trading_pipeline_events';

    protected $fillable = [
        'event_key',
        'exchange',
        'symbol',
        'timeframe',
        'stage',
        'candle_close_at',
        'status',
        'payload',
        'attempts',
        'last_error',
        'dispatched_at',
        'completed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'candle_close_at' => 'immutable_datetime',
        'payload' => 'array',
        'attempts' => 'integer',
        'dispatched_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
