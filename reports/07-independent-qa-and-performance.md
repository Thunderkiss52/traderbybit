# Gate 7 — Independent QA and Performance Report

## 1. Automated Test Suites Verification
- **PHP Laravel Suite**: 302 tests total (300 passed, 2 skipped [1 for live remote Bybit API, 1 for SQLite skip on PostgreSQL Go worker], 0 failed, 1,750 assertions). Log recorded in `reports/phpunit_full.log`.
- **Go Packages Suite**: `research-go/` 13 packages passed cleanly.
- **Go Worker & PostgreSQL Integration**: `tests/Integration/GoResearchWorkerIntegrationTest.php` passed (PostgreSQL `research_jobs` table claimed via `FOR UPDATE SKIP LOCKED` -> executed -> updated to `SUCCEEDED` with telemetry).
- **Frontend Test Suite**: `frontend/` 41 node tests passed cleanly (`node --test tests/*.test.js`).
- **Frontend Production Build**: `vue-tsc --noEmit && vite build` built successfully in 8.33s with zero TypeScript errors.
- **Pint Code Formatting**: Clean pass with zero style violations (`vendor/bin/pint --test`).
- **Browser E2E Suite**: **PASSED** (Playwright 1.58.0 + Google Chrome 151.0.7922.71 headless). Verified against genuinely running application (Vite preview on `127.0.0.1:4173` proxying to isolated Laravel on `127.0.0.1:8000`). Zero uncaught JavaScript errors, zero failed critical API requests. Full screenshots captured:
  - `reports/e2e_dashboard.png` (504 KB)
  - `reports/e2e_research.png` (233 KB)
  - `reports/e2e_statistics.png` (133 KB)

## 2. Unified Verification Script (`scripts/verify-system.sh`)
- Single command executes all 17 core verification stages across all domains:
  1. Market Data Intervals & Multi-Timeframe Research: PASS
  2. Market Scanner 16-Evaluation & Deduplication: PASS
  3. Signal Generation & Causality Invariants: PASS
  4. Support/Resistance Strategies & 8 Primary Accounts: PASS
  5. Primary Accounts Migration Rehearsal & ID Preservation: PASS
  6. Signal Quality Policy (A-E & UNRATED): PASS
  7. Paper Trading Lifecycle & Risk Invariants: PASS
  8. Account Safety & Forward Account Isolation: PASS
  9. Autonomous Learning Pipeline & Model Training: PASS
  10. Gate 6 Promotion Policy & Multiplicity Bounds: PASS
  11. Model Advisory Decision Veto & Rollback: PASS
  12. Exit Horizon & Meta-Labeling Paired Research: PASS
  13. Research & Trading Isolation API Tests: PASS
  14. Go Core Packages Tests: PASS
  15. PostgreSQL & Go Worker End-to-End Integration: PASS
  16. Frontend Unit Tests & Code Style Check: PASS
  17. Headless Browser E2E Tests (Playwright / Chrome): PASS

All 17 stages PASSED with exit code 0.

## 3. Measured Performance & Latency Benchmark
- **Measured Source**: `storage/latency_benchmark.json`
- **Environment**: Isolated PostgreSQL (`127.0.0.1:25432`), Redis (`127.0.0.1:26379`, `CACHE_STORE=redis`, `REDIS_CLIENT=predis`), PHP 8.4, Linux
- **Representative Database Records**:
  - `paper_accounts`: 8
  - `signals`: 120
  - `virtual_positions`: 25
  - `strategy_observations`: 351
  - `market_candles`: 1,250
  - `market_contexts`: 100
- **In-Process Controller Execution (`$controller->dashboard`)**:
  - Cold Latency (5 runs in isolated namespace): avg 211.59 ms (min 182.19 ms, max 279.78 ms)
  - Warm Latency (50 runs): P50 0.53 ms, P95 0.74 ms (min 0.41 ms, max 0.84 ms)
  - *Architectural Explanation*: In-process memory cache read of pre-computed PHP array payload without network, TCP, HTTP kernel, routing, or JSON serialization overhead takes sub-millisecond time.
