# VECTOR — Final CI Recovery Acceptance Report

**Date:** 2026-11-07  
**Repository:** `/home/thunder/work/traderbybit`  
**Branch:** `main`  
**Status:** `LOCAL_CI_FIXES_VERIFIED`

---

## 1. Executive Summary & Safety Boundaries

This engineering cycle resolved the confirmed root causes of both failing GitHub Actions CI jobs:
1. **`PHP (feature-2)`** — `INSUFFICIENT_SAFE_DISK_SPACE` in `AcquireCommandTest` and candle seeding integrity in `EventDrivenTradingPipelineChainTest`.
2. **`workflow-checks`** — Docker Compose digest pull cache miss (`test_cached_digest_pull_avoids_registry_and_missing_content_fails`) and malformed `PYCODE` heredocs in GitHub workflows.

### Strict Safety Adherence
- **Virtual Paper Mode Only:** All operations remain strictly in virtual paper trading mode. No live exchange trading or withdrawal orders are placed; read-only API keys only.
- **Topology & Account Preservation:** The dynamic 10-exchange × 13-strategy = 130 virtual portfolios architecture and historical 26 Bybit/Binance account ledgers remain completely intact and unaffected.
- **Isolation:** `econom-group` and external projects were untouched.
- **Deployment & Git Restrictions:** No remote git pushes or manual production deployments performed. All changes and verification are confined to verified local commits on `main`.

---

## 2. Workstream A — PHP Feature Shard 2 (`feature-2`)

### 2.1 Failure 1: Disk Space Threshold in Historical Acquisition Test
- **Failing Test:** `Tests\Feature\HistoricalData\AcquireCommandTest::test_acquire_creates_batch_and_dispatches_entries_with_limit`
- **Error Stack:**
  ```text
  App\HistoricalData\AcquisitionFailure: INSUFFICIENT_SAFE_DISK_SPACE
  app/HistoricalData/StagingStorage.php:37
  app/HistoricalData/BatchControl.php:31
  tests/Feature/HistoricalData/AcquireCommandTest.php:54
  ```
- **Forensic Root Cause:**
  - `StagingStorage::admit()` called native `disk_free_space($root)`.
  - The required disk space threshold is computed as:
    `$required = reserve_disk_bytes (10 GB) + max_staging_bytes (6 GB) + databaseEstimate (~2.2 GB) = ~18.2 GB`.
  - While local development workstations have hundreds of gigabytes of free disk space (e.g. 871 GB), standard GitHub Actions `ubuntu-latest` runners typically operate with only ~12–14 GB of free root partition space after checking out the repository, downloading tools, and building Docker images.
  - Because `free_bytes < required_bytes`, `StagingStorage::admit()` threw `INSUFFICIENT_SAFE_DISK_SPACE`.
  - Furthermore, `StagingStorage` lacked an injectable disk-space abstraction, preventing tests from deterministically validating disk capacity without altering production safety constants.

