# Architecture and data-flow audit

## Current historical path

`BacktestRunService` receives UTC bounds and symbols, asks `DatasetInspector` for a source checksum, binds strategy/paper configuration into a fingerprint, then runs `ChronologicalSplitter` and `GridOptimizer`. `BacktestSimulationEngine` reads 5-minute closed candles by open time, uses `StrategySignalEvaluator` and `StrategyInputFactory` for 5/15/30/60-minute indicators, reads as-of context for execution metadata and bid/ask, and accrues funding through each candle close. Results persist in `backtest_runs`, `backtest_segments`, trades, equity snapshots, and reports. Laravel remains the PAPER financial writer. Go research workers are separate; current Go datasets/backtest packages are not the archive's proposed immutable dataset system.

## Current export path

`AuditExportService` is a private forward-PAPER/research CSV ZIP, explicitly marked `FORWARD_PAPER_AND_RESEARCH_NO_HISTORICAL_BACKTEST`. It uses `[from,to)` for ordinary event rows and bounded warm-up candles, stages CSVs, writes a manifest and checksum list, then publishes the ZIP. The R8/R9 historical-backtest audit service/ZIP exists only in the archive, so there is no current simulator-to-historical-ZIP lineage to accept. R9's double-checksum design is itself not a repeatable-read snapshot.

## Material gaps and ownership

- Market candles, context, and funding tables remain mutable PostgreSQL rows. A run recheck now rejects visible mutation before completion, but no immutable dataset version or transaction snapshot binds all asynchronous reads.
- Current persisted run fingerprint contains source checksum, bounds, strategy/paper configuration and versions. It lacks an immutable dataset ID, full consumed-row manifest, durable retention pin and engine revision. The lead-lag peer is now included in the checksum but not listed as a separate persisted dataset-symbol field.
- Dataset Inspector now covers configured 30-minute setup candles and a pre-window context observation. Source conflict journal state and every possible strategy-specific auxiliary input are not yet included in a durable manifest.
- Forward audit exports still combine queries at different read-committed instants; verified ZIP bytes do not prove a single database snapshot. `DailyPerformanceService` and source queries need one coherent read-only snapshot before product acceptance.
- Current source has no R6 global capital guard or R8 backtest audit subsystem. R3 active-job uniqueness migration is absent. Candidate packages cannot be treated as production features.

No production, browser, or actual forward-PAPER database was accessed for this audit.
