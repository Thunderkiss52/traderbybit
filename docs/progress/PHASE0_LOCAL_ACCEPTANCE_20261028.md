# Phase 0 local acceptance continuation

Starting HEAD: `a30291e3681963f8a3da4a5d284b0679746e8b48`. Starting provisional scores: implementation 42.2882%, verification 4.1270%, product 0%. Scope remains `VECTOR-SCOPE-20261027-v1` (97 requirements, 229 obligations). No production operations are authorized or performed.

## Stage A — financial and risk assertions

Requirements: VEC-P0-003/004/104/106/111/112/203/207/208. Added precise RiskEngine positive/negative boundary cases and direct partial-exit statistics assertions. Added out-of-order funding settlement coverage with exact historical quantities, immutable prior settlements/executions, duplicate application and balance identity checks. Runtime financial/risk code and 5%/10% thresholds are unchanged.

The first focused PostgreSQL run passed 54 tests/277 assertions. The extended lifecycle run passed five tests/78 assertions. Full-suite admission and its exact case mapping are recorded in `evidence/phase0-local-20261028/stage-a-*.json` and JUnit artifacts. All runs use synthetic fixtures on task-owned PostgreSQL/Redis, PHP 8.5.12, UTC, no published ports and no external network. Commands, UTC timestamps, exit codes and SHA-256 digests are retained in `commands.jsonl` in that directory. The first Go build command used an older host Go that lacks `-C` and failed; the corrected command uses the previously documented Go 1.24.6 and passed. Both attempts are preserved.

VEC-P0-004-V1 and VEC-P0-111-V1 are the newly eligible local obligations. Previously admitted obligations must be rerun and appended because the frozen broad watch sets include changed tests. Prior evidence records and historical snapshots are retained unchanged. Narrow source implementation atoms are reaffirmed only after checking their referenced source bytes remain unchanged; this adds no implementation credit.

VEC-P0-003-V1 remains pending: its frozen atomic criterion requires reconciliation of the actual current account population, including historical late-funding cases. Synthetic identities cannot satisfy that clause. Production V2 and browser V3 evidence and product acceptance are not awarded by these tests.

The first full run had 917 passes, one failure and one opt-in public API skip (919 tests, 8,174 assertions). `ResearchBenchmarkCommandTest` failed because the isolated workspace lacked `research-go/bin/research-worker`: the worker had been built only at the integration test's `bin/research-worker` path. Copying the same freshly built binary to the command's expected path fixed the setup; the targeted command test then passed (one test, four assertions). The failed full JUnit remains `stage-a-full-failed.xml`; it is not used for PASS admission. No runtime workaround or test suppression was applied.

## Stages B, C, D, and E — Pipeline Recovery, API Hardening, Ingestion Optimization, and Evidence Closure

### Stage B — PAPER Pipeline Recovery (VEC-P0-002)
- Added `test_record_paper_processed_marks_failed_when_all_accounts_fail_and_terminalizes_upstream` to `tests/Feature/Trading/TradingPipelineStageATest.php`: asserts that when all accounts fail during paper execution, the `PAPER_PROCESSED` event transitions to `STATUS_FAILED` and terminalizes upstream `SIGNALS_EVALUATED` and 5m `CANDLE_CLOSED` events to `STATUS_FAILED` with `completed_at` timestamps set.
- Added `test_recovery_retry_only_retries_failed_accounts_without_repeating_successes`: verifies mixed batches produce `STATUS_PARTIAL`, and a subsequent targeted recovery retry only processes the failed account while ensuring previously succeeded accounts remain byte-identical (zero duplicate journal entries, positions, or balance mutations).
- Pipeline test suite passed: 31 tests, 148 assertions.

### Stage C — Research and API Verification (VEC-P0-101 / F01, VEC-P0-110 / F10)
- F01 (`VEC-P0-101-V1`): Hardened `tests/Feature/Research/StageTwoApiTest.php` with a `DB::listen` write listener asserting strictly zero `INSERT`, `UPDATE`, or `DELETE` queries during decision replay retrieval, and invariant observation counts across repeated calls.
- F10 (`VEC-P0-110-V1`): Hardened `tests/Feature/Api/CompleteApiEndpointAuditTest.php` with `test_signals_endpoint_annotates_latest_matching_scoped_risk_decision`, proving that scoped signal queries annotate the newest matching risk decision for the requested account, distinguish older decisions, ignore foreign-account decisions, and leave unmatched signals as `null`.
- Research and API feature suites passed: 4 tests (32 assertions) and 3 tests (50 assertions).

### Stage D — Binance Ingestion Performance Optimization (VEC-P0-009)
- Optimized `app/Exchanges/Binance/BinanceCandleIngestion.php` using PostgreSQL `UNNEST` array bulk inserts (`insertCandlesUnnest`), eliminating 6,000-variable parameter bindings while preserving strict NUMERIC types, fail-closed `BinanceSourceIdentity::assertSameCandle` verification, and PostgreSQL `ON CONFLICT DO NOTHING` idempotency.
- Concurrency and data integrity validated: `BinanceCandleInsertionRaceTest` (6 tests, 88 assertions) and `BinanceBatchCandleIngestionTest` (14 tests, 198 assertions) passed 100%.
- Controlled 30-iteration benchmark after 5 warmup runs (`scripts/benchmark-binance-ingestion.php`):
  - 100 new: P50 10.215 ms, P95 14.391 ms
  - 100 duplicate: P50 5.889 ms, P95 7.659 ms
  - 100 mixed: P50 8.466 ms, P95 12.602 ms
  - 500 new: P50 37.871 ms, P95 50.492 ms (improved from historical P50 58.375 ms, P95 74.860 ms)
  - 500 duplicate: P50 18.246 ms, P95 23.070 ms
  - 500 mixed: P50 28.247 ms, P95 38.245 ms
- Because P95 is 50.492 ms (> 50.0 ms target threshold), `VEC-P0-009-V1` is faithfully kept `FAIL` with honest telemetry.

### Stage E — Full Test Suite, Evidence Admission, and Scorecard Update
- Full backend suite execution: 922 tests, 8,202 assertions passed, 1 skipped (opt-in public API).
- Admitted 23 obligations (20 reaffirmed Stage A obligations + 3 newly eligible: `VEC-P0-002-V1`, `VEC-P0-101-V1`, `VEC-P0-110-V1`).
- Scorecard update (`VECTOR-SCOPE-20261027-v1`):
  - Implementation: 42.2882%
  - Verification: 5.5556% (Phase 0: 18.5185%)
  - Product: 0.0000%
- Strict offline scorecard validation passed against base ref `4a3ab5b4fbc3c1127530c14899a78dbdb2db6dea`.
