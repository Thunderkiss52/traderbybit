# VECTOR — Release Hardening Report (F-01, F-02, F-03)

**Date:** 2026-10-31  
**Base Commit:** `efd8516` (`fix(backtest): scope evaluator cache and harden test database guards`)  
**Target Branch:** `main`  
**Evaluation Verdict:** `READY_FOR_OWNER_REVIEW`  

---

## 1. Executive Summary

This release hardening remediation resolves all three release blockers identified in the independent pre-deployment audit (`VECTOR_INDEPENDENT_AUDIT_20261031.md`):

1. **F-01 (Severity P1): PostgreSQL Test Isolation and Port Mismatch**  
   Standardized test database configuration in `phpunit.xml`, hardened `assertIsolatedDatabase()` in `tests/TestCase.php` to validate database name (`bybit_test`), PostgreSQL driver, safe test hosts (loopback, private container networks, container service names), valid port, URL override safety, and live server identity (`current_database()`, `current_user`) before any schema inspection or migration occurs. Added 10 deterministic unit tests in `tests/Unit/Database/PostgresTestDatabaseIsolationGuardTest.php`.

2. **F-02 (Severity P2): Fail-Closed Pre-Deployment CI Verification**  
   Replaced advisory warnings in `.github/workflows/deploy.yml` with fail-closed bash checks utilizing Python 3 JSON validation. Deployment strictly aborts (`exit 1`) if no CI run exists, or if any CI run for the exact commit SHA is failed, cancelled, or still in progress. Added automated test suite `scripts/tests/test_deploy_workflow_ci_verification.sh` covering 7 test scenarios (100% pass).

3. **F-03 (Severity P2): Elimination of Production Image Build Fallbacks**  
   Removed `compose build research-worker` fallbacks in `docker/scripts/deploy.sh` and `docker/scripts/update.sh` when `DEPLOY_MODE=pull`. Implemented strict pre-inspection of all three immutable images (`app`, `web`, `research-worker`) after pulling. If any image fails to pull or is missing, deployment aborts immediately (`exit 1`) and triggers automatic rollback to the previous release. Added automated test suite `scripts/tests/test_deploy_immutable_pull_scenarios.sh` covering 5 deployment scenarios (100% pass).

All regression suites (PHP Pint, Unit, Integration, Feature, Go `-race`, Frontend tests & Vite build, shell scripts, and YAML schemas) passed with zero defects.

---

## 2. Root Cause Analysis & Implementations

### Finding F-01: PostgreSQL Test Isolation & Port Mismatch (P1)

- **Root Cause:**  
  In `efd8516`, `assertIsolatedDatabase()` in `tests/Integration/M9SnapshotIsolationPostgresIntegrationTest.php` compared connection port against `env('TEST_DB_PORT', env('DB_PORT', 25432))`. When developer environment `.env` had `TEST_DB_PORT=25452` while `phpunit.xml` had `<env name="DB_PORT" value="25432"/>` (and omitted `TEST_DB_PORT`), `env('TEST_DB_PORT')` returned `25452`, conflicting with the connection's port `25432`. Furthermore, host assertions were restricted to `'127.0.0.1'`, breaking containerized CI setups (where the database host is named `postgres` or `vector-postgres`). Additionally, `ensurePostgresTestDatabaseReady()` checked `Schema::hasTable` before running `assertIsolatedDatabase()`, violating fail-closed preflight semantics.
- **Remediation:**  
  1. Updated `phpunit.xml` with explicit `TEST_DB_*` environment variables matching the test PostgreSQL port (`25432`), host (`127.0.0.1`), database (`bybit_test`), user (`postgres`), password (`testpass`), and Predis client.
  2. Moved `assertIsolatedDatabase(?Connection $connection = null)` and helper `isSafeTestHost(string $host)` to base `tests/TestCase.php`, making database preflight isolation checks universally available across all tests.
  3. Enforced fail-closed checks in `assertIsolatedDatabase()`:
     - Rejects `production`, `staging`, or `prod` environments; requires `testing` and `runningUnitTests()`.
     - Strictly requires PostgreSQL driver (`pgsql`).
     - Strictly requires database name `bybit_test`.
     - Validates host via `isSafeTestHost()`, supporting loopback (`127.0.0.1`, `localhost`, `::1`), known CI container service names (`postgres`, `pgsql`, `db`, `vector-postgres`), and private RFC 1918/bridge IP ranges (`172.*`, `10.*`, `192.168.*`).
     - Validates positive port integer.
     - Inspects database URL to detect and reject dangerous overrides.
     - Executes live server query: `SELECT current_database() AS database_name, current_user AS database_user`.
  4. Placed `assertIsolatedDatabase()` at the entry point of `ensurePostgresTestDatabaseReady()` before any schema inspection or migration calls.
  5. Implemented `tests/Unit/Database/PostgresTestDatabaseIsolationGuardTest.php` with 10 deterministic tests covering production abort, driver mismatches, host rejection, port checks, live identity verification, and safe host resolution.

