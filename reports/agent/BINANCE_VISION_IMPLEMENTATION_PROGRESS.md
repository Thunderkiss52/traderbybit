# VECTOR — Binance Vision Implementation Progress & Stage Delivery

**Date:** 2026-11-05  
**Repository Working Directory:** `/home/thunder/work/traderbybit`  
**Base Ref:** `origin/main` (`129c33c`)  
**Primary Integration Commits:**
- `bca5ea5`: `feat(history): plan licensed Binance Vision acquisitions with durable manifests`
- `3b0d1bc`: `feat(history): verify official checksums and validate bounded ZIP streams in Go`
- `8683f68`: `feat(history): resume fenced archive imports and isolate historical funding from PAPER`
- `9a93fe5`: `feat(history): validate coverage pin source revisions and enforce research-use restrictions`
- `f8d0914`: `feat(history): replay pinned Binance datasets in Go with isolated research trades`

---

## 1. Stage-by-Stage Implementation Breakdown

### Stage 1: Manifest Planning and Licensing Policy
- **Commit:** `bca5ea5`
- **Scope:**
  - Implemented `DownloadPlanner` calculating monthly and daily archive manifests for Binance USD-M Futures.
  - Implemented `SourcePolicy` recording provider terms (`CC-BY-NC-SA-4.0`, `VISION-1.0-2026-08-26`) and restricting imported datasets to non-commercial historical research.
  - Database schema: `2026_11_05_000002_create_historical_acquisition_tables.php` defining `historical_download_batches`, `historical_download_entries`, and `historical_acquisition_events`.
  - Planning tests: `tests/Feature/HistoricalData/PlannerTest.php` asserting exact 240 kline archives for BTCUSDT + ETHUSDT 2024–2025 across 5 timeframes (1m, 5m, 15m, 30m, 1h), leap year February handling, and resource estimates.

### Stage 2: Official Checksum Verification & Go Archive Ingestion
- **Commit:** `3b0d1bc`
- **Scope:**
  - Implemented `ArchiveDownloader` fetching archive and companion `.CHECKSUM` file, streaming to isolated temporary directory.
  - Incrementally calculates SHA-256 and compares with official checksum.
  - Go archive validator (`research-go/internal/dataset/importer/archive.go`) enforcing V3.3 safety bounds: decompressed bytes ≤ 2.0 GB, single entry ≤ 1.5 GB, entries ≤ 100, decompression timeout ≤ 120s.
  - Strict security rejection for zip-slip, nested archives, encrypted files, and directory traversal.
  - Go CLI tool: `cmd/historical-import` for streaming CSV parsing.
  - Tests: `DownloaderTest.php` and `archive_test.go`.

### Stage 3: Resumable Pipeline & Historical Funding Ingestion
- **Commit:** `8683f68`
- **Scope:**
  - Implemented `AcquisitionWorker` with pessimistic row locking (`lease_owner`, `lease_until`), exponential backoff with jitter on network retries, and atomic state transitions.
  - Implemented `FundingFetcher` retrieving monthly funding archives and falling back to the official read-only `/fapi/v1/fundingRate` API endpoint with pagination and rate-limit backoff.
  - Database schema: `2026_11_05_000003_create_historical_funding_and_marks.php` storing `historical_funding_events` and mark prices.
  - Zero cross-contamination with live/paper funding ledgers.
  - Tests: `AcquisitionTest.php` verifying retry recovery, deduplication, and funding sign/precision preservation.

### Stage 4: Coverage Validation & Immutable Dataset Pinning
- **Commit:** `9a93fe5`
- **Scope:**
  - Implemented `CoverageValidator` inspecting series completeness, expected vs actual bars, data gaps, funding event coverage, and unresolved candle conflicts.
  - Implemented `AcquisitionDataset` creating deterministic, immutable dataset pins (`acquisition_dataset_pins`).
  - Strict zero-silent-conflict policy: any contradictory bar creates immutable conflict audit records in `market_candle_conflicts`.
  - Enforced licensing restrictions preventing export or promotion into live/paper execution.
  - Tests: `CoverageValidatorTest.php` and `DatasetPinTest.php`.

### Stage 5: Go Replay Engine & Isolated Simulated Research Trades
- **Commit:** `f8d0914`
- **Scope:**
  - Implemented `ResearchWorker` and `ResearchService` managing historical replay jobs.
  - Go replay engine (`research-go/cmd/historical-replay` and `research-go/internal/historical/replay.go`):
    - Closed-candle-only signal generation.
    - Zero lookahead bias.
    - Multi-timeframe alignment across 5m, 15m, 30m, 1h.
    - Accurate funding application at historical settlement timestamps.
    - Conservative stop-first order execution modeling with fees and slippage.
  - Database schema: `2026_11_05_000005_create_historical_research_runs.php` recording `historical_research_runs`, `historical_research_trades`, `historical_research_equity`, and `historical_research_executions`.
  - Absolute isolation from authoritative PAPER accounts and journals.
  - Tests: `ResearchTest.php` and `replay_test.go`.

### Stage 6: Authenticated REST API & Owner Web UI
- **Scope:**
  - REST Controller: `HistoricalController` providing endpoints for preview, creation, control (start/pause/resume/cancel/retry), coverage audit, strategy catalog, research runs, artifacts (trades/equity/executions), and partition exports (`train`, `validation`, `holdout`).
  - Quasar UI: `frontend/src/pages/HistoricalDataPage.vue` mounted at `/exchanges/binance/historical` with navigation button in `ExchangesPage.vue`.
  - Frontend TypeScript client: `frontend/src/services/historical.ts`.
  - Tests: `tests/Feature/HistoricalData/ApiTest.php` (45 assertions) verifying owner token authentication, unauthenticated rejection, idempotency headers, durable audit logging, and lack of filesystem path leaks.

### Stage 7: Operational CLI & Background Recovery
- **Scope:**
  - Artisan command: `historical:data` supporting actions `plan`, `create`, `status`, `start`, `pause`, `resume`, `cancel`, `retry`, `validate`, `verify`, `recover`, `research`, and `run`.
  - Background Recovery: `Recovery` dispatched via Laravel Console scheduler every minute to heal stalled worker leases without duplicate processing.
  - Dockerfile build: Updated `docker/php/Dockerfile` multi-stage build compiling `/historical-import` and `/historical-replay` binaries.

### Stage 8: Acceptance Verification & System Integrity
- **Scope:**
  - Playwright browser test (`scripts/browser/historical_data.mjs`) driving headless Chromium through the complete UI flow: preview plan, save batch, acknowledge terms, start background acquisition, pause/resume, refresh, validate coverage, select custom strategy, run Go historical backtest, and inspect resulting trades and equity.
  - Automated verification that 26 PAPER accounts (13 Bybit + 13 Binance) and all BLOCKED statuses remain untouched (`6613d3c2217730026545fcf9f211debf|26|26` identical before and after test).
  - Throughput and resource benchmarks on synthetic January fixture (44,640 rows at 14,053 rows/sec, 22 MiB memory).
  - Full regression test suites passing across PHP, Go, and Frontend.
