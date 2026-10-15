<?php

declare(strict_types=1);

return [
    'internal_scale' => 24,
    'output_scale' => 8,
    'ema' => [
        'entry_period' => 20,
        'fast_period' => 50,
        'slow_period' => 200,
    ],
    'sma_period' => 200,
    'rsi_period' => 14,
    'macd' => [
        'fast_period' => 12,
        'slow_period' => 26,
        'signal_period' => 9,
    ],
    'atr_period' => 14,
    'bollinger' => [
        'period' => 20,
        'standard_deviations' => '2',
    ],
    'adx_period' => 14,
    'vwap_period' => 20,
    'volume_period' => 20,
    'support_resistance' => [
        'lookback' => 50,
        'left_bars' => 2,
        'right_bars' => 2,
        'cluster_tolerance' => '0.002',
    ],
];
