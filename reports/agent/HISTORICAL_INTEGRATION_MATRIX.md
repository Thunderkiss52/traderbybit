# Historical Integration Matrix — Comprehensive R1–R9 Status

**Date:** 2026-11-04T18:37:00+03:00  
**Current Authority:** Git HEAD `a724429`  
**Baseline:** `origin/main` (`afd5d19`)  

---

## 1. Integration Status Matrix

| Round | Description / Focus | Intended Behavior | Current Implementation & Evidence | Status |
|---|---|---|---|---|
| **R1** | Senior Audit Patch (23 paths, 122 hunks) | Address pre-R2 senior audit findings: audit export coverage, primary account metrics, risk checks. | All 23 target paths exist and are verified. `AuditExportService::writePrimaryCoverage` includes all primary accounts. Accounting identities enforced. | `ALREADY_PRESENT` / `INTEGRATED_VERIFIED` |
| **R2** | Cumulative / Incremental Patch (43 paths) | Intermediate fixes across paper trading, strategy inputs, indicators. | Architectural conventions evolved to modular structures with explicit contracts. Findings addressed in current code and verified by regression suite. | `ALREADY_PRESENT` / `SUPERSEDED` |
| **R3** | Research Worker & Job Concurrency | Active-job uniqueness, database clock lease expiry, worker resurrection prevention, CAS stale lease reconciliation, secret sanitization. | Enforced database clock lease expiry (`lease_expires_at > clock_timestamp()` in Go and `lease_expires_at > $now` in Laravel), failJob row locks, transaction-scoped advisory locks, and credential sanitization. Commits `b273ab7` & `1764922`. Verified by `GoResearchWorkerIntegrationTest` (4 passed) and `ResearchJobSubmissionPostgresIntegrationTest` (3 passed). | `INTEGRATED_VERIFIED` |
| **R4** | Safety Evolution & PAPER Risk Ceilings | Owner-approval resume journal, blocked account persistence, unknown drawdown rejection, hard paper risk ceilings. | Selective integration in commit `cd3a1da`: owner approval journaled for unblocking, blocked accounts never automatically unblocked, hard ceilings enforced regardless of config. Verified by `AccountSafetyRegressionTest` (10 passed). | `INTEGRATED_VERIFIED` |
| **R5** | Defect Fixes & Research Execution Eligibility | Research experiment execution eligibility, candidate starvation, calculation tolerances. | Added support for approved COMPLETED `ResearchExperimentRecord` instances in `PaperExecutionEligibilityService` (commit `3c58b98`). Removed candidate starvation loop. Verified by `CentralExecutionEligibilityTest` (9 passed). | `INTEGRATED_VERIFIED` |
| **R6** | Global Capital Guard & Topology Synchronization | Global 5% portfolio loss cap, prospective risk ceiling, advisory lock serialization, 26 accounts topology synchronization. | Implemented `GlobalPaperCapitalGuard` with `pg_advisory_xact_lock(20261103, 50001)` and `PaperTopologySynchronizer` (commit `0b17010`). Verified by `GlobalCapitalAndTopologyR6Test` (4 passed), `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest` (2 passed), and `TwentySixPaperAccountsVerificationTest` (6 passed). | `INTEGRATED_VERIFIED` |
| **R7** | Learning Lifecycle & Candidate Starvation | Offline research uninterrupted for blocked accounts, candidate starvation prevention, shadow evaluation. | Excluded finished and exhausted candidates from active in-flight evaluation slots in `ProcessAutonomousLearningJob` (commit `3c58b98`). Blocked accounts continue offline research. Real exchange execution gate firmly kept disabled. Verified by `AutonomousLearningPipelineTest` (3 passed). | `INTEGRATED_VERIFIED` |
| **R8** | Backtest Audit ZIP Subsystem & Sizing Settings | Asynchronous ZIP archive exporter with member checksums, CSV injection defense, credential redaction, sizing settings, 10,000 USDT capital policy. | Implemented `BacktestAuditService`, queue job `BuildBacktestAuditJob`, streaming ZIP `BacktestAuditArchiveBuilder`, `BacktestAuditCsv`, `BacktestAuditRedactor`, `PaperSizingSettings`, and `NewPrimaryCapitalPolicy` (commit `4ac1044`). Verified by `BacktestHistoricalAuditR8Test` (3 passed) and `R8SizingSettingsTest` (11 passed). | `INTEGRATED_VERIFIED` |
| **R9** | Temporal Boundary Correctness & Input Integrity | Inclusive/exclusive boundaries, UTC precision, multi-timeframe candle selection, lead-lag peer inclusion, right-boundary event consumption. | Integrated verified temporal intent with explicit correction for event time `to` in `DatasetInspector` and `BacktestRunService` (commit `a1b7232`). Added immutable historical dataset pinning in `historical_dataset_pins` (commit `407dc34`). Verified by `DatasetInspectorTest` (14 passed) and `TemporalIntegrityAndCausalityTest` (8 passed). | `INTEGRATED_VERIFIED` |

---

## 2. Summary of Classifications

- **Total Historical Units Assessed:** 9 (R1 through R9).
- **INTEGRATED_VERIFIED:** 7 (R1, R3, R4, R5, R6, R7, R8, R9).
- **ALREADY_PRESENT / SUPERSEDED:** 2 (R1, R2).
- **MISSING / CONFLICTING / REGRESSED:** 0. All compatible fixes are integrated into the current codebase with passing regression tests.
