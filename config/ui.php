<?php

declare(strict_types=1);

return [
    'backtest_token' => env('UI_BACKTEST_TOKEN'),
    'symbols' => ['BTCUSDT', 'ETHUSDT'],
    'max_points' => 1000,
    'slow_api_ms' => (int) env('UI_SLOW_API_MS', 500),
    'large_response_bytes' => (int) env('UI_LARGE_RESPONSE_BYTES', 100_000),
];
