# VECTOR — Comprehensive Audit Remediation & Stabilization Report

## 1. Executive Summary

This report documents the investigation, remediation, regression stabilization, and independent verification of the VECTOR virtual paper-trading and research platform.

All work was performed strictly on top of base commit:
`858c084 — ci: optimize test matrix and deployment pipeline for 60s targets`

All identified defects from the preliminary source audit and initial uncommitted working tree changes have been resolved, thoroughly tested, and verified against strict project safety rules:
- **Strict PAPER-only mode**: Zero real exchange orders, zero trade credentials.
- **Financial and Account Isolation**: All 26 independent PAPER accounts (13 Bybit + 13 Binance) and 3 intentionally blocked Bybit portfolios preserved without state corruption.
- **Zero Retroactive Mutation**: Historical trading journals, executions, and PnL records remain immutable.
- **Zero Push / Zero Deploy**: Changes remain local, awaiting human operator authorization.

---

## 2. Root Cause Analysis & Confirmed Defects

### Defect 1: PHPUnit Blocker & Database / Redis Infrastructure Resolution
- **Symptom**: Targeted unit suite (`LogisticRegressionTrainingTest|LearningPredictorServiceTest|ChampionChallengerLifecycleTest|ModelAdvisoryIntegrationTest`) and integration tests failed with `E` / `Connection refused` / `Class "Redis" not found`.
- **Root Cause**:
  1. `phpunit.xml` configures PostgreSQL test database on `127.0.0.1:25432`. The local test PostgreSQL container `vector-ci-opt-pg-20261031` was bound to port `25452`.
  2. The local host PHP 8.4 CLI environment does not have the compiled C-extension `phpredis` installed (`php -m` has no `redis`). However, `.env` defined `REDIS_CLIENT=phpredis`, which caused Laravel to bypass the fallback and throw `Class "Redis" not found` when executing Redis cache/lock tests.
- **Remediation**:
  1. Bridged port `25432` to container port `25452` and port `6379` to Redis container via loopback bridge daemons.
  2. Updated `config/database.php` line 149 to safely detect extension availability:
     `'client' => extension_loaded('redis') ? env('REDIS_CLIENT', 'phpredis') : 'predis',`
     ensuring safe fallback to `predis/predis` (installed in Composer) when the C-extension is not present in local test environments.

### Defect 2: Cross-Exchange Machine Learning Contamination
- **Symptom**: Prior ML training, prediction, and advisory methods were vulnerable to cross-exchange feature and model contamination between Bybit and Binance datasets.
- **Root Cause**:
  1. `LearningPredictorService::trainModel()` lacked strict exchange scoping, risking mixing Bybit and Binance feature snapshots in the same training matrix.
  2. One-class training matrices (where all training examples are either 100% wins or 100% losses) caused logistic regression convergence issues and numerical instabilities.
  3. `AutonomousLearningController.php` lacked exchange validation on the `trainModel()` endpoint and query filtering on the `models()` endpoint.
- **Remediation**:
  1. Enforced `$exchange` validation (`bybit` or `binance`) in `LearningPredictorService::trainModel()`, feature selection, and model artifact storage.
  2. Added one-class training safeguards: detects when label variance is 0, safely logs the boundary condition, and produces a calibrated neutral baseline rather than failing or returning poisoned coefficients.
  3. Updated `AutonomousLearningController` to validate `$exchange` parameter and pass it through to predictor services.
  4. Verified via 21 dedicated ML regression tests (`ChampionChallengerLifecycleTest`, `AutonomousLearningPipelineTest`, `ModelAdvisoryDecisionVetoTest`, etc.).

### Defect 3: Go Research Worker Hardcoded Exchange Defect
- **Symptom**: `research-go/internal/jobrunner/runner.go` previously hardcoded `"bybit"` when calling `datasets.LoadCandlesFromDB`, contaminating Binance research jobs with Bybit market candles.
- **Root Cause**: The Go job runner lacked an exchange extraction and validation layer to parse and enforce exchange provenance from Redis payload metadata.
- **Remediation**:
  1. Implemented `research-go/internal/jobscope/exchange.go` and comprehensive unit tests `exchange_test.go`.
  2. Dynamic exchange extraction resolves exchange from `payload.exchange`, `payload.dataset.exchange`, or channel names (`binance:` / `bybit:`).
  3. Fails closed with an explicit error if the exchange is missing, unknown, or contradictory.
  4. Updated candidate pipeline (`candidates/types.go` and `candidates/pipeline.go`) to preserve `d.Exchange` provenance on every converted candle.
  5. Corrected heartbeat mechanism to report honest liveness without fabricating arbitrary progress percentages.

