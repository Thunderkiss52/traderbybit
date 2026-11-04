<?php

declare(strict_types=1);

return [
    'risk_version' => env('PAPER_RISK_VERSION', '1.0.0'),
    'execution_version' => env('PAPER_EXECUTION_VERSION', '1.0.0'),
    'schema_version' => '1.0.0',
    'fee_schedule_version' => env('PAPER_FEE_SCHEDULE_VERSION', '1.0.0'),
    'fee_provenance' => [
        'standard' => 'VIP0',
        'source' => 'Bybit VIP Benefits 2026-10-12 public schedule',
        'observed_execution_role' => 'TAKER_ONLY',
        'maker_execution_status' => 'NOT_EVALUATED_IN_PAPER_TRADING',
    ],
    'slippage_semantics' => [
        'stored_unit' => 'PRICE_DELTA_QUOTE_CURRENCY_PER_CONTRACT',
        'calculation_formula' => 'abs(fill_price - reference_price)',
        'usdt_drag_formula' => 'quantity * slippage',
        'double_deduction_prohibited' => true,
    ],
    'default_account' => ['name' => 'Research Portfolio', 'starting_balance' => '10000'],
    // Canonical, ordered list of the independent primary paper accounts.
    'primary_strategies' => [
        'baseline',
        'trend_pullback',
        'bollinger_mean_reversion',
        'donchian_atr_breakout',
        'regime_switching',
        'momentum',
        'support_resistance_bounce',
        'support_resistance_breakout',
        'funding_open_interest_squeeze',
        'liquidity_sweep_reversal',
        'volatility_compression_breakout',
        'btc_eth_lead_lag',
        'multi_timeframe_trend_following',
    ],
    // Historical owner-approved Bybit cohort. Future registered strategies are
    // automatically attached but default to BLOCKED pending a separate approval.
    'initially_approved_bybit_strategies' => [
        'baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout',
        'regime_switching', 'momentum', 'support_resistance_bounce',
        'support_resistance_breakout', 'funding_open_interest_squeeze',
        'liquidity_sweep_reversal', 'volatility_compression_breakout',
        'btc_eth_lead_lag', 'multi_timeframe_trend_following',
    ],
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
    // Aggregate funded PRIMARY virtual capital: 5% below SUM(account peak equity) stops NEW entries (conservative upper bound).
    // Not a synchronized portfolio HWM or a guaranteed maximum realized loss.
    'global_capital' => ['enabled' => true, 'maximum_loss_percent' => '5'],
    'topology' => ['auto_sync_enabled' => true],
    'losing_streak' => ['count' => 3, 'cooldown_minutes' => 60],
    'market_safety' => [
        'maximum_context_age_seconds' => 300, 'maximum_spread_percent' => '0.001', 'minimum_turnover_24h' => '50000000',
        'minimum_atr_percent' => '0.001', 'maximum_atr_percent' => '0.05', 'require_indicator_snapshot' => true,
        'maximum_absolute_funding_rate' => '0.001', 'maximum_absolute_open_interest_change' => '0.10',
    ],
    'processing' => ['lock_seconds' => 180, 'candle_interval' => '5', 'batch_size' => 500, 'max_forward_fill_lag_seconds' => 300, 'max_signal_ingest_lag_seconds' => 300],
];
