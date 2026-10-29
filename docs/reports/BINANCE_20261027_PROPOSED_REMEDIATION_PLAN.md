# VECTOR Proposed Historical Remediation Plan: Binance Candles (2026-10-27T00:00:00Z)

**Document Version:** 1.0.0
**Target Date:** 2026-10-29
**Target Environment:** Production PostgreSQL (Database: `traderbybit` / Container: `postgres-vector`)
**Approval Required:** Operator / Project Owner Authorization Required
**Execution Script:** [`scripts/diagnostics/remediate_binance_20261027_candles.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/remediate_binance_20261027_candles.sql)

**Final-gate qualification (2026-10-29):** This is a prepared proposal, not an
approved execution procedure. The production identity and historical values below
were not revalidated in the [pre-push gate](P1_PRE_PUSH_RELEASE_GATE_20261029.md).
The SQL ends with an unconditional `COMMIT`; its verification comment does not
pause batch execution for operator inspection. Review transaction controls,
expected row counts, backup contents, source provenance and rollback scope under
separate operator authorization before any execution. The script was not run.

---

## 1. Safety Notice & Operational Policy

> [!CAUTION]
> **NO AUTOMATIC REMEDIATION IN RUNTIME OR DEPLOYMENT**
> Per VECTOR Project Architecture rules:
> - Application code, background workers, and automated migrations **MUST NEVER** overwrite historical financial records or immutable market candles automatically.
> - Historical repair must occur via explicit, operator-supervised transactional execution.
> - No remote Git push or deployment is permitted.

---

## 2. Remediation Scope

Four specific rows in table `market_candles` require correction to match official Binance USD(S)-M Futures historical klines:
1. `exchange = 'binance'`, `category = 'linear'`, `symbol = 'BTCUSDT'`, `interval = '30'`, `open_time = '2026-10-27 00:00:00+00'`
2. `exchange = 'binance'`, `category = 'linear'`, `symbol = 'ETHUSDT'`, `interval = '30'`, `open_time = '2026-10-27 00:00:00+00'`
3. `exchange = 'binance'`, `category = 'linear'`, `symbol = 'BTCUSDT'`, `interval = '60'`, `open_time = '2026-10-27 00:00:00+00'`
4. `exchange = 'binance'`, `category = 'linear'`, `symbol = 'ETHUSDT'`, `interval = '60'`, `open_time = '2026-10-27 00:00:00+00'`

Additionally, corresponding records in `market_candle_conflicts` will be updated to:
- `status = 'RESOLVED'`
- `resolved_at = NOW()`

---

## 3. Step-by-Step Operator Execution Procedure

### Step 1: Pre-Execution Verification
Verify database connection and confirm current stored values before touching any row:
```bash
docker exec -i postgres-vector psql -U traderbybit -d traderbybit -c "
SELECT symbol, interval, open_time, open, high, low, close, volume, turnover
FROM market_candles
WHERE exchange = 'binance'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('30', '60')
  AND open_time = '2026-10-27 00:00:00+00';
"
```

### Step 2: Execute Remediation Script
Run the prepared script in transactional mode:
```bash
docker exec -i postgres-vector psql -U traderbybit -d traderbybit < scripts/diagnostics/remediate_binance_20261027_candles.sql
```

The script performs:
1. `CREATE TABLE IF NOT EXISTS backup_market_candles_20261027 AS SELECT ...` (Guarantees zero-data-loss rollback capability).
2. Updates `high`, `close`, `volume`, and `turnover` on the 4 targeted rows.
3. Marks conflict records as `RESOLVED`.
4. Outputs verification table.
5. Commits transaction.

### Step 3: Post-Execution Verification
1. Confirm updated rows match official Binance numbers:
   - BTCUSDT 30m: `high=80288.00, close=80241.30, volume=1404.927, turnover=112693773.11690`
   - ETHUSDT 30m: `high=2329.37, close=2327.64, volume=33035.266, turnover=76847940.03269`
   - BTCUSDT 1h: `high=80364.20, close=80193.30, volume=2525.695, turnover=202650793.58550`
   - ETHUSDT 1h: `high=2330.79, close=2326.19, volume=59814.527, turnover=139187033.96072`
2. Check Research Health API:
   Verify `/api/v1/research/health/data-freshness` reports `status: 'HEALTHY'` or `status: 'WARNING'` (if queue lag), with 0 active conflicts (`unresolved_conflicts_count = 0`).

---

## 4. Rollback Procedure (If Needed)

If any discrepancy occurs, execute immediate rollback from the backup table:
```sql
BEGIN;
UPDATE market_candles c
SET high = b.high,
    close = b.close,
    volume = b.volume,
    turnover = b.turnover,
    updated_at = NOW()
FROM backup_market_candles_20261027 b
WHERE c.exchange = b.exchange
  AND c.symbol = b.symbol
  AND c.interval = b.interval
  AND c.open_time = b.open_time;

UPDATE market_candle_conflicts
SET status = 'UNRESOLVED',
    resolved_at = NULL
WHERE exchange = 'binance'
  AND symbol IN ('BTCUSDT', 'ETHUSDT')
  AND interval IN ('30', '60')
  AND open_time = '2026-10-27 00:00:00+00';
COMMIT;
```
