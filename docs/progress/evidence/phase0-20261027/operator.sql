-- Operator execution only; this assignment ran this file only on isolated fixtures.
-- psql -X -v ON_ERROR_STOP=1 -v window_start=2026-10-20T00:00:00Z \
--   -v as_of=2026-10-27T00:00:00Z -f operator.sql
-- Replace the example window with the authorized UTC window (at most 7 days).
-- Use an existing SELECT-only role and an operator-selected connection.
-- No Artisan, stored application functions, repairs, advisory locks or writes.
-- LIMIT sentinel reached => incomplete evidence; partition the window and rerun.
\set ON_ERROR_STOP on
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout = '15s';
SET LOCAL lock_timeout = '2s';
SET LOCAL idle_in_transaction_session_timeout = '60s';

SELECT current_database(), current_user, version(), current_setting('transaction_read_only'),
       current_setting('transaction_isolation'), now() AS captured_at,
       :'window_start'::timestamptz AS window_start, :'as_of'::timestamptz AS as_of;

-- Q1: Current account state, not reconstructed state at as_of. Includes BLOCKED accounts.
SELECT id, exchange_id, primary_strategy_id, strategy_id, strategy_version,
       configuration_hash, status, created_at, starting_balance, current_equity,
       realized_pnl, unrealized_pnl, peak_equity, current_drawdown_percent,
       maximum_drawdown_percent, cooldown_until,
       current_equity - starting_balance - realized_pnl - unrealized_pnl AS stored_identity_residual,
       count(*) OVER () AS total_primary_accounts
FROM paper_accounts WHERE is_primary = true
ORDER BY exchange_id, primary_strategy_id, id LIMIT 65;

-- Q2: Actual persisted decisions; no assumed 192/1344-slot denominator.
SELECT exchange, strategy_id, strategy_version, side, count(*) AS decisions,
       min(decision_time) AS first_decision, max(decision_time) AS last_decision
FROM signals
WHERE decision_time >= :'window_start'::timestamptz AND decision_time < :'as_of'::timestamptz
GROUP BY exchange, strategy_id, strategy_version, side
ORDER BY exchange, strategy_id, strategy_version, side LIMIT 1001;

SELECT exchange, strategy_id, reasons::jsonb AS reasons, count(*) AS skip_decisions
FROM signals
WHERE side = 'SKIP' AND decision_time >= :'window_start'::timestamptz
  AND decision_time < :'as_of'::timestamptz
GROUP BY exchange, strategy_id, reasons::jsonb ORDER BY exchange, strategy_id, count(*) DESC LIMIT 1001;

-- Q3: Position accounting and late-funding candidates. No missing amount is estimated.
SELECT p.id, p.paper_account_id, p.exchange_id, p.symbol, p.side, p.status,
       p.opened_at, p.closed_at, p.initial_quantity, p.remaining_quantity,
       p.gross_pnl, p.fees, p.funding, p.net_pnl, p.funding_complete,
       p.net_pnl - (p.gross_pnl - p.fees + p.funding) AS position_identity_residual,
       p.last_processed_event_at
FROM virtual_positions p JOIN paper_accounts a ON a.id = p.paper_account_id
WHERE a.is_primary AND (p.closed_at IS NULL OR
      (p.closed_at >= :'window_start'::timestamptz AND p.closed_at < :'as_of'::timestamptz))
ORDER BY p.paper_account_id, p.opened_at, p.id LIMIT 5001;

SELECT p.paper_account_id, p.exchange_id, p.id AS position_id, e.id AS execution_id,
       e.exchange_id AS execution_exchange, e.type, e.quantity, e.price,
       e.commission, e.gross_pnl, e.market_event_at, e.idempotency_key
FROM virtual_positions p JOIN paper_accounts a ON a.id = p.paper_account_id
JOIN virtual_executions e ON e.position_id = p.id
WHERE a.is_primary AND (p.closed_at IS NULL OR
      (p.closed_at >= :'window_start'::timestamptz AND p.closed_at < :'as_of'::timestamptz))
ORDER BY p.paper_account_id, p.id, e.market_event_at, e.id LIMIT 10001;

SELECT p.paper_account_id, p.exchange_id, p.id AS position_id, p.symbol, p.side,
       m.id AS market_event_id, m.funding_at, m.rate AS market_rate, m.mark_price AS market_mark,
       f.id AS settlement_id, f.quantity AS recorded_held_quantity,
       f.rate AS settlement_rate, f.mark_price AS settlement_mark, f.amount,
       m.created_at AS market_event_arrived_at, f.created_at AS settled_at
