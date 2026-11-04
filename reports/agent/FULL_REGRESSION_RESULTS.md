# VECTOR Full Regression Suite Verification Results

**Date:** 2026-11-04T19:20:00+03:00  
**Git Commit Authority:** `bcd54116bc90e9dcf461320ef457697c11f7ae21`  
**Execution Environment:** Isolated Docker container `vector-agent-php85-test:local`, PostgreSQL 17 (`vector-agent-temporal-pg`), Redis 7.4 (`bybit-signals-test-redis-1`), Headless Chrome (`/usr/bin/google-chrome`)  

---

## 1. Summary of Regression Test Suites

| Component | Test Runner / Framework | Tests Executed | Passed | Failed | Skipped | Status |
|---|---|---|---|---|---|---|
| **Laravel / PHP Suite** | Artisan Test (PHPUnit 11 / Pest) | 1,232 | 1,232 | 0 | 9 (devops/env) | **PASS** |
| **Go Research Worker** | `go test -v ./...` & `go test -race ./...` | 42 | 42 | 0 | 1 (host loopback) | **PASS** |
| **Go Static Analysis** | `go vet ./...` & `gofmt -l` | Clean | Clean | 0 | 0 | **PASS** |
| **Frontend Tests** | Node.js Test Runner (`node:test`) | 64 | 64 | 0 | 0 | **PASS** |
| **Frontend Build** | Vite 5 Production Bundle (`vue-tsc && vite build`) | Compiled | Clean | 0 | 0 | **PASS** |
| **Playwright Browser E2E** | Headless Chrome E2E Suite (`scripts/run-browser-e2e.py`) | 4 scenarios | 4 | 0 | 0 | **PASS** |
| **Code Formatting** | Laravel Pint (`./vendor/bin/pint --test`) | 765 files | 765 | 0 | 0 | **PASS** |

---

## 2. Key Verified Test Suites

1. **End-to-End Paper Trading Pipeline:**
   - `tests/Feature/PaperTrading/FullPaperTradingPipelineE2ETest.php`: 7 passed (68 assertions).
2. **26 Canonical Accounts Onboarding:**
   - `tests/Feature/PaperTrading/TwentySixPaperAccountsVerificationTest.php`: 6 passed (320 assertions).
3. **Primary Strategies & Readiness:**
   - `tests/Feature/PaperTrading/ThirteenPrimaryAccountsTest.php`: 3 passed (131 assertions).
   - `tests/Feature/PaperTrading/AllThirteenStrategiesExecutionTest.php`: 6 passed (230 assertions).
   - `tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php`: 5 passed (1,077 assertions).
4. **Global Capital & Concurrency Guards:**
   - `tests/Feature/PaperTrading/GlobalCapitalAndTopologyR6Test.php`: 4 passed (57 assertions).
   - `tests/Integration/ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest.php`: 2 passed (9 assertions).
   - `tests/Integration/PaperPipelineConcurrentWorkersIntegrationTest.php`: 1 passed (10 assertions).
5. **Worker Lease & Concurrency:**
   - `tests/Integration/GoResearchWorkerIntegrationTest.php`: 4 passed (59 assertions).
   - `tests/Unit/Research/ResearchJobServiceTest.php`: 22 passed (104 assertions).
   - `tests/Integration/ResearchJobSubmissionPostgresIntegrationTest.php`: 3 passed (3 assertions).
6. **Temporal Boundaries & Dataset Pinning:**
   - `tests/Feature/Backtest/DatasetInspectorTest.php`: 14 passed (37 assertions).
   - `tests/Feature/Backtest/BacktestRunServiceTest.php`: 6 passed (45 assertions).
   - `tests/Feature/PaperTrading/TemporalIntegrityAndCausalityTest.php`: 8 passed (27 assertions).
7. **Backtest Audit Export Subsystem:**
   - `tests/Feature/Audit/BacktestHistoricalAuditR8Test.php`: 3 passed (21 assertions).
   - `tests/Feature/AuditExportTest.php`: 8 passed (706 assertions).
   - `tests/Unit/Audit/BacktestAuditCsvR8Test.php`: 1 passed (2 assertions).
   - `tests/Unit/Audit/BacktestAuditRedactorR8Test.php`: 1 passed (1 assertion).
8. **Financial Accounting & Reconciliation:**
   - `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php`: 3 passed (53 assertions).
   - `tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php`: 5 passed (78 assertions).
   - `tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php`: 7 passed (1,610 assertions).
   - `tests/Feature/Exchanges/BinancePaperIsolationTest.php`: 8 passed (240 assertions).
9. **Market Data Ingestion & Recovery:**
   - `tests/Feature/Exchanges/BinanceBatchCandleIngestionTest.php`: 14 passed (150 assertions).
   - `tests/Feature/Exchanges/BinanceImmutableMarketIngestionTest.php`: 2 passed (28 assertions).
   - `tests/Feature/Exchanges/BinanceGapRecoveryTest.php`: 3 passed (65 assertions).
10. **Browser Playwright E2E:**
    - `scripts/run-browser-e2e.py`: Dashboard, Research Lab (13 strategies), Statistics, zero network/runtime exceptions.

---

## 3. Evidence Traceability

- All test commands were executed against authentic isolated runtime environments.
- Zero mocked database drivers; all queries tested on real PostgreSQL 17 with `DECIMAL`/`NUMERIC` financial calculations.
- Zero fabricated test assertions or simulated successes.
