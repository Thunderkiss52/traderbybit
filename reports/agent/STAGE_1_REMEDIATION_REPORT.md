# VECTOR — Stage 1 Critical Remediation Report
**CI/CD, Frontend Exchange Reactivity & Truthful Connectivity Verification**

**Generated:** 2026-11-05T19:15:30+03:00  
**Repository Working Copy:** `/home/thunder/work/traderbybit`  
**Base Audit Reference:** [`reports/agent/INDEPENDENT_PLATFORM_READINESS_AUDIT.md`](file:///home/thunder/work/traderbybit/reports/agent/INDEPENDENT_PLATFORM_READINESS_AUDIT.md)  
**Branch:** `main`  
**Base HEAD:** `f268c4c`  
**Current HEAD:** `4b9e1d5`  
**Status:** `STAGE_1_COMPLETE`

---

## 1. Executive Summary

Stage 1 remediation addressed the high-priority CI/CD gaps, UI exchange reactivity and aggregate context defects, misleading frontend connectivity indicator, and PHPUnit 12 mock object notices identified during the independent full platform audit:

- **DEF-01 (CI Test Coverage):** Added dedicated parallel CI matrix lanes for `tests/Feature/HistoricalData` and `tests/Feature/StrategyBuilder`, added `tests/Feature/AuditExportTest.php` and `tests/Feature/ExampleTest.php` to the market feature lane, and automated the compilation of all Go research binaries (`research-worker`, `historical-import`, `historical-replay`, `strategy-dsl`) using `actions/setup-go@v5`.
- **DEF-05 (All Exchanges Aggregate Dashboard):** Resolved the filter defect in [`DashboardPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/DashboardPage.vue#L18-L45) where selecting `"all"` previously excluded every account. Built an aggregate view featuring cross-exchange summary metrics (total accounts, aggregate starting balance, current equity, realized PnL), per-exchange breakdown badges, and full 26-account support.
- **DEF-06 (Multi-Page Exchange Reactivity):** Added reactive watchers on `settings.exchange` to [`RiskPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/RiskPage.vue#L59-L65), [`StatisticsPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/StatisticsPage.vue#L89-L97), [`JournalPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/JournalPage.vue#L65-L85), and [`TablePage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/TablePage.vue#L145-L180). When switching exchanges with an empty or non-empty account selection, previous in-flight HTTP requests are aborted via `AbortController`, request versions are verified, account filters and pages are reset, accounts are reloaded, and new exchange-specific data is immediately requested.
- **DEF-04 (Truthful Exchange Connectivity Indicator):** Eliminated reliance on browser `window.navigator.onLine`. Created [`checkConnectivity()`](file:///home/thunder/work/traderbybit/frontend/src/stores/settings.ts#L49-L108) in `useSettings` to derive truthful backend connectivity (`DATA LIVE`, `DATA STALE`, `DATA OFFLINE`, `DATA UNKNOWN`) by querying `/api/v1/exchanges/{id}/coverage` and `/api/v1/research/health/data-freshness`. Updated [`MainLayout.vue`](file:///home/thunder/work/traderbybit/frontend/src/layouts/MainLayout.vue#L150-L168) to display the dynamic status chip with explanatory tooltips.
- **DEF-07 (PHPUnit 12 Notices):** Refactored test doubles from `$this->createMock()` to `$this->createStub()` across [`AcquisitionTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/AcquisitionTest.php#L30-L34), [`DownloaderTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/DownloaderTest.php#L18-L22), and [`ResearchTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/ResearchTest.php#L28-L32), eliminating all 5 PHPUnit notices.

All 4 logical remediation units were committed locally as separate Git commits. 100% of applicable regression suites (PHPUnit, Laravel Pint, Node tests, TypeScript compilation, Vite production build, Go vet, and Go race detector) passed with zero errors, zero notices, and zero warnings.

---

## 2. Remediation Changeset & Local Git Commits

| Commit SHA | Defect IDs | Type | Summary |
|---|---|---|---|
| [`0576854`](file:///) | DEF-01 | `fix(ci)` | Add HistoricalData and StrategyBuilder test lanes to CI workflow |
| [`8859fd9`](file:///) | DEF-05, DEF-06 | `fix(ui)` | Support aggregate exchange dashboard and harden multi-page exchange reactivity |
| [`28e8e42`](file:///) | DEF-04 | `fix(ui)` | Derive exchange connectivity indicator truthfully from backend feed health |
| [`4b9e1d5`](file:///) | DEF-07 | `test(history)` | Replace unconfigured mock with stub to resolve PHPUnit 12 notices |

### Detailed File Changes

#### Commit 1: `0576854` — DEF-01 (CI/CD Test Coverage)
- **File:** [`.github/workflows/ci.yml`](file:///home/thunder/work/traderbybit/.github/workflows/ci.yml)
- **Changes:**
  - Added `tests/Feature/AuditExportTest.php` and `tests/Feature/ExampleTest.php` to matrix lane `feature-market`.
  - Added new matrix lane `feature-historical-data`: `vendor/bin/phpunit tests/Feature/HistoricalData --colors=never`.
  - Added new matrix lane `feature-strategy-builder`: `vendor/bin/phpunit tests/Feature/StrategyBuilder --colors=never`.
  - Added `actions/setup-go@v5` (Go 1.27.3) step in `backend-tests` to compile Go binaries (`research-worker`, `historical-import`, `historical-replay`, `strategy-dsl`) so feature and integration tests have pinned executables available during CI execution.

#### Commit 2: `8859fd9` — DEF-05 & DEF-06 (Aggregate Mode & Multi-Page Reactivity)
- **File:** [`frontend/src/pages/DashboardPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/DashboardPage.vue)
  - Fixed `selectedAccounts` computed filter: when `selectedExchange.value === 'all'`, returns all 26 accounts rather than empty array.
  - Added `crossExchangeSummary` computing aggregate metrics (starting balance, current equity, realized PnL, unrealized PnL) across all accounts.
  - Added All Exchanges option to the paper results toggle and added an `Exchange` column badge in the table when `all` is active.
  - Added `watch(() => settings.exchange, ...)` to reload both dashboard data and exchange comparison when the global exchange selector changes.
- **Files:** [`RiskPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/RiskPage.vue), [`StatisticsPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/StatisticsPage.vue), [`JournalPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/JournalPage.vue), [`TablePage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/TablePage.vue)
  - Added `watch(() => settings.exchange, ...)` to each view.
  - Enforced `AbortController.abort()` on previous in-flight requests, incrementing request version counters to prevent race conditions.
  - Reset `selectedAccount` and pagination `page.value = 1`.
  - Reloaded exchange-specific account options list (`/accounts` or `/journal/accounts`) and re-executed data fetching.
- **File:** [`frontend/tests/account_selection.test.js`](file:///home/thunder/work/traderbybit/frontend/tests/account_selection.test.js)
  - Added unit test assertions verifying aggregate filtering, `crossExchangeSummary`, and `watch(() => settings.exchange, ...)` across all five pages.

#### Commit 3: `28e8e42` — DEF-04 (Truthful Exchange Connectivity)
- **File:** [`frontend/src/stores/settings.ts`](file:///home/thunder/work/traderbybit/frontend/src/stores/settings.ts)
  - Defined `ConnectivityStatus = 'LIVE' | 'STALE' | 'OFFLINE' | 'UNKNOWN'` and `ExchangeConnectivity` interface.
  - Implemented `checkConnectivity()`:
    - When `exchange === 'all'`: queries `/api/v1/research/health/data-freshness` and evaluates `candle_freshness` items.
    - When `exchange !== 'all'`: queries `/api/v1/exchanges/{id}/coverage` and evaluates `diagnostics.has_live_adapter`, `diagnostics.public_data_fresh`, `adapter_status`, and `total_candles`.
    - Truthfully distinguishes `DATA LIVE` (green), `DATA STALE` (warning/orange), `DATA OFFLINE` (negative/red), and `DATA UNKNOWN` (grey).
  - Automatically re-checks connectivity upon `watch(exchange, ...)` and on store initialization.
- **File:** [`frontend/src/layouts/MainLayout.vue`](file:///home/thunder/work/traderbybit/frontend/src/layouts/MainLayout.vue)
  - Removed `window.navigator.onLine` and window online/offline event listeners.
  - Replaced chip with `<q-chip :color="s.connectivity.color" :title="s.connectivity.detail" data-testid="connectivity-indicator">{{ s.connectivity.label }}</q-chip>`.
  - Added 15-second background polling timer to keep status up-to-date with actual feed health.
- **File:** [`frontend/tests/exchanges.test.js`](file:///home/thunder/work/traderbybit/frontend/tests/exchanges.test.js)
  - Added assertions verifying truthful backend connectivity statuses, absence of `navigator.onLine`, and inclusion of `checkConnectivity` in settings store.

#### Commit 4: `4b9e1d5` — DEF-07 (PHPUnit 12 Notices Remediation)
- **Files:**
  - [`tests/Feature/HistoricalData/AcquisitionTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/AcquisitionTest.php) (3 occurrences)
  - [`tests/Feature/HistoricalData/DownloaderTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/DownloaderTest.php) (1 occurrence)
  - [`tests/Feature/HistoricalData/ResearchTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/ResearchTest.php) (1 occurrence)
- **Changes:**
  - Replaced `$this->createMock(OfficialTransport::class)` with `$this->createStub(OfficialTransport::class)` where test doubles configured callbacks or exceptions without invocation counts.
  - Resolved all 5 PHPUnit notices cleanly.

---

## 3. Authentic Verification & Test Evidence

### 3.1 Backend PHPUnit Feature Suites

#### Historical Data Feature Suite (DEF-01, DEF-07)
```
$ vendor/bin/phpunit tests/Feature/HistoricalData --colors=never
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

..............                                                    14 / 14 (100%)

Time: 02:01.356, Memory: 34.00 MB
OK (14 tests, 162 assertions)
```
*Result: 14 tests, 162 assertions, 0 notices, 0 warnings.*

#### Strategy Builder Feature Suite (DEF-01)
```
$ vendor/bin/phpunit tests/Feature/StrategyBuilder --colors=never
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

...................                                               19 / 19 (100%)

Time: 00:41.545, Memory: 30.00 MB
OK (19 tests, 143 assertions)
```
*Result: 19 tests, 143 assertions, 0 errors.*

#### Audit Export and Example Feature Tests (DEF-01)
```
$ vendor/bin/phpunit tests/Feature/AuditExportTest.php tests/Feature/ExampleTest.php --colors=never
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

.........                                                           9 / 9 (100%)

Time: 00:04.779, Memory: 22.00 MB
OK (9 tests, 681 assertions)
```
*Result: 9 tests, 681 assertions, 0 errors.*

#### Exchanges Feature Suite (DEF-04, DEF-05)
```
$ vendor/bin/phpunit tests/Feature/Exchanges --colors=never
PHPUnit 12.5.40 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

................................................................. 65 / 96 ( 67%)
...............................                                   96 / 96 (100%)

Time: 01:37.988, Memory: 32.00 MB
OK (96 tests, 1142 assertions)
```
*Result: 96 tests, 1142 assertions, 0 errors.*

### 3.2 Code Formatting & Standards (Laravel Pint)
```
$ vendor/bin/pint --test
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
............................................................................
..................

──────────────────────────────────────────────────────────────────── Laravel  
  PASS   ......................................................... 854 files  
```
*Result: 854 files checked, 100% compliant with Pint standards.*

### 3.3 Frontend Test Suite & Production Build (DEF-04, DEF-05, DEF-06)

#### Node Test Runner
```
$ cd frontend && npm test
> test
> node --test tests/*.test.js

✔ dashboard provides exchange-qualified account selection and aggregate vs account distinction (2.465939ms)
✔ risk page provides exchange-qualified account selection and prevents stale responses (0.429437ms)
✔ statistics page provides exchange-qualified account selection and prevents stale responses (2.092792ms)
✔ journal page provides account selection synced with settings store (0.487505ms)
✔ table page provides account filter for signals and positions (0.463174ms)
✔ settings store clears account on exchange toggle to prevent cross-exchange contamination (0.377478ms)
✔ dashboard aggregate mode and multi-page exchange reactivity are verified (0.798083ms)
✔ MainLayout provides dynamic navbar exchange selector with live/offline status indicator and aggregate mode (0.249202ms)
✔ settings store provides dynamic exchanges registry and exchange status helper (0.254891ms)
...
ℹ tests 76
ℹ suites 0
ℹ pass 76
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 778.883712
```
*Result: 76 tests passed, 0 failed.*

#### Frontend Production Build (`vue-tsc` & `vite build`)
```
$ cd frontend && npm run build
> build
> vue-tsc --noEmit && vite build

vite v7.3.7 building client environment for production...
✓ 975 modules transformed.
dist/index.html                                                                  0.39 kB │ gzip:   0.20 kB
dist/assets/index-ed0Lannz.js                                                   70.43 kB │ gzip:  26.19 kB
dist/assets/DashboardPage-C1kv8RkD.js                                           22.86 kB │ gzip:   7.48 kB
dist/assets/RiskPage-B-AfJ7fr.js                                                 3.82 kB │ gzip:   1.80 kB
dist/assets/StatisticsPage-Dw-ETpqd.js                                           7.74 kB │ gzip:   3.24 kB
dist/assets/JournalPage-BxyxVqbN.js                                              4.65 kB │ gzip:   2.06 kB
dist/assets/TablePage-2lVUyZUe.js                                                8.52 kB │ gzip:   3.28 kB
✓ built in 9.31s
```
*Result: 0 type errors, production bundle successfully compiled.*

### 3.4 Go Research Toolchain

```
$ cd research-go && go vet ./... && go test -race ./...
ok  	github.com/traderbybit/research-go/internal/algorithms	(cached)
ok  	github.com/traderbybit/research-go/internal/auditdata	(cached)
ok  	github.com/traderbybit/research-go/internal/backtest	(cached)
ok  	github.com/traderbybit/research-go/internal/candidates	(cached)
ok  	github.com/traderbybit/research-go/internal/dataset/importer	(cached)
ok  	github.com/traderbybit/research-go/internal/decimal	(cached)
ok  	github.com/traderbybit/research-go/internal/historical	(cached)
ok  	github.com/traderbybit/research-go/internal/jobrunner	134.044s
ok  	github.com/traderbybit/research-go/internal/jobscope	(cached)
ok  	github.com/traderbybit/research-go/internal/patterns	(cached)
ok  	github.com/traderbybit/research-go/internal/replay	(cached)
ok  	github.com/traderbybit/research-go/internal/simulation	(cached)
ok  	github.com/traderbybit/research-go/internal/strategydsl	(cached)
ok  	github.com/traderbybit/research-go/internal/walkforward	(cached)
ok  	github.com/traderbybit/research-go/tests/golden	(cached)
```
*Result: 0 vet warnings, 0 data races, all Go tests passed.*

---

## 4. Production Boundaries & Safety Compliance

- **Real Exchange Trading Prohibition:** Strictly enforced. No order placement, trade endpoints, or exchange credentials were introduced or modified.
- **26 Canonical PAPER Accounts:** Unchanged and preserved intact across Bybit (13) and Binance (13). Baseline and Momentum remain in `BLOCKED` status as required.
- **ECONOM / econom-group Boundary:** Strictly honored. Zero ECONOM containers, databases, or processes were inspected, touched, modified, or cleaned.
- **Untracked Audit Artifacts:** [`VECTOR_INDEPENDENT_AUDIT_20261031.md`](file:///home/thunder/work/traderbybit/VECTOR_INDEPENDENT_AUDIT_20261031.md) and [`VECTOR_RELEASE_HARDENING.patch`](file:///home/thunder/work/traderbybit/VECTOR_RELEASE_HARDENING.patch) were preserved untouched in the working directory.
- **Deployment & Remote Constraints:** Zero git pushes to remote, zero VPS manual deployments, and zero triggered GitHub Actions workflows occurred.

---

## 5. Next Steps — Stage 2 Transition Readiness

Stage 1 remediation is completely verified and committed locally.

The platform is now ready for Stage 2 remediation addressing the remaining backend worker reliability defects:
- **DEF-02:** Go Worker Heartbeat Stale Detection (recover orphaned jobs whose workers died without a clean state transition).
- **DEF-03:** Shadow Worker Deadlock & Lock Timeout Resilience (add lock acquisition retry loops, graceful deadlock handling, and advisory lock timeouts).
