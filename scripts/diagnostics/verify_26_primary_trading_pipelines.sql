-- ==============================================================================
-- VECTOR V2.6: 26 PRIMARY Strategy Trading Pipeline Verification Script
-- Mode: STRICTLY READ-ONLY (SELECT only, transaction_read_only = on)
-- Target: PostgreSQL 16+ (bybit_signals production / bybit_test local)
-- Scope: All 13 Bybit + 13 Binance PRIMARY Accounts
-- Horizons: 24 Hours and 7 Days
-- Safety: Safe for production execution on VPS. Zero mutations or locks.
-- ==============================================================================

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';

-- ------------------------------------------------------------------------------
-- 1. CENSUS & ACCOUNT ELIGIBILITY STATE (All 26 PRIMARY Accounts)
-- ------------------------------------------------------------------------------
SELECT 
    pa.exchange_id,
    pa.primary_strategy_id AS strategy_id,
    pa.name,
    pa.status,
    ROUND(pa.starting_balance::numeric, 2) AS starting_balance,
    ROUND(pa.current_equity::numeric, 2) AS current_equity,
    ROUND(pa.realized_pnl::numeric, 2) AS realized_pnl,
    ROUND(pa.unrealized_pnl::numeric, 2) AS unrealized_pnl,
    ROUND(pa.current_drawdown_percent::numeric, 4) AS current_dd_pct,
    ROUND(pa.maximum_drawdown_percent::numeric, 4) AS max_dd_pct,
    CASE 
        WHEN pa.status != 'ACTIVE' THEN 'BLOCKED_STATUS'
        WHEN pa.current_drawdown_percent >= 5.0 THEN 'BLOCKED_DRAWDOWN'
        WHEN pa.cooldown_until IS NOT NULL AND pa.cooldown_until > NOW() THEN 'BLOCKED_COOLDOWN'
        WHEN pa.exchange_id = 'binance' AND pa.current_equity <= 0 THEN 'BLOCKED_UNFUNDED'
        ELSE 'ELIGIBLE'
    END AS entry_eligibility,
    pa.id AS account_id
FROM paper_accounts pa
WHERE pa.is_primary = TRUE
ORDER BY pa.exchange_id, pa.primary_strategy_id;

-- ------------------------------------------------------------------------------
-- 2. 24-HOUR STRATEGY DECISION FUNNEL (Actual Persisted vs. Theoretical Capacity)
-- ------------------------------------------------------------------------------
-- Theoretical capacity: 96 15-minute bars per symbol per day * 2 symbols = 192
SELECT 
    s.exchange,
    s.strategy_id,
    COUNT(*) AS actual_persisted_evaluations_24h,
    192 AS theoretical_capacity_24h,
    ROUND((COUNT(*)::numeric / 192 * 100), 1) AS pipeline_coverage_pct,
    COUNT(*) FILTER (WHERE s.side = 'BUY') AS buy_decisions_24h,
    COUNT(*) FILTER (WHERE s.side = 'SELL') AS sell_decisions_24h,
    COUNT(*) FILTER (WHERE s.side = 'SKIP') AS skip_decisions_24h,
    ROUND((COUNT(*) FILTER (WHERE s.side = 'SKIP')::numeric / NULLIF(COUNT(*), 0) * 100), 2) AS skip_rate_pct
FROM signals s
WHERE s.decision_time >= NOW() - INTERVAL '24 hours'
GROUP BY s.exchange, s.strategy_id
ORDER BY s.exchange, s.strategy_id;

-- ------------------------------------------------------------------------------
-- 3. 7-DAY STRATEGY DECISION FUNNEL (Actual Persisted vs. Theoretical Capacity)
-- ------------------------------------------------------------------------------
-- Theoretical capacity: 672 15-minute bars per symbol per week * 2 symbols = 1344
SELECT 
    s.exchange,
    s.strategy_id,
    COUNT(*) AS actual_persisted_evaluations_7d,
    1344 AS theoretical_capacity_7d,
    ROUND((COUNT(*)::numeric / 1344 * 100), 1) AS pipeline_coverage_pct,
    COUNT(*) FILTER (WHERE s.side = 'BUY') AS buy_decisions_7d,
    COUNT(*) FILTER (WHERE s.side = 'SELL') AS sell_decisions_7d,
    COUNT(*) FILTER (WHERE s.side = 'SKIP') AS skip_decisions_7d,
    ROUND((COUNT(*) FILTER (WHERE s.side = 'SKIP')::numeric / NULLIF(COUNT(*), 0) * 100), 2) AS skip_rate_pct
