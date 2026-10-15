<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',
    'queue' => 'research',
    'maximum_decisions' => 600,
    'maximum_patterns' => 80,
    'maximum_terms' => 2,
    'minimum_train' => 20,
    'minimum_validation' => 10,
    'minimum_test' => 10,
    'minimum_out_of_sample_expectancy_r' => '0',
    'confidence_level_z' => 1.96,
    'multiple_testing_alpha' => 0.05,
    'holding_bars_5m' => 48,
    'stop_atr' => '1',
    'target_atr' => '1.5',
    'taker_fee_rate' => '0.00055',
    'slippage_rate' => '0.0002',
    'allowed_symbols' => ['BTCUSDT', 'ETHUSDT'],
];
