<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',
    'schema_version' => '1.0.0',
    'starting_balance' => env('BACKTEST_STARTING_BALANCE', '10000'),
    'split' => ['train' => 60, 'validation' => 20, 'test' => 20],
    'warmup_bars' => 220,
    'boundary_policy' => 'close_at_last_available_bar',
    'funding_expected_interval_hours' => 8,
    'returns' => ['interval_minutes' => 5, 'periods_per_year' => 105120, 'risk_free_rate' => '0'],
    'walk_forward' => ['train_months' => 6, 'validation_months' => 2, 'test_months' => 2, 'step_months' => 2],
    'optimization' => [
        'enabled' => true,
        'max_combinations' => 32,
        'minimum_trades' => 5,
        'drawdown_penalty' => '0.50',
        'stability_penalty' => '0.25',
        'grid' => [
            'trend.adx_min' => ['18', '20', '25'],
            'triggers.ema_pullback.rsi_midline' => ['48', '52'],
            'triggers.breakout.relative_volume_min' => ['1.25', '1.50'],
            'price_model.stop_atr' => ['1.0', '1.5'],
            'structure.minimum_level_space_r' => ['2.5'],
        ],
    ],
    'stress' => [
        'fee_multipliers' => ['2', '3'],
        'slippage_multipliers' => ['2', '3'],
        'exclude_ambiguous' => true,
    ],
    'regimes' => ['lookback_days' => 30, 'trend_return_threshold' => '0.05'],
    'queue' => ['timeout' => 3600, 'tries' => 2, 'lock_seconds' => 3700],
];