FROM signals s
WHERE s.decision_time >= NOW() - INTERVAL '7 days'
GROUP BY s.exchange, s.strategy_id
ORDER BY s.exchange, s.strategy_id;

-- ------------------------------------------------------------------------------
-- 4. 24-HOUR TOP SKIP REASONS PER STRATEGY
-- ------------------------------------------------------------------------------
WITH expanded_skips AS (
    SELECT 
        s.exchange,
        s.strategy_id,
        jsonb_array_elements_text(CASE WHEN jsonb_typeof(s.reasons::jsonb) = 'array' THEN s.reasons::jsonb ELSE '[]'::jsonb END) AS reason
    FROM signals s
    WHERE s.side = 'SKIP'
      AND s.decision_time >= NOW() - INTERVAL '24 hours'
)
SELECT 
    exchange,
    strategy_id,
    reason,
    COUNT(*) AS occurrences_24h
FROM expanded_skips
GROUP BY exchange, strategy_id, reason
ORDER BY exchange, strategy_id, occurrences_24h DESC;

-- ------------------------------------------------------------------------------
-- 5. 24-HOUR PRE-RISK BLOCKS AND REASONS
-- ------------------------------------------------------------------------------
SELECT 
    pa.exchange_id,
    pa.primary_strategy_id AS strategy_id,
    tje.payload->>'reason' AS block_reason,
    COUNT(*) AS blocked_count_24h
FROM trading_journal_events tje
JOIN paper_accounts pa ON pa.id = tje.paper_account_id
WHERE tje.event_type = 'PRE_RISK_ENTRY_BLOCKED'
  AND tje.occurred_at >= NOW() - INTERVAL '24 hours'
GROUP BY pa.exchange_id, pa.primary_strategy_id, tje.payload->>'reason'
ORDER BY pa.exchange_id, pa.primary_strategy_id, blocked_count_24h DESC;

-- ------------------------------------------------------------------------------
-- 6. 24-HOUR & 7-DAY RISK ENGINE EVALUATION AND APPROVALS
-- ------------------------------------------------------------------------------
SELECT 
    pa.exchange_id,
    pa.primary_strategy_id AS strategy_id,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '24 hours') AS risk_evaluated_24h,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '24 hours' AND rd.allowed = TRUE) AS risk_approved_24h,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '24 hours' AND rd.allowed = FALSE) AS risk_rejected_24h,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '7 days') AS risk_evaluated_7d,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '7 days' AND rd.allowed = TRUE) AS risk_approved_7d,
    COUNT(*) FILTER (WHERE rd.decided_at >= NOW() - INTERVAL '7 days' AND rd.allowed = FALSE) AS risk_rejected_7d
FROM paper_accounts pa
LEFT JOIN risk_decisions rd ON rd.paper_account_id = pa.id
WHERE pa.is_primary = TRUE
GROUP BY pa.exchange_id, pa.primary_strategy_id
ORDER BY pa.exchange_id, pa.primary_strategy_id;

-- ------------------------------------------------------------------------------
-- 7. 24-HOUR & 7-DAY ENTRY EXECUTIONS PER ACCOUNT
-- ------------------------------------------------------------------------------
SELECT 
    pa.exchange_id,
    pa.primary_strategy_id AS strategy_id,
    COUNT(*) FILTER (WHERE ve.type = 'ENTRY' AND ve.market_event_at >= NOW() - INTERVAL '24 hours') AS entry_executions_24h,
    COUNT(*) FILTER (WHERE ve.type = 'ENTRY' AND ve.market_event_at >= NOW() - INTERVAL '7 days') AS entry_executions_7d,
    COUNT(*) FILTER (WHERE ve.type != 'ENTRY' AND ve.market_event_at >= NOW() - INTERVAL '24 hours') AS exit_executions_24h,
    COUNT(*) FILTER (WHERE ve.type != 'ENTRY' AND ve.market_event_at >= NOW() - INTERVAL '7 days') AS exit_executions_7d
FROM paper_accounts pa
LEFT JOIN virtual_positions vp ON vp.paper_account_id = pa.id
LEFT JOIN virtual_executions ve ON ve.position_id = vp.id
WHERE pa.is_primary = TRUE
GROUP BY pa.exchange_id, pa.primary_strategy_id
ORDER BY pa.exchange_id, pa.primary_strategy_id;

