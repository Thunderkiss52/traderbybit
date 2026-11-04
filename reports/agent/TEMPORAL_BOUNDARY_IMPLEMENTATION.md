# Temporal boundary implementation — local candidate

## Focused changes

1. `DatasetInspector` now streams closed 5/15/30/60-minute candles that are available by `to`, checks each timeframe's last fully closed bar and exact continuity, requires 5-minute-aligned bounds, hashes millisecond timestamps, warm-up rows (at least one preceding bar), context/funding through the last close, and the latest pre-window context. Invalid scopes fail closed.
2. Source Eloquent models preserve millisecond timestamps and timezone offsets on writes.
3. `BacktestRunService` includes the BTC/ETH peer in lead-lag dataset validation and repeats the source checksum before completing. A mismatch leaves the run `FAILED`; an unchanged source still completes and reuses the fingerprint.
4. `AuditExportService` streams all final ZIP members back and checks their hashes against pre-assembly values before publishing. A changed member fails the export.

## Executed negative controls

PostgreSQL tests reject missing 30-minute history, gaps, absent exact 5-minute `from`, subsecond `to`, missing peer history, and a candle mutation injected by a database trigger during a run. A ZIP with bytes changed after checksum capture is rejected. Tests verify warm-up mutation, non-hour start/partial higher-timeframe end, UTC offset equivalence, millisecond context/funding persistence, and the event at the final close versus one millisecond later.

## Remaining consistency limit

The second checksum detects persisted changes at completion; it is not a repeatable-read transaction and cannot prove that all intermediate reads saw one immutable version. The forward audit ZIP check proves member-byte integrity against its manifest, not consistency among separate database queries. Historical simulator-to-audit export/replay remains unavailable until an immutable dataset/version or snapshot implementation is designed and integrated.
