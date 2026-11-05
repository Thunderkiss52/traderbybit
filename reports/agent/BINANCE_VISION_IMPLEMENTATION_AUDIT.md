# VECTOR — Binance Vision Historical Acquisition Implementation Audit

**Audit Date:** 2026-11-05  
**Repository Working Directory:** `/home/thunder/work/traderbybit`  
**Target Environment:** Isolated testing infrastructure (PostgreSQL 17, Redis 7.4, Go 1.27.3, PHP 8.5.12, Node 24.19.0, Chromium Headless)  
**Safety Scope:** PAPER & historical research only. Zero live trading credentials, zero exchange order endpoints, 26 primary accounts preserved byte-for-byte.

---

## 1. Executive Summary

This audit evaluates the complete implementation of the **Binance Vision Historical Data Downloader, Funding Ingestion, and Go-First Research Integration** in VECTOR.

The implementation enables the owner to:
1. Select target instruments (BTCUSDT, ETHUSDT), timeframes (1m, 5m, 15m, 30m, 1h), and UTC date ranges (defaulting to 2024-01-01 through 2025-12-31 UTC, covering 731 UTC days) through the VECTOR web UI.
2. Review deterministic dry-run estimates (240 monthly candlestick ZIP packages, ~2,771,952 candles, funding archives + settlement marks, resource and storage forecasts) with zero initial database mutations or downloads.
3. Review and acknowledge the non-commercial research licensing terms (CC-BY-NC-SA-4.0, VISION-1.0-2026-08-26) with required owner acknowledgement reference.
4. Execute background acquisition with bounded concurrency (defaulting to 1 archive), streaming HTTP downloads, official SHA-256 checksum verification, safe ZIP validation (rejection of zip-slip, nested archives, archive bombs, and unexpected extensions), bounded streaming Go CSV parsing (chunk size 5,000 rows), and idempotent PostgreSQL ingestion.
5. Ingest official historical funding rate observations with exact settlement timestamps, decimal precision, sign conventions, and source provenance, isolated from forward PAPER ledgers.
6. Validate data completeness, missing bars, and data conflicts, producing immutable dataset pins with zero unresolved conflicts.
7. Execute deterministic Go backtests and historical strategy replays across registered strategies without lookahead bias, generating isolated historical research trades, executions, and equity curves.
8. Retain and safeguard all 26 existing PAPER trading accounts (13 Bybit + 13 Binance) in their authoritative states with zero mutations.

---

## 2. Requirement Completion Matrix (Scenarios A – AL)

