<?php

declare(strict_types=1);

namespace App\Exchanges\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExchangeStrategyCompatibilityRecord extends Model
{
    protected $table = 'exchange_strategy_compatibilities';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'research_eligible' => 'boolean',
            'shadow_eligible' => 'boolean',
            'paper_eligible' => 'boolean',
            'market_type_compatible' => 'boolean',
            'instruments_compatible' => 'boolean',
            'timeframes_compatible' => 'boolean',
            'history_compatible' => 'boolean',
            'features_compatible' => 'boolean',
            'execution_model_compatible' => 'boolean',
            'diagnostics' => 'array',
            'capability_snapshot' => 'array',
            'dataset_coverage' => 'array',
            'evaluated_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
            'created_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
            'updated_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
        ];
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(ExchangeRecord::class, 'exchange_id', 'id');
    }
}
