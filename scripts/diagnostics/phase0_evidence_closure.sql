-- =============================================================================
-- VECTOR PHASE 0 PRODUCTION EVIDENCE CLOSURE SUPPLEMENT
-- Read-only row-level and summary evidence for the remaining production V2 gaps.
--
-- Safety invariants:
-- 1. Strictly read-only: SELECT only, no DML, DDL or advisory locks.
-- 2. Wrapped in BEGIN TRANSACTION READ ONLY ... ROLLBACK.
-- 3. Bounded statement/lock/idle timeouts.
-- 4. Row-level exports are deterministic and paginated with LIMIT/OFFSET.
-- 5. Missing evidence stays PENDING. This script never promotes an obligation.
--
-- Invocation:
--   psql -X -v ON_ERROR_STOP=1 \
--     -v funding_offset=0 -v pipeline_offset=0 \
--     -f scripts/diagnostics/phase0_evidence_closure.sql
--
-- Offsets default to 0 when omitted. Rerun with 100, 200, ... while the
-- corresponding summary reports more unresolved rows.
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';
SET LOCAL idle_in_transaction_session_timeout = '60s';

\if :{?funding_offset}
\else
\set funding_offset 0
\endif

\if :{?pipeline_offset}
\else
\set pipeline_offset 0
\endif

-- 17. Canonical 26-account identity census, including absent and duplicate rows.
-- PASS requires one row per expected exchange/strategy pair with the expected
-- mode and account identity. A zero balance is a value, not a missing account.
WITH expected(strategy_id) AS (
    VALUES
        ('baseline'),
        ('trend_pullback'),
        ('bollinger_mean_reversion'),
        ('donchian_atr_breakout'),
        ('regime_switching'),
        ('momentum'),
        ('support_resistance_bounce'),
        ('support_resistance_breakout'),
        ('funding_open_interest_squeeze'),
        ('liquidity_sweep_reversal'),
        ('volatility_compression_breakout'),
        ('btc_eth_lead_lag'),
        ('multi_timeframe_trend_following')
),
exchanges(exchange_id) AS (VALUES ('bybit'), ('binance')),
actual AS (
    SELECT
        exchange_id,
        primary_strategy_id,
        count(*) AS account_count,
        string_agg(id::text, ',' ORDER BY id) AS account_ids,
        string_agg(DISTINCT mode, ',' ORDER BY mode) AS modes,
        string_agg(DISTINCT status, ',' ORDER BY status) AS statuses,
        sum(starting_balance) AS starting_balance,
        sum(current_equity) AS current_equity,
        max(created_at) AS latest_created_at
    FROM paper_accounts
    WHERE is_primary = true
    GROUP BY exchange_id, primary_strategy_id
)
SELECT
    e.exchange_id,
    x.strategy_id,
    coalesce(a.account_count, 0) AS account_count,
    a.account_ids,
    a.modes,
    a.statuses,
    coalesce(a.starting_balance, 0) AS starting_balance,
    coalesce(a.current_equity, 0) AS current_equity,
    a.latest_created_at,
    CASE
        WHEN a.account_count = 1 THEN 'IDENTITY_PRESENT'
        WHEN a.account_count IS NULL THEN 'MISSING'
        ELSE 'DUPLICATE_OR_UNEXPECTED'
    END AS census_state
FROM expected x
CROSS JOIN exchanges e
LEFT JOIN actual a
    ON a.exchange_id = e.exchange_id
   AND a.primary_strategy_id = x.strategy_id
ORDER BY e.exchange_id, x.strategy_id;