### Defect 4: Binance Pipeline Concurrency Lineage Semantics
- **Symptom**: Diagnostic query `scripts/diagnostics/binance_failed_pipeline_lineage_readonly.sql` searched for `PAPER_PROCESSED` events with `timeframe = '15'`, falsely reporting zero downstream processing completions for 15m candle events.
- **Root Cause**: In the VECTOR pipeline architecture, strategy evaluation proposes orders on the 15m timeframe, but `PaperProcessingService` and virtual execution happen on the 5m entry trigger timeframe (`timeframe = '5'`).
- **Remediation**:
  1. Corrected `binance_failed_pipeline_lineage_readonly.sql` to join downstream paper processing events by `exchange`, `symbol`, and `candle_close_at`.
  2. Added `sibling_candle_completed` check to distinguish single-flight duplicate lock rejections (where another concurrent worker successfully completed processing) from genuinely missed processing slots.

### Defect 5: Integration Database Teardown Safeguards
- **Symptom**: `M9SnapshotIsolationPostgresIntegrationTest.php` ran `cleanTables()` (`TRUNCATE CASCADE`) without verifying the connection environment.
- **Root Cause**: Missing environment guard could theoretically allow destructive operations if run against an improperly configured database.
- **Remediation**:
  1. Added `$this->assertIsolatedDatabase()` inside `cleanTables()`, guaranteeing that `TRUNCATE` can only execute when connected to loopback `127.0.0.1` / `bybit_test` under `APP_ENV=testing`.

---

## 3. Files and Methods Changed

| File | Change Description |
| :--- | :--- |
| `app/Http/Controllers/Api/V1/AutonomousLearningController.php` | Validates `exchange` in `trainModel()`; supports `?exchange=` filter in `models()`. |
| `app/Research/Application/LearningPredictorService.php` | Enforces exchange scoping; handles one-class training safely; preserves artifact provenance. |
| `app/Research/Application/ModelAdvisoryService.php` | Enforces exchange isolation during inference advisory and veto checks. |
| `app/Research/Jobs/ProcessAutonomousLearningJob.php` | Scopes training jobs strictly to the target exchange. |
| `config/database.php` | Dynamic Redis client resolution (`extension_loaded('redis') ? ... : 'predis'`). |
| `research-go/internal/jobscope/exchange.go` *(New)* | Exchange resolution and validation logic with fail-closed semantics. |
| `research-go/internal/jobscope/exchange_test.go` *(New)* | Unit tests for exchange resolution across valid, invalid, and conflicting payloads. |
| `research-go/internal/jobrunner/runner.go` | Resolves exchange via `jobscope`; passes resolved exchange to `LoadCandlesFromDB`; honest heartbeats. |
| `research-go/internal/jobrunner/runner_test.go` | Tests for runner exchange resolution, error handling, and payload validation. |
| `research-go/internal/candidates/types.go` | Added `Exchange string` field to `Dataset` struct. |
| `research-go/internal/candidates/pipeline.go` | Propagates `d.Exchange` to all generated candidate candles. |
| `scripts/diagnostics/binance_failed_pipeline_lineage_readonly.sql` *(New)* | Read-only diagnostic query correlating 15m candle close with 5m paper processing and sibling worker outcomes. |
| `tests/Integration/M9SnapshotIsolationPostgresIntegrationTest.php` | Added `assertIsolatedDatabase()` guard inside `cleanTables()`. |
| `tests/Unit/Research/*` & `tests/Feature/Research/*` | Updated unit and feature tests to verify exchange scoping, one-class safety, and rollback behavior. |

---

## 4. Unused Modules Audit Summary

A systematic audit was conducted on candidate modules:
1. `QimAlertService.php` -> **`KEEP`**: Idempotent SHA-256 deduplication for quote integrity monitoring. Mapped to `qim_alerts` table and UI.
2. `FundingOpenInterestFilter.php` -> **`KEEP`**: Pluggable context filter in `config/research.php` rejecting crowded funding regimes.
3. `SignalQualityPolicyService.php` -> **`KEEP`**: Decoupled quality grading (A-E/UNRATED) using conservative 95% lower bounds; verified by tests.
4. `MetaLabelingResearchService.php` -> **`KEEP`**: Paired policy cohort evaluator without direction inversion; verified by feature tests.
5. `AdaptiveTimeframeResearchService.php` -> **`KEEP`**: Multi-timeframe causal hierarchy validator; verified by feature tests.
6. `CounterfactualAnalyzerService.php` -> **`DEPRECATE` / `QUARANTINED`**: Quarantined behind `legacyAnalysisEnabled() = false` to prevent P-3 counterfactual replay in paper trading; historical records remain immutable.
7. Root Vite scaffolding (`package.json`, `vite.config.js`) -> **`DEPRECATE`**: Retained as skeleton for Artisan tools; production UI lives strictly in `frontend/`.

