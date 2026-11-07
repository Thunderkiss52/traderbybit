# VECTOR — Production Deployment Recovery Runbook & Forensic Acceptance Report

**Date:** 2026-11-07  
**Repository:** `/home/thunder/work/traderbybit`  
**Target Release Candidate:** `6d1ca50229a36f3a2d80ef0a8e50cc038ab93db1`  
**Classification:** Critical Production Infrastructure Remediation & Operations Runbook  
**Mode:** Strictly PAPER Mode (Zero real exchange orders; read-only market data keys only)

---

## 1. Executive Incident Summary & Forensic Attribution

On the production VPS host (`/srv/bybit-signals/`), an inconsistency was confirmed between the running container runtime environment and the canonical deployment metadata:

### 1.1 Confirmed Runtime State
- **Running Containers:** All ten (10) application services are uniformly and stably running image tag `sha-129c33c933a688cbc7a5e372d2ea41504a6f677a`:
  - `app`
  - `web`
  - `queue`
  - `queue-bybit`
  - `queue-binance`
  - `scheduler`
  - `market-ws`
  - `audit-worker`
  - `backtest-worker`
  - `research-worker`
- **Filesystem Symlink:** `/srv/bybit-signals/current` points to `/srv/bybit-signals/releases/129c33c933a688cbc7a5e372d2ea41504a6f677a`.
- **Database Migrations:** PostgreSQL has applied 11 migrations in batch 24 (dated November 4–5, 2026), originally introduced in commit `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`.
- **Database Data Integrity:** All 26 independent paper accounts (13 Bybit, 13 Binance) and all 130 portfolio records remain intact.

### 1.2 Discrepancy & Attempted Deployment Failure
- **Canonical Deployment Metadata (`deploy-state.json`):**
  ```json
  {
    "status": "CONSISTENT",
    "current_release_sha": "832de9b5c2ff5c25351c25fa2e110a78a5f574ca",
    "current_image_tag": "sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca",
    "previous_release_sha": "129c33c933a688cbc7a5e372d2ea41504a6f677a",
    "previous_image_tag": "sha-129c33c933a688cbc7a5e372d2ea41504a6f677a"
  }
  ```
- **Projection Files:**
  - `.deploy-current`: `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca`
  - `release-current`: `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`
- **Deployment Block:** When attempting to deploy candidate `6d1ca50229a36f3a2d80ef0a8e50cc038ab93db1`, pre-deployment validation (`validate-deployment-state.sh pre-deploy`) queried `CURRENT_TAG` (`sha-832de9b5...`) from metadata, compared it to running containers (`sha-129c33c9...`), and halted fail-closed with:
  ```
  [FATAL] Mixed-version containers detected in pre-deploy check. Realign containers before deploying.
  ```

---

## 2. Root Cause Forensic Analysis

Detailed code inspection of historical release `832de9b5:docker/scripts/update.sh` proved the exact sequence of events that produced this state:

1. **Premature Metadata Commit:**  
   In `832de9b5`, lines 130–135 called `commit_deployment_state "${NEW_TAG}" "${CURRENT_TAG}" ... "CONSISTENT"` **before** post-deployment validation and image cleanup had completed.
2. **Post-Commit Validation Failure:**  
   When post-deployment validation or cleanup encountered an error (such as a timeout or digest format mismatch), the shell `ERR` trap fired `rollback_application()`.
3. **Uncoordinated Application Rollback:**  
   `rollback_application()` executed:
   ```bash
   export IMAGE_TAG="${CURRENT_TAG}" # sha-129c33c9...
   compose up -d --no-build --no-deps "${APPLICATION_SERVICES[@]}"
   exit "${failure_code}"
   ```
   This restored the application containers to `129c33c9`, but **never restored `deploy-state.json`, `.deploy-current`, or `release-current` back to `129c33c9`**.
4. **Wrapper Abort Prior to Symlink Update:**  
   Because `update.sh` exited non-zero, `/usr/local/sbin/bybit-signals-deploy` halted on `set -e` before line 74 (`ln -sfn ... current`). The symlink `/srv/bybit-signals/current` stayed at `129c33c9`.
5. **Database Migration Invariant:**  
   Because database migrations are intentionally never automatically rolled back, batch 24 remained applied. Because batch 24 migrations were purely additive tables and columns, `129c33c9` continued serving traffic and handling jobs normally.
6. **Defect in Completed Journal Handling (`recover-deployment-state.sh`):**  
   Lines 260–285 inspected `recovery-journal.json` on resume. When a journal was already in state `COMMITTED` from a previous recovery session, the script failed to archive it, incorrectly treating it as an active recovery session and aborting fail-closed if the requested target differed from the historical target.

---

## 3. Engineering Remediations Implemented

The following fixes have been implemented, verified, and integrated into the repository:

### 3.1 Coordinated Metadata Updates in `docker/scripts/update.sh`
- `rollback_application()` now coordinates metadata persistence:
  - If rollback verification succeeds: commits state as `ROLLED_BACK` and updates `.deploy-current` and `release-current` to the restored release (`CURRENT_TAG`).
  - If rollback verification fails: commits state as `ROLLBACK_FAILED` fail-closed.