### Finding F-02: Deployment Fail-Closed CI Verification (P2)

- **Root Cause:**  
  `.github/workflows/deploy.yml` previously printed a warning and continued if a CI run was missing or unsuccessful:
  `Warning: CI run for commit ${COMMIT_SHA} is not marked as successful yet.`
  This allowed deployments of untested or failing commits if container images happened to exist in GHCR. Furthermore, the workflow relied on `jq`, which was not consistently present in all environments.
- **Remediation:**  
  1. Updated `.github/workflows/deploy.yml` step `Verify CI checks`:
     - If `runs_json` is empty or `[]`, exits with code 1 (`Error: No CI workflow run found for commit ${COMMIT_SHA} in '.github/workflows/ci.yml'`).
     - Employs a robust Python 3 one-liner parsing `runs_json` via `sys.stdin` to match `headSha == COMMIT_SHA`, `status == "completed"`, and `conclusion == "success"`.
     - If no matching successful run exists, prints detailed statuses of any existing runs for that commit SHA and terminates with `exit 1` (`Deployment rejected: immutable preverified deployment requires all CI matrix checks to pass`).
  2. Implemented `scripts/tests/test_deploy_workflow_ci_verification.sh` testing 7 distinct CI scenarios:
     - Scenario 1: Matching SHA + successful CI -> accepted (exit 0).
     - Scenario 2: Matching SHA + failed CI -> rejected (exit 1).
     - Scenario 3: Matching SHA + cancelled CI -> rejected (exit 1).
     - Scenario 4: Matching SHA + pending CI -> rejected (exit 1).
     - Scenario 5: No matching CI run -> rejected (exit 1).
     - Scenario 6: Successful CI for a different SHA -> rejected (exit 1).
     - Scenario 7: Missing or invalid image metadata -> rejected (exit 1).
  3. Validated YAML formatting and schema compliance with PyYAML.

### Finding F-03: Elimination of Production Image Build Fallback (P2)

- **Root Cause:**  
  `docker/scripts/deploy.sh` and `docker/scripts/update.sh` contained fallback logic:
  `if ! docker image inspect "${RESEARCH_WORKER_IMAGE:-...}" ...; then compose build research-worker; fi`
  In production `DEPLOY_MODE=pull`, building images on the VPS host creates non-deterministic, mutable binaries, violates immutable artifact promotion, risks VPS disk exhaustion, and bypasses CI provenance guarantees.
- **Remediation:**  
  1. Updated `docker/scripts/deploy.sh`:
     - In `DEPLOY_MODE=pull`, pulls all three required services: `app`, `web`, `research-worker`.
     - If the pull fails, aborts immediately (`exit 1`).
     - Executes `docker image inspect` on each image with `${IMAGE_TAG:-latest}`. If any image is missing, logs an error and aborts with `exit 1` without attempting any build fallback.
  2. Updated `docker/scripts/update.sh`:
     - In `DEPLOY_MODE=pull`, pulls and inspects all three images with `${NEW_TAG}`.
     - If any pull fails or image is missing, logs `Silent build fallback is prohibited in production. Aborting update.` and exits with code 1.
     - The existing `ERR` trap executes `rollback_application()`, safely restoring the previous container release (`CURRENT_TAG`).
  3. Implemented `scripts/tests/test_deploy_immutable_pull_scenarios.sh` testing 5 mock Docker scenarios:
     - Scenario 1: All three images present -> deployment succeeds (exit 0).
     - Scenario 2: Research-worker missing -> aborts, no build fallback, rolls back (exit 1).
     - Scenario 3: App image missing -> aborts, no build fallback, rolls back (exit 1).
     - Scenario 4: Image pull failure -> aborts and rolls back to current release (exit 1).
     - Scenario 5: Failed health check -> triggers rollback to previous release (exit 1).

---

## 3. Regression Test Evidence

