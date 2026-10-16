<?php

declare(strict_types=1);

return [
    'queue' => ['name' => 'research', 'max_concurrent' => 1, 'memory_mb' => 512, 'timeout_seconds' => 3600],
    'automatic' => [
        'enabled' => (bool) env('RESEARCH_AUTO_BOOTSTRAP', true),
        'evaluation_days' => (int) env('RESEARCH_AUTO_EVALUATION_DAYS', 14),
        'minimum_pattern_decisions' => (int) env('RESEARCH_AUTO_PATTERN_MINIMUM_DECISIONS', 700),
        'pattern_retry_new_decisions' => (int) env('RESEARCH_AUTO_PATTERN_RETRY_NEW_DECISIONS', 120),
    ],
    'optimization' => [
        'maximum_combinations' => 100,
        'grids' => [
            'baseline' => [],
            'trend_pullback' => ['_research_parameters.adx_min' => ['18', '20', '24'], '_research_parameters.pullback_atr' => ['0.35', '0.50'], '_research_parameters.stop_atr' => ['1.5', '2.0']],
            'bollinger_mean_reversion' => ['_research_parameters.adx_max' => ['16', '18'], '_research_parameters.rsi_buy_max' => ['30', '35'], '_research_parameters.stop_atr' => ['1.25', '1.5']],
            'donchian_atr_breakout' => ['_research_parameters.period' => [15, 20, 30], '_research_parameters.relative_volume_min' => ['1.1', '1.2'], '_research_parameters.stop_atr' => ['1.5', '2.0']],
            'regime_switching' => ['_research_parameters.trend_adx_min' => ['18', '20'], '_research_parameters.range_adx_max' => ['16', '18'], '_research_parameters.high_volatility_atr_percent' => ['0.04', '0.05']],
        ],
    ],
    'strategies' => [
        'trend_pullback' => ['version' => '1.0.0', 'adx_min' => '20', 'pullback_atr' => '0.50', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 240],
        'bollinger_mean_reversion' => ['version' => '1.0.0', 'adx_max' => '18', 'rsi_buy_max' => '35', 'rsi_sell_min' => '65', 'target_r' => '1.5', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.0', 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 180],
        'donchian_atr_breakout' => ['version' => '1.0.0', 'period' => 20, 'relative_volume_min' => '1.20', 'stop_atr' => '2.0', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'trailing_stop' => false, 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 360],
        'regime_switching' => ['version' => '1.0.0', 'trend_adx_min' => '20', 'range_adx_max' => '18', 'trend_ema_separation_min' => '0.002', 'high_volatility_atr_percent' => '0.05'],
    ],
    'context_filter' => ['funding_extreme' => '0.001', 'open_interest_change_extreme' => '0.10'],
    'ranking' => ['profit_factor_min' => '1.3', 'max_drawdown_percent' => '10', 'minimum_trades' => 100],
];
