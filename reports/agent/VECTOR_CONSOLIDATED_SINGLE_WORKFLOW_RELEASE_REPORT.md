# VECTOR — Consolidated Single Workflow Release & Approval Architecture Report

**Date:** 2026-11-07  
**Repository:** `/home/thunder/work/traderbybit`  
**Branch:** `main`  
**Status:** `VERIFIED_LOCAL_ACCEPTANCE`

---

## 1. Executive Summary & Objective

Per operator directive, the CI/CD and deployment workflows have been consolidated into **exactly one workflow** in the GitHub Actions sidebar:

**`.github/workflows/ci.yml` (`Release`)**

### Core Requirements Satisfied
1. **Single Sidebar Entry:** Exactly one `.yml` file exists in `.github/workflows/`, eliminating split workflow confusion.
2. **Push Starts Build Automatically:** A push to `main` (and PRs) immediately triggers all 13 test and verification jobs, builds Docker images, publishes immutable SHA-tagged and digest-pinned images to GHCR, and verifies complete execution through the authoritative `CI required` aggregate gate.
3. **Deploy Never Runs Automatically:** Deployment to production is strictly gated. Under no circumstances will a push automatically deploy to production without explicit human action.
4. **Exact-SHA Provenance:** Production deployment strictly executes the verified commit SHA and published GHCR image digests. No rebuilding or duplicate CI occurs during deployment.
5. **No Blocked Branch Protection:** `CI required` reports its status check to GitHub immediately upon completion; it is never blocked while `Deploy` awaits human approval.
6. **Cancellation Immunity:** Concurrency is configured so subsequent pushes to `main` never cancel an approved or running production deployment.

---

## 2. Platform Evaluation: GitHub Environments & "Required Reviewers"

### Platform Analysis for `Thunderkiss52/traderbybit`
- **Repository Visibility:** Private repository (`origin: git@github.com:Thunderkiss52/traderbybit.git`).
- **GitHub Plan Rules for Private Repositories:**
  - **GitHub Free:** Environments can be created and hold secrets/variables, but Environment Protection Rules (*Required reviewers*, *Wait timer*, *Deployment branches*) are disabled.
  - **GitHub Pro / Team / Enterprise:** Environment Protection Rules (*Required reviewers*) are fully available on private repositories.

### Dual-Mode Operational Architecture
To ensure seamless operation regardless of the GitHub account plan while maintaining a 100% fail-closed guarantee against accidental deployment, the consolidated workflow supports two operational modes:

```
                                  [ Push to main ]
                                         │
                                         ▼
                             ┌───────────────────────┐
                             │  Parallel Build Jobs  │
                             │  (13 shards + GHCR)   │
                             └───────────┬───────────┘
                                         │
                                         ▼
                             ┌───────────────────────┐
                             │      CI required      │ (Posts Green Checkmark
                             │    Aggregate Gate     │  for Branch Protection)
                             └───────────┬───────────┘
                                         │
               ┌─────────────────────────┴─────────────────────────┐
               │                                                   │
  [ ENABLE_NATIVE_DEPLOY_APPROVAL=true ]            [ Default / GitHub Free ]
               │                                                   │
               ▼                                                   ▼
 ┌───────────────────────────┐                       ┌───────────────────────────┐
 │   environment: production │                       │   Deploy stage skipped    │
 │    (Waiting for Review)   │                       │       on push run         │
 └─────────────┬─────────────┘                       └─────────────┬─────────────┘
               │                                                   │
  (Operator approves in UI)                               (Operator clicks
               │                                           "Run workflow" → deploy)
               ▼                                                   │
 ┌───────────────────────────┐                                     ▼
 │   Deploy Production       │                       ┌───────────────────────────┐
 │   (Same Run: ~60-90s)     │                       │   Deploy Production       │
 └───────────────────────────┘                       │   (Dispatch Run: ~60-90s) │
                                                     └───────────────────────────┘
```

#### Mode A: Native Single-Run Approval (Build → Manual Approval → Deploy)
- **Applicability:** Account has GitHub Pro, Team, or Enterprise, with *Required reviewers* configured on environment `production`.
- **Activation:** Set repository variable `ENABLE_NATIVE_DEPLOY_APPROVAL = true`.
- **Execution:** Push to `main` runs Build → `ci-required` succeeds → `Deploy` transitions to *"Waiting for review"*. The operator clicks **"Review deployments"** on the same run page and approves deployment. Build and Deploy execute within the **exact same workflow run**.

#### Mode B: Zero-Risk Manual Dispatch (GitHub Free / Standalone Operator Release)
- **Applicability:** Account is on GitHub Free or operator prefers decoupled deployment trigger.
- **Activation:** Leave `ENABLE_NATIVE_DEPLOY_APPROVAL` unset (or `false`).
- **Execution:** Push to `main` runs Build and passes `ci-required`; `Deploy` is skipped. When ready to deploy, open **Actions → Release → Run workflow** (Action: `deploy`). The deployment executes in ~60–90s without rebuilding.

---

## 3. Concurrency & Protection Invariants

1. **Workflow-Level Concurrency:**
   ```yaml
   concurrency:
     group: ci-${{ github.workflow }}-${{ github.ref }}
     cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}
   ```
   - On pull requests or feature branches, obsolete test runs are cancelled automatically on new push.
   - On `main`, `cancel-in-progress` is strictly `false`. A new push to `main` will **never cancel** an in-flight build or deployment.
2. **Deploy Job-Level Concurrency:**
   ```yaml
   concurrency:
     group: bybit-signals-production
     cancel-in-progress: false
   ```
   - Production deployments join the dedicated `bybit-signals-production` mutual exclusion group.
   - Multiple deployment attempts queue safely; no concurrent deployments are possible.
3. **Stale Target Protection:**
   - In both Mode A and Mode B, `release_guard.py current` checks that the deployment target SHA matches the exact current `main` HEAD immediately before archive transfer and host wrapper invocation.

---

## 4. Verification Evidence

| Quality Check | Command | Result |
|---|---|---|
| Single Workflow File Assertion | `python3 -m unittest scripts/tests/test_cicd.py` | **PASS** (17/17 tests pass; verifies exactly 1 workflow file) |
| Workflow Syntax & Policy | `/tmp/actionlint -shellcheck=''` | **PASS** (0 errors across all workflow triggers and steps) |
| Deploy CI Authorization Matrix | `bash scripts/tests/test_deploy_workflow_ci_verification.sh` | **PASS** (7/7 scenario assertions pass) |
| PHP Code Formatting | `./vendor/bin/pint --test` | **PASS** |
| Whitespace & Patch Cleanliness | `git diff --check` | **PASS** (0 warnings) |

---

## 5. GitHub Repository Settings Configuration Instructions

To enable Mode A (Native Single-Run Approval), the operator should perform the following one-time configuration in GitHub:

1. Navigate to: `GitHub → Repository Settings → Environments`.
2. Click **`production`** (or create it if not present).
3. Under **Deployment protection rules**, check **Required reviewers**.
4. Add your GitHub username (or designated deployment operators).
5. Click **Save protection rules**.
6. Navigate to: `Settings → Secrets and variables → Actions → Variables`.
7. Click **New repository variable**:
   - Name: `ENABLE_NATIVE_DEPLOY_APPROVAL`
   - Value: `true`
8. Click **Add variable**.

If the repository is on GitHub Free and environment protection rules are unavailable, keep `ENABLE_NATIVE_DEPLOY_APPROVAL` unset. Mode B will safely operate via the single workflow's `Run workflow` button with zero risk of automatic deployment.