### 2.2 Remediation
1. **Injectable Disk Space Dependency:** Created [`App\HistoricalData\DiskSpaceProbe`](file:///home/thunder/work/traderbybit/app/HistoricalData/DiskSpaceProbe.php) with method `freeBytes(string $path): float|int|false` delegating to `@disk_free_space($path)` in production.
2. **Decoupled Staging Storage:** Updated [`App\HistoricalData\StagingStorage`](file:///home/thunder/work/traderbybit/app/HistoricalData/StagingStorage.php) to accept `DiskSpaceProbe` via constructor injection (`$this->diskProbe->freeBytes($root)`), preserving all production threshold checks and safety invariants without reducing production margins.
3. **Deterministic Test Coverage:**
   - In [`tests/Feature/HistoricalData/AcquireCommandTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/AcquireCommandTest.php), injected a deterministic 100 GB `DiskSpaceProbe` stub into the container for `test_acquire_creates_batch_and_dispatches_entries_with_limit`.
   - Added `test_acquire_aborts_with_insufficient_safe_disk_space` verifying that `INSUFFICIENT_SAFE_DISK_SPACE` is predictably thrown when available disk space is below safety limits.
   - Added unit test suite [`tests/Unit/HistoricalData/StagingStorageTest.php`](file:///home/thunder/work/traderbybit/tests/Unit/HistoricalData/StagingStorageTest.php) verifying sufficient capacity, insufficient capacity, and probe error handling.
4. **Idempotent Candle Seeding:** In [`tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php), switched `seedContiguousHistory` to use `updateOrCreate` to ensure idempotency across wall-clock test executions.

### 2.3 Verification Evidence
- `AcquireCommandTest`: 3/3 passed (12 assertions) in 5.5s.
- `StagingStorageTest`: 3/3 passed (8 assertions) in 0.05s.
- `EventDrivenTradingPipelineChainTest`: 2/2 passed (29 assertions) in 14.3s.

---

## 3. Workstream B — Workflow Checks (`workflow-checks`)

### 3.1 Failure 2: Cached Digest Pull in `test_deployment_drift_integration.py`
- **Failing Test:** `test_cached_digest_pull_avoids_registry_and_missing_content_fails`
- **Error Stack:**
  ```text
  AssertionError: 0 != 1
  web Pulling
  app Pulling
  research-worker Pulling
  Get "http://127.0.0.1:49989/v2/": connect: connection refused
  ```
- **Forensic Root Cause:**
  - In `docker/scripts/common.sh`, `pull_release_images()` executed `compose pull --policy missing app web research-worker` when a valid exact-SHA digest manifest existed.
  - Docker Compose's internal `imageAlreadyPresent` implementation (docker/compose#12726) has an upstream bug when evaluating digest-pinned image strings (`image:tag@sha256:...`): it fails to match local daemon digests against image summaries and falls back to issuing registry pull API requests.
  - When the isolated local registry was stopped (`self.registry_compose('stop', 'registry')`), Compose's attempt to contact `127.0.0.1:49989` failed with `connection refused`.

### 3.2 Heredoc Indentation Syntax Defect
- In `.github/workflows/ci.yml` (line 458) and `.github/workflows/deploy.yml` (line 147), Python code inside `python3 - <<'PYCODE'` heredocs had 10–12 spaces of leading indentation. When executed, Python threw `IndentationError: unexpected indent`.

### 3.3 Remediation
1. **Local Digest Presence Guard in `pull_release_images`:**
   In [`docker/scripts/common.sh`](file:///home/thunder/work/traderbybit/docker/scripts/common.sh):
   - Validates the exact-SHA digest overlay across all 10 application services.
   - For each deployment target (`app`, `web`, `research-worker`), checks whether the pinned image reference is already present in the local Docker daemon via `docker image inspect "${img}"`.
   - If all targets are present locally with matching digests, the pull step returns 0 immediately without invoking registry network traffic.
   - If any target is missing from the local daemon (e.g. an unverified or corrupted digest), only the missing targets are pulled via `compose pull --policy missing "${pull_targets[@]}"`, failing closed if the registry is unreachable or the image does not exist.
   - If the overlay lacks verified exact-SHA digests (legacy tag-only), all targets are pulled with `--policy always`.
2. **Fixed Heredoc Indentation:**
   In [`.github/workflows/ci.yml`](file:///home/thunder/work/traderbybit/.github/workflows/ci.yml) and [`.github/workflows/deploy.yml`](file:///home/thunder/work/traderbybit/.github/workflows/deploy.yml), unindented Python heredocs to column 0, ensuring syntactically valid script execution.

### 3.4 Verification Evidence
- `test_cached_digest_pull_avoids_registry_and_missing_content_fails`: **PASSED** in 16.7s.
- `scripts/tests/test_*.py` (full suite): **83/83 PASSED** in 381.2s (0 errors, 0 failures).
- Deployment verification scripts:
  - `test_deploy_immutable_pull_scenarios.sh`: **5/5 PASSED**.
  - `test_deploy_workflow_ci_verification.sh`: **7/7 PASSED**.
  - `test_docker_compose_image_resolution.sh`: **9/9 PASSED**.
  - `test_deployment_recovery_matrix.sh`: **12/12 SCENARIOS PASSED**.
  - `test_same_sha_configuration_refresh.sh`: **100% OK**.
- Code formatting & whitespace:
  - `./vendor/bin/pint --test`: **PASSED**.
  - `git diff --check`: **Clean** (0 whitespace errors).

---

## 4. Modified Files

1. [`app/HistoricalData/DiskSpaceProbe.php`](file:///home/thunder/work/traderbybit/app/HistoricalData/DiskSpaceProbe.php) *(new)*
2. [`app/HistoricalData/StagingStorage.php`](file:///home/thunder/work/traderbybit/app/HistoricalData/StagingStorage.php)
3. [`tests/Feature/HistoricalData/AcquireCommandTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/HistoricalData/AcquireCommandTest.php)
4. [`tests/Unit/HistoricalData/StagingStorageTest.php`](file:///home/thunder/work/traderbybit/tests/Unit/HistoricalData/StagingStorageTest.php) *(new)*
5. [`tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php`](file:///home/thunder/work/traderbybit/tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php)
6. [`docker/scripts/common.sh`](file:///home/thunder/work/traderbybit/docker/scripts/common.sh)
7. [`.github/workflows/ci.yml`](file:///home/thunder/work/traderbybit/.github/workflows/ci.yml)
8. [`.github/workflows/deploy.yml`](file:///home/thunder/work/traderbybit/.github/workflows/deploy.yml)
9. [`reports/agent/VECTOR_FINAL_CI_RECOVERY_REPORT.md`](file:///home/thunder/work/traderbybit/reports/agent/VECTOR_FINAL_CI_RECOVERY_REPORT.md)

---

## 5. Acceptance Status

- **`PHP (feature-2)`:** Verified locally (all tests passing, deterministic disk space injection and validation in place).
- **`workflow-checks`:** Verified locally (all 83 Python tests and deployment shell scripts passing).
- **Workflow Syntax:** Verified (heredocs valid, actionlint compliant).
- **Topology & PAPER Invariants:** 10 exchanges × 13 strategies = 130 accounts preserved. Zero real exchange trading or withdrawal orders.
- **Overall Status:** `LOCAL_CI_FIXES_VERIFIED`.