- `trap - ERR` is disarmed immediately upon acceptance and state commit, before image cleanup or storage reports.

### 3.2 Real Service Image Mapping in `docker/scripts/common.sh`
- `commit_deployment_state()` dynamically resolves the exact image reference for each of the 10 application services via `resolve_service_image_references()`.
- Populates `services` dictionary in `deploy-state.json` with actual qualified image references and runtime statuses instead of placeholder empty strings.
- Synchronizes projection files atomically; removes stale `.deploy-previous` if identical to `.deploy-current`.

### 3.3 Container Drift Categorization in `docker/scripts/validate-deployment-state.sh`
- Inspects running container images across all 10 application services.
- Distinguishes **Uniform Runtime Release Mismatch** (all 10 containers uniformly running a single verified tag that differs from canonical metadata) from **Genuine Mixed-Version Containers** (services running disparate versions).
- Under `AUTO_RECOVER_ON_DEPLOY=1`, automatically reconciles canonical metadata to the uniform running release without interrupting healthy containers, re-validates, and proceeds with deployment.
- Interrupted update guard: detects pending in-progress updates (`MIGRATING`, `TRANSITIONING`, `BLOCKED_SCHEMA_REVIEW`, `ROLLBACK_FAILED`) in `pre-deploy` and fails closed before any container or database mutations.

### 3.4 Historical Recovery Journal Rotation in `docker/scripts/recover-deployment-state.sh`
- Detects if `recovery-journal.json` is already in state `COMMITTED`. If so, automatically archives it to `recovery-journal-<recovery_id>.json` preserving the audit trail, and starts a fresh recovery session.
- Automatically adopts the uniform running container tag as target if `--target-tag` is omitted.
- Skips container restart during recovery when containers are already running the target image (`verify_image_identity` returns 0).
- Adds explicit `--realign-metadata` support.

### 3.5 Fallback & Guidance in `docker/scripts/production-deploy-wrapper.sh`
- Wrapper checks newest unpacked release directory for `recover-deployment-state.sh` before falling back to `current`.
- Emits clear recovery instructions if pre-deployment checks fail.

---

## 4. Production Recovery Runbook

### Safety Preconditions
- **PAPER Trading Only:** All orders are virtual; real exchange orders are strictly prohibited.
- **Do Not Roll Back Database:** The 11 migrations in batch 24 must **NOT** be rolled back or deleted. Candidate `6d1ca502` and all subsequent releases require these migrations.
- **Do Not Touch ECONOM Resources:** Any containers, volumes, or networks belonging to `econom-group` or `econom` must never be modified.

---

### Procedure A: Automated GitHub Actions CI/CD Deployment (Recommended)

Because `AUTO_RECOVER_ON_DEPLOY=1` is exported by the production deploy wrapper, the fixed release candidate automatically heals the metadata inconsistency during standard deployment:

1. **Trigger Automated CI:**
   Push verified commits on `main` to trigger the GitHub Actions workflow (`.github/workflows/ci.yml`).
2. **Verify CI Required Status:**
   Confirm that `CI required` is green and image digests are published to GHCR.
3. **Dispatch Production Deployment:**
   - In GitHub Actions, navigate to **Deploy Production**.
   - Click **Run workflow** selecting branch `main` (commit `6d1ca502...` or HEAD).
4. **Automated Remote Execution Flow:**
   - Deploy wrapper extracts the archive to `/srv/bybit-signals/releases/6d1ca502...`.
   - `validate-deployment-state.sh pre-deploy` runs from the new release.
   - Detects all 10 containers running `sha-129c33c933a688cbc7a5e372d2ea41504a6f677a` with metadata at `sha-832de9b5...`.
   - Automatically executes metadata reconciliation to `sha-129c33c9...`.
   - Re-runs pre-deploy validation (PASS).
   - Runs `update.sh`: executes new database migrations (batch 25), transitions containers to `sha-6d1ca502...`, verifies readiness and health.
   - Updates `/srv/bybit-signals/current` symlink to `6d1ca502...`.

---

### Procedure B: Manual Operator CLI Recovery on VPS (If Executed by Operator)

If the VPS operator chooses to reconcile deployment state manually via SSH before deploying:

#### Step 1: Connect to VPS and Inspect State
```bash
ssh <DEPLOY_USER>@<DEPLOY_HOST>
sudo /usr/local/sbin/bybit-signals-deploy recover --inspect
```
*Expected Output:*
- Shows status: `CONSISTENT`
- Shows current tag: `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca`
- Shows container drift: `YES (Uniform Release Mismatch: containers=sha-129c33c933a688cbc7a5e372d2ea41504a6f677a vs metadata=sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca)`

#### Step 2: Archive Stale Completed Recovery Journal (If Present)
```bash
sudo ls -la /srv/bybit-signals/deploy-state/recovery-journal.json
# If state in journal is COMMITTED:
sudo mv -f /srv/bybit-signals/deploy-state/recovery-journal.json \
  /srv/bybit-signals/deploy-state/recovery-journal-archived-$(date +%s).json
```

