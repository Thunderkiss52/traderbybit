<?php

declare(strict_types=1);

return [
    // Shared app_storage volume: pending evidence blocks only its affected candle window.
    'conflict_journal_path' => storage_path('app/candle-integrity'),
    'exchange' => 'bybit',
    'category' => 'linear',
    'symbols' => ['BTCUSDT', 'ETHUSDT'],
    'intervals' => ['5', '15', '30', '60'],
    'rest' => [
        'base_url' => env('BYBIT_REST_URL', 'https://api.bybit.com'),
        'timeout_seconds' => (int) env('BYBIT_HTTP_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('BYBIT_HTTP_CONNECT_TIMEOUT', 3),
        'max_retries' => (int) env('BYBIT_HTTP_MAX_RETRIES', 4),
        'backoff_base_ms' => (int) env('BYBIT_BACKOFF_BASE_MS', 250),
        'backoff_max_ms' => (int) env('BYBIT_BACKOFF_MAX_MS', 5000),
        'page_size' => 1000,
    ],
    'websocket' => [
        'url' => env('BYBIT_WS_URL', 'wss://stream.bybit.com/v5/public/linear'),
        'heartbeat_seconds' => (int) env('BYBIT_WS_HEARTBEAT_SECONDS', 10),
        'receive_timeout_seconds' => (int) env('BYBIT_WS_RECEIVE_TIMEOUT', 1),
        'reconnect_max_seconds' => (int) env('BYBIT_WS_RECONNECT_MAX_SECONDS', 30),
        'recovery_lookback_minutes' => (int) env('BYBIT_WS_RECOVERY_LOOKBACK_MINUTES', 180),
    ],
    'sync' => [
        'backfill_passes' => (int) env('MARKET_DATA_BACKFILL_PASSES', 2),
        'lock_seconds' => (int) env('MARKET_DATA_LOCK_SECONDS', 900),
        'freshness_grace_multiplier' => (int) env('MARKET_DATA_FRESHNESS_MULTIPLIER', 3),
        'initial_history_days' => (int) env('MARKET_DATA_INITIAL_HISTORY_DAYS', 30),
    ],
    'funding' => [
        'initial_lookback_days' => (int) env('MARKET_FUNDING_LOOKBACK_DAYS', 30),
    ],
    'funding_schedules' => [
        'bybit' => [
            'BTCUSDT' => ['interval_hours' => 8, 'offset_hours' => 0],
            'ETHUSDT' => ['interval_hours' => 8, 'offset_hours' => 0],
        ],
        'binance' => [
            'BTCUSDT' => ['interval_hours' => 8, 'offset_hours' => 0],
            'ETHUSDT' => ['interval_hours' => 8, 'offset_hours' => 0],
        ],
    ],
    'open_interest' => [
        'interval' => env('MARKET_OPEN_INTEREST_INTERVAL', '15min'),
        'maximum_pages' => (int) env('MARKET_OPEN_INTEREST_MAXIMUM_PAGES', 3),
    ],
];
