<?php

declare(strict_types=1);

return [
    'version' => '1.2.0',
    'schema_version' => '1.0.0',
    'decision_timeframe' => '15',
    'history_limit' => 220,
    'timeframes' => [
        'trend' => '60',
        'setup' => '30',
        'entry' => '15',
        'confirmation' => '5',
    ],
    'freshness' => [
        'max_lag_seconds' => ['5' => 0, '15' => 0, '30' => 1799, '60' => 3599],
        'market_context_max_age_seconds' => 300,
        'require_market_context' => true,
    ],
    'trend' => [
        'adx_min' => '20',
        'adx_strong' => '30',
    ],
    'triggers' => [
        'ema_pullback' => [
            'lookback_bars' => 3,
            'atr_zone_multiplier' => '0.35',
            'rsi_midline' => '50',
        ],
        'macd_crossover' => [
            'buy_rsi_min' => '45',
            'buy_rsi_max' => '70',
            'sell_rsi_min' => '30',
            'sell_rsi_max' => '55',
            'relative_volume_min' => '1.10',
        ],
        'breakout' => [
            'relative_volume_min' => '1.50',
            'min_buffer_atr' => '0.10',
            'max_candle_range_atr' => '2.00',
        ],
    ],
    'confirmation' => [
        'rsi_midline' => '50',
    ],
    'level_interaction' => [
        'proximity_atr' => '0.15',
        'breakout_buffer_atr' => '0.05',
        'minimum_probability_percent' => 65,
    ],
    'liquidity' => [
        'minimum_turnover_24h' => '50000000',
        'maximum_spread_percent' => '0.001',
    ],
    'volatility' => [
        'minimum_atr_percent' => '0.001',
        'maximum_atr_percent' => '0.05',
    ],
    'volume' => [
        'minimum_relative_volume' => '0.50',
        'minimum_average_turnover' => '100000',
    ],
    'structure' => [
        'minimum_level_space_r' => '2.50',
    ],
    'context' => [
        'extreme_funding_absolute' => '0.001',
        'extreme_open_interest_change_absolute' => '0.10',
    ],
    'price_model' => [
        'entry_zone_atr' => '0.10',
        'stop_atr' => '1.50',
        'tp1_r' => '1.50',
        'tp2_r' => '2.50',
    ],
    'scoring' => [
        'weights' => [
            'trend_alignment' => 15,
            'setup_30m' => 10,
            'confirmation_5m' => 15,
            'first_trigger' => 15,
            'additional_trigger' => 10,
            'additional_trigger_max' => 20,
            'strong_adx' => 10,
            'relative_volume' => 10,
            'level_space' => 5,
            'momentum' => 5,
        ],
        'classes' => [
            'A' => 80,
            'B' => 60,
            'C' => 40,
        ],
    ],
    'model_risk_percent' => [
        'A' => '0.50',
        'B' => '0.25',
        'C' => '0.10',
    ],
    'signal_expiration_minutes' => 30,
    'maximum_holding_minutes' => 240,
    'lock_seconds' => 120,
];
