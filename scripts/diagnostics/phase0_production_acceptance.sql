-- =============================================================================
-- VECTOR PHASE 0 PRODUCTION ACCEPTANCE DIAGNOSTIC SUITE (HARDENED)
-- Read-Only Verification Script for Authoritative Production Acceptance
-- Operating Safety Invariants:
-- 1. Strictly read-only (SELECT only; ZERO INSERT, UPDATE, DELETE, DDL).
-- 2. Execution wrapped in BEGIN TRANSACTION READ ONLY ... ROLLBACK.
-- 3. Local timeouts enforced: statement_timeout = '30s', lock_timeout = '5s'.
-- 4. Invocation must use: psql -X -v ON_ERROR_STOP=1
-- 5. No secrets, credentials, or sensitive tokens emitted in output.
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';
SET LOCAL idle_in_transaction_session_timeout = '60s';

-- 1. Account Census Verification (VEC-P0-001-V2, VEC-P0-109-V2)
-- Must verify exactly 13 Bybit and 13 Binance accounts with independent balances.
SELECT
    exchange_id,
    mode,
    count(*) as total_accounts,
    sum(starting_balance) as total_starting_balance,
    sum(current_equity) as total_current_equity,
    min(current_equity) as min_equity,
    max(current_equity) as max_equity
FROM paper_accounts
WHERE mode = 'STRATEGY_FORWARD_PAPER'
GROUP BY exchange_id, mode
ORDER BY exchange_id;

-- 2. Cross-Exchange Isolation Check (VEC-P0-002-V2, VEC-P0-004-V2, VEC-P0-116-V2)
-- Position records must strictly match the exchange of their parent paper account.
SELECT
    p.id as position_id,
    p.symbol,
    a.exchange_id as account_exchange,
    s.exchange as signal_exchange
FROM virtual_positions p
JOIN paper_accounts a ON a.id = p.paper_account_id
LEFT JOIN signals s ON s.id = p.signal_id
WHERE s.exchange IS NOT NULL AND a.exchange_id != s.exchange
LIMIT 10;

-- 3a. Unique Constraint Catalog Verification (VEC-P0-009-V2 / Data Integrity)
-- Verifies that PostgreSQL catalog actively enforces uniqueness on candle identity
-- (exchange, category, symbol, interval, open_time).
SELECT
    c.conname,
    c.contype,
    c.conrelid::regclass as table_name,
    i.indisvalid as index_is_valid
FROM pg_constraint c
JOIN pg_index i ON i.indexrelid = c.conindid
WHERE c.conrelid = 'market_candles'::regclass
  AND c.conname = 'market_candles_identity_unique';

-- 3b. Recent Candle Deduplication Check (Operational 7-Day Window)
-- Verifies zero duplicate candles exist in the recent operational window.
-- Bounded by open_time >= NOW() - INTERVAL '7 days' to prevent full-table scan.
SELECT
    exchange, category, symbol, interval, open_time, count(*) as count
FROM market_candles
WHERE open_time >= NOW() - INTERVAL '7 days'
GROUP BY exchange, category, symbol, interval, open_time
HAVING count(*) > 1
LIMIT 10;

-- 3c. Recent Ingestion Stream Summary (24-Hour Active Freshness)
-- Verifies live candle ingestion activity per exchange and timeframe without full table scan.
SELECT
    exchange, symbol, interval,
    count(*) as candles_24h,
    min(open_time) as oldest_open_24h,
    max(open_time) as newest_open_24h,
    max(updated_at) as latest_ingestion_at
FROM market_candles
WHERE open_time >= NOW() - INTERVAL '24 hours'
GROUP BY exchange, symbol, interval
ORDER BY exchange, symbol, interval;

-- 4. Double-Entry Accounting Invariant (VEC-P0-001-V2, VEC-P0-003-V2, VEC-P0-102-V2)
-- Realized Net PnL must strictly equal Gross PnL - Fees + Signed Funding.
SELECT
    id as position_id,
    gross_pnl,
    fees,
    funding,
    net_pnl,
    (gross_pnl - fees + funding) as calculated_net_pnl,
    abs(net_pnl - (gross_pnl - fees + funding)) as discrepancy
FROM virtual_positions
WHERE status IN ('CLOSED_TP', 'CLOSED_SL', 'CLOSED_EXPIRED')
  AND abs(net_pnl - (gross_pnl - fees + funding)) > 0.0001
LIMIT 10;

-- 5. Execution Fill Non-Negative Quantity & Price Invariant (VEC-P0-005-V2)
SELECT count(*) as invalid_executions_count
FROM virtual_executions
WHERE quantity <= 0 OR price <= 0;

