-- =============================================================================
-- VECTOR PHASE 0 PRODUCTION ACCEPTANCE DIAGNOSTIC QUERIES
-- Read-Only Verification Script for Authoritative Production Acceptance
-- Operating Rules:
-- 1. Strictly read-only (SELECT only; ZERO INSERT, UPDATE, DELETE).
-- 2. Covers all 26 independent PAPER accounts (13 Bybit + 13 Binance).
-- 3. Verifies schema integrity, double-entry accounting, and absence of cross-exchange leaks.
-- =============================================================================

-- 1. Account Census Verification (VEC-P0-001, VEC-P0-002, VEC-P0-109)
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

-- 2. Cross-Exchange Isolation Check (VEC-P0-002, VEC-P0-004, VEC-P0-116)
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

-- 3. Candle Ingestion Deduplication and Immutability (VEC-P0-009, VEC-P0-010)
-- Verifies no duplicate candles exist for (exchange, category, symbol, interval, open_time).
SELECT 
    exchange, category, symbol, interval, open_time, count(*) as count
FROM market_candles
GROUP BY exchange, category, symbol, interval, open_time
HAVING count(*) > 1
LIMIT 10;

-- 4. Double-Entry Accounting Invariant (VEC-P0-001, VEC-P0-102)
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
WHERE status = 'CLOSED_TP' OR status = 'CLOSED_SL' OR status = 'CLOSED_EXPIRED'
  AND abs(net_pnl - (gross_pnl - fees + funding)) > 0.0001
LIMIT 10;

-- 5. Execution Fill Non-Negative Quantity & Price Invariant (VEC-P0-005)
SELECT count(*) as invalid_executions_count
FROM virtual_executions
WHERE quantity <= 0 OR price <= 0;

-- 6. Autonomy Approval Audit Trail Immutability (VEC-P0-126)
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

-- 7. Kill-Switch State and Dual Authorities (VEC-P0-119, VEC-P0-120)
-- Verify both authority states in production.
SELECT 
    'research_promotion_controls' as authority,
    action,
    kill_switch_active,
    reason,
    created_at
FROM research_promotion_controls
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

-- 8. Slippage Attribution Consistency (VEC-P0-124)
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
