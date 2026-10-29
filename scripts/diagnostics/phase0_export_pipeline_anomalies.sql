-- =============================================================================
-- VECTOR PHASE 0: Machine-Readable Export of Pipeline Anomalies (STALE, PARTIAL, FAILED)
-- Outputs a single strictly valid JSON array of pipeline event objects.
--
-- Invocation (pure JSON output):
--   psql -X -A -t -v ON_ERROR_STOP=1 \
--     -U "${POSTGRES_USER:-bybit_signals}" \
--     -d "${POSTGRES_DB:-bybit_signals}" \
--     -f scripts/diagnostics/phase0_export_pipeline_anomalies.sql \
--     > "$ARTIFACT_DIR/pipeline_anomalies.json"
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';
SET LOCAL idle_in_transaction_session_timeout = '60s';

SELECT coalesce(jsonb_pretty(jsonb_agg(ev)), '[]')
FROM (
    SELECT
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
) ev;

ROLLBACK;
