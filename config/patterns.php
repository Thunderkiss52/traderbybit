<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',
    'queue' => 'research',
    'maximum_decisions' => 800,
    'maximum_patterns' => 80,
    'maximum_terms' => 2,
    'minimum_train' => 20,
    'minimum_validation' => 10,
    'minimum_test' => 10,
    'minimum_out_of_sample_expectancy_r' => '0',
    'confidence_level_z' => 1.96,
    'multiple_testing_alpha' => 0.05,
    'walk_forward_folds' => 3,
    'holding_bars_5m' => 48,
    'stop_atr' => '1',
    'target_atr' => '1.5',
    'taker_fee_rate' => '0.00055',
    'slippage_rate' => '0.0002',
    'allowed_symbols' => ['BTCUSDT', 'ETHUSDT'],
    'candlestick' => [
        'doji_body_ratio_max' => '0.10',
        'spinning_body_ratio_max' => '0.30',
        'marubozu_body_ratio_min' => '0.90',
        'long_wick_body_multiple' => '2.0',
        'equal_price_tolerance_ratio' => '0.05',
        'star_outer_body_ratio_min' => '0.50',
        'star_middle_body_ratio_max' => '0.30',
    ],
    'market_structure' => [
        'pivot_span' => 2,
        'range_lookback' => 12,
        'retest_lookback' => 6,
        'level_tolerance_range_multiple' => '0.50',
        'minimum_swing_range_multiple' => '1.00',
        'breakout_buffer_range_multiple' => '0.10',
        'compression_width_range_multiple' => '4.00',
        'rejection_wick_body_multiple' => '1.50',
    ],
    'occurrence_outcomes' => [
        'horizon_bars' => 12,
        'minimum_directional_return' => '0.001',
    ],
];