-- 18. Bootstrap capital and opening-snapshot provenance per PRIMARY account.
-- NOTE: paper_accounts.id and paper_capital_events.id are random UUIDs with NO causal insertion order.
-- Ordering causality is established STRICTLY via equity_snapshots.id (BIGINT / BIGSERIAL insertion sequence)
-- joined to paper_capital_events via the explicit key relationship:
--   equity_snapshots.source_event_key = 'owner-paper-capital:' || paper_capital_events.id::text
-- If the insertion sequence or capital event linkage cannot be proven, status is INSUFFICIENT_BOOTSTRAP_EVIDENCE.
WITH initial_snaps AS (
    SELECT
        paper_account_id,
        min(id) AS initial_snapshot_id,
        min(recorded_at) AS initial_snapshot_at
    FROM equity_snapshots
    WHERE source_event_key IN ('account:initial', 'account:initial:binance')
    GROUP BY paper_account_id
),
funded_snaps AS (
    SELECT
        es.paper_account_id,
        min(es.id) AS funded_snapshot_id,
        min(es.recorded_at) AS funded_snapshot_at,
        min(es.source_event_key) AS funded_snapshot_source,
        min(pc.id::text) AS linked_capital_event_id,
        min(pc.amount) AS approved_capital_amount,
        min(pc.occurred_at) AS capital_occurred_at
    FROM equity_snapshots es
    JOIN paper_capital_events pc
      ON pc.paper_account_id = es.paper_account_id
     AND es.source_event_key = ('owner-paper-capital:' || pc.id::text)
    WHERE pc.reason = 'OWNER_APPROVED_PAPER_ALLOCATION'
      AND pc.amount > 0
    GROUP BY es.paper_account_id
)
SELECT
    pa.exchange_id,
    pa.primary_strategy_id,
    pa.status,
    pa.mode,
    pa.starting_balance,
    pa.current_equity,
    pa.created_at AS account_created_at,
    coalesce(fs.approved_capital_amount, 0) AS approved_capital_total,
    init.initial_snapshot_id,
    init.initial_snapshot_at,
    fs.funded_snapshot_id,
    fs.funded_snapshot_at,
    fs.funded_snapshot_source,
    fs.linked_capital_event_id,
    CASE
        WHEN pa.exchange_id = 'bybit' AND init.initial_snapshot_id IS NOT NULL
            THEN 'BYBIT_CANONICAL_BOOTSTRAP'
        WHEN init.initial_snapshot_id IS NULL AND fs.funded_snapshot_id IS NOT NULL
            THEN 'DIRECT_FUNDED_NO_ZERO_BOOTSTRAP'
        WHEN init.initial_snapshot_id IS NOT NULL AND fs.funded_snapshot_id IS NOT NULL
             AND init.initial_snapshot_id < fs.funded_snapshot_id
             AND init.initial_snapshot_at <= fs.capital_occurred_at
            THEN 'BOOTSTRAP_SEQUENCE_PROVEN'
        WHEN init.initial_snapshot_id IS NOT NULL AND fs.funded_snapshot_id IS NULL
            THEN 'UNFUNDED_INITIAL_ONLY'
        ELSE 'INSUFFICIENT_BOOTSTRAP_EVIDENCE'
    END AS bootstrap_causality_status,
    (SELECT es.id FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
      ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1) AS latest_snapshot_id,
    (SELECT es.recorded_at FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
      ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1) AS latest_snapshot_at,
    (SELECT es.equity FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
      ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1) AS latest_snapshot_equity,
    (SELECT es.valued_through FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
      ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1) AS latest_valued_through,
    (SELECT es.valuation_fresh FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
      ORDER BY es.recorded_at DESC, es.id DESC LIMIT 1) AS latest_valuation_fresh,
    (SELECT count(*) FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
        AND es.recorded_at >= now() - INTERVAL '7 days'
        AND es.equity = 0) AS zero_equity_snapshots_7d,
    (SELECT count(*) FROM equity_snapshots es
      WHERE es.paper_account_id = pa.id
        AND es.recorded_at >= now() - INTERVAL '7 days'
        AND es.equity < 0) AS negative_equity_snapshots_7d
FROM paper_accounts pa
LEFT JOIN initial_snaps init ON init.paper_account_id = pa.id
LEFT JOIN funded_snaps fs ON fs.paper_account_id = pa.id
WHERE pa.is_primary = true
ORDER BY pa.exchange_id, pa.primary_strategy_id;

-- 19. Funding completeness summary by exchange and lifecycle state.
SELECT
    p.exchange_id,
    p.funding_complete,
    count(*) AS position_count,
    count(*) FILTER (WHERE p.closed_at IS NULL) AS open_positions,
    count(*) FILTER (WHERE p.closed_at IS NOT NULL) AS closed_positions,
    count(*) FILTER (WHERE p.closed_at IS NOT NULL AND p.funding_complete = false) AS unresolved_closed,
    count(*) FILTER (WHERE EXISTS (
        SELECT 1 FROM position_funding_events f WHERE f.position_id = p.id
    )) AS positions_with_settlements,
    min(p.opened_at) AS earliest_opened_at,
    max(p.opened_at) AS latest_opened_at