- **Real HTTP Network Benchmark over TCP (`/api/v1/dashboard` on `127.0.0.1:8000`)**:
  - Cold HTTP Latency (5 repeated runs with isolated cache evictions): avg **336.83 ms** (min 246.92 ms, max 571.29 ms) -> **PASS** (SLA threshold < 1,500 ms)
  - Warm HTTP Latency (50 runs over TCP socket with isolated Redis backend):
    - Minimum: 10.45 ms
    - P50 (Median): **12.18 ms**
    - Average: 12.97 ms
    - P95: **16.72 ms** -> **PASS** (SLA threshold < 200 ms)
    - Maximum: 17.93 ms
  - **Cold-to-Warm Speedup**: 27.6x
  - **Query Aggregates**: Removed `limit(200)` truncation; full 24-hour SKIP reasons and account statistics computed in bounded time over indexed timestamps (`decision_time >= now() - 24h`).

## 4. QA Defect Ledger
| ID | Defect Description | Root Cause | Fix Applied | Verification Evidence |
| :--- | :--- | :--- | :--- | :--- |
| **DEF-01** | Dashboard latency under load | Unindexed timestamp filters and repeated un-cached queries caused latency spikes | Added Redis caching with discrete versioned keys (`Cache::remember('paper:dashboard:full_payload:v3', 10, ...)`), indexed `(decision_time, strategy_id)` queries | `storage/latency_benchmark.json`, cold HTTP 336.83ms, warm P95 16.72ms |
| **DEF-02** | 24-hour SKIP aggregate truncation | Hardcoded `limit(100)` / `limit(200)` in `ResearchController` truncated SKIP reason statistics when volume exceeded limits | Removed arbitrary row limits; scoped aggregation strictly to indexed 24-hour time window with group-by | `ResearchController::dashboard`, `ResearchApiTest` |
| **DEF-03** | Eloquent casting & schema mismatches | `reasons` column stored JSON but expected array/string; `learning_model_artifacts` lacked `features` column (stored in `parameters`) | Standardized Eloquent casts (`'reasons' => 'array'`), updated model attributes and migrations | `PrimaryAccountMigrationRehearsalTest`, `Gate6PromotionPolicyTest` |
| **DEF-04** | SQLite concurrency limitation with Go worker | SQLite does not support `FOR UPDATE SKIP LOCKED` or concurrent multi-process writes for Go workers | Configured isolated PostgreSQL test environment (`127.0.0.1:25432`) with explicit driver check guard in tests | `tests/Integration/GoResearchWorkerIntegrationTest.php` |
| **DEF-05** | Gate 6 promotion policy lacked multiplicity & dependence controls | Naive $1.96 \times SE$ bound ignored multiple comparisons and serial correlation in overlapping trade returns | Implemented Newey-West HAC variance (lag 4) and Bonferroni critical value ($z \approx 2.576$ for $M=5$ trials); enforced 14-day/50-opp challenger gate | `tests/Feature/Research/Gate6PromotionPolicyTest.php` (12/12 passed) |
| **DEF-06** | S/R strategies returned continuous SKIP | S/R bounce and breakout required $\ge 30$ historical forecast observations before issuing signals | Documented `level_probability_insufficient_data` behavior; created deterministic 30-sample fixtures verifying BUY/SELL activation | `tests/Feature/Strategy/SupportResistanceStrategiesTest.php` |
| **DEF-07** | Browser E2E suite unverified | Headless Playwright runner had not been automated in verification script | Automated Playwright + Google Chrome 151 suite (`scripts/run-browser-e2e.sh`); verified all 8 accounts, dropdowns, zero errors | `reports/browser-e2e.log`, 3 screenshots generated, exit code 0 |

## 5. Independent QA Signoff & Release Categorization

### A. Corrected Defects
All 7 identified defects (DEF-01 through DEF-07) are completely fixed, with regression tests passing cleanly in the CI suite.

### B. Accepted Limitations
1. **SQLite Driver vs PostgreSQL**: SQLite cannot run concurrent row-level locking tests for the Go worker; all production and integration tests must run against PostgreSQL (`127.0.0.1:25432`).
2. **Forward Live Cycles**: Accumulation of continuous 15-minute live cycles requires production deployment to VPS; locally verified via deterministic candle-cycle fixtures.

### C. Outstanding Deployment Blockers
1. **A30 (Production Audit)**: Currently **`DEPLOYED_UNVERIFIED`**. In accordance with deployment policy, code cannot be pushed to `main` or deployed to VPS without explicit user authorization via `.github/workflows/deploy.yml`.
