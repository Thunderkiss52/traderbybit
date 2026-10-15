<?php

declare(strict_types=1);

return [
    'risk_version' => env('PAPER_RISK_VERSION', '1.0.0'),
    'execution_version' => env('PAPER_EXECUTION_VERSION', '1.0.0'),
    'schema_version' => '1.0.0',
    'default_account' => ['name' => 'Research Portfolio', 'starting_balance' => '10000'],
    'fees' => ['taker_rate' => '0.00055', 'maker_rate' => '0.00020', 'default_liquidity' => 'taker'],
    'slippage_rate' => '0.0002',
    'tp1_fraction' => '0.50',
    'portfolio' => [
        'maximum_open_positions' => 3,
        'maximum_total_risk_percent' => '1.50',
        'maximum_model_leverage' => '2.00',
        'maximum_notional_percent' => '200',
    ],
    'risk_by_quality' => ['A' => '0.50', 'B' => '0.25', 'C' => '0.10'],
    'correlation' => ['window' => 100, 'threshold' => '0.80', 'insufficient_data_policy' => 'reject'],
    'drawdown' => ['block_new_entries_percent' => '5', 'halt_percent' => '10', 'risk_closure_percent' => '15'],
    'losing_streak' => ['count' => 3, 'cooldown_minutes' => 60],
    'market_safety' => [
        'maximum_context_age_seconds' => 300, 'maximum_spread_percent' => '0.001', 'minimum_turnover_24h' => '50000000',
        'minimum_atr_percent' => '0.001', 'maximum_atr_percent' => '0.05', 'require_indicator_snapshot' => true,
    ],
    'processing' => ['lock_seconds' => 180, 'candle_interval' => '5', 'batch_size' => 500],
];
