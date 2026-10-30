-- Operator-only, read-only production diagnostic. No DDL, DML or repairs.
-- Supply through psql -X -v ON_ERROR_STOP=1 with PGOPTIONS=default_transaction_read_only=on.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '60s';
SET LOCAL lock_timeout = '5s';

\echo === READ_ONLY_SESSION_AND_SCHEMA ===
SELECT current_database() AS database_name, current_user AS database_role,
       current_setting('transaction_read_only') AS transaction_read_only,
       transaction_timestamp() AS snapshot_at;

SELECT expected.migration, COUNT(m.migration) AS applied_count,
       to_regclass(expected.table_name) AS created_table
FROM (VALUES
    ('2026_10_29_220000_create_market_candle_conflicts_table', 'market_candle_conflicts'),
    ('2026_10_29_220100_create_market_candle_conflict_journal_table', 'market_candle_conflict_journal')
) AS expected(migration, table_name)
LEFT JOIN migrations m ON m.migration = expected.migration
GROUP BY expected.migration, expected.table_name
ORDER BY expected.migration;

SELECT table_name, COUNT(*) AS columns_present
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name IN ('paper_accounts', 'virtual_positions', 'virtual_executions',
                     'position_funding_events', 'paper_capital_events', 'equity_snapshots',
                     'market_candle_conflicts', 'market_candle_conflict_journal')
GROUP BY table_name ORDER BY table_name;

SELECT c.relname AS table_name, p.conname AS constraint_name, p.contype AS constraint_type
FROM pg_constraint p JOIN pg_class c ON c.oid = p.conrelid
WHERE c.relname IN ('market_candle_conflicts', 'market_candle_conflict_journal')
ORDER BY c.relname, p.conname;

SELECT tablename, indexname, indexdef FROM pg_indexes
WHERE schemaname = current_schema()
  AND tablename IN ('paper_accounts', 'paper_capital_events', 'equity_snapshots')
  AND indexdef LIKE '%UNIQUE%'
ORDER BY tablename, indexname;

SELECT exchange, state, COUNT(*) AS journal_entries
FROM market_candle_conflict_journal GROUP BY exchange, state ORDER BY exchange, state;
SELECT exchange, status, COUNT(*) AS conflict_scopes
FROM market_candle_conflicts GROUP BY exchange, status ORDER BY exchange, status;

\echo === SIX_FAILED_BYBIT_ACCOUNTS_AND_M9_DIFFERENCES ===

