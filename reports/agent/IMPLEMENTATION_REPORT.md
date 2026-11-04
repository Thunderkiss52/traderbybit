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
