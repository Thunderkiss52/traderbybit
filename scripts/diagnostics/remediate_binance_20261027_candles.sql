-- ==============================================================================
-- VECTOR: Historical Binance Candle Remediation Script
-- Target: 2026-10-27T00:00:00Z premature closed candle correction
-- Symbols: BTCUSDT (30m, 1h), ETHUSDT (30m, 1h)
--
-- CAUTION: DO NOT EXECUTE AUTOMATICALLY IN CI/CD OR UNATTENDED DEPLOYMENTS.
-- This procedure modifies immutable historical records and requires explicit
-- operator approval after forensic validation against Binance REST archive.
-- ==============================================================================

BEGIN;

-- 1. Create durable pre-remediation backup table if not already created
CREATE TABLE IF NOT EXISTS backup_market_candles_20261027 AS
SELECT *
FROM market_candles
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('30', '60')
  AND open_time = '2026-10-27 00:00:00+00';

-- 2. Inspect current stored values vs official Binance REST values
-- Official Binance USDT-M Futures API (fapi.binance.com/fapi/v1/klines):
-- BTCUSDT 30m: open=80116.00, high=80288.00, low=80097.40, close=80241.30, volume=1404.927, turnover=112693773.11690
-- ETHUSDT 30m: open=2323.46, high=2329.37, low=2321.93, close=2327.64, volume=33035.266, turnover=76847940.03269
-- BTCUSDT 1h:  open=80116.00, high=80364.20, low=80097.40, close=80193.30, volume=2525.695, turnover=202650793.58550
-- ETHUSDT 1h:  open=2323.46, high=2330.79, low=2321.93, close=2326.19, volume=59814.527, turnover=139187033.96072

-- 3. Update BTCUSDT 30m
UPDATE market_candles
SET high = 80288.00,
    close = 80241.30,
    volume = 1404.927,
    turnover = 112693773.11690,
    updated_at = NOW()
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol = 'BTCUSDT'
  AND interval = '30'
  AND open_time = '2026-10-27 00:00:00+00';

-- 4. Update ETHUSDT 30m
UPDATE market_candles
SET high = 2329.37,
    close = 2327.64,
    volume = 33035.266,
    turnover = 76847940.03269,
    updated_at = NOW()
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol = 'ETHUSDT'
  AND interval = '30'
  AND open_time = '2026-10-27 00:00:00+00';

-- 5. Update BTCUSDT 1h (60m)
UPDATE market_candles
SET high = 80364.20,
    close = 80193.30,
    volume = 2525.695,
    turnover = 202650793.58550,
    updated_at = NOW()
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol = 'BTCUSDT'
  AND interval = '60'
  AND open_time = '2026-10-27 00:00:00+00';

-- 6. Update ETHUSDT 1h (60m)
UPDATE market_candles
SET high = 2330.79,
    close = 2326.19,
    volume = 59814.527,
    turnover = 139187033.96072,
    updated_at = NOW()
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol = 'ETHUSDT'
  AND interval = '60'
  AND open_time = '2026-10-27 00:00:00+00';

-- 7. Mark conflict records in market_candle_conflicts as RESOLVED
UPDATE market_candle_conflicts
SET status = 'RESOLVED',
    resolved_at = NOW(),
    updated_at = NOW()
WHERE exchange = 'binance'
  AND category = 'linear'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('30', '60')
  AND open_time = '2026-10-27 00:00:00+00';

-- 8. Final verification query
SELECT symbol, interval, open_time, open, high, low, close, volume, turnover
FROM market_candles
WHERE exchange = 'binance'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('30', '60')
  AND open_time = '2026-10-27 00:00:00+00'
ORDER BY symbol, interval;

-- Operator verification checkpoint: Inspect output before COMMIT.
-- If values match official Binance numbers, commit transaction.
COMMIT;
