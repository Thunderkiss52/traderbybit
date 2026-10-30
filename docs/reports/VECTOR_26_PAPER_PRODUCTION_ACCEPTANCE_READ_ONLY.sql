-- VECTOR production acceptance: 26 PAPER portfolios.
-- Read-only MVCC snapshot. No writes, no locks, no scans beyond small aggregates.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '120s';
SET LOCAL lock_timeout = '5s';

\echo === SECTION_00_READ_ONLY_SNAPSHOT ===
SELECT current_setting('transaction_read_only') AS transaction_read_only,
       current_setting('transaction_isolation') AS transaction_isolation,
       transaction_timestamp() AS snapshot_at,
       pg_current_snapshot()::text AS snapshot,
       pg_backend_pid() AS backend_pid;

\echo === SECTION_01_ACCOUNT_CENSUS_26 ===
SELECT a.exchange_id, a.primary_strategy_id, a.id AS account_id, a.status,
       a.starting_balance, a.current_equity, a.peak_equity,
       a.realized_pnl, a.unrealized_pnl, a.current_drawdown_percent,
       a.maximum_drawdown_percent, a.updated_at AS account_updated_at,
       (SELECT count(*) FROM virtual_positions v WHERE v.paper_account_id = a.id) AS position_count,
       (SELECT count(*) FROM virtual_positions v WHERE v.paper_account_id = a.id AND v.closed_at IS NOT NULL) AS closed_position_count,
       (SELECT count(*) FROM virtual_positions v WHERE v.paper_account_id = a.id AND v.status IN ('OPEN', 'PARTIALLY_CLOSED')) AS open_position_count,
       (SELECT max(v.closed_at) FROM virtual_positions v WHERE v.paper_account_id = a.id AND v.closed_at IS NOT NULL) AS latest_closed_position_at,
       (SELECT max(e.registered_at) FROM virtual_executions e JOIN virtual_positions v ON v.id = e.position_id WHERE v.paper_account_id = a.id) AS latest_execution_at,
       (SELECT max(s.recorded_at) FROM equity_snapshots s WHERE s.paper_account_id = a.id) AS latest_equity_snapshot_at,
       (SELECT max(j.occurred_at) FROM trading_journal_events j WHERE j.paper_account_id = a.id) AS latest_journal_event_at
FROM paper_accounts a
WHERE a.is_primary = true
ORDER BY a.exchange_id, a.primary_strategy_id;
\echo === SECTION_02_COHORT_INTEGRITY_26 ===
SELECT 'bybit' AS exchange_id, count(*) AS primary_account_count,
       count(*) FILTER (WHERE primary_strategy_id IS NULL) AS missing_strategy_count,
       count(DISTINCT primary_strategy_id) AS distinct_strategy_count
FROM paper_accounts WHERE exchange_id = 'bybit' AND is_primary = true
UNION ALL
SELECT 'binance' AS exchange_id, count(*),
       count(*) FILTER (WHERE primary_strategy_id IS NULL),
       count(DISTINCT primary_strategy_id)
FROM paper_accounts WHERE exchange_id = 'binance' AND is_primary = true;

\echo === SECTION_02B_DUPLICATE_OR_MIXED_PRIMARY ===
SELECT exchange_id, primary_strategy_id, count(*) AS duplicate_count
FROM paper_accounts WHERE is_primary = true
GROUP BY exchange_id, primary_strategy_id
HAVING count(*) > 1
ORDER BY exchange_id, primary_strategy_id;

\echo === SECTION_02C_PRIMARY_WITH_WRONG_EXCHANGE_POSITIONS ===
SELECT p.paper_account_id, a.primary_strategy_id, a.exchange_id AS account_exchange,
       p.exchange_id AS position_exchange, count(*) AS position_count
FROM virtual_positions p
JOIN paper_accounts a ON a.id = p.paper_account_id
WHERE a.is_primary = true AND p.exchange_id <> a.exchange_id
GROUP BY p.paper_account_id, a.primary_strategy_id, a.exchange_id, p.exchange_id
ORDER BY a.exchange_id, a.primary_strategy_id;

\echo === SECTION_02D_PRIMARY_WITH_WRONG_EXCHANGE_EXECUTIONS ===
SELECT e.position_id, a.primary_strategy_id, a.exchange_id AS account_exchange,
       e.exchange_id AS execution_exchange
FROM virtual_executions e
JOIN virtual_positions p ON p.id = e.position_id
JOIN paper_accounts a ON a.id = p.paper_account_id
WHERE a.is_primary = true AND e.exchange_id <> a.exchange_id
ORDER BY a.exchange_id, a.primary_strategy_id
LIMIT 50;