FROM virtual_positions p
GROUP BY p.exchange_id, p.funding_complete
ORDER BY p.exchange_id, p.funding_complete;

-- 19b. Market funding provenance coverage. Null mark_price_source is legacy or
-- unverified provenance and cannot be treated as exchange-certified.
SELECT
    m.exchange,
    m.category,
    count(*) AS funding_event_count,
    count(*) FILTER (WHERE to_jsonb(m)->>'mark_price_source' IS NULL) AS null_mark_source,
    count(*) FILTER (WHERE to_jsonb(m)->>'mark_price_source' IS NOT NULL) AS explicit_mark_source,
    min(m.funding_at) AS earliest_funding_at,
    max(m.funding_at) AS latest_funding_at
FROM market_funding_events m
GROUP BY m.exchange, m.category
ORDER BY m.exchange, m.category;

-- 20. Paginated per-position funding evidence. Feed JSON to the offline
-- classifier together with independently obtained exchange funding schedules.
-- A returned row does NOT prove exchange coverage or historical mark correctness.
SELECT
    count(*) OVER () AS total_unresolved_rows,
    p.id,
    p.paper_account_id,
    p.exchange_id,
    p.symbol,
    p.side,
    p.opened_at,
    p.closed_at,
    p.initial_quantity,
    p.funding_complete,
    p.gross_pnl,
    p.fees,
    p.funding,
    p.net_pnl,
    p.net_pnl - (p.gross_pnl - p.fees + p.funding) AS net_identity_difference,
    coalesce((SELECT sum(f.amount) FROM position_funding_events f
               WHERE f.position_id = p.id), 0) AS settled_funding,
    (SELECT jsonb_agg(jsonb_build_object(
                'at', m.funding_at,
                'rate', m.rate,
                'mark', m.mark_price,
                'source', to_jsonb(m)->>'mark_price_source',
                'market_event_id', m.id
            ) ORDER BY m.funding_at, m.id)
       FROM market_funding_events m
      WHERE m.exchange = p.exchange_id
        AND m.category = 'linear'
        AND m.symbol = p.symbol
        AND m.funding_at > p.opened_at
        AND m.funding_at <= p.closed_at) AS market_events,
    (SELECT jsonb_agg(jsonb_build_object(
                'at', e.market_event_at,
                'type', e.type,
                'quantity', e.quantity
            ) ORDER BY e.market_event_at, e.id)
       FROM virtual_executions e
      WHERE e.position_id = p.id) AS executions,
    (SELECT jsonb_agg(to_jsonb(f) ORDER BY f.funding_at, f.id)
       FROM position_funding_events f
      WHERE f.position_id = p.id) AS settlements
FROM virtual_positions p
WHERE p.closed_at IS NOT NULL
  AND p.funding_complete = false
ORDER BY p.exchange_id, p.opened_at, p.id
LIMIT 100 OFFSET :funding_offset;

-- 21. Pipeline anomaly summary. Age and attempt counts expose stuck events;
-- aggregate status alone cannot prove that an account succeeded.
SELECT
    tpe.exchange,
    tpe.stage,
    tpe.status,
    count(*) AS event_count,
    count(*) FILTER (WHERE tpe.created_at >= now() - INTERVAL '7 days') AS events_7d,
    count(*) FILTER (WHERE tpe.completed_at IS NULL) AS never_completed,
    count(*) FILTER (WHERE tpe.completed_at IS NULL
                       AND tpe.created_at < now() - INTERVAL '5 minutes') AS stuck_over_5m,
    max(tpe.attempts) AS maximum_attempts,
    count(*) FILTER (WHERE tpe.last_error IS NOT NULL) AS rows_with_last_error,
    min(tpe.created_at) AS earliest_created_at,
    max(tpe.created_at) AS latest_created_at,
    max(tpe.completed_at) AS latest_completed_at
FROM trading_pipeline_events tpe
WHERE tpe.status IN ('STALE', 'PARTIAL', 'FAILED', 'PENDING', 'DISPATCHED')
GROUP BY tpe.exchange, tpe.stage, tpe.status
ORDER BY tpe.exchange, tpe.stage, tpe.status;