-- ------------------------------------------------------------------------------
-- 8. POSITION LIFECYCLE SUMMARY (Open, Closed, Net PnL)
-- ------------------------------------------------------------------------------
SELECT 
    pa.exchange_id,
    pa.primary_strategy_id AS strategy_id,
    COUNT(*) FILTER (WHERE vp.status IN ('OPEN', 'PARTIALLY_CLOSED')) AS active_positions,
    COUNT(*) FILTER (WHERE vp.status = 'CLOSED_TP') AS closed_tp_count,
    COUNT(*) FILTER (WHERE vp.status = 'CLOSED_SL') AS closed_sl_count,
    COUNT(*) FILTER (WHERE vp.status = 'CLOSED_TIMEOUT') AS closed_timeout_count,
    COUNT(vp.id) AS total_historical_positions,
    COALESCE(ROUND(SUM(vp.net_pnl::numeric), 2), 0.00) AS total_position_net_pnl
FROM paper_accounts pa
LEFT JOIN virtual_positions vp ON vp.paper_account_id = pa.id
WHERE pa.is_primary = TRUE
GROUP BY pa.exchange_id, pa.primary_strategy_id
ORDER BY pa.exchange_id, pa.primary_strategy_id;

-- ------------------------------------------------------------------------------
-- 9. LEVEL INTERACTION FORECAST MATURITY (S/R Bounce & Breakout Gating)
-- ------------------------------------------------------------------------------
SELECT 
    lif.exchange,
    lif.symbol,
    lif.event,
    COUNT(*) AS total_forecasts,
    COUNT(*) FILTER (WHERE lif.outcome IS NOT NULL) AS matured_observations,
    COUNT(*) FILTER (WHERE lif.outcome IS NULL) AS pending_maturation,
    CASE 
        WHEN COUNT(*) FILTER (WHERE lif.outcome IS NOT NULL) >= 30 THEN 'GATING_PASSED_ACTIVE'
        ELSE 'INSUFFICIENT_DATA_GATED'
    END AS statistical_gating_status
FROM level_interaction_forecasts lif
GROUP BY lif.exchange, lif.symbol, lif.event
ORDER BY lif.exchange, lif.symbol, lif.event;

-- ------------------------------------------------------------------------------
-- 10. MARKET DATA INGESTION & PIPELINE HEALTH (Last 24 Hours)
-- ------------------------------------------------------------------------------
SELECT 
    c.exchange,
    c.symbol,
    c.interval,
    COUNT(*) AS closed_candles_24h,
    MAX(c.open_time) AS latest_closed_open_time,
    ROUND(EXTRACT(EPOCH FROM (NOW() - MAX(c.open_time))) / 60, 1) AS minutes_since_latest_candle
FROM market_candles c
WHERE c.is_closed = TRUE
  AND c.open_time >= NOW() - INTERVAL '24 hours'
GROUP BY c.exchange, c.symbol, c.interval
ORDER BY c.exchange, c.symbol, c.interval;

-- ------------------------------------------------------------------------------
-- 11. TRADING PIPELINE EVENT STAGE & STATUS BREAKDOWN (Last 24 Hours)
-- ------------------------------------------------------------------------------
SELECT 
    tpe.exchange,
    tpe.stage,
    tpe.status,
    COUNT(*) AS event_count_24h
FROM trading_pipeline_events tpe
WHERE tpe.candle_close_at >= NOW() - INTERVAL '24 hours'
GROUP BY tpe.exchange, tpe.stage, tpe.status
ORDER BY tpe.exchange, tpe.stage, tpe.status;

-- ------------------------------------------------------------------------------
-- 12. HISTORICAL BINANCE EXECUTIONS BREAKDOWN (Confirming 60 Positions & Accounts)
-- ------------------------------------------------------------------------------
SELECT 
    pa.primary_strategy_id AS strategy_id,
    pa.status AS account_status,
    COUNT(DISTINCT vp.id) AS binance_positions_count,
    COUNT(DISTINCT ve.id) AS binance_entry_executions,
    COALESCE(ROUND(SUM(vp.net_pnl::numeric), 2), 0.00) AS total_net_pnl,
    MIN(vp.opened_at) AS earliest_position_opened_at,
    MAX(vp.opened_at) AS latest_position_opened_at
FROM paper_accounts pa
LEFT JOIN virtual_positions vp ON vp.paper_account_id = pa.id
LEFT JOIN virtual_executions ve ON ve.position_id = vp.id AND ve.type = 'ENTRY'
WHERE pa.exchange_id = 'binance' AND pa.is_primary = TRUE
GROUP BY pa.primary_strategy_id, pa.status
ORDER BY pa.primary_strategy_id;

ROLLBACK;