\echo === SECTION_03_M9_FINANCIAL_RECONCILIATION ===
WITH active AS (
    SELECT p.paper_account_id,
           sum(p.unrealized_pnl) AS active_position_unrealized,
           count(*) FILTER (WHERE p.status IN ('OPEN', 'PARTIALLY_CLOSED')) AS active_position_count,
           count(*) FILTER (WHERE p.status NOT IN ('OPEN', 'PARTIALLY_CLOSED') AND p.unrealized_pnl <> 0) AS closed_nonzero_count,
           jsonb_agg(jsonb_build_object('position_id', p.id, 'unrealized_pnl', p.unrealized_pnl))
               FILTER (WHERE p.status NOT IN ('OPEN', 'PARTIALLY_CLOSED') AND p.unrealized_pnl <> 0) AS closed_nonzero_positions
    FROM virtual_positions p
    JOIN paper_accounts a ON a.id = p.paper_account_id
    WHERE a.is_primary = true
    GROUP BY p.paper_account_id
),
expected AS (
    SELECT a.id AS account_id, a.exchange_id, a.primary_strategy_id,
           a.realized_pnl AS account_realized,
           sum(p.gross_pnl) + sum(p.funding) - sum(p.fees) AS expected_realized,
           a.starting_balance + a.realized_pnl + a.unrealized_pnl AS expected_equity
    FROM paper_accounts a
    LEFT JOIN virtual_positions p ON p.paper_account_id = a.id
    WHERE a.is_primary = true
    GROUP BY a.id, a.exchange_id, a.primary_strategy_id,
             a.realized_pnl, a.starting_balance, a.unrealized_pnl
)
SELECT e.account_id, e.exchange_id, e.primary_strategy_id,
       e.account_realized, e.expected_realized,
       (e.account_realized - e.expected_realized) AS realized_difference,
       a.current_equity, e.expected_equity,
       (a.current_equity - e.expected_equity) AS equity_difference,
       a.unrealized_pnl AS account_unrealized,
       act.active_position_unrealized AS active_position_unrealized,
       (a.unrealized_pnl - act.active_position_unrealized) AS unrealized_difference,
       act.active_position_count, act.closed_nonzero_count,
       act.closed_nonzero_positions
FROM paper_accounts a
JOIN expected e ON e.account_id = a.id
LEFT JOIN active act ON act.paper_account_id = a.id
WHERE abs(a.unrealized_pnl - COALESCE(act.active_position_unrealized, 0)) > 0.000001
   OR abs(a.current_equity - e.expected_equity) > 0.000001
   OR abs(a.realized_pnl - e.expected_realized) > 0.000001
   OR COALESCE(act.closed_nonzero_count, 0) > 0
ORDER BY e.exchange_id, e.primary_strategy_id;

\echo === SECTION_04_MARKET_DATA_FRESHNESS ===
SELECT exchange, category, symbol, "interval",
       count(*) AS closed_candles,
       max(open_time) AS latest_closed_open_time,
       (extract(epoch from (now() - max(open_time))) / 60)::int AS lag_minutes,
       count(*) FILTER (WHERE open_time >= now() - interval '24 hours') AS candles_last_24h,
       count(*) FILTER (WHERE open_time < now() - interval '24 hours' AND open_time >= now() - interval '48 hours') AS candles_prev_24h
FROM market_candles
WHERE is_closed = true
  AND exchange IN ('bybit', 'binance')
  AND category = 'linear'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('5', '15', '30', '60', '240')
GROUP BY exchange, category, symbol, interval
ORDER BY exchange, symbol, interval;

\echo === SECTION_04B_MISSING_INTERVALS_LAST_24H ===
WITH series AS (
    SELECT exchange, symbol, "interval",
           generate_series(
               date_trunc('hour', now() - interval '24 hours'),
               date_trunc('hour', now()),
               interval '1 hour'
           ) AS expected_open_time
    FROM (SELECT DISTINCT exchange, symbol, "interval" FROM market_candles
          WHERE exchange IN ('bybit', 'binance') AND symbol IN ('BTCUSDT', 'ETHUSDT')
            AND "interval" IN ('5', '15', '30', '60', '240')) t
),
missing AS (
    SELECT s.exchange, s.symbol, s."interval", s.expected_open_time
    FROM series s
    LEFT JOIN market_candles c
      ON c.exchange = s.exchange AND c.symbol = s.symbol AND c."interval" = s."interval"
     AND c.open_time = s.expected_open_time AND c.is_closed = true
    WHERE c.id IS NULL
)
SELECT exchange, symbol, "interval", count(*) AS missing_candles
FROM missing
GROUP BY exchange, symbol, "interval"
ORDER BY exchange, symbol, "interval";

