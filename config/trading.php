<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Trading Pipeline Execution Mode
    |--------------------------------------------------------------------------
    | 'EVENT_DRIVEN': Low-latency event-driven pipeline triggered by closed candles.
    | 'SCHEDULED': Safe fallback rollback mode using cron polling.
    */
    'pipeline_mode' => env('TRADING_PIPELINE_MODE', 'EVENT_DRIVEN'),

    /*
    |--------------------------------------------------------------------------
    | Isolated Queue Topology
    |--------------------------------------------------------------------------
    | Dedicated queue names ensuring Bybit and Binance have strictly isolated
    | execution workers and are never starved by slow REST ingestion or research.
    */
    'queues' => [
        'bybit' => env('QUEUE_TRADING_BYBIT', 'trading-bybit'),
        'binance' => env('QUEUE_TRADING_BINANCE', 'trading-binance'),
        'ingest' => env('QUEUE_MARKET_INGEST', 'market-ingest'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Latency and Freshness Guards
    |--------------------------------------------------------------------------
    */
    'freshness_seconds' => (int) env('TRADING_FRESHNESS_SECONDS', 300),
    'recovery_window_seconds' => (int) env('TRADING_RECOVERY_WINDOW_SECONDS', 900),
    'decision_timeframe' => '15',
    'execution_timeframe' => '5',
    'lock_timeout_seconds' => 60,
    'symbols' => ['BTCUSDT', 'ETHUSDT'],
];
