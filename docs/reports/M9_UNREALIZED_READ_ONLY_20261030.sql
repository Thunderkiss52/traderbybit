-- One consistent, read-only snapshot. Run only with separately authorized production diagnostics.
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

WITH affected(strategy_id) AS (
    VALUES ('support_resistance_bounce'), ('support_resistance_breakout'),
           ('funding_open_interest_squeeze'), ('liquidity_sweep_reversal'),
           ('volatility_compression_breakout'), ('btc_eth_lead_lag')
), position_totals AS (
    SELECT v.paper_account_id,
           COUNT(*) AS position_count,
           COUNT(*) FILTER (WHERE v.status IN ('OPEN', 'PARTIALLY_CLOSED')) AS active_count,
           COUNT(*) FILTER (WHERE v.status NOT IN ('OPEN', 'PARTIALLY_CLOSED') AND v.unrealized_pnl <> 0) AS closed_nonzero_count,
           COALESCE(SUM(v.unrealized_pnl), 0) AS all_positions_unrealized,
           COALESCE(SUM(v.unrealized_pnl) FILTER (WHERE v.status IN ('OPEN', 'PARTIALLY_CLOSED')), 0) AS active_positions_unrealized,
           MAX(v.last_processed_event_at) AS latest_position_event
    FROM virtual_positions v
    JOIN paper_accounts a ON a.id = v.paper_account_id
    JOIN affected t ON t.strategy_id = a.primary_strategy_id
    WHERE a.exchange_id = 'bybit' AND a.is_primary = true
    GROUP BY v.paper_account_id
)
SELECT a.id AS account_id, a.primary_strategy_id, a.status AS account_status,
       a.unrealized_pnl AS account_unrealized, a.current_equity,
       a.starting_balance + a.realized_pnl + a.unrealized_pnl AS account_formula_equity,
       COALESCE(p.active_positions_unrealized, 0) AS active_positions_unrealized,
       COALESCE(p.all_positions_unrealized, 0) AS all_positions_unrealized,
       a.unrealized_pnl - COALESCE(p.active_positions_unrealized, 0) AS active_difference,
       a.unrealized_pnl - COALESCE(p.all_positions_unrealized, 0) AS original_m9_difference,
       COALESCE(p.position_count, 0) AS position_count,
       COALESCE(p.active_count, 0) AS active_count,
       COALESCE(p.closed_nonzero_count, 0) AS closed_nonzero_count,
       p.latest_position_event, a.updated_at AS account_updated_at,
       s.id AS latest_snapshot_id, s.recorded_at AS latest_snapshot_at,
       s.unrealized_pnl AS latest_snapshot_unrealized,
       s.valued_through AS latest_snapshot_valued_through,
       s.valuation_fresh AS latest_snapshot_valuation_fresh
FROM paper_accounts a
JOIN affected t ON t.strategy_id = a.primary_strategy_id
LEFT JOIN position_totals p ON p.paper_account_id = a.id
LEFT JOIN LATERAL (
    SELECT es.id, es.recorded_at, es.unrealized_pnl, es.valued_through, es.valuation_fresh
    FROM equity_snapshots es WHERE es.paper_account_id = a.id
    ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1
) s ON true
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
ORDER BY a.primary_strategy_id;

SELECT a.primary_strategy_id, v.id AS position_id, v.symbol, v.status,
       v.unrealized_pnl, v.remaining_quantity, v.opened_at, v.closed_at,
       v.last_processed_event_at, v.updated_at
FROM virtual_positions v
JOIN paper_accounts a ON a.id = v.paper_account_id
WHERE a.exchange_id = 'bybit' AND a.is_primary = true
  AND a.primary_strategy_id IN (
      'support_resistance_bounce', 'support_resistance_breakout',
      'funding_open_interest_squeeze', 'liquidity_sweep_reversal',
      'volatility_compression_breakout', 'btc_eth_lead_lag'
  )
  AND (v.status IN ('OPEN', 'PARTIALLY_CLOSED') OR v.unrealized_pnl <> 0)
ORDER BY a.primary_strategy_id, v.opened_at, v.id;

SELECT (SELECT COUNT(*) FROM paper_accounts WHERE exchange_id = 'binance' AND is_primary = true) AS binance_primary_accounts,
       (SELECT COUNT(*) FROM paper_capital_events WHERE exchange_id = 'binance') AS binance_capital_events,
       (SELECT COUNT(*) FROM equity_snapshots WHERE exchange_id = 'binance') AS binance_equity_snapshots;

COMMIT;