\echo === SECTION_04C_CONFLICT_QUARANTINE ===
SELECT exchange, category, symbol, "interval", status,
       count(*) AS conflict_rows,
       max(last_seen_at) AS latest_conflict_seen
FROM market_candle_conflicts
GROUP BY exchange, category, symbol, "interval", status
ORDER BY exchange, symbol, interval, status;

\echo === SECTION_04D_CONFLICT_JOURNAL ===
SELECT exchange, symbol, "interval", state, count(*) AS rows
FROM market_candle_conflict_journal
GROUP BY exchange, symbol, "interval", state
ORDER BY exchange, symbol, interval, state;

\echo === SECTION_05_STRATEGY_EVALUATION_26 ===
WITH obs AS (
    SELECT exchange, strategy_id,
           count(*) AS observation_count,
           max(decision_time) AS latest_decision_time,
           count(*) FILTER (WHERE side = 'BUY') AS buy_count,
           count(*) FILTER (WHERE side = 'SELL') AS sell_count,
           count(*) FILTER (WHERE side = 'SKIP') AS skip_count,
           count(*) FILTER (WHERE side NOT IN ('BUY', 'SELL', 'SKIP')) AS other_count
    FROM strategy_observations
    WHERE exchange IN ('bybit', 'binance')
      AND decision_time >= now() - interval '24 hours'
    GROUP BY exchange, strategy_id
)
SELECT o.exchange, o.strategy_id,
       COALESCE(o.observation_count, 0) AS observations_24h,
       COALESCE(o.buy_count, 0) AS buy_24h,
       COALESCE(o.sell_count, 0) AS sell_24h,
       COALESCE(o.skip_count, 0) AS skip_24h,
       o.latest_decision_time,
       (SELECT max(s.decision_time) FROM signals s
        WHERE s.exchange = o.exchange AND s.strategy_id = o.strategy_id
          AND s.decision_time >= now() - interval '24 hours') AS latest_signal_24h,
       (SELECT count(*) FROM signals s
        WHERE s.exchange = o.exchange AND s.strategy_id = o.strategy_id
          AND s.decision_time >= now() - interval '24 hours') AS signals_24h,
       (SELECT count(*) FROM paper_signal_states pss
        JOIN paper_accounts pa ON pa.id = pss.paper_account_id
        WHERE pa.exchange_id = o.exchange AND pa.primary_strategy_id = o.strategy_id
          AND pss.processed_at >= now() - interval '24 hours') AS paper_states_24h,
       (SELECT jsonb_agg(subq.reason) FROM (
            SELECT pss.reason
            FROM paper_signal_states pss
            JOIN paper_accounts pa ON pa.id = pss.paper_account_id
            WHERE pa.exchange_id = o.exchange AND pa.primary_strategy_id = o.strategy_id
            AND pss.processed_at >= now() - interval '24 hours'
            AND pss.reason IS NOT NULL
            ORDER BY pss.processed_at DESC LIMIT 20
        ) subq) AS recent_rejection_reasons
FROM obs o
UNION ALL
SELECT p.exchange_id, p.primary_strategy_id, 0, 0, 0, 0, null, null, null, null, null
FROM (SELECT DISTINCT exchange_id, primary_strategy_id FROM paper_accounts WHERE is_primary = true) p
WHERE NOT EXISTS (SELECT 1 FROM obs o WHERE o.exchange = p.exchange_id AND o.strategy_id = p.primary_strategy_id)
ORDER BY exchange, strategy_id;

\echo === SECTION_06_PIPELINE_EVENTS_24H ===
SELECT exchange, symbol, timeframe, stage, status,
       count(*) AS rows,
       max(candle_close_at) AS latest_candle_close_at,
       count(*) FILTER (WHERE status = 'PENDING') AS pending,
       count(*) FILTER (WHERE status = 'COMPLETED') AS completed,
       count(*) FILTER (WHERE status NOT IN ('PENDING', 'COMPLETED')) AS other_status,
       max(dispatched_at) AS latest_dispatched_at,
       max(completed_at) AS latest_completed_at
FROM trading_pipeline_events
WHERE exchange IN ('bybit', 'binance')
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND timeframe IN ('5', '15', '30', '60', '240')
  AND created_at >= now() - interval '24 hours'
