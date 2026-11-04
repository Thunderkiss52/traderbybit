# Implementation report — 2026-11-04

## Completed local unit: historical input boundary integrity

Baseline `afd5d194f02e3478c5c6c2e4dfdd00380c216a00`. No archive patch was applied wholesale. The implemented fix is based on the current simulator's actual candle-open `[from,to)` selection and final-close event semantics. `DatasetInspector` now validates aligned UTC scope; covers all four consumed timeframes, full closes, continuity, configured warm-up, pre-window context, and funding/context through the final close; preserves millisecond timestamps; and streams candle rows. The run service includes the BTC/ETH lead-lag peer and checks the source fingerprint again before publishing completion. The forward audit exporter verifies every final ZIP member against hashes captured before assembly. A recovery test fixture was made independent of wall-clock scheduler dispatch.

Changed source: `app/Backtest/Application/{DatasetInspector,BacktestRunService}.php`, `app/Audit/Application/AuditExportService.php`, and three source Eloquent records (`CandleRecord`, `MarketContextRecord`, `MarketFundingEventRecord`). Changed tests: `tests/Feature/Backtest/{DatasetInspectorTest,BacktestRunServiceTest}.php`, `tests/Feature/AuditExportTest.php`, `tests/Feature/Exchanges/BinanceGapRecoveryTest.php`. All changes are local, PAPER/research only.

## Historical fixes applied

The verified temporal intent of R9 is integrated in current code, with an explicit correction for event time `to`. R9's literal `<to` context/funding change was rejected because the final selected 5-minute candle closes at `to` and can consume an event at that instant. The archive's R1–R8, Phase 0, and Phase 1.x patches remain classified in `HISTORICAL_INTEGRATION_MATRIX.md`; none were applied wholesale.

## Acceptance boundary

The resulting checksum is a before/after mutation detector, not an immutable version or one stable read snapshot. Simulations, ordinary audit ZIPs, and asynchronous jobs cannot yet be independently reproduced from a retained source snapshot. No historical-backtest audit ZIP exists at this HEAD. Phase 0 and migration phases are not accepted. No production data or deployment was touched.

## Completed local unit: R3 active research-job uniqueness

After the temporal unit, the archived R3 research-job race was confirmed in current source and corrected with a transaction-scoped PostgreSQL advisory lock, row lock, and partial unique index. The migration aborts on pre-existing active duplicates without rewriting jobs. Isolated two-process PostgreSQL contention, direct duplicate rejection, terminal key reuse, and duplicate-preflight tests passed. Details and exact files are in `R3_RESEARCH_JOB_SUBMISSION.md`. Other R3 worker-lease hunks remain a separate task.

## Completed local unit: dependency locks

`composer.lock` and `frontend/package-lock.json` were updated to patched versions after the baseline scans found seven package findings. Installed local dependencies, both audits, the full PHP suite, 64 frontend tests, TypeScript and production build all passed. See `DEPENDENCY_REMEDIATION.md`. No runtime application behavior or production image was changed in this unit.

## Completed local unit: R4 PAPER safety guards

The current account reactivation and risk-boundary gaps were corrected without raising any default risk limit or modifying an existing account. Owner approval is now required for a blocked/halted-to-ACTIVE transition and is journaled; recovery and unknown drawdown remain blocked. Risk and sizing enforce the existing fixed ceilings even if configuration is loosened. Focused isolated PostgreSQL tests passed; see `R4_PAPER_RISK_GUARDS.md`. The full R4 patch remains unintegrated.

## Completed local unit: R6 Global Paper Capital Guard and Topology Synchronizer

Implemented `GlobalPaperCapitalGuard` (5% portfolio loss cap and entry risk ceiling on PRIMARY accounts) and `PaperTopologySynchronizer` (idempotently reconciling enabled exchanges $\times$ primary strategies topology). Serialized PostgreSQL entries using `pg_advisory_xact_lock(20261103, 50001)`. Made Binance deployment, provisioning, capital, and reconciliation dynamic across registered primary strategies, and added bounded mutation parameter spaces for all 13 strategy families. Statically recompiled `research-worker`. Committed as `0b17010`. Verified by `GlobalCapitalAndTopologyR6Test` (4 passed), `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest` (2 passed), `ThirteenPrimaryAccountsTest` (3 passed).

## Completed local unit: R8 Historical Backtest Audit Subsystem and Sizing Settings

Implemented asynchronous, signed-download `BacktestAuditService`, queue job `BuildBacktestAuditJob`, streaming ZIP archive builder `BacktestAuditArchiveBuilder` (exporting segments, trades, equity, optimizations, reports, candles, market context, funding, and SHA-256 member manifest), spreadsheet formula injection escaping `BacktestAuditCsv`, and recursive credential redaction `BacktestAuditRedactor`. Added `PaperSizingSettings` (supporting fixed notional and fixed margin with 2x leverage cap) and `NewPrimaryCapitalPolicy` (10,000 USDT virtual starting balance). Wired sizing into `PaperProcessingService` and `PositionSizingService`. Committed as `4ac1044`. Verified by `BacktestHistoricalAuditR8Test` (3 passed), `BacktestAuditCsvR8Test` (1 passed), `BacktestAuditRedactorR8Test` (1 passed), `R8CapitalPolicyTest` (4 passed), `R8SizingSettingsTest` (11 passed).

## Completed local unit: R3 Worker Lease and Fencing Integrity

Enforced database clock lease expiry check (`lease_expires_at > clock_timestamp()` in Go and `lease_expires_at > $now` in Laravel) across `StartJob`, `Heartbeat`, `CompleteJob`, and `FailJob`. Added row lock `lockForUpdate()` in `failJob` to prevent stale workers from resurrecting cancelled or reclaimed jobs. Added atomic compare-and-swap update in `reconcileStaleLeases`. Added credential and DSN sanitization to Go worker `FailJob`. Recompiled static binary `research-go/bin/research-worker` with `CGO_ENABLED=0`. Committed as `1764922`. Verified by `GoResearchWorkerIntegrationTest` (4 passed), `ResearchJobServiceTest` (22 passed), and `ResearchJobSubmissionPostgresIntegrationTest` (3 passed).