WITH affected(strategy_id) AS (
    VALUES ('support_resistance_bounce'), ('support_resistance_breakout'),
           ('funding_open_interest_squeeze'), ('liquidity_sweep_reversal'),
           ('volatility_compression_breakout'), ('btc_eth_lead_lag')
), position_totals AS (
    SELECT v.paper_account_id,
           COUNT(*) AS position_count,
           COUNT(*) FILTER (WHERE v.status IN ('OPEN', 'PARTIALLY_CLOSED')) AS active_count,
           COUNT(*) FILTER (WHERE v.status NOT IN ('OPEN', 'PARTIALLY_CLOSED') AND v.unrealized_pnl <> 0) AS closed_nonzero_count,
           COUNT(*) FILTER (WHERE v.exchange_id IS DISTINCT FROM 'bybit') AS wrong_exchange_count,
           COUNT(*) FILTER (WHERE v.net_pnl <> v.gross_pnl + v.funding - v.fees) AS position_net_mismatch_count,
           COALESCE(SUM(v.unrealized_pnl) FILTER (WHERE v.exchange_id = 'bybit'), 0) AS all_positions_unrealized,
           COALESCE(SUM(v.unrealized_pnl) FILTER (WHERE v.exchange_id = 'bybit' AND v.status IN ('OPEN', 'PARTIALLY_CLOSED')), 0) AS active_positions_unrealized,
           COALESCE(SUM(v.gross_pnl) FILTER (WHERE v.exchange_id = 'bybit'), 0) AS gross_pnl,
           COALESCE(SUM(v.fees) FILTER (WHERE v.exchange_id = 'bybit'), 0) AS fees,
           COALESCE(SUM(v.funding) FILTER (WHERE v.exchange_id = 'bybit'), 0) AS funding,
           MAX(v.last_processed_event_at) AS latest_position_event
    FROM virtual_positions v
    JOIN paper_accounts a ON a.id = v.paper_account_id
    JOIN affected t ON t.strategy_id = a.primary_strategy_id
    WHERE a.exchange_id = 'bybit' AND a.is_primary = true
    GROUP BY v.paper_account_id
), execution_totals AS (
    SELECT v.paper_account_id, COUNT(*) AS execution_count,
           COALESCE(SUM(e.commission), 0) AS execution_fees,
           COALESCE(SUM(e.gross_pnl) FILTER (WHERE e.type <> 'ENTRY'), 0) AS execution_gross,
           COUNT(*) FILTER (WHERE e.exchange_id IS DISTINCT FROM 'bybit') AS wrong_exchange_count
    FROM virtual_positions v JOIN paper_accounts a ON a.id = v.paper_account_id
    JOIN affected t ON t.strategy_id = a.primary_strategy_id
    JOIN virtual_executions e ON e.position_id = v.id
    WHERE a.exchange_id = 'bybit' AND a.is_primary = true
    GROUP BY v.paper_account_id
), funding_totals AS (
    SELECT v.paper_account_id, COUNT(*) AS settlement_count,
           COALESCE(SUM(f.amount), 0) AS settlement_amount,
           COUNT(*) FILTER (WHERE f.exchange_id IS DISTINCT FROM 'bybit'
               OR m.exchange IS DISTINCT FROM 'bybit' OR m.category IS DISTINCT FROM 'linear'
               OR m.symbol IS DISTINCT FROM v.symbol OR f.rate IS DISTINCT FROM m.rate
               OR f.mark_price IS DISTINCT FROM m.mark_price) AS source_or_terms_mismatch_count
    FROM virtual_positions v JOIN paper_accounts a ON a.id = v.paper_account_id
    JOIN affected t ON t.strategy_id = a.primary_strategy_id
    JOIN position_funding_events f ON f.position_id = v.id
    LEFT JOIN market_funding_events m ON m.id = f.market_funding_event_id
    WHERE a.exchange_id = 'bybit' AND a.is_primary = true
    GROUP BY v.paper_account_id
), snapshot_totals AS (
    SELECT s.paper_account_id, COUNT(*) AS snapshot_count,
           COUNT(*) FILTER (WHERE s.exchange_id IS DISTINCT FROM 'bybit') AS wrong_exchange_count
    FROM equity_snapshots s JOIN paper_accounts a ON a.id = s.paper_account_id
    JOIN affected t ON t.strategy_id = a.primary_strategy_id
    WHERE a.exchange_id = 'bybit' AND a.is_primary = true
    GROUP BY s.paper_account_id
)
SELECT a.id AS account_id, a.primary_strategy_id, a.status AS account_status,
       a.unrealized_pnl AS account_unrealized, a.current_equity,
       a.starting_balance + a.realized_pnl + a.unrealized_pnl AS account_formula_equity,
       COALESCE(p.active_positions_unrealized, 0) AS active_positions_unrealized,
       COALESCE(p.all_positions_unrealized, 0) AS all_positions_unrealized,
       a.unrealized_pnl - COALESCE(p.active_positions_unrealized, 0) AS active_difference,
       a.unrealized_pnl - COALESCE(p.all_positions_unrealized, 0) AS original_m9_difference,
       a.unrealized_pnl IS DISTINCT FROM COALESCE(p.active_positions_unrealized, 0) AS corrected_m9_fails,
       COALESCE(p.closed_nonzero_count, 0) > 0 AS closed_mark_fails,
       COALESCE(p.gross_pnl, 0) AS position_gross,
       COALESCE(p.fees, 0) AS position_fees,
       COALESCE(p.funding, 0) AS position_funding,
       a.realized_pnl - (COALESCE(p.gross_pnl, 0) + COALESCE(p.funding, 0) - COALESCE(p.fees, 0)) AS realized_difference,
       a.current_equity - (a.starting_balance + a.realized_pnl + a.unrealized_pnl) AS equity_difference,
       ABS(a.realized_pnl - (COALESCE(p.gross_pnl, 0) + COALESCE(p.funding, 0) - COALESCE(p.fees, 0))) > 0.000001 AS realized_m9_fails,
       ABS(a.current_equity - (a.starting_balance + a.realized_pnl + a.unrealized_pnl)) > 0.000001 AS equity_m9_fails,
       COALESCE(p.position_count, 0) AS position_count,
       COALESCE(p.active_count, 0) AS active_count,
       COALESCE(p.closed_nonzero_count, 0) AS closed_nonzero_count,
       COALESCE(p.wrong_exchange_count, 0) AS wrong_exchange_positions,
       COALESCE(p.position_net_mismatch_count, 0) AS position_net_mismatches,
       COALESCE(e.execution_count, 0) AS execution_count,
       COALESCE(e.execution_fees, 0) AS execution_fees,
       COALESCE(e.execution_gross, 0) AS execution_gross,
       COALESCE(e.wrong_exchange_count, 0) AS wrong_exchange_executions,
       COALESCE(f.settlement_count, 0) AS funding_settlements,
       COALESCE(f.settlement_amount, 0) AS funding_settlement_amount,
       COALESCE(f.source_or_terms_mismatch_count, 0) AS funding_source_or_terms_mismatches,
       COALESCE(st.snapshot_count, 0) AS snapshot_count,
       COALESCE(st.wrong_exchange_count, 0) AS wrong_exchange_snapshots,
       p.latest_position_event, a.updated_at AS account_updated_at,
       s.id AS latest_snapshot_id, s.recorded_at AS latest_snapshot_at,
       s.unrealized_pnl AS latest_snapshot_unrealized,
       s.valued_through AS latest_snapshot_valued_through,
       s.valuation_fresh AS latest_snapshot_valuation_fresh