GROUP BY exchange, symbol, timeframe, stage, status
ORDER BY exchange, symbol, timeframe, stage, status;

\echo === SECTION_06B_PIPELINE_STALLED_PENDING ===
SELECT exchange, symbol, timeframe, stage, status,
       count(*) AS pending_rows,
       min(candle_close_at) AS oldest_pending_candle_close_at,
       (extract(epoch from (now() - min(candle_close_at))) / 60)::int AS oldest_pending_age_minutes,
       max(attempts) AS max_attempts,
       max(last_error) AS last_error
FROM trading_pipeline_events
WHERE status = 'PENDING'
  AND exchange IN ('bybit', 'binance')
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
GROUP BY exchange, symbol, timeframe, stage, status
ORDER BY oldest_pending_age_minutes DESC
LIMIT 50;

\echo === SECTION_07_EXECUTIONS_AND_FINANCIAL_ACTIVITY_24H ===
SELECT a.exchange_id, a.primary_strategy_id,
       count(*) FILTER (WHERE e.type = 'ENTRY') AS entries_24h,
       count(*) FILTER (WHERE e.type <> 'ENTRY') AS exits_24h,
       max(e.registered_at) AS latest_execution_at,
       sum(e.gross_pnl) FILTER (WHERE e.type <> 'ENTRY') AS realized_pnl_from_exits,
       sum(e.commission) AS commissions_24h,
       sum(e.slippage) AS slippage_24h
FROM virtual_executions e
JOIN virtual_positions p ON p.id = e.position_id
JOIN paper_accounts a ON a.id = p.paper_account_id
WHERE a.is_primary = true
  AND e.registered_at >= now() - interval '24 hours'
GROUP BY a.exchange_id, a.primary_strategy_id
ORDER BY a.exchange_id, a.primary_strategy_id;

\echo === SECTION_07B_OPEN_POSITIONS ===
SELECT a.exchange_id, a.primary_strategy_id, a.status AS account_status,
       p.id AS position_id, p.symbol, p.side, p.status AS position_status,
       p.entry_price, p.unrealized_pnl, p.gross_pnl, p.net_pnl,
       p.opened_at, p.maximum_holding_until
FROM paper_accounts a
JOIN virtual_positions p ON p.paper_account_id = a.id
WHERE a.is_primary = true AND p.status IN ('OPEN', 'PARTIALLY_CLOSED')
ORDER BY a.exchange_id, a.primary_strategy_id, p.opened_at;

\echo === SECTION_07C_JOURNAL_ANOMALIES ===
SELECT a.exchange_id, a.primary_strategy_id, j.event_type,
       count(*) AS rows, max(j.occurred_at) AS latest_occurred_at
FROM trading_journal_events j
JOIN paper_accounts a ON a.id = j.paper_account_id
WHERE a.is_primary = true
  AND j.event_type IN ('DUPLICATE_EXECUTION', 'AMBIGUOUS_EXECUTION', 'EXECUTION_FAILURE',
                       'POSITION_TIMEOUT', 'FUNDING_INCOMPLETE', 'DATA_INTEGRITY_UNAVAILABLE')
GROUP BY a.exchange_id, a.primary_strategy_id, j.event_type
ORDER BY a.exchange_id, a.primary_strategy_id, j.event_type;

\echo === SECTION_08_RISK_DECISIONS_24H ===
SELECT a.exchange_id, a.primary_strategy_id,
       count(*) AS risk_decisions_24h,
       count(*) FILTER (WHERE r.allowed = true) AS allowed_24h,
       count(*) FILTER (WHERE r.allowed = false) AS rejected_24h,
       max(r.decided_at) AS latest_risk_decision_at,
       (SELECT jsonb_agg(subq.reasons) FROM (
            SELECT r.reasons FROM risk_decisions r
            JOIN paper_accounts pa ON pa.id = r.paper_account_id
            WHERE pa.exchange_id = a.exchange_id AND pa.primary_strategy_id = a.primary_strategy_id
              AND r.decided_at >= now() - interval '24 hours' AND r.allowed = false
            ORDER BY r.decided_at DESC LIMIT 20
        ) subq) AS recent_rejection_reasons
FROM risk_decisions r
JOIN paper_accounts a ON a.id = r.paper_account_id
WHERE a.is_primary = true AND r.decided_at >= now() - interval '24 hours'
GROUP BY a.exchange_id, a.primary_strategy_id
ORDER BY a.exchange_id, a.primary_strategy_id;

