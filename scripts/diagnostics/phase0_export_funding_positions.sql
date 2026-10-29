-- =============================================================================
-- VECTOR PHASE 0: Machine-Readable Export of Unresolved Closed Positions
-- Outputs a single strictly valid JSON array of position objects for offline classification.
--
-- Invocation (pure JSON output):
--   psql -X -A -t -v ON_ERROR_STOP=1 \
--     -U "${POSTGRES_USER:-bybit_signals}" \
--     -d "${POSTGRES_DB:-bybit_signals}" \
--     -f scripts/diagnostics/phase0_export_funding_positions.sql \
--     > "$ARTIFACT_DIR/funding_positions.json"
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';
SET LOCAL idle_in_transaction_session_timeout = '60s';

SELECT coalesce(jsonb_pretty(jsonb_agg(pos)), '[]')
FROM (
    SELECT
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
        coalesce((SELECT sum(f.amount) FROM position_funding_events f WHERE f.position_id = p.id), 0) AS settled_funding,
        coalesce((SELECT jsonb_agg(jsonb_build_object(
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
            AND m.funding_at <= p.closed_at), '[]'::jsonb) AS market_events,
        coalesce((SELECT jsonb_agg(jsonb_build_object(
                    'at', e.market_event_at,
                    'type', e.type,
                    'quantity', e.quantity
                ) ORDER BY e.market_event_at, e.id)
           FROM virtual_executions e
          WHERE e.position_id = p.id), '[]'::jsonb) AS executions,
        coalesce((SELECT jsonb_agg(to_jsonb(f) ORDER BY f.funding_at, f.id)
           FROM position_funding_events f
          WHERE f.position_id = p.id), '[]'::jsonb) AS settlements
    FROM virtual_positions p
    WHERE p.closed_at IS NOT NULL
      AND p.funding_complete = false
    ORDER BY p.exchange_id, p.opened_at, p.id
) pos;

ROLLBACK;