FROM paper_accounts a
JOIN affected t ON t.strategy_id = a.primary_strategy_id
LEFT JOIN position_totals p ON p.paper_account_id = a.id
LEFT JOIN execution_totals e ON e.paper_account_id = a.id
LEFT JOIN funding_totals f ON f.paper_account_id = a.id
LEFT JOIN snapshot_totals st ON st.paper_account_id = a.id
LEFT JOIN LATERAL (
    SELECT es.id, es.recorded_at, es.unrealized_pnl, es.valued_through, es.valuation_fresh
    FROM equity_snapshots es WHERE es.paper_account_id = a.id
    ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1
) s ON true
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
ORDER BY a.primary_strategy_id;

\echo === SIX_ACCOUNTS_POSITION_LEDGER_DETAIL ===
SELECT a.primary_strategy_id, v.id AS position_id, v.exchange_id, v.symbol, v.status,
       v.initial_quantity, v.remaining_quantity, v.gross_pnl, v.fees,
       v.funding, v.net_pnl, v.unrealized_pnl, v.funding_complete,
       COALESCE(e.executions, 0) AS executions, COALESCE(e.entries, 0) AS entries,
       COALESCE(e.entry_quantity, 0) AS entry_quantity,
       COALESCE(e.exit_quantity, 0) AS exit_quantity,
       COALESCE(e.fees, 0) AS execution_fees,
       COALESCE(e.gross, 0) AS execution_gross,
       COALESCE(f.settlements, 0) AS funding_settlements,
       COALESCE(f.amount, 0) AS settlement_amount,
       v.opened_at, v.closed_at, v.last_processed_event_at
FROM virtual_positions v
JOIN paper_accounts a ON a.id = v.paper_account_id
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS executions, COUNT(*) FILTER (WHERE type = 'ENTRY') AS entries,
           COALESCE(SUM(quantity) FILTER (WHERE type = 'ENTRY'), 0) AS entry_quantity,
           COALESCE(SUM(quantity) FILTER (WHERE type IN ('TAKE_PROFIT_1', 'TAKE_PROFIT_2', 'STOP_LOSS', 'TIMEOUT', 'RISK_CLOSURE')), 0) AS exit_quantity,
           COALESCE(SUM(commission), 0) AS fees,
           COALESCE(SUM(gross_pnl) FILTER (WHERE type <> 'ENTRY'), 0) AS gross
    FROM virtual_executions WHERE position_id = v.id
) e ON true
LEFT JOIN LATERAL (
    SELECT COUNT(*) AS settlements, COALESCE(SUM(amount), 0) AS amount
    FROM position_funding_events WHERE position_id = v.id
) f ON true
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN (
      'support_resistance_bounce', 'support_resistance_breakout',
      'funding_open_interest_squeeze', 'liquidity_sweep_reversal',
      'volatility_compression_breakout', 'btc_eth_lead_lag'
  )
ORDER BY a.primary_strategy_id, v.opened_at, v.id;