\echo === SECTION_08B_BLOCKED_ACCOUNTS ===
SELECT a.exchange_id, a.primary_strategy_id, a.id AS account_id, a.status,
       a.current_drawdown_percent, a.peak_equity, a.current_equity,
       a.cooldown_until,
       (SELECT count(*) FROM risk_decisions r WHERE r.paper_account_id = a.id
         AND r.allowed = false AND r.decided_at >= now() - interval '7 days') AS rejected_7d,
       (SELECT max(j.occurred_at) FROM trading_journal_events j
        WHERE j.paper_account_id = a.id AND j.event_type = 'PRE_RISK_ENTRY_BLOCKED') AS latest_entry_block_at
FROM paper_accounts a
WHERE a.is_primary = true AND a.status <> 'ACTIVE'
ORDER BY a.exchange_id, a.primary_strategy_id;

\echo === SECTION_09_BINANCE_CAPITAL_PROVENANCE ===
SELECT a.exchange_id, a.primary_strategy_id, a.id AS account_id,
       a.starting_balance, a.current_equity,
       count(*) AS capital_events,
       sum(c.amount) AS total_capital,
       max(c.occurred_at) AS latest_capital_at,
       count(DISTINCT c.approval_reference) AS distinct_approval_references,
       (SELECT count(*) FROM paper_capital_events c
        WHERE c.paper_account_id = a.id AND c.approval_reference LIKE 'checkpoint:%') AS checkpoint_events
FROM paper_accounts a
LEFT JOIN paper_capital_events c ON c.paper_account_id = a.id
WHERE a.exchange_id = 'binance' AND a.is_primary = true
GROUP BY a.exchange_id, a.primary_strategy_id, a.id, a.starting_balance, a.current_equity
ORDER BY a.exchange_id, a.primary_strategy_id;

\echo === SECTION_10_GO_WORKER_REGISTRATION ===
SELECT worker_id, engine, version, status, hostname, pid,
       supported_algorithms, heartbeat_at, registered_at,
       last_job_id, last_job_finished_at,
       total_jobs_completed, total_jobs_failed,
       (extract(epoch from (now() - heartbeat_at)))::int AS heartbeat_age_seconds
FROM research_workers
ORDER BY engine, heartbeat_at DESC;

\echo === SECTION_10B_RESEARCH_JOBS ===
SELECT job_id, kind, status, strategy_id, algorithm_version,
       requested_at_utc, claimed_at, started_at, completed_at,
       progress, progress_message, retry_count, max_retries,
       lease_owner, lease_expires_at, fencing_token,
       (extract(epoch from (now() - COALESCE(completed_at, started_at, claimed_at, requested_at_utc))))::int AS age_seconds,
       sanitized_error
FROM research_jobs
WHERE status IN ('QUEUED', 'CLAIMED', 'RUNNING', 'FAILED', 'EXPIRED', 'RETRY_WAIT')
ORDER BY requested_at_utc DESC
LIMIT 50;

\echo === SECTION_10C_RESEARCH_JOBS_COMPLETED ===
SELECT kind, status, count(*) AS rows,
       min(completed_at) AS earliest_completed_at,
       max(completed_at) AS latest_completed_at
FROM research_jobs
WHERE status = 'SUCCEEDED'
GROUP BY kind, status
ORDER BY kind;

\echo === SECTION_11_SUMMARY ===
SELECT
  (SELECT count(*) FROM paper_accounts WHERE is_primary = true) AS total_primary_accounts,
  (SELECT count(*) FROM paper_accounts WHERE is_primary = true AND exchange_id = 'bybit') AS bybit_primary_accounts,
  (SELECT count(*) FROM paper_accounts WHERE is_primary = true AND exchange_id = 'binance') AS binance_primary_accounts,
  (SELECT count(*) FROM paper_accounts WHERE is_primary = true AND status = 'ACTIVE') AS active_accounts,
  (SELECT count(*) FROM paper_accounts WHERE is_primary = true AND status <> 'ACTIVE') AS non_active_accounts,
  (SELECT count(*) FROM virtual_positions WHERE status IN ('OPEN', 'PARTIALLY_CLOSED')) AS open_positions,
  (SELECT count(*) FROM trading_pipeline_events WHERE status = 'PENDING') AS pending_pipeline_events,
  (SELECT count(*) FROM market_candle_conflicts WHERE status = 'UNRESOLVED') AS unresolved_candle_conflicts,
  (SELECT count(*) FROM research_workers WHERE status = 'ready') AS ready_research_workers,
  (SELECT count(*) FROM research_jobs WHERE status IN ('QUEUED', 'CLAIMED', 'RUNNING')) AS active_research_jobs;

COMMIT;