-- 22. Paginated row-level pipeline anomaly evidence with matching downstream
-- PAPER event. Missing legacy failure identities remain missing evidence and
-- are never authorization to replay all peer accounts.
SELECT
    count(*) OVER () AS total_matching_rows,
    tpe.id,
    tpe.event_key,
    tpe.exchange,
    tpe.symbol,
    tpe.timeframe,
    tpe.stage,
    tpe.status,
    tpe.candle_close_at,
    tpe.created_at,
    tpe.dispatched_at,
    tpe.completed_at,
    tpe.attempts,
    tpe.last_error,
    tpe.payload,
    paper.event_key AS paper_event_key,
    paper.status AS paper_status,
    paper.completed_at AS paper_completed_at,
    paper.payload->>'eligible_accounts' AS paper_eligible_accounts,
    paper.payload->>'processed_accounts' AS paper_processed_accounts,
    paper.payload->>'failures_count' AS paper_failures_count,
    paper.payload->'failures' AS paper_failures,
    paper.payload->'eligible_account_ids' AS paper_eligible_account_ids,
    paper.payload->'processed_account_ids' AS paper_processed_account_ids
FROM trading_pipeline_events tpe
LEFT JOIN trading_pipeline_events paper
    ON paper.exchange = tpe.exchange
   AND paper.symbol = tpe.symbol
   AND paper.candle_close_at = tpe.candle_close_at
   AND paper.stage = 'PAPER_PROCESSED'
WHERE tpe.status IN ('STALE', 'PARTIAL', 'FAILED')
ORDER BY tpe.candle_close_at, tpe.id
LIMIT 100 OFFSET :pipeline_offset;

-- 23. Autonomy authority state. Effective capability evaluation remains an
-- application/API obligation; SQL only shows both stored authorities and the
-- deterministic kill-switch consequence.
WITH settings AS (
    SELECT * FROM autonomy_settings WHERE id = 'default'
),
latest_control AS (
    SELECT action, reason, actor, sequence, created_at
    FROM research_promotion_controls
    WHERE action IN ('KILL_SWITCH_ACTIVATED', 'KILL_SWITCH_RELEASED')
    ORDER BY sequence DESC
    LIMIT 1
)
SELECT
    coalesce(s.id, 'default') AS settings_id,
    (s.id IS NOT NULL) AS settings_row_present,
    coalesce(s.kill_switch_active, false) AS learning_kill_switch_active,
    lc.action AS research_control_action,
    lc.reason AS research_control_reason,
    lc.sequence AS research_control_sequence,
    lc.created_at AS research_control_at,
    (coalesce(s.kill_switch_active, false)
        OR coalesce(lc.action, '') = 'KILL_SWITCH_ACTIVATED') AS effective_kill_switch_active,
    s.capabilities,
    s.max_active_shadow_experiments,
    s.max_forward_paper_trials,
    s.max_promotions_per_week,
    s.min_shadow_observation_days,
    s.max_allowed_drawdown_percent,
    s.updated_at
FROM (SELECT 1) AS anchor
LEFT JOIN settings s ON true
LEFT JOIN latest_control lc ON true;

-- 23b. Autonomy approval audit coverage, including rejected promotions.
SELECT
    actor,
    action,
    target_type,
    count(*) AS event_count,
    min(created_at) AS earliest_event,
    max(created_at) AS latest_event
FROM autonomy_approval_events
GROUP BY actor, action, target_type
ORDER BY latest_event DESC, action;

-- 24. Candidate-to-backtest ownership linkage. Text comparison avoids casting
-- malformed legacy metadata to uuid and aborting the read-only run.
SELECT
    c.id AS candidate_id,
    c.name,
    c.candidate_version,
    c.status,
    c.search_metadata->>'backtest_run_id' AS claimed_backtest_run_id,
    br.status AS backtest_status,
    br.created_at AS backtest_created_at,
    c.created_at AS candidate_created_at,
    c.metrics
FROM learning_candidate_strategies c
LEFT JOIN backtest_runs br
    ON br.id::text = c.search_metadata->>'backtest_run_id'
WHERE c.created_at >= now() - INTERVAL '90 days'
ORDER BY c.created_at DESC, c.id
LIMIT 100;