\echo === BINANCE_COHORT_AND_PARTIAL_WRITES ===
WITH expected(strategy_id) AS (
    VALUES ('baseline'), ('trend_pullback'), ('bollinger_mean_reversion'),
           ('donchian_atr_breakout'), ('regime_switching'), ('momentum'),
           ('support_resistance_bounce'), ('support_resistance_breakout'),
           ('funding_open_interest_squeeze'), ('liquidity_sweep_reversal'),
           ('volatility_compression_breakout'), ('btc_eth_lead_lag'),
           ('multi_timeframe_trend_following')
), capital AS (
    SELECT paper_account_id, COUNT(*) AS events, COALESCE(SUM(amount), 0) AS amount,
           STRING_AGG(DISTINCT approval_reference, ', ' ORDER BY approval_reference) AS approvals,
           COUNT(*) FILTER (WHERE exchange_id IS DISTINCT FROM 'binance') AS wrong_exchange
    FROM paper_capital_events GROUP BY paper_account_id
), snapshots AS (
    SELECT paper_account_id, COUNT(*) AS snapshots,
           COUNT(*) FILTER (WHERE exchange_id IS DISTINCT FROM 'binance') AS wrong_exchange,
           COUNT(*) FILTER (WHERE exchange_id = 'bybit' AND source_event_key LIKE 'checkpoint:%') AS repair_candidates
    FROM equity_snapshots GROUP BY paper_account_id
), positions AS (
    SELECT paper_account_id, COUNT(*) AS positions FROM virtual_positions GROUP BY paper_account_id
), journal AS (
    SELECT paper_account_id, COUNT(*) AS events FROM trading_journal_events GROUP BY paper_account_id
)
SELECT x.strategy_id, a.id AS account_id, a.status, a.is_primary, a.mode,
       a.strategy_id AS stored_strategy_id, a.research_experiment_id,
       a.strategy_version, a.configuration_hash, a.starting_balance,
       a.current_equity, a.realized_pnl, a.unrealized_pnl,
       COALESCE(c.events, 0) AS capital_events,
       COALESCE(c.amount, 0) AS approved_capital, c.approvals,
       COALESCE(c.wrong_exchange, 0) AS wrong_exchange_capital,
       a.starting_balance - COALESCE(c.amount, 0) AS capital_difference,
       COALESCE(s.snapshots, 0) AS snapshots,
       COALESCE(s.wrong_exchange, 0) AS wrong_exchange_snapshots,
       COALESCE(s.repair_candidates, 0) AS checkpoint_repair_candidates,
       COALESCE(p.positions, 0) AS positions,
       COALESCE(j.events, 0) AS journal_events
FROM expected x LEFT JOIN paper_accounts a
  ON a.exchange_id = 'binance' AND a.primary_strategy_id = x.strategy_id
LEFT JOIN capital c ON c.paper_account_id = a.id
LEFT JOIN snapshots s ON s.paper_account_id = a.id
LEFT JOIN positions p ON p.paper_account_id = a.id
LEFT JOIN journal j ON j.paper_account_id = a.id
ORDER BY x.strategy_id;

SELECT exchange_id, is_primary, status, COUNT(*) AS accounts,
       COALESCE(SUM(starting_balance), 0) AS starting_balance,
       COALESCE(SUM(current_equity), 0) AS current_equity
FROM paper_accounts GROUP BY exchange_id, is_primary, status
ORDER BY exchange_id, is_primary, status;

SELECT (SELECT COUNT(*) FROM paper_accounts WHERE exchange_id = 'binance') AS binance_accounts,
       (SELECT COUNT(*) FROM paper_capital_events WHERE exchange_id = 'binance') AS binance_capital_events,
       (SELECT COUNT(*) FROM equity_snapshots s JOIN paper_accounts a ON a.id = s.paper_account_id
         WHERE a.exchange_id = 'binance') AS binance_owned_snapshots,
       (SELECT COUNT(*) FROM virtual_positions v JOIN paper_accounts a ON a.id = v.paper_account_id
         WHERE a.exchange_id = 'binance') AS binance_positions,
       (SELECT COUNT(*) FROM virtual_executions e JOIN virtual_positions v ON v.id = e.position_id
         JOIN paper_accounts a ON a.id = v.paper_account_id WHERE a.exchange_id = 'binance') AS binance_executions;

COMMIT;
