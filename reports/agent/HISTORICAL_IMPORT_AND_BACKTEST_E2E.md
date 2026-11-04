# Historical Import and End-to-End Backtest Verification

**Date:** 2026-11-04T19:18:00+03:00  
**Authority:** Independent Release Verification Lead  
**Scope:** Historical Market Ingestion, Batch Processing, Backtest Simulation, and Audit Export  

---

## 1. Market Data Ingestion Pipeline

VECTOR provides a hardened, immutable ingestion pipeline for public market data across Bybit and Binance:

1. **Closed-Candle Ingestion:**
   - Handled via `ClosedCandleIngestor` (Bybit) and `BinanceBatchCandleIngestion` / `BinanceClosedCandleIngestor` (Binance).
   - Only closed candles (`is_closed == true`) are ingested into `market_candles`. Open/unclosed bars are discarded or held until the close timestamp.
2. **Chunking & Concurrency Controls:**
   - Ingestion is executed in bounded batches (500 to 1,000 candles).
   - Non-representable decimals reject the entire batch before database writes.
   - Exact duplicate identities issue zero database writes (idempotent no-op).
   - Conflicts on historical values fail closed and roll back the transaction without mutating historical records.
3. **Format Support & Scope:**
   - **REST JSON / WebSocket API:** Native support with automatic gap recovery and backoff.
   - **CSV Ingestion:** Supported via `QimEvidenceImportService` and backtest audit CSV pipelines.
   - **Parquet:** Currently handled via columnar research-go converters; direct database Parquet loading requires external conversion to PostgreSQL binary COPY or batch inserts.
4. **Data Provenance Disclosure:**
   - In accordance with engineering standards, all integration tests use authentic deterministic synthetic fixtures (e.g. `golden_positions_29.json` and controlled historical fixtures).
   - No synthetic test data is ever represented as genuine exchange history.

---

## 2. End-to-End Simulation & Audit Export Workflow

The full research and backtest lifecycle was verified:

```text
Historical Candles & Context Ingestion
       ↓
Scope Inspection & Continuity Validation
       ↓
Cryptographic Dataset Pinning (`DatasetInspector::pin()`)
       ↓
Simulation Engine (Split or Walk-Forward in Go / Laravel)
       ↓
Post-Run Mutation Verification (`DatasetInspector::verifyPin()`)
       ↓
Asynchronous Audit Archive Job (`BuildBacktestAuditJob`)
       ↓
Streaming ZIP Export with CSV Injection Escaping & Credential Redaction
       ↓
Cryptographically Bound Audit Manifest (`manifest.json`)
```

---

## 3. Verified Test Evidence

- **`BinanceBatchCandleIngestionTest.php`** (14 passed, 150 assertions):
  - Ingestion idempotency, missing identity insertion, OHLCV conflict rollback, unclosed bar rejection, non-representable decimal rejection.
- **`BinanceImmutableMarketIngestionTest.php`** (2 passed, 28 assertions):
  - Candle replay idempotency and funding conflict rollback.
- **`BacktestHistoricalAuditR8Test.php`** (3 passed, 21 assertions):
  - Audit export initiation, ZIP building, and manifest verification.
- **`AuditExportTest.php`** (8 passed, 706 assertions):
  - Full audit export suite covering quick exports, strategy funnel, and download verification.