-- 25. Read-only replay candidates. Replay with persist=false only; no state
-- mutation is authorized by this evidence handoff.
SELECT
    (SELECT id FROM strategy_observations
      ORDER BY decision_time DESC, id DESC LIMIT 1) AS latest_observation_id,
    (SELECT id FROM signals
      ORDER BY decision_time DESC, id DESC LIMIT 1) AS latest_signal_id;

-- 26. Schema preconditions for the release under acceptance. The funding mark
-- source is additive and nullable; pre-remediation rows may legitimately be NULL.
SELECT
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'market_funding_events'
          AND column_name = 'mark_price_source'
    ) AS funding_mark_source_column_present,
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'equity_snapshots'
          AND column_name = 'valued_through'
    ) AS equity_valued_through_column_present,
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'equity_snapshots'
          AND column_name = 'valuation_fresh'
    ) AS equity_valuation_fresh_column_present;

-- 27. Deployment and migration provenance for the release under acceptance.
SELECT
    max(batch) AS latest_batch,
    count(*) AS applied_migration_count,
    count(*) FILTER (WHERE batch = (SELECT max(batch) FROM migrations)) AS latest_batch_size
FROM migrations;

SELECT migration, batch
FROM migrations
ORDER BY batch DESC, migration DESC
LIMIT 50;

SELECT
    'market_candles' AS evidence_source,
    max(updated_at) AS latest_updated_at,
    max(open_time) AS latest_market_time
FROM market_candles
UNION ALL
SELECT
    'trading_pipeline_events',
    max(updated_at),
    max(candle_close_at)
FROM trading_pipeline_events
UNION ALL
SELECT
    'equity_snapshots',
    max(updated_at),
    max(recorded_at)
FROM equity_snapshots;

-- 28. Drawdown circuit breaker enforcement and position servicing liveness (VEC-P0-004-V2, VEC-P0-127-V2).
-- Inspects accounts at or above 5% entry-block threshold and verifies whether active positions
-- continue to be serviced (exits, fills, settlements) while entries are rejected.
SELECT
    pa.id AS account_id,
    pa.exchange_id,
    pa.primary_strategy_id,
    pa.status,
    pa.current_equity,
    pa.peak_equity,
    pa.current_drawdown_percent,
    (SELECT count(*) FROM risk_decisions rd
      WHERE rd.paper_account_id = pa.id AND rd.allowed = false) AS rejected_entry_decisions_count,
    (SELECT count(*) FROM virtual_positions vp
      WHERE vp.paper_account_id = pa.id AND vp.closed_at IS NULL) AS open_positions_count,
    (SELECT count(*) FROM virtual_positions vp
      WHERE vp.paper_account_id = pa.id AND vp.closed_at IS NOT NULL) AS closed_positions_count,
    (SELECT max(vp.opened_at) FROM virtual_positions vp
      WHERE vp.paper_account_id = pa.id) AS latest_position_opened_at,
    (SELECT max(ve.market_event_at) FROM virtual_executions ve
      JOIN virtual_positions vp ON vp.id = ve.position_id
      WHERE vp.paper_account_id = pa.id) AS latest_execution_at,
    (SELECT max(pfe.funding_at) FROM position_funding_events pfe
      JOIN virtual_positions vp ON vp.id = pfe.position_id
      WHERE vp.paper_account_id = pa.id) AS latest_funding_at
FROM paper_accounts pa
WHERE pa.current_drawdown_percent >= 5.0
ORDER BY pa.current_drawdown_percent DESC;

-- 28b. Drawdown entry-block bypass audit.
-- Verifies whether any position opened after a 5% entry-block decision was recorded.
-- A count of zero proves strict enforcement of entry blocking.
SELECT
    vp.id AS position_id,
    vp.paper_account_id,
    vp.symbol,
    vp.side,
    vp.opened_at,
    vp.closed_at,
    rd.decided_at AS block_decision_at,
    rd.reasons AS block_reasons
FROM virtual_positions vp
JOIN risk_decisions rd
  ON rd.paper_account_id = vp.paper_account_id
 AND rd.allowed = false
 AND rd.reasons::text LIKE '%drawdown%'
 AND vp.opened_at > rd.decided_at
WHERE vp.created_at >= now() - INTERVAL '30 days'
ORDER BY vp.opened_at DESC
LIMIT 10;

-- Conclude with an explicit rollback; no session state survives.
ROLLBACK;