#### Step 3: Realign Deployment Metadata to Running Release (`129c33c9`)
```bash
sudo /usr/local/sbin/bybit-signals-deploy recover --repair \
  --target-tag sha-129c33c933a688cbc7a5e372d2ea41504a6f677a \
  --target-release 129c33c933a688cbc7a5e372d2ea41504a6f677a
```
*Expected Behavior:*
- Resolves local images for `sha-129c33c9...`.
- Realign containers step: detects all 10 services already match `sha-129c33c9...`; skips restart (`Skipping restart`).
- Probes readiness and executes healthcheck (`PASS`).
- Commits `deploy-state.json`, `.deploy-current`, and `release-current` with `sha-129c33c9...`.
- Zero container recreation; zero virtual trading disruption.

#### Step 4: Verify Deployment State
```bash
sudo /usr/local/sbin/bybit-signals-deploy inspect
# Or:
sudo DEPLOY_STATE_DIR="/srv/bybit-signals/deploy-state" ENV_FILE="/srv/bybit-signals/.env.production" \
  /srv/bybit-signals/current/docker/scripts/validate-deployment-state.sh pre-deploy
```
*Expected Output:*
```
Pre-deployment state validation passed.
Exit code: 0
```

#### Step 5: Proceed with GitHub Actions Production Deployment
Trigger **Deploy Production** for `6d1ca50229a36f3a2d80ef0a8e50cc038ab93db1`.

---

## 5. Verification & Test Evidence

### 5.1 Unit and Update Failure Injection Suite
Command: `python3 -m unittest scripts/tests/test_deployment_update.py -v`
```
test_backup_failures_preserve_running_services ... ok
test_cleanup_failure_does_not_rollback_accepted_release ... ok
test_failed_migration_requires_review_before_retry ... ok
test_forward_migration_blocks_application_rollback ... ok
test_interrupted_migration_in_progress_blocks_predeploy_validator ... ok
test_invalid_digest_manifest_fails_before_pull ... ok
test_migration_failure_never_rolls_back_or_transitions ... ok
test_missing_image_preserves_running ... ok
test_pull_failure_preserves_running_and_state ... ok
test_same_sha_preserves_previous ... ok
test_success_and_timing_summary ... ok
test_wrong_image_health_partial_and_interruption_roll_back ... ok
test_wrong_revision_blocks_before_transition ... ok

Ran 13 tests in 109.657s
OK (Exit code: 0)
```

### 5.2 Deployment Recovery & Resilience Matrix
Command: `bash scripts/test_deployment_recovery_matrix.sh`
```
=== Running SC-01: Clean State Validation === [PASS]
=== Running SC-02: Corrupted Duplicate State === [PASS]
=== Running SC-03: Missing Release Metadata === [PASS]
=== Running SC-04: Real Container Drift Detection === [PASS]
=== Running SC-05: Target Image Validation Path === [PASS]
=== Running SC-06: Real Database Schema Compatibility Verification === [PASS]
=== Running SC-07: Interruption & Safe Resumption with Same Recovery ID === [PASS]
=== Running SC-08: Individual Service Readiness Failure Prevents State Commit === [PASS]
=== Running SC-09: Lock Reentrancy & Competing Process Contention === [PASS]
=== Running SC-10: Stage Failure Injection & Safe Retry === [PASS]
=== Running SC-11: Bootstrap Recovery with Explicit Targets === [PASS]
=== Running SC-12: Subsequent Clean Deployment Validation === [PASS]
=== Running SC-13: Uniform Running Release Mismatch Detection & Reconciliation === [PASS]
=== Running SC-14: Completed Historical Recovery Journal Archival === [PASS]
=== Running SC-15: Interrupted Update Detection in pre-deploy === [PASS]
=== Running SC-16: Rollback Failed Status Detection === [PASS]

FINAL TEST MATRIX RESULTS: 16/16 SCENARIOS PASSED
PostgreSQL Table Digests 100% Preserved Across All Scenarios.
Zero Automated Migration Rollbacks Executed.
Exit code: 0
```

### 5.3 Additional Regression Suites
- `bash scripts/test_same_sha_configuration_refresh.sh` -> **PASSED (100% OK)**
- `bash scripts/tests/test_deploy_immutable_pull_scenarios.sh` -> **PASSED (5/5)**
- `bash scripts/tests/test_docker_compose_image_resolution.sh` -> **PASSED (9/9)**
- `bash scripts/tests/test_deploy_workflow_ci_verification.sh` -> **PASSED (7/7)**
- `python3 -m unittest scripts/tests/test_cicd.py -v` -> **PASSED (17/17)**
- `git diff --check` -> **CLEAN (0 errors, 0 trailing whitespace)**

---

## 6. Safety Confirmation
- Real exchange trading: strictly prohibited; read-only market data only.
- 130 portfolios (10 exchanges × 13 strategies): preserved and verified.
- PostgreSQL financial records: table digests 100% identical.
- Zero live deployments or git pushes performed.
