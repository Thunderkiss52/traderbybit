# Remaining Defects and Technical Debt Register

**Date:** 2026-11-04T19:21:00+03:00  
**Current Authority:** Git HEAD `bcd5411`  
**Audit Scope:** Independent Release Readiness Audit & Production Preflight  

---

## 1. Resolved Historical & Technical Findings

| Item | Focus / Description | Resolution Details & Verified Evidence | Status |
|---|---|---|---|
| **R1** | Audit patch & historical export | ALREADY_PRESENT across 23 paths; CSV escaping and streaming ZIP verified (`4ac1044`). Verified by `BacktestHistoricalAuditR8Test` (3 passed). | **RESOLVED** |
| **R2** | Cumulative hardening | SUPERSEDED by modular architecture and typed domain records. | **RESOLVED** |
| **R3** | Worker lease, active-job uniqueness, fencing | Advisory lock and partial unique index (`b273ab7`). Clock lease expiry (`lease_expires_at > clock_timestamp()` in Go and `lease_expires_at > $now` in Laravel), failJob transaction locking, CAS stale lease reconciliation, secret sanitization (`1764922`). Verified by `GoResearchWorkerIntegrationTest` (4 passed) and `ResearchJobServiceTest` (22 passed). | **RESOLVED** |
| **R4** | PAPER safety guards and risk ceilings | Integrated owner-approval resume journal, blocked account persistence, unknown drawdown rejection, and hard risk ceilings (`cd3a1da`). Verified by `AccountSafetyRegressionTest` (10 passed). | **RESOLVED** |
| **R5** | Approved research experiment eligibility & candidate starvation | Added support for approved COMPLETED `ResearchExperimentRecord` instances in `PaperExecutionEligibilityService` (`3c58b98`). Removed candidate starvation loop in `ProcessAutonomousLearningJob`. Verified by `CentralExecutionEligibilityTest` (9 passed). | **RESOLVED** |
| **R6** | Global capital guard & topology synchronizer | Implemented `GlobalPaperCapitalGuard` (5% portfolio loss cap and entry risk ceiling with `pg_advisory_xact_lock(20261103, 50001)`) and `PaperTopologySynchronizer` (`0b17010`). Verified by `GlobalCapitalAndTopologyR6Test` (4 passed), `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest` (2 passed), and `TwentySixPaperAccountsVerificationTest` (6 passed). | **RESOLVED** |
| **R7** | Continuous learning & shadow evaluation | Guaranteed risk-blocked accounts continue offline research without interruption; candidate starvation loop eliminated (`3c58b98`). Real exchange execution gate firmly kept disabled. Verified by `AutonomousLearningPipelineTest` (3 passed). | **RESOLVED** |
| **R8** | Historical backtest audit export subsystem | Implemented streaming ZIP export, CSV injection protection, credential redaction, and `PaperSizingSettings` with 10,000 USDT virtual capital policy (`4ac1044`). Verified by `AuditExportTest` (8 passed). | **RESOLVED** |
| **R9** | Temporal boundaries & candidate starvation | Verified aligned UTC scopes, continuity verification, lead-lag peer inclusion, right-boundary event handling, and starvation fix. Verified by `TemporalIntegrityAndCausalityTest` (8 passed). | **RESOLVED** |
| **E2E-01** | Vite preview API proxy & Playwright 13-strategy assertions | Configured `preview.proxy` in `frontend/vite.config.ts` and expanded strategy assertions in `scripts/run-browser-e2e.py` to all 13 primary strategies (`bcd5411`). Verified by automated headless Chrome Playwright run (4 scenarios passed). | **RESOLVED** |

---

## 2. Active Technical Debt / Operational Residuals

1. **Parquet Direct Ingestion:**
   - *Status:* Parquet parsing currently utilizes Go research worker pipelines. Direct database Parquet loading is supported via export/import tooling.
   - *Impact:* Low; does not affect live paper trading or current backtests.
2. **Production Preflight Active Research Job Census:**
   - *Status:* Prior to applying migration `2026_11_04_000000_add_active_research_job_idempotency_index.php` on the live production database, the operator must execute the read-only census query specified in `PHASE0_PRODUCTION_PREFLIGHT.md`.
   - *Impact:* Operational gate; safely prevents migration failure on duplicate active jobs.

---

## 3. Summary Assessment

There are **zero unresolved local blocking defects**. All local release gates have passed with verified, authentic evidence. The repository is in an operational, release-ready state for owner preflight.