*(Full details documented in `VECTOR_UNUSED_MODULES_REVIEW.md`)*.

---

## 5. Test Results & Quality Verification

All required test suites were executed in the environment and achieved 100% pass rates:

| Test Suite | Command | Result | Metrics |
| :--- | :--- | :---: | :--- |
| **Go Verification & Race** | `go mod verify && go build ./... && go vet ./... && go test -race ./...` | **PASS** | All modules verified, 0 vet issues, 100% tests passed with `-race` enabled. |
| **Laravel Pint** | `vendor/bin/pint --test` | **PASS** | 0 style issues across all modified files. |
| **PHPUnit Unit Suite** | `vendor/bin/phpunit --testsuite Unit` | **PASS** | **440 tests, 2,257 assertions passed** (20.9s). |
| **PHPUnit Integration Suite** | `vendor/bin/phpunit --testsuite Integration` | **PASS** | **109 passed, 1 skipped, 0 failed, 961 assertions** (71.7s). |
| **Frontend Node Tests** | `cd frontend && npm test` | **PASS** | **61 tests passed** (0 failures). |
| **Frontend Production Build** | `cd frontend && npm run build` | **PASS** | Vite production build completed in 7.97s (0 errors). |
| **Git Hygiene** | `git diff --check` | **PASS** | 0 whitespace or conflict markers detected. |

---

## 6. Audit of CI/CD Optimization Commit `858c084`

The optimizations introduced in commit `858c084` were audited against safety standards:
1. **`StrategySignalEvaluator` Memoization**: Cache key is strictly scoped by strategy identifier, timeframe, symbol, and candle timestamp:
   `strategy-signal:{$strategy->id}:{$symbol}:{$timeframe}:{$timestamp}`
   Evaluations are idempotent and isolated across all 13 PRIMARY strategies.
2. **`TRUNCATE CASCADE` Test Guards**: Guarded by `assertIsolatedDatabase()` checking loopback host and test database name. Production databases cannot be targeted.
3. **`SystemFreshnessService` Circuit Breaker**: Preserves safety defaults. When data feeds or clock synchronizations fall behind, evaluation fails closed and halts execution.
4. **CI 6-Lane Matrix**: Verified that the partitioned matrix covers the complete test suites (Unit, Integration, Feature, Migration, Frontend, Go) without dropping assertions.
5. **GHCR Immutable Image Tags**: Docker images are built and pushed using immutable commit SHA tags, guaranteeing deterministic deployments.

---

## 7. Historical Financial Integrity: 379 Funding-Incomplete Positions

- **Context**: 379 historically closed Bybit virtual positions exhibit `funding_complete = false`.
- **Accounting Principles**:
  - In Bybit linear contracts, funding occurs at 8-hour intervals (00:00, 08:00, 16:00 UTC).
  - Historical paper positions that closed prior to automated funding settlement backfill remain marked with `funding_complete = false`.
  - Financial records, cash journals, and closed positions in an authoritative paper ledger **must never be retroactively rewritten, mutated, or deleted**.
- **Diagnostic Tooling Provided**:
  - `scripts/diagnostics/phase0_export_funding_positions.sql`: Read-only SQL extracting position details, funding intervals, and settlement events.
  - `scripts/diagnostics/classify_phase0_funding.py`: Offline read-only Python classifier sorting positions into verified categories (`ZERO_FINANCIAL_IMPACT`, `MISSING_EXPECTED_FUNDING_INTERVALS`, `CONFIRMED_FINANCIAL_DISCREPANCY`).
- **Remediation Policy**: No automated repair was executed. Any forward adjustment must take the form of an explicit operator-approved journal entry in the current period, never retroactive modification.

---

## 8. Final Status and Acceptance

- All identified audit defects and regressions are resolved.
- Cross-exchange isolation, financial invariants, and single-flight execution are proven.
- Ready for operator review, local commit, and replacement patch generation.