FROM virtual_positions p JOIN paper_accounts a ON a.id = p.paper_account_id
JOIN market_funding_events m ON m.exchange = p.exchange_id AND m.category = 'linear'
  AND m.symbol = p.symbol AND m.funding_at > p.opened_at
  AND m.funding_at <= coalesce(p.closed_at, :'as_of'::timestamptz)
LEFT JOIN position_funding_events f ON f.position_id = p.id AND f.market_funding_event_id = m.id
WHERE a.is_primary AND p.opened_at < :'as_of'::timestamptz
  AND (p.closed_at IS NULL OR p.closed_at >= :'window_start'::timestamptz)
ORDER BY p.paper_account_id, p.id, m.funding_at LIMIT 10001;
-- Missing market events do not appear above. Certified per-symbol schedules must
-- independently enumerate expected boundaries; absence is never zero funding.

-- Q4: Terminal states with exact correlation keys; dispatch is not completion.
SELECT exchange, symbol, timeframe, stage, status, event_key, candle_close_at,
       completed_at, payload->>'failures_count' AS failures_count,
       payload->>'eligible_accounts' AS eligible_accounts,
       payload->>'processed_accounts' AS processed_accounts
FROM trading_pipeline_events
WHERE candle_close_at >= :'window_start'::timestamptz AND candle_close_at < :'as_of'::timestamptz
ORDER BY exchange, symbol, candle_close_at, stage LIMIT 20001;

-- Q5: Prior, timestamp-qualified observations; current source caps history at 2000.
-- This is a population inventory, NOT a verdict that a strategy should trade.
WITH prior AS (
 SELECT exchange, category, symbol, event, outcome, outcome_at, triggered_at, features,
        row_number() OVER (PARTITION BY exchange, category, symbol, event ORDER BY triggered_at DESC) AS n
 FROM level_interaction_forecasts
 WHERE triggered_at < :'as_of'::timestamptz AND outcome IS NOT NULL
   AND outcome_at IS NOT NULL AND outcome_at <= :'as_of'::timestamptz
)
SELECT exchange, category, symbol, event, count(*) AS prior_observations_capped_2000,
       count(*) FILTER (WHERE outcome = 'SUCCESS') AS successes,
       count(*) FILTER (WHERE outcome = 'FAILURE') AS failures,
       count(*) FILTER (WHERE outcome NOT IN ('SUCCESS','FAILURE')) AS unresolved_outcomes
FROM prior WHERE n <= 2000 GROUP BY exchange, category, symbol, event
ORDER BY exchange, category, symbol, event LIMIT 1001;

SELECT exchange, category, symbol, event, triggered_at, horizon_ends_at, outcome_at,
       sample_size, validation_status, model_version, configuration_hash,
       features->>'cohort_sample_size' AS cohort_sample_size,
       features->>'probability_population' AS probability_population
FROM level_interaction_forecasts
WHERE triggered_at >= :'window_start'::timestamptz AND triggered_at < :'as_of'::timestamptz
ORDER BY exchange, category, symbol, event, triggered_at DESC LIMIT 5001;

-- Q6: Market intervals. Edge gaps before first/after last row need separate window comparison.
WITH bars AS (
 SELECT exchange, symbol, interval, open_time,
        lag(open_time) OVER (PARTITION BY exchange, category, symbol, interval ORDER BY open_time) AS previous_open
 FROM market_candles WHERE category = 'linear' AND is_closed
   AND symbol IN ('BTCUSDT','ETHUSDT') AND interval IN ('5','15','30','60')
   AND open_time >= :'window_start'::timestamptz AND open_time < :'as_of'::timestamptz
)
SELECT exchange, symbol, interval, count(*) AS closed_bars, min(open_time), max(open_time),
       count(*) FILTER (WHERE previous_open IS NOT NULL AND
           extract(epoch FROM open_time - previous_open) <> interval::integer * 60) AS irregular_intervals
FROM bars GROUP BY exchange, symbol, interval ORDER BY exchange, symbol, interval;

-- Q7: Candidate lifecycle evidence; do not trigger generation, evaluation or promotion.
SELECT id, parent_strategy_id, candidate_version, configuration_hash, status,
       search_metadata->>'backtest_run_id' AS backtest_run_id,
       search_metadata->>'evaluation_status' AS evaluation_status,
       search_metadata->>'evaluation_attempts' AS evaluation_attempts,
       validation_gates->>'status' AS gate_status,
       validation_gates->>'failure_reason' AS failure_reason
FROM learning_candidate_strategies
WHERE created_at >= :'window_start'::timestamptz AND created_at < :'as_of'::timestamptz
ORDER BY created_at, id LIMIT 1001;
ROLLBACK;