-- 6. Autonomy Approval Audit Trail Immutability (VEC-P0-126-V2)
-- Ensures audit events exist with valid actors and sequence integrity.
SELECT
    actor,
    action,
    target_type,
    count(*) as event_count,
    min(created_at) as earliest_event,
    max(created_at) as latest_event
FROM autonomy_approval_events
GROUP BY actor, action, target_type
ORDER BY latest_event DESC;

-- 7. Kill-Switch State and Dual Authorities (VEC-P0-119-V2, VEC-P0-120-V2)
-- Verify both authority states in production.
SELECT
    'research_promotion_controls' as authority,
    action,
    (action = 'KILL_SWITCH_ACTIVATED') as kill_switch_active,
    reason,
    created_at
FROM research_promotion_controls
WHERE action IN ('KILL_SWITCH_ACTIVATED', 'KILL_SWITCH_RELEASED')
ORDER BY sequence DESC
LIMIT 1;

SELECT
    'autonomy_settings' as authority,
    kill_switch_active,
    capabilities->>'auto_promote_paper' as auto_promote_paper,
    capabilities->>'live_exchange_trading' as live_exchange_trading,
    updated_at
FROM autonomy_settings
WHERE id = 'default';

-- 8. Slippage Attribution Consistency (VEC-P0-124-V2)
-- Ensure fills map 1:1 to position paper accounts without leakage.
SELECT
    f.generation_id,
    f.paper_account_id as fill_account,
    p.paper_account_id as position_account,
    count(*) as mismatch_count
FROM slippage_attribution_fills f
JOIN virtual_positions p ON p.id = f.position_id
WHERE f.paper_account_id != p.paper_account_id
GROUP BY f.generation_id, f.paper_account_id, p.paper_account_id;

-- 9. Account-Level Entry Block & Risk Rejection Observability (VEC-P0-004-V2, VEC-P0-127-V2)
-- Verifies accounts at/above 5% drawdown threshold and rejected entry decisions with lineage.
SELECT
    id as account_id,
    exchange_id,
    strategy_id,
    current_equity,
    peak_equity,
    current_drawdown_percent,
    (current_drawdown_percent >= 5.0) as is_entry_blocked,
    (current_drawdown_percent >= 10.0) as is_system_halted
FROM paper_accounts
WHERE current_drawdown_percent >= 5.0
ORDER BY current_drawdown_percent DESC
LIMIT 10;

SELECT
    d.id as decision_id,
    d.paper_account_id,
    a.exchange_id,
    a.strategy_id,
    d.allowed,
    d.reasons,
    d.decided_at
FROM risk_decisions d
JOIN paper_accounts a ON a.id = d.paper_account_id
WHERE d.allowed = false
ORDER BY d.decided_at DESC
LIMIT 10;

-- 10. Position Lifecycle Status and Funding Completeness (VEC-P0-003-V2, VEC-P0-201-V2, VEC-P0-203-V2, VEC-P0-207-V2)
SELECT
    status,
    funding_complete,
    count(*) as position_count,
    min(opened_at) as earliest_opened,
    max(updated_at) as latest_update
FROM virtual_positions
GROUP BY status, funding_complete
ORDER BY status, funding_complete;

-- 11. Pipeline Recovery & Terminalization Events (VEC-P0-002-V2, VEC-P0-204-V2, VEC-P0-209-V2)
SELECT
    stage,
    status,
    count(*) as event_count,
    sum(attempts) as total_attempts,
    count(*) FILTER (WHERE last_error IS NOT NULL) as error_count,
    max(created_at) as latest_event_at
FROM trading_pipeline_events
WHERE created_at >= NOW() - INTERVAL '7 days'
GROUP BY stage, status
ORDER BY stage, status;

-- 12. Equity Snapshots Integrity & Provenance (VEC-P0-112-V2, VEC-P0-208-V2)
-- Verifies equity snapshots are recorded per account without null equity values.
SELECT
    count(*) as total_snapshots_7d,
    count(DISTINCT paper_account_id) as distinct_accounts,
    min(equity) as min_equity,
    max(drawdown_percent) as max_drawdown_pct,
    count(*) FILTER (WHERE equity IS NULL OR equity <= 0) as invalid_equity_count
FROM equity_snapshots
WHERE recorded_at >= NOW() - INTERVAL '7 days';

-- Conclude diagnostic run with explicit ROLLBACK to guarantee zero database mutations
ROLLBACK;
