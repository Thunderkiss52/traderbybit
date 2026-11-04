# VECTOR Historical Dataset Reproducibility & Pinning Report

**Date:** 2026-11-04T18:38:00+03:00  
**Verification Target:** Work Unit F (Immutable Dataset Pinning) & Work Unit G/H (Backtest Audit Export Subsystem)  
**Authoritative Engine:** PostgreSQL 17, `DatasetInspector`, `BacktestAuditService`  
**Git Commits:** `a1b7232`, `4ac1044`, `407dc34`  

---

## 1. Problem Definition & Architectural Solution

**Previous Defect (High):** Historical simulations and audit exports detected mutations via before/after checksums, but lacked a retained immutable dataset row or permanent version pin in the database.

**Implemented Architecture:**
1. **Schema Migration:** Created `historical_dataset_pins` (`2026_11_04_000003_create_historical_dataset_pins_table.php`) with:
   - Unique `scope_fingerprint`: SHA-256 hash of `exchange | category | symbols | from | to | warmup_bars`.
   - `checksum`: SHA-256 fingerprint over all consumed 5m, 15m, 30m, and 60m closed candles, pre-window context, in-window market context snapshots, and funding settlements.
   - `series_counts`: exact count of closed bars verified per symbol and timeframe.
   - `pinned_at`: immutable UTC timestamp.
2. **Deterministic Pinning API:**
   - `DatasetInspector::pin()`: inspects data scope, creates/retrieves pin record. If a pin already exists with different data, raises `Historical dataset conflict: dataset content differs from previously pinned version`.
   - `DatasetInspector::verifyPin()`: re-inspects data and verifies against the pinned checksum.
3. **Backtest Integration:**
   - `BacktestRunService::run()` pins the dataset prior to simulation.
   - Verifies the pin before marking the backtest `COMPLETED`.
   - If concurrent mutations occur during simulation (e.g. database trigger modifying candle records), the verification throws `Historical inputs changed during backtest.` and marks the run `FAILED`.

---

## 2. Test Verification & Evidence

- **`DatasetInspectorTest` (14 passed, 37 assertions):**
  - Deterministic checksum computation across complete closed history.
  - Multi-timeframe hierarchy verification (5m, 15m, 30m, 60m).
  - Exchange isolation (Binance cannot consume Bybit history).
  - Subsecond timestamp precision preservation.
  - Idempotent dataset pinning: repeated calls return `already_pinned => true` with identical ID and checksum.
  - Conflict rejection: mutating a candle in a pinned scope triggers immediate rejection in both `verifyPin()` and subsequent `pin()` attempts.

- **`BacktestHistoricalAuditR8Test` (3 passed):**
  - Idempotent queuing of ZIP archive generation via `BuildBacktestAuditJob`.
  - Non-completed backtests rejected with HTTP 422.
  - Archive builder generates private ZIP containing:
    - `manifest.json` (with `VECTOR_BACKTEST_AUDIT_R8_1` schema version and dataset checksum verification)
    - `trades.csv`
    - `segments.csv`
    - `candles.csv`
    - `market_context.csv`
    - `funding.csv`
  - Signed download streaming verified via relative signed URL middleware (`signed:relative`).
  - Escaping against spreadsheet formula injection (`BacktestAuditCsv`).
  - Recursive credential redaction (`BacktestAuditRedactor`).