| Test Suite / Tool | Command | Scope / Coverage | Result | Duration |
| :--- | :--- | :--- | :--- | :--- |
| **Laravel Pint** | `XDEBUG_MODE=off vendor/bin/pint --test` | Code style & formatting | **PASS** (0 style issues) | 0.81s |
| **PHPUnit Unit** | `XDEBUG_MODE=off REDIS_CLIENT=predis vendor/bin/phpunit --testsuite Unit` | Isolated domain, strategy, indicator, database guard unit tests | **PASS** (451 tests, 2,287 assertions) | 20.95s |
| **PHPUnit Integration** | `XDEBUG_MODE=off REDIS_CLIENT=predis vendor/bin/phpunit --testsuite Integration` | Database isolation, PostgreSQL concurrency, M9 snapshot isolation, cache scoping | **PASS** (109 passed, 1 skipped, 961 assertions) | 81.15s |
| **PHPUnit Feature** | `XDEBUG_MODE=off REDIS_CLIENT=predis vendor/bin/phpunit --testsuite Feature` | Full exchange workflows, paper pipeline, candle backfill, APIs | **PASS** (555 tests, 7,609 assertions) | 381.52s |
| **Go Mod Verify** | `cd research-go && go mod verify` | Go dependency integrity | **PASS** (all modules verified) | 0.05s |
| **Go Vet & Build** | `cd research-go && go build ./... && go vet ./...` | Static analysis & compilation | **PASS** (clean compilation) | 0.42s |
| **Go Format** | `cd research-go && test -z "$(gofmt -l .)"` | Go code formatting | **PASS** (0 unformatted files) | 0.02s |
| **Go Test Race** | `cd research-go && go test -race -count=1 ./...` | Concurrency & race detection across algorithms, backtest, jobrunner, candidates | **PASS** (all packages pass) | 4.10s |
| **Frontend Tests** | `cd frontend && npm test` | Quasar / Vue 3 / Node test suite | **PASS** (64 tests passed) | 0.85s |
| **Frontend Build** | `cd frontend && npm run build` | Production Vite / TypeScript bundle | **PASS** (built in 8.16s) | 8.16s |
| **F-02 Test Suite** | `bash scripts/tests/test_deploy_workflow_ci_verification.sh` | 7 automated CI verification scenarios | **PASS** (7/7 scenarios passed) | 0.15s |
| **F-03 Test Suite** | `bash scripts/tests/test_deploy_immutable_pull_scenarios.sh` | 5 automated immutable pull & rollback scenarios | **PASS** (5/5 scenarios passed) | 0.18s |
| **Git Whitespace** | `git diff --check` | Whitespace errors and conflict markers | **PASS** (clean) | 0.02s |

---

## 4. Safety & System Boundaries Compliance

- **PAPER Mode Only:** Zero live exchange order endpoints, zero credentials with trading permissions, read-only public market data ingestion strictly enforced.
- **Account Isolation:** Preserved all 26 independent paper accounts (13 Bybit + 13 Binance) and 3 permanently blocked Bybit accounts (`blocked-1`, `blocked-2`, `blocked-3`).
- **Financial Ledger Integrity:** Zero modifications to historical financial records, double-entry ledger entries, or virtual trade logs.
- **ECONOM Resource Isolation:** Zero modifications or interactions with external or shared ECONOM services/containers.
- **Operator-Controlled Deployment:** Zero direct production deployments, zero git push operations; changes remain strictly local on `main`.

---

## 5. Artifacts and File Manifest

### Modified Files:
- `.github/workflows/deploy.yml` — Fail-closed CI check with Python JSON parsing.
- `docker/scripts/deploy.sh` — Strict image inspection in pull mode; removed build fallback.
- `docker/scripts/update.sh` — Strict image inspection in pull mode; removed build fallback.
- `phpunit.xml` — Explicit test database environment variables.
- `tests/TestCase.php` — Universally available `assertIsolatedDatabase()` and `isSafeTestHost()`.
- `tests/Integration/M9SnapshotIsolationPostgresIntegrationTest.php` — Clean inheritance of base isolation assertions.

### New Test Files:
- `tests/Unit/Database/PostgresTestDatabaseIsolationGuardTest.php` — 10 unit tests for database isolation guards.
- `scripts/tests/test_deploy_workflow_ci_verification.sh` — 7 automated tests for deploy workflow CI check.
- `scripts/tests/test_deploy_immutable_pull_scenarios.sh` — 5 automated tests for immutable Docker pull and rollback.

### Documentation:
- `VECTOR_RELEASE_HARDENING_REPORT.md` — This comprehensive release hardening report.

---

## 6. Release Verdict

**VERDICT: READY_FOR_OWNER_REVIEW**  
All three confirmed audit blockers (F-01, F-02, F-03) are resolved, verified by automated unit, integration, shell, and regression test suites. The codebase maintains strict safety boundaries, financial ledger integrity, and immutable deployment guarantees.