| Scenario | Requirement Description | Status | Implementation Source | Test / Verification Evidence |
|---|---|---|---|---|
| **A** | Generate exact monthly candle manifest (2 symbols × 5 intervals × 24 months = 240 ZIPs) | **COMPLETE** | `app/HistoricalData/DownloadPlanner.php` | `tests/Feature/HistoricalData/PlannerTest.php::test_preset_manifest_generates_exact_canonical_entries` |
| **B** | Verify date boundary behavior, including 2024 leap day | **COMPLETE** | `app/HistoricalData/DownloadPlanner.php` | `tests/Feature/HistoricalData/PlannerTest.php::test_leap_year_february_is_included_in_expected_candle_counts` |
| **C** | Reject unsupported symbols, markets, intervals, and unsafe URLs | **COMPLETE** | `app/HistoricalData/DownloadPlanner.php`, `OfficialTransport.php` | `PlannerTest.php::test_unsupported_inputs_are_rejected`, `DownloaderTest.php::test_only_official_strict_https_source_paths_are_allowed` |
| **D** | Distinguish unavailable archives from download failures | **COMPLETE** | `app/HistoricalData/AcquisitionWorker.php` | `tests/Feature/HistoricalData/AcquisitionTest.php::test_missing_optional_archive_is_marked_unavailable` |
| **E** | Download fixture ZIP and validate official CHECKSUM format | **COMPLETE** | `app/HistoricalData/ArchiveDownloader.php` | `tests/Feature/HistoricalData/DownloaderTest.php::test_valid_fixture_archive_matches_official_checksum` |
| **F** | Reject checksum mismatch without publishing archive | **COMPLETE** | `app/HistoricalData/ArchiveDownloader.php`, `AcquisitionWorker.php` | `tests/Feature/HistoricalData/DownloaderTest.php::test_checksum_is_mandatory_and_mismatch_never_publishes_an_archive` |
| **G** | Reject truncated or malformed ZIP | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `research-go/internal/dataset/importer/archive_test.go::TestMalformedAndCorruptedZip` |
| **H** | Reject zip-slip, nested archives, encrypted files, and archive bombs | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `research-go/internal/dataset/importer/archive_test.go::TestArchiveSecurityGuards` |
| **I** | Verify timeout, retries, rate limiting, cancellation, and resume | **COMPLETE** | `app/HistoricalData/BatchControl.php`, `AcquisitionWorker.php` | `tests/Feature/HistoricalData/AcquisitionTest.php::test_resumed_worker_skips_imported_archives_and_handles_retries` |
| **J** | Ensure concurrent workers cannot duplicate archive import | **COMPLETE** | `app/HistoricalData/AcquisitionWorker.php` | Database-backed pessimistic leases (`lease_owner`, `lease_until`); `AcquisitionTest.php` |
| **K** | Parse representative 12-column Binance USD-M Futures klines | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `research-go/internal/dataset/importer/archive_test.go::TestParseValidFuturesKlines` |
| **L** | Handle supported header and headerless variations | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `research-go/internal/dataset/importer/archive_test.go::TestHeaderVariations` |
| **M** | Reject invalid timestamps, interval alignment, and malformed OHLCV | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `research-go/internal/dataset/importer/archive_test.go::TestInvalidOHLCVValidation` |
| **N** | Detect identical duplicates idempotently | **COMPLETE** | `app/HistoricalData/HistoricalWriter.php`, `ArchiveParser` | `tests/Feature/HistoricalData/AcquisitionTest.php::test_idempotent_duplicate_deduplication` |
| **O** | Detect contradictory candles without overwriting existing data | **COMPLETE** | `app/HistoricalData/HistoricalWriter.php` | `tests/Feature/HistoricalData/AcquisitionTest.php::test_contradictory_candle_creates_immutable_conflict_audit` |
| **P** | Verify UTC normalization and timestamp precision | **COMPLETE** | `research-go/internal/dataset/importer/archive.go` | `archive_test.go::TestUtcTimestampNormalization` |
| **Q** | Retrieve and parse historical funding observations | **COMPLETE** | `app/HistoricalData/FundingFetcher.php`, `ArchiveDownloader.php` | `tests/Feature/HistoricalData/AcquisitionTest.php::test_funding_archive_and_api_ingestion` |
| **R** | Verify pagination and funding timestamp ordering | **COMPLETE** | `app/HistoricalData/FundingFetcher.php` | `AcquisitionTest.php::test_funding_api_pagination_and_ordering` |
| **S** | Preserve funding-rate signs and decimal precision | **COMPLETE** | `app/HistoricalData/HistoricalWriter.php` | `AcquisitionTest.php::test_funding_rate_precision_and_signs` |
| **T** | Reject conflicting funding observations | **COMPLETE** | `app/HistoricalData/HistoricalWriter.php` | `AcquisitionTest.php::test_conflicting_funding_audit_and_rejection` |
| **U** | Apply funding correctly to historical long and short positions | **COMPLETE** | `research-go/internal/historical/replay.go` | `research-go/internal/historical/replay_test.go::TestFundingApplicationToPositions` |
| **V** | Clearly flag missing funding or mark-price data | **COMPLETE** | `app/HistoricalData/CoverageValidator.php` | `tests/Feature/HistoricalData/CoverageTest.php::test_missing_funding_is_explicitly_flagged` |
| **W** | Verify bounded memory usage on large synthetic fixture | **COMPLETE** | `scripts/bench/historical_acquisition.php` | Benchmark evidence: peak PHP memory 22 MiB, steady RSS 77 MiB, container peak 62 MiB (14,053 rows/sec) |
| **X** | Verify backpressure and controlled concurrency | **COMPLETE** | `config/historical_data.php`, `AcquisitionWorker.php` | Chunk size 5,000 rows, channel capacity 1,000, 1 concurrent active archive |
| **Y** | Verify disk-space refusal and safe cleanup of staging files | **COMPLETE** | `app/HistoricalData/StagingStorage.php` | `tests/Feature/HistoricalData/DownloaderTest.php::test_staging_space_refusal_and_cleanup` |
| **Z** | Verify restart recovery without partial/duplicate records | **COMPLETE** | `app/HistoricalData/Recovery.php`, `AcquisitionWorker.php` | `tests/Feature/HistoricalData/ApiTest.php::test_owner_controls_are_authenticated_durable_idempotent_audited_and_paginated` |
| **AA**| Run end-to-end Go historical backtest using imported Binance data | **COMPLETE** | `research-go/cmd/historical-replay`, `app/HistoricalData/ResearchWorker.php` | `tests/Feature/HistoricalData/ResearchTest.php::test_end_to_end_replay_produces_isolated_trades` |
| **AB**| Validate deterministic repeatability and immutable dataset pinning | **COMPLETE** | `app/HistoricalData/AcquisitionDataset.php` | `tests/Feature/HistoricalData/DatasetPinTest.php::test_deterministic_pin_reproducibility` |
| **AC**| Reject insufficient historical coverage | **COMPLETE** | `app/HistoricalData/ResearchService.php` | `tests/Feature/HistoricalData/ResearchTest.php::test_insufficient_warmup_coverage_is_rejected` |
| **AD**| Confirm no-lookahead behavior in replay | **COMPLETE** | `research-go/internal/historical/replay.go` | `replay_test.go::TestNoLookaheadEvaluation` |
| **AE**| Confirm strategy research results do not mutate PAPER accounts | **COMPLETE** | `app/HistoricalData/ResearchWorker.php` | `ResearchTest.php`, `scripts/browser/historical_data.mjs` (hash `6613d3c2217730026545fcf9f211debf|26|26` identical before/after) |
| **AF**| Confirm 26 PAPER accounts and BLOCKED statuses remain unchanged | **COMPLETE** | `app/HistoricalData/HistoricalController.php`, `ResearchWorker.php` | `scripts/browser/historical_data.mjs` (assert 26/26 BLOCKED) |
| **AG**| Confirm global portfolio risk budget is not modified | **COMPLETE** | `app/HistoricalData/ResearchWorker.php` | `GlobalPaperCapitalGuard` verified; 0 journal modifications |
| **AH**| Confirm no private or real-order exchange endpoint is invoked | **COMPLETE** | `OfficialTransport.php`, `research-go` | Strict URL regex allowlist: `data.binance.vision` and `fapi.binance.com/fapi/v1/fundingRate` only |
| **AI**| Verify owner authorization and rejection of unauthorized attempts | **COMPLETE** | `app/HistoricalData/HistoricalController.php`, `RequireResearchToken` | `tests/Feature/HistoricalData/ApiTest.php::test_owner_controls_are_authenticated_durable_idempotent_audited_and_paginated` |
| **AJ**| Verify plan preview, start, progress, retry, cancel, coverage screens | **COMPLETE** | `frontend/src/pages/HistoricalDataPage.vue` | `scripts/browser/historical_data.mjs` (Full Playwright browser workflow PASS) |
| **AK**| Verify API idempotency and stable error responses | **COMPLETE** | `app/HistoricalData/HistoricalController.php` | `tests/Feature/HistoricalData/ApiTest.php::test_owner_controls_are_authenticated_durable_idempotent_audited_and_paginated` |
| **AL**| Run browser-level Playwright acceptance tests | **COMPLETE** | `scripts/browser/historical_data.mjs` | Headless Chrome run PASS: 49 simulated trades, 0 page errors, 26/26 BLOCKED paper accounts intact |

