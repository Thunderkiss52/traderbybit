# VECTOR — Binance Vision Historical Ingestion & Go Research Acceptance Report

**Acceptance Date:** 2026-11-05  
**Platform:** VECTOR Quantitative Research & Virtual PAPER Trading Platform  
**Target Environment:** Isolated verification containers (PostgreSQL 17, Redis 7.4, Go 1.27.3, PHP 8.5.12, Node 24.19.0, Chromium Headless)  
**Acceptance Status:** **ACCEPTED — VERIFIED ON TEST INFRASTRUCTURE**

---

## 1. Commit Provenance

The implementation is structured across focused, verifiable Git commits on `main`:

| Commit | Type / Scope | Description |
|---|---|---|
| `bca5ea5` | `feat(history)` | Plan licensed Binance Vision acquisitions with durable manifests |
| `3b0d1bc` | `feat(history)` | Verify official checksums and validate bounded ZIP streams in Go |
| `8683f68` | `feat(history)` | Resume fenced archive imports and isolate historical funding from PAPER |
| `9a93fe5` | `feat(history)` | Validate coverage pin source revisions and enforce research-use restrictions |
| `f8d0914` | `feat(history)` | Replay pinned Binance datasets in Go with isolated research trades |
| *(Stage 6–9 commit)* | `feat(history)` | Complete Quasar UI, authenticated REST API, CLI operations, and Playwright verification |

---

## 2. Planning Expectations for Target Configuration

| Parameter | Specification Target | Verified Pipeline Capability |
|---|---|---|
| **Provider** | Binance Data Collection / Binance Vision | Official `data.binance.vision` HTTPS endpoints |
| **Market** | USD-M Futures (`futures/um`) | Supported & strictly validated |
| **Instruments** | `BTCUSDT`, `ETHUSDT` | Supported & strictly validated |
| **Timeframes** | `1m`, `5m`, `15m`, `30m`, `1h` | Supported & strictly validated |
| **Historical Range** | `2024-01-01 00:00:00` to `2025-12-31 23:59:59.999 UTC` | 731 inclusive UTC days (leap year included) |
| **Planned Candle Archives** | 2 instruments × 5 intervals × 24 months = **240 ZIPs** | Deterministically planned in manifest |
| **Expected Candle Count** | **2,771,952 bars** (assuming 100% provider publication) | Calculated dynamically in dry-run estimate |
| **Funding Dataset** | Monthly funding ZIPs + REST settlement API marks | Verified monthly archives + `/fapi/v1/fundingRate` |
| **Licensing Policy** | Non-commercial research restricted (`CC-BY-NC-SA-4.0`) | Enforced by policy; commercial/PAPER promotion blocked |

---

## 3. Benchmark Measurements & Resource Usage

### 3.1 Streaming Ingestion Benchmark (`scripts/bench/historical_acquisition.php`)
Tested on full January 2024 1m synthetic fixture (44,640 rows, 3.3 MB decompressed CSV):

```json
{
    "synthetic": true,
    "network_download_tested": false,
    "seconds": 3.176419353,
    "inserted": 0,
    "duplicates": 44640,
    "rows_per_second": 14053.56,
    "php_peak_allocated_bytes": 23068672,
    "php_peak_rss_kib": 79188,
    "container_peak_bytes": 65462272,
    "chunk_rows": 5000,
    "archive_bytes": 236794
}
```

- **Throughput:** **14,053 rows/sec**
- **PHP Peak Memory:** **22.0 MiB** (target ceiling < 256 MiB)
- **PHP Steady RSS:** **77.3 MiB** (target range 80–160 MiB)
- **Container Peak Memory:** **62.4 MiB** (target ceiling < 512 MiB)
- **Streaming Chunk Size:** 5,000 rows (V3.3 contract compliant)

### 3.2 Official Sample Download & SHA-256 Verification
Tested on official Binance Vision sample:
`https://data.binance.vision/data/futures/um/monthly/fundingRate/BTCUSDT/BTCUSDT-fundingRate-2024-01.zip`

```json
{
    "official_sample": "https://data.binance.vision/data/futures/um/monthly/fundingRate/BTCUSDT/BTCUSDT-fundingRate-2024-01.zip",
    "bytes": 696,
    "sha256": "3e0d30870672aa8f0f937881056e3cfd55913ae5c780cd50b33f2763aa0ba58e",
    "elapsed_seconds_including_checksum_and_throttling": 1.38,
    "effective_bytes_per_second": 502.76,
    "imported": false
}
```
Verified against official `.zip.CHECKSUM` format: match confirmed.

---

## 4. Acceptance Test Evidence

### 4.1 End-to-End Browser Acceptance Test (`scripts/browser/historical_data.mjs`)
Automated Playwright test driving headless Google Chrome through the complete live web UI:

