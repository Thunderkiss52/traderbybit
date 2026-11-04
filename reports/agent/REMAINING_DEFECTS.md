# Remaining Defects and Technical Debt Register

**Date:** 2026-11-04T18:39:00+03:00  
**Current Authority:** Git HEAD `a724429`  

---

## 1. Resolved Historical & Technical Findings

| Item | Focus / Description | Resolution Details & Verified Evidence | Status |
|---|---|---|---|
| **R3** | Worker lease, active-job uniqueness, fencing | Advisory lock and partial unique index (`b273ab7`). Clock lease expiry (`lease_expires_at > clock_timestamp()` in Go and `lease_expires_at > $now` in Laravel), failJob transaction locking, CAS stale lease reconciliation, secret sanitization (`1764922`). Verified by `GoResearchWorkerIntegrationTest` (4 passed) and `ResearchJobServiceTest` (22 passed). | **RESOLVED** |
| **R4** | PAPER safety guards and risk ceilings | Integrated owner-approval resume journal, blocked account persistence, unknown drawdown rejection, and hard risk ceilings (`cd3a1da`). Verified by `AccountSafetyRegressionTest` (10 passed). | **RESOLVED** |
| **R5** | Approved research experiment eligibility & candidate starvation | Added support for approved COMPLETED `ResearchExperimentRecord` instances in `PaperExecutionEligibilityService` (`3c58b98`). Removed candidate starvation loop in `ProcessAutonomousLearningJob`. Verified by `CentralExecutionEligibilityTest` (9 passed). | **RESOLVED** |
| **R6** | Global capital guard & topology synchronizer | Implemented `GlobalPaperCapitalGuard` (5% portfolio loss cap and entry risk ceiling with `pg_advisory_xact_lock(20261103, 50001)`) and `PaperTopologySynchronizer` (`0b17010`). Verified by `GlobalCapitalAndTopologyR6Test` (4 passed), `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest` (2 passed), and `TwentySixPaperAccountsVerificationTest` (6 passed). | **RESOLVED** |
| **R7** | Continuous learning & shadow evaluation | Guaranteed risk-blocked accounts continue offline research without interruption; candidate starvation loop eliminated (`3c58b98`). Real exchange execution gate firmly kept disabled. Verified by `AutonomousLearningPipelineTest` (3 passed). | **RESOLVED** |
| **R8** | Historical backtest audit ZIP export subsystem & sizing settings | Implemented `BacktestAuditService`, queue job `BuildBacktestAuditJob`, streaming ZIP `BacktestAuditArchiveBuilder`, CSV injection defense `BacktestAuditCsv`, credential redactor `BacktestAuditRedactor`, `PaperSizingSettings`, and `NewPrimaryCapitalPolicy` (`4ac1044`). Verified by `BacktestHistoricalAuditR8Test` (3 passed) and `R8SizingSettingsTest` (11 passed). | **RESOLVED** |
| **R9** | Temporal boundary correctness & input integrity | Validated aligned UTC scope, multi-timeframe candle selection (5m, 15m, 30m, 60m), continuity verification, lead-lag peer inclusion, and right-boundary event handling (`a1b7232`). Verified by `DatasetInspectorTest` (14 passed) and `TemporalIntegrityAndCausalityTest` (8 passed). | **RESOLVED** |
| **Defect 9 (High)** | Immutable historical dataset pinning | Implemented `historical_dataset_pins` table and `DatasetInspector::pin()` / `verifyPin()` (`407dc34`). Datasets are permanently pinned with scope fingerprints, series counts, and content checksums. Concurrent mutation during simulation is rejected. Verified by `DatasetInspectorTest` (14 passed). | **RESOLVED** |
| **Work Unit C** | End-to-end PAPER trading pipeline verification | Implemented `FullPaperTradingPipelineE2ETest.php` (`16517f8`, `a724429`). Verified complete lifecycle across Bybit & Binance with TP1, TP2, SL, timeout, funding events, fees, and M9 ledger reconciliation (7 passed, 68 assertions). | **RESOLVED** |
| **Work Unit B** | 26 canonical PAPER accounts onboarding | Implemented `TwentySixPaperAccountsVerificationTest.php` (`07fac76`). Verified onboarding Scenarios A, B, C, D, E and sizing options (6 passed, 320 assertions). | **RESOLVED** |
| **Dependency Locks** | Vulnerable locked dependencies | Remediated 7 package advisories across `composer.lock` and `frontend/package-lock.json` (`a553c7d`). Lockfile audits and builds pass cleanly. | **RESOLVED** |

---

## 2. Residual Operational Requirements (Production VPS Acceptance)

| Priority | Scope | Description & Requirement |
|---|---|---|
| **Gate 0** | Production Deployment | **Phase 0 Operational Obligations:** 45 requirements documented in `OWNER_ACTIONS_REQUIRED.md` require read-only execution on the production VPS and formal operator sign-off before manual deployment. Local test suites and mock environments cannot replace live read-only verification. |
| **Gate 1** | Production Preflight | Read-only production duplicate census and historical data validation must be executed prior to applying the partial unique index migration on the live database. |
| **Gate 2** | Real Exchange Execution | Real exchange trading endpoints remain strictly prohibited. API keys are public read-only market data only. |