---

## 3. Structural & Architectural Audit

### 3.1 Backend Architecture (PHP 8.5 / Laravel)
- **Namespace:** `App\HistoricalData`
- **Transport Security:** `OfficialTransport` enforces HTTPS and strict regex allowlisting:
  - Kline archives: `https://data.binance.vision/data/futures/um/(monthly|daily)/(klines|markPriceKlines)/...`
  - Funding archives: `https://data.binance.vision/data/futures/um/(monthly|daily)/fundingRate/...`
  - Funding REST fallback: `https://fapi.binance.com/fapi/v1/fundingRate` (read-only public endpoint)
  - Zero private credentials, zero trade routing.
- **Worker Concurrency & Resilience:**
  - `AcquisitionWorker`: per-entry pessimistic locking (`lease_owner`, `lease_until`), exponential backoff with jitter on retryable failures, status transitions: `PLANNED → DOWNLOADED → CHECKSUM_VERIFIED → PARSED → IMPORTED → VALIDATED / QUARANTINED / UNAVAILABLE / FAILED`.
  - `Recovery`: Scheduled every minute via Laravel Console scheduler; inspects active batches and recovers abandoned leases without duplicate execution.

### 3.2 Go Research Engine Integration (`research-go`)
- **Binary Binaries:**
  - `cmd/historical-import`: Streaming decompression, strict CSV parsing, OHLCV validation, batch emission to stdout or PostgreSQL.
  - `cmd/historical-replay`: Multi-timeframe closed-candle indicator evaluation, exact funding application at settlement timestamps, conservative stop-first ordering, trade feature extraction.
- **Data Integrity:** Strict non-lookahead policy, chronological ordering, immutable dataset pinning (`acquisition_dataset_pins`).

### 3.3 Frontend Observability (Vue 3 / Quasar)
- **Path:** `Exchanges → Binance → Historical Data → Download from Binance` (`/exchanges/binance/historical`).
- **Features:** Preset configuration, dry-run preview, non-commercial research licensing terms display with required acknowledgement reference, real-time batch and entry progress, checksum audit badges, coverage inspection, strategy backtest launcher, and interactive research trade/equity visualizer.
