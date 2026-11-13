<?php

declare(strict_types=1);

return [
    'signal_intelligence' => [
        // Observational jobs only; labels never authorize entries or promotion.
        'enabled' => (bool) env('RESEARCH_SIGNAL_INTELLIGENCE_ENABLED', true),
        'code_revision' => env('RESEARCH_SIGNAL_INTELLIGENCE_CODE_REVISION'),
        'horizons_minutes' => [15, 60],
        'reference_notional' => '5',
        'max_signals_per_cycle' => 8,
        'quality_policy' => [
            'version' => 'SIGNAL_QUALITY_HOEFFDING_V1',
            'min_independent_samples' => 100,
            'min_distinct_days' => 20,
            'alpha' => 0.05,
            'multiple_tests' => 100,
            'embargo_ms' => 60000,
            'return_lower_bound' => '-1',
            'return_upper_bound' => '1',
            'neutral_band' => '0.0001',
        ],
    ],
    'queue' => ['name' => 'research', 'max_concurrent' => 1, 'memory_mb' => 512, 'timeout_seconds' => 3600],
    // Legacy SKIP counterfactual analysis is quarantined. Replaying rejected
    // signals into simulated entries/targets is P-3 behaviour and is prohibited;
    // existing rows stay readable but must never be extended or mutated.
    'counterfactual' => [
        // Hard-disabled in source. An environment variable alone must never
        // reactivate the prohibited P-3 rejected-signal replay.
        'legacy_analysis_enabled' => false,
        'legacy_status' => 'LEGACY_READ_ONLY',
        'legacy_reason' => 'P3_REJECTED_SIGNAL_REPLAY_PROHIBITED',
    ],
    'forward_paper' => [
        'automatic_accounts' => (bool) env('RESEARCH_FORWARD_PAPER_AUTOMATIC_ACCOUNTS', true),
        'enabled_strategies' => ['trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout', 'regime_switching', 'momentum', 'support_resistance_bounce', 'support_resistance_breakout', 'funding_open_interest_squeeze', 'liquidity_sweep_reversal', 'volatility_compression_breakout', 'btc_eth_lead_lag', 'multi_timeframe_trend_following'],
        // These strategies publish research signals but are not yet eligible for
        // simulated fills until execution semantics are independently validated.
        'signal_only_strategies' => [],
    ],
    'automatic' => [
        'enabled' => (bool) env('RESEARCH_AUTO_BOOTSTRAP', true),
        'evaluation_days' => (int) env('RESEARCH_AUTO_EVALUATION_DAYS', 14),
        'minimum_pattern_decisions' => (int) env('RESEARCH_AUTO_PATTERN_MINIMUM_DECISIONS', 700),
        'pattern_retry_new_decisions' => (int) env('RESEARCH_AUTO_PATTERN_RETRY_NEW_DECISIONS', 120),
    ],
    'candidate_evaluation' => [
        'minimum_days' => (int) env('RESEARCH_CANDIDATE_EVALUATION_MINIMUM_DAYS', 30),
        'target_days' => (int) env('RESEARCH_CANDIDATE_EVALUATION_TARGET_DAYS', 180),
    ],
    'evolution' => [
        // Only explicitly bounded mutation spaces are eligible; all 13 PRIMARY families are covered.
        'families' => ['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout', 'regime_switching', 'momentum', 'support_resistance_bounce', 'support_resistance_breakout', 'funding_open_interest_squeeze', 'liquidity_sweep_reversal', 'volatility_compression_breakout', 'btc_eth_lead_lag', 'multi_timeframe_trend_following'],
        'validation_days' => (int) env('RESEARCH_EVOLUTION_VALIDATION_DAYS', 180),
        'validation_end_lag_hours' => (int) env('RESEARCH_EVOLUTION_END_LAG_HOURS', 24),
        'max_total_active' => (int) env('RESEARCH_EVOLUTION_MAX_TOTAL_ACTIVE', 8),
        'max_per_family' => (int) env('RESEARCH_EVOLUTION_MAX_PER_FAMILY', 2),
        'max_evaluations_per_cycle' => (int) env('RESEARCH_EVOLUTION_MAX_EVALUATIONS_PER_CYCLE', 2),
        'max_evaluation_attempts' => 3,
        'spaces' => [
            'baseline' => ['trend.adx_min' => ['step' => 2, 'min' => 16, 'max' => 26], 'price_model.stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5], 'triggers.breakout.relative_volume_min' => ['step' => 0.1, 'min' => 1.2, 'max' => 1.8], 'structure.minimum_level_space_r' => ['step' => 0.5, 'min' => 2.0, 'max' => 4.0]],
            'trend_pullback' => ['adx_min' => ['step' => 2, 'min' => 16, 'max' => 26], 'pullback_atr' => ['step' => 0.1, 'min' => 0.3, 'max' => 0.7], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5]],
            'bollinger_mean_reversion' => ['adx_max' => ['step' => 2, 'min' => 12, 'max' => 20], 'rsi_buy_max' => ['step' => 5, 'min' => 25, 'max' => 40], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.0]],
            'donchian_atr_breakout' => ['period' => ['step' => 5, 'min' => 10, 'max' => 40], 'relative_volume_min' => ['step' => 0.1, 'min' => 1.0, 'max' => 1.5], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5]],
            'regime_switching' => ['trend_adx_min' => ['step' => 2, 'min' => 16, 'max' => 26], 'range_adx_max' => ['step' => 2, 'min' => 12, 'max' => 20], 'high_volatility_atr_percent' => ['step' => 0.005, 'min' => 0.03, 'max' => 0.07]],
            'momentum' => ['minimum_score' => ['step' => 5, 'min' => 60, 'max' => 85], 'adx_min' => ['step' => 2, 'min' => 16, 'max' => 26], 'relative_volume_min' => ['step' => 0.1, 'min' => 0.9, 'max' => 1.4]],
            'support_resistance_bounce' => ['minimum_success_probability' => ['step' => 0.05, 'min' => 0.50, 'max' => 0.70], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.0], 'tp1_r' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.0]],
            'support_resistance_breakout' => ['minimum_success_probability' => ['step' => 0.05, 'min' => 0.50, 'max' => 0.70], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5], 'tp1_r' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5]],
            // New families are research-only until causal WFO, costs, attribution and owner gates pass.
            'funding_open_interest_squeeze' => ['price_momentum_lookback' => ['step' => 1, 'min' => 2, 'max' => 10], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5]],
            'liquidity_sweep_reversal' => ['level_lookback' => ['step' => 4, 'min' => 12, 'max' => 40], 'stop_atr' => ['step' => 0.25, 'min' => 1.0, 'max' => 2.5]],
            'volatility_compression_breakout' => ['compression_bars' => ['step' => 2, 'min' => 4, 'max' => 12], 'base_bars' => ['step' => 4, 'min' => 16, 'max' => 40], 'compression_rate' => ['step' => 0.05, 'min' => 0.60, 'max' => 0.95]],
            'btc_eth_lead_lag' => ['peer_lag_bars' => ['step' => 1, 'min' => 2, 'max' => 8], 'peer_move_threshold' => ['step' => 0.0005, 'min' => 0.001, 'max' => 0.004]],
            'multi_timeframe_trend_following' => ['entry_ema_fast' => ['step' => 2, 'min' => 5, 'max' => 13], 'entry_ema_slow' => ['step' => 2, 'min' => 17, 'max' => 35]],
        ],
    ],
    'optimization' => [
        'maximum_combinations' => 100,
        'grids' => [
            'baseline' => [],
            'trend_pullback' => ['_research_parameters.adx_min' => ['18', '20', '24'], '_research_parameters.pullback_atr' => ['0.35', '0.50'], '_research_parameters.stop_atr' => ['1.5', '2.0']],
            'bollinger_mean_reversion' => ['_research_parameters.adx_max' => ['16', '18'], '_research_parameters.rsi_buy_max' => ['30', '35'], '_research_parameters.stop_atr' => ['1.25', '1.5']],
            'donchian_atr_breakout' => ['_research_parameters.period' => [15, 20, 30], '_research_parameters.relative_volume_min' => ['1.1', '1.2'], '_research_parameters.stop_atr' => ['1.5', '2.0']],
            'regime_switching' => ['_research_parameters.trend_adx_min' => ['18', '20'], '_research_parameters.range_adx_max' => ['16', '18'], '_research_parameters.high_volatility_atr_percent' => ['0.04', '0.05']],
            'momentum' => ['_research_parameters.minimum_score' => [65, 70, 75], '_research_parameters.adx_min' => ['18', '20'], '_research_parameters.relative_volume_min' => ['1.0', '1.2']],
        ],
    ],
    'strategies' => [
        'trend_pullback' => ['version' => '1.3.0', 'confirmation_mode' => 'WEIGHTED', 'minimum_score' => 70, 'adx_min' => '20', 'pullback_atr' => '0.50', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 240],
        'bollinger_mean_reversion' => ['version' => '1.3.0', 'confirmation_mode' => 'WEIGHTED', 'minimum_score' => 70, 'adx_max' => '18', 'rsi_buy_max' => '35', 'rsi_sell_min' => '65', 'target_r' => '1.5', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.0', 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 180],
        'donchian_atr_breakout' => ['version' => '1.3.0', 'confirmation_mode' => 'WEIGHTED', 'minimum_score' => 70, 'period' => 20, 'relative_volume_min' => '1.20', 'stop_atr' => '2.0', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'trailing_stop' => false, 'model_risk_percent' => '0.25', 'maximum_holding_minutes' => 360],
        'regime_switching' => ['version' => '1.3.0', 'trend_adx_min' => '20', 'range_adx_max' => '18', 'trend_ema_separation_min' => '0.002', 'high_volatility_atr_percent' => '0.05'],
        'momentum' => ['version' => '1.0.0', 'confirmation_mode' => 'WEIGHTED', 'minimum_score' => 70, 'rsi_buy_min' => '55', 'rsi_sell_max' => '45', 'relative_volume_min' => '1.0', 'adx_min' => '18', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'model_risk_percent' => '0.20', 'maximum_holding_minutes' => 240],
        'support_resistance_bounce' => ['version' => '1.0.0', 'minimum_success_probability' => '0.55', 'minimum_expected_r' => '0', 'modeled_cost_r' => '0.03', 'stop_atr' => '1.25', 'tp1_r' => '1.5', 'tp2_r' => '2.0', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 240],
        'support_resistance_breakout' => ['version' => '1.0.0', 'minimum_success_probability' => '0.55', 'minimum_expected_r' => '0', 'modeled_cost_r' => '0.03', 'stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.5', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 360],
        'funding_open_interest_squeeze' => ['version' => '1.0.0', 'min_bars' => 60, 'funding_rate_threshold' => '0.0001', 'open_interest_min_change' => '0', 'price_momentum_lookback' => 4, 'price_momentum_threshold' => '0.001', 'mode' => 'CROWDING_REVERSAL', 'stop_atr' => '1.5', 'tp1_r' => '3.0', 'tp2_r' => '3.0', 'entry_zone_atr' => '0.10', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 240],
        'liquidity_sweep_reversal' => ['version' => '1.0.0', 'min_bars' => 60, 'level_lookback' => 20, 'min_level_margin' => '0', 'stop_atr' => '1.5', 'tp1_r' => '3.0', 'tp2_r' => '3.0', 'entry_zone_atr' => '0.10', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 240],
        'volatility_compression_breakout' => ['version' => '1.0.0', 'min_bars' => 60, 'compression_bars' => 6, 'base_bars' => 20, 'compression_rate' => '0.8', 'breakout_lookback' => 20, 'stop_atr' => '1.5', 'tp1_r' => '3.0', 'tp2_r' => '3.0', 'entry_zone_atr' => '0.10', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 360],
        'btc_eth_lead_lag' => ['version' => '1.0.0', 'min_bars' => 60, 'peer_lag_bars' => 4, 'local_lag_bars' => 4, 'peer_move_threshold' => '0.002', 'local_move_threshold' => '0.0005', 'max_peer_staleness_bars' => 2, 'stop_atr' => '1.5', 'tp1_r' => '3.0', 'tp2_r' => '3.0', 'entry_zone_atr' => '0.10', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 180],
        'multi_timeframe_trend_following' => ['version' => '1.0.0', 'min_bars' => 60, 'entry_mode' => 'EMA_CROSS', 'entry_ema_fast' => 9, 'entry_ema_slow' => 21, 'breakout_lookback' => 20, 'stop_atr' => '1.5', 'tp1_r' => '3.0', 'tp2_r' => '3.0', 'entry_zone_atr' => '0.10', 'model_risk_percent' => '0.10', 'maximum_holding_minutes' => 240],
    ],
    'context_filter' => ['funding_extreme' => '0.001', 'open_interest_change_extreme' => '0.10'],
    'ranking' => ['profit_factor_min' => '1.3', 'max_drawdown_percent' => '10', 'minimum_trades' => 100],
    'autonomy' => [
        // Paper-only autonomy is OFF by default. It can only be enabled in an
        // isolated local environment; it never places a real order and it never
        // touches the eight primary paper accounts.
        'paper_promotion_enabled' => (bool) env('RESEARCH_AUTONOMY_PAPER_PROMOTION_ENABLED', false),
        'challenger_execution_enabled' => (bool) env('RESEARCH_AUTONOMY_CHALLENGER_EXECUTION_ENABLED', true),
        // Releasing the paper-only kill switch requires an explicit
        // authorisation; the default keeps it engaged.
        'kill_switch_release_enabled' => (bool) env('RESEARCH_AUTONOMY_KILL_SWITCH_RELEASE_ENABLED', false),
        'familywise_experiment_count' => 5,
        'gates' => [
            'min_valid_utc_days' => 20,
            'min_ess' => 20,
            'min_train_observations' => 300,
            'min_oos_observations' => 100,
            'min_shadow_observations' => 100,
            'min_shadow_days' => 14,
            'min_shadow_trades' => 50,
            'min_net_improvement_lcb' => '0',
            'max_drawdown_degradation_pct' => '0',
            'max_concentration_degradation' => '0',
        ],
        'allocation' => [
            // Correlation-aware budgeting: the strategies whose pairwise
            // correlation reaches the threshold form a cluster and the total
            // risk of a cluster is capped. The proposal is research-only.
            'correlation_cluster_threshold' => 0.8,
            'max_cluster_share' => 0.6,
        ],
    ],
];
