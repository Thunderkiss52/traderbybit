-- Operator-only diagnostic. Read-only MVCC snapshot; no account changes.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '60s';
SET LOCAL lock_timeout = '5s';

\echo === READ_ONLY_SNAPSHOT ===
SELECT current_setting('transaction_read_only') AS transaction_read_only,
       current_setting('transaction_isolation') AS transaction_isolation,
       transaction_timestamp() AS snapshot_at;

\echo === THREE_BYBIT_ACCOUNTS_AND_CURRENT_RISK_STATE ===
SELECT a.id, a.primary_strategy_id, a.status, a.starting_balance,
       a.current_equity, a.peak_equity, a.realized_pnl, a.unrealized_pnl,
       a.current_drawdown_percent, a.maximum_drawdown_percent,
       CASE WHEN a.peak_equity = 0 THEN NULL
            ELSE (a.peak_equity - a.current_equity) * 100 / a.peak_equity END AS recomputed_drawdown_percent,
       a.cooldown_until, a.drawdown_override, a.updated_at
FROM paper_accounts a
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN ('baseline', 'regime_switching', 'trend_pullback')
ORDER BY a.primary_strategy_id;

\echo === FIRST_RECORDED_SNAPSHOTS_AT_OR_ABOVE_ENTRY_LIMIT ===
SELECT a.primary_strategy_id, s.id AS snapshot_id, s.recorded_at,
       s.equity, s.realized_pnl, s.unrealized_pnl, s.drawdown_percent,
       s.source_event_key
FROM paper_accounts a
CROSS JOIN LATERAL (
    SELECT id, recorded_at, equity, realized_pnl, unrealized_pnl,
           drawdown_percent, source_event_key
    FROM equity_snapshots
    WHERE paper_account_id = a.id AND drawdown_percent >= 5
    ORDER BY recorded_at, id LIMIT 5
) s
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN ('baseline', 'regime_switching', 'trend_pullback')
ORDER BY a.primary_strategy_id, s.recorded_at, s.id;

\echo === LATEST_SNAPSHOTS ===
SELECT a.primary_strategy_id, s.id AS snapshot_id, s.recorded_at,
       s.equity, s.realized_pnl, s.unrealized_pnl, s.drawdown_percent,
       s.source_event_key
FROM paper_accounts a
CROSS JOIN LATERAL (
    SELECT id, recorded_at, equity, realized_pnl, unrealized_pnl,
           drawdown_percent, source_event_key
    FROM equity_snapshots WHERE paper_account_id = a.id
    ORDER BY recorded_at DESC, id DESC LIMIT 10
) s
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN ('baseline', 'regime_switching', 'trend_pullback')
ORDER BY a.primary_strategy_id, s.recorded_at DESC, s.id DESC;

\echo === RECENT_RISK_DECISIONS ===
SELECT a.primary_strategy_id, r.id, r.decided_at, r.allowed, r.reasons,
       r.snapshot->'drawdown_percent' AS recorded_drawdown_percent
FROM paper_accounts a
CROSS JOIN LATERAL (
    SELECT id, decided_at, allowed, reasons, snapshot
    FROM risk_decisions WHERE paper_account_id = a.id
    ORDER BY decided_at DESC, id DESC LIMIT 20
) r
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN ('baseline', 'regime_switching', 'trend_pullback')
ORDER BY a.primary_strategy_id, r.decided_at DESC, r.id DESC;

\echo === RECENT_ENTRY_BLOCK_AND_POSITION_EVENTS ===
SELECT a.primary_strategy_id, j.sequence, j.occurred_at, j.event_type,
       j.payload->>'reason' AS reason, j.payload->>'type' AS execution_type,
       j.position_id
FROM paper_accounts a
CROSS JOIN LATERAL (
    SELECT sequence, occurred_at, event_type, payload, position_id
    FROM trading_journal_events WHERE paper_account_id = a.id
      AND event_type IN ('PRE_RISK_ENTRY_BLOCKED', 'RISK_REJECTED',
                         'POSITION_OPENED', 'POSITION_CLOSED',
                         'POSITION_PARTIALLY_CLOSED')
    ORDER BY occurred_at DESC, sequence DESC LIMIT 30
) j
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN ('baseline', 'regime_switching', 'trend_pullback')
ORDER BY a.primary_strategy_id, j.occurred_at DESC, j.sequence DESC;

COMMIT;
