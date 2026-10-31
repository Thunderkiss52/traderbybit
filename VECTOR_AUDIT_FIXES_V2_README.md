# VECTOR Audit Fixes V2 — Comprehensive Patch & Integration Guide

## 1. Patch Metadata

- **Baseline Commit**: `858c084 — ci: optimize test matrix and deployment pipeline for 60s targets`
- **Patch File**: `VECTOR_AUDIT_FIXES_V2.patch`
- **Scope**: Source audit remediation, cross-exchange ML isolation, Go research worker dynamic exchange resolution, Binance pipeline lineage correlation, database test guards, and CI/CD test environment resilience.
- **Safety Standard**: PAPER-only. Zero live trading, zero credential escalation, zero historical ledger mutation.

---

## 2. Included Fixes & Components

1. **Cross-Exchange ML Isolation**:
   - `app/Http/Controllers/Api/V1/AutonomousLearningController.php`
   - `app/Research/Application/LearningPredictorService.php`
   - `app/Research/Application/ModelAdvisoryService.php`
   - `app/Research/Jobs/ProcessAutonomousLearningJob.php`
   - Feature tests: `AutonomousLearningPipelineTest.php`, `LearningPredictorRollbackTest.php`, `LegacyActiveModelSafetyTest.php`, `ModelAdvisoryDecisionVetoTest.php`
   - Unit tests: `ChampionChallengerLifecycleTest.php`, `LearningPredictorServiceTest.php`, `LogisticRegressionTrainingTest.php`, `ModelAdvisoryIntegrationTest.php`
2. **Go Research Worker Exchange Selection & Candidate Lineage**:
   - `research-go/internal/jobscope/exchange.go` *(New)*
   - `research-go/internal/jobscope/exchange_test.go` *(New)*
   - `research-go/internal/jobrunner/runner.go`
   - `research-go/internal/jobrunner/runner_test.go`
   - `research-go/internal/candidates/pipeline.go`
   - `research-go/internal/candidates/types.go`
3. **Database Test Isolation & Redis Client Fallback**:
   - `config/database.php`: Safe dynamic Redis client selection fallback to `predis`.
   - `tests/Integration/M9SnapshotIsolationPostgresIntegrationTest.php`: `assertIsolatedDatabase()` guard on destructive table truncations.
4. **Binance Concurrency Pipeline Diagnostics**:
   - `scripts/diagnostics/binance_failed_pipeline_lineage_readonly.sql` *(New)*: Read-only correlation SQL query for 15m candle close events and 5m paper processing executions.
5. **Documentation & Architecture Governance**:
   - `VECTOR_AUDIT_REMEDIATION_REPORT.md`
   - `VECTOR_UNUSED_MODULES_REVIEW.md`
   - `VECTOR_ROADMAP_IMPLEMENTATION_MATRIX.md`

---

## 3. How to Apply and Verify the Patch

### Step 1: Pre-flight Verification on Clean Checkout of `858c084`
```bash
git checkout 858c084
git status --short
# Verify working tree is clean
```

### Step 2: Validate Patch Integrity
```bash
# Check applicability without modifying files
git apply --check VECTOR_AUDIT_FIXES_V2.patch
```

### Step 3: Apply the Patch
```bash
git apply VECTOR_AUDIT_FIXES_V2.patch
```

### Step 4: Run the Complete Verification Suite
```bash
# 1. Go research worker tests with race detector
cd research-go
go mod verify
go build ./...
go vet ./...
test -z "$(gofmt -l .)"
go test -count=1 ./...
go test -race -count=1 ./...
cd ..

# 2. PHP Code Formatting
XDEBUG_MODE=off vendor/bin/pint --test

# 3. PHPUnit Unit Suite
XDEBUG_MODE=off vendor/bin/phpunit --testsuite Unit

# 4. PHPUnit Integration Suite
XDEBUG_MODE=off vendor/bin/phpunit --testsuite Integration

# 5. Frontend Tests & Build
cd frontend
npm test
npm run build
cd ..
```
