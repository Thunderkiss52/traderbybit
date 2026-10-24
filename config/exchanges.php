<?php

declare(strict_types=1);

return [
    'enabled' => ['bybit', 'binance'],
    'default' => 'bybit',
    'binance_paper_provisioning_approved' => env('VECTOR_BINANCE_PAPER_PROVISIONING_APPROVED', false),
    'binance' => [
        'base_url' => 'https://fapi.binance.com', // Public USD-M futures market data ONLY.
        'timeout_seconds' => 8,
        // Public 5m OI observations older than this may not drive a signal.
        'open_interest_max_lag_seconds' => 900,
        'max_candles_per_request' => 500,
        // Opt-in only after isolated DB verification, real funded PAPER accounts and documented costs.
        'paper_execution_approved' => (bool) env('VECTOR_BINANCE_PAPER_EXECUTION_APPROVED', false),
        'paper_taker_rate' => env('VECTOR_BINANCE_PAPER_TAKER_RATE'), // Approved, versioned exchange fee evidence required.
        'paper_slippage_rate' => env('VECTOR_BINANCE_PAPER_SLIPPAGE_RATE'), // Exchange-specific, not the Bybit assumption.
        'research_cycle_enabled' => (bool) env('VECTOR_BINANCE_RESEARCH_CYCLE_ENABLED', false),
    ],
    // No live credentials or order-placement endpoint belongs in this configuration.
];
