<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SignalRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'signals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candle_id' => 'integer',
            'decision_time' => 'immutable_datetime', 'candle_close_time' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'reasons' => 'array', 'risks' => 'array', 'indicator_snapshot' => 'array', 'contract' => 'array',
            'entry_low' => 'string', 'entry_high' => 'string', 'stop_loss' => 'string', 'take_profit_1' => 'string', 'take_profit_2' => 'string',
            'risk_reward_1' => 'string', 'risk_reward_2' => 'string', 'model_risk_percent' => 'string',
        ];
    }

    public function candle(): BelongsTo
    {
        return $this->belongsTo(CandleRecord::class, 'candle_id');
    }
}
