<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

final class DefinitionSchema
{
    public static function document(): array
    {
        return ['schema_version' => 1, 'id_prefix' => 'user_', 'timeframes' => ['5', '15', '30', '60'], 'indicators' => DefinitionValidator::TYPES,
            'required' => ['schema_version', 'id', 'version', 'symbols', 'exchanges', 'timeframe', 'indicators', 'entry', 'stop_atr', 'take_atr', 'risk_percent', 'timeout_bars', 'cooldown_bars'],
            'indicator_shape' => ['type' => 'indicator enum', 'period' => 'integer 2..500', 'timeframe' => 'timeframe enum'],
            'condition_shapes' => [['and' => ['condition']], ['or' => ['condition']], ['not' => 'condition'], ['op' => 'gt|lt|gte|lte|crosses_above|crosses_below|within|change_pct', 'left' => 'indicator name', 'right' => 'indicator name, JSON number, or two-number range']],
            'risk_percent_maximum' => 0.5, 'confirmation_required' => true];
    }
}