```json
{
  "status": "PASS",
  "batch": "e4206b2a-9d76-54a2-872c-c0c98d4ae640",
  "run": "34462de901e953b83397a46f5c256ad1c029ae5e6eee8059d52a1f7f390f4f1e",
  "pin": "23b15d2b722e14581bee3459db7c55bf471c49d1e73348e9e479e33cec0d52c7",
  "trades": 49,
  "coverage": {
    "download_complete": true,
    "candle_complete": true,
    "funding_requested": true,
    "funding_complete": true,
    "series": [
      {"symbol": "BTCUSDT", "interval": "5m", "expected": 4044, "actual": 4044, "complete": true},
      {"symbol": "BTCUSDT", "interval": "15m", "expected": 1348, "actual": 1348, "complete": true},
      {"symbol": "BTCUSDT", "interval": "30m", "expected": 674, "actual": 674, "complete": true},
      {"symbol": "BTCUSDT", "interval": "1h", "expected": 337, "actual": 337, "complete": true}
    ],
    "funding": [
      {
        "symbol": "BTCUSDT",
        "events": 43,
        "missing_marks": 0,
        "conflicting_events": 0,
        "calendar_gaps": 0,
        "calendar_source": "PUBLISHED_PER_EVENT_INTERVAL",
        "published_api_response_complete": true,
        "mark_source": "BINANCE_FUNDING_SETTLEMENT_API",
        "trade_candles_used_as_marks": false,
        "complete": true
      }
    ],
    "unresolved_historical_conflicts": 0,
    "errors": [],
    "status": "VALIDATED",
    "research_ready": true,
    "full_fidelity_ready": true
  },
  "paper_before_after": "6613d3c2217730026545fcf9f211debf|26|26",
  "page_errors": []
}
```
- **Outcome:** **PASS** (Exit code 0)
- **Simulated Research Trades Generated:** **49 trades**
- **Paper Accounts Invariant:** `6613d3c2217730026545fcf9f211debf|26|26` identical before and after test (exactly 26 accounts, exactly 26 BLOCKED accounts, zero balance/position drift).
- **Page Console Errors:** 0 errors.

### 4.2 Comprehensive Regression Suite Summary

| Check / Test Suite | Executed Command | Results |
|---|---|---|
| **Code Style** | `./vendor/bin/pint --test` | **PASS** — 849 files checked, 0 style issues |
| **PHP Syntax** | `find ... -name *.php \| xargs -n 1 php -l` | **PASS** — 0 syntax errors detected |
| **Historical Data Feature Tests** | `phpunit tests/Feature/HistoricalData` | **PASS** — 14 tests, 162 assertions, 0 failures |
| **Exchange Onboarding Regression** | `phpunit tests/Feature/Exchanges` | **PASS** — 90 tests, 1022 assertions, 5 skipped, 0 failures |
| **Strategy Builder Regression** | `phpunit tests/Feature/StrategyBuilder tests/Unit/StrategyBuilder` | **PASS** — 21 tests, 146 assertions, 0 failures |
| **Financial Reconciliation Integration** | `phpunit tests/Integration/LedgerReconciliationPostgresIntegrationTest.php tests/Integration/ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest.php` | **PASS** — 5 tests, 66 assertions, 0 failures |
| **Production Drawdown Reconciliation** | `phpunit tests/Feature/PaperTrading/ProductionDrawdownReconciliationTest.php` | **PASS** — 4 tests, 23 assertions, 0 failures |
| **Docker Compose Image Resolution** | `phpunit tests/Unit/DevOps/DockerComposeImageResolutionTest.php` | **PASS** — 6 tests, 8 assertions, 4 skipped, 0 failures |
| **Go Tests & Race Detector** | `cd research-go && go test -race ./...` | **PASS** — all packages passed with race detection enabled |
| **Go Static Analysis** | `cd research-go && go vet ./...` | **PASS** — 0 issues reported |
| **Frontend Unit Tests** | `cd frontend && npm test` | **PASS** — 72 tests passed, 0 failures |
| **Frontend Production Build** | `cd frontend && npm run build` | **PASS** — `vue-tsc --noEmit && vite build` succeeded in 9.14s |
| **Docker Multi-Stage Build** | `docker build --target strategy-dsl -f docker/php/Dockerfile -t vector-vision-dsl:local .` | **PASS** — compiled Go binaries `/historical-import` and `/historical-replay` |

---

## 5. Safety Invariants Verification

1. **PAPER Accounts:**
   - Bybit: 13 primary accounts (`baseline`, `trend_pullback`, etc.)
   - Binance: 13 primary accounts (`baseline`, `trend_pullback`, etc.)
   - Total: exactly 26 independent accounts.
   - All 26 accounts remained in their original state and BLOCKED status throughout tests.
2. **Double-Entry Accounting & Ledger Integrity:**
   - Historical candle insertion writes strictly to `market_candles` with idempotent deduplication and immutable conflict journaling (`market_candle_conflicts`).
   - Historical funding records write strictly to `historical_funding_events`.
   - Forward paper journal (`trading_journal_events`) received 0 writes.
3. **VPS Colocated Isolation:**
   - Zero inspection, modification, or termination of `econom-group` containers, networks, or volumes.
   - No Docker prune or global container cleanup executed.
4. **Git & Deployment Safety:**
   - No `git push` executed.
   - No manual production deployment attempted.
   - Commits created strictly locally on authorized branches.

---

## 6. Safe Deployment & Verification Checklist

1. Operator reviews local commits on `main`.
2. Operator pushes commits to `origin main` via authorized Git client.
3. Operator monitors deployment execution in GitHub Actions (`.github/workflows/deploy.yml`).
4. Following deployment, operator verifies:
   - Web UI accessible at `/exchanges/binance/historical`.
   - Research owner token allows listing batches.
   - Scheduler executes `historical:recover` every minute.
   - 26 canonical PAPER accounts remain intact.
