-- Read-only evidence for historical failed Binance 15m decision candles.
-- PAPER_PROCESSED stores timeframe='5'; NEVER join by timeframe='15'.
-- This reports evidence, not permission to retroactively execute trades.
BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '20s';
SET LOCAL lock_timeout = '5s';

WITH failed AS (
    SELECT id, exchange, symbol, timeframe, candle_close_at, created_at,
           attempts, last_error
    FROM trading_pipeline_events
    WHERE exchange = 'binance'
      AND stage = 'CANDLE_CLOSED'
      AND status = 'FAILED'
      AND timeframe = '15'
), lineage AS (
    SELECT f.*,
           EXISTS (
               SELECT 1 FROM trading_pipeline_events e
               WHERE e.exchange = f.exchange
                 AND e.symbol = f.symbol
                 AND e.candle_close_at = f.candle_close_at
                 AND e.stage = 'CANDLE_CLOSED'
                 AND e.status = 'COMPLETED'
                 AND e.id != f.id
           ) AS sibling_candle_completed,
           EXISTS (
               SELECT 1 FROM trading_pipeline_events e
               WHERE e.exchange = f.exchange
                 AND e.symbol = f.symbol
                 AND e.candle_close_at = f.candle_close_at
                 AND e.stage = 'SIGNALS_EVALUATED'
                 AND e.timeframe = '15'
                 AND e.status = 'COMPLETED'
           ) AS signals_completed,
           EXISTS (
               SELECT 1 FROM trading_pipeline_events e
               WHERE e.exchange = f.exchange
                 AND e.symbol = f.symbol
                 AND e.candle_close_at = f.candle_close_at
                 AND e.stage = 'PAPER_PROCESSED'
                 AND e.status = 'COMPLETED'
           ) AS paper_completed,
           EXISTS (
               SELECT 1 FROM trading_pipeline_events e
               WHERE e.exchange = f.exchange
                 AND e.symbol = f.symbol
                 AND e.candle_close_at = f.candle_close_at
                 AND e.stage = 'PAPER_PROCESSED'
                 AND e.status IN ('PARTIAL', 'FAILED')
           ) AS paper_incomplete
    FROM failed f
)
SELECT symbol, timeframe,
       COUNT(*) AS failed_candles,
       COUNT(*) FILTER (WHERE sibling_candle_completed) AS sibling_candle_completed_same_slot,
       COUNT(*) FILTER (WHERE signals_completed) AS signals_completed_same_slot,
       COUNT(*) FILTER (WHERE paper_completed) AS paper_completed_same_slot,
       COUNT(*) FILTER (WHERE paper_incomplete) AS paper_incomplete_same_slot,
       COUNT(*) FILTER (WHERE NOT sibling_candle_completed AND NOT signals_completed
                          AND NOT paper_completed AND NOT paper_incomplete) AS genuinely_missed_slots,
       MAX(candle_close_at) AS latest_failed_slot
FROM lineage
GROUP BY symbol, timeframe
ORDER BY symbol, timeframe;

ROLLBACK;
