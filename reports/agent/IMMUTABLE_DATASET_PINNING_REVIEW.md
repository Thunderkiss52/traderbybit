# Immutable Historical Dataset Pinning Architecture Review

**Date:** 2026-11-04T19:18:00+03:00  
**Authority:** Independent Release Verification Lead & Quantitative Systems Architect  
**Scope:** Dataset Pinning, Mutation Detection, Cryptographic Consistency, and Historical Reproducibility  

---

## 1. Architectural Model & Classification

The implementation in VECTOR provides:
**A Content-Addressed Cryptographic Dataset Pin with Pre-Run Fingerprinting and Post-Run Mutation Detection (Verification Gate)**.

It is **not** a storage-level copy-on-write clone or a database transaction held open across hours of computation. Instead, it provides:
1. **Mathematical Content Addressing:** Computes a unique `scope_fingerprint = sha256(exchange|category|symbols|from|to|warmup)`.
2. **Deterministic Cryptographic Hashing:** Traverses all OHLCV bars across four essential timeframes (5m, 15m, 30m, 60m), including preceding warmup bars, within-window market context records (bid, ask, turnover, tick size, funding rate, open interest), and market funding events.
3. **Immutable Database Ledger:** Records the verified scope, timestamps, series counts, warnings, and SHA-256 checksum into the `historical_dataset_pins` table (migration `2026_11_04_000003_create_historical_dataset_pins_table.php`).
4. **Pre-Run vs Post-Run Guard:**
   - In `BacktestRunService::run()`, the dataset is pinned prior to simulation start.
   - At the conclusion of the simulation, `verifyPin()` re-hashes the exact range from the database.
   - If any candle, context, or funding row was mutated, inserted, or deleted during the backtest run, the run immediately fails with `RuntimeException('Historical inputs changed during backtest.')` and is never marked `COMPLETED` or reproducible.
5. **Conflict Rejection:** Any subsequent run attempting to reuse an identical scope fingerprint with altered underlying data is rejected with `RuntimeException('Historical dataset conflict: dataset content differs from previously pinned version.')`.

---

## 2. Temporal & Continuity Verification

`DatasetInspector::inspect()` enforces strict temporal boundaries:
- Rejects subsecond timestamps (`from->format('u') !== '000000'`).
- Enforces 5-minute alignment (`getTimestamp() % 300 === 0`).
- Strictly excludes open candles (`is_closed == true`).
- Strictly excludes the bar at `to` (exclusive right boundary).
- Detects and rejects gaps in historical continuity without fabricating prices.
- Hashes prior context and warmup bars even when nominal warm-up is set to zero.

---

## 3. Verified Test Evidence

| Test Scenario | Test Class & Method | Result | Assertions |
|---|---|---|---|
| Deterministic checksum calculation | `DatasetInspectorTest::test_checksum_is_deterministic_for_complete_closed_history` | **PASS** | 2 |
| Continuity gap detection | `DatasetInspectorTest::test_gap_is_rejected_instead_of_fabricating_a_price` | **PASS** | 2 |
| 30m hierarchy hashing | `DatasetInspectorTest::test_configured_thirty_minute_history_is_required_and_hashed` | **PASS** | 2 |
| Prior context & subsecond timing | `DatasetInspectorTest::test_prior_context_and_subsecond_observation_time_affect_checksum` | **PASS** | 2 |
| Right-boundary event handling | `DatasetInspectorTest::test_close_boundary_context_and_funding_are_hashed_but_later_events_are_dropped` | **PASS** | 2 |
| Idempotent dataset pinning | `DatasetInspectorTest::test_dataset_pinning_is_idempotent_and_creates_immutable_record` | **PASS** | 6 |
| Conflict rejection on mutation | `DatasetInspectorTest::test_dataset_pinning_rejects_conflicting_historical_mutation` | **PASS** | 2 |
| Simulation mutation abort | `BacktestRunServiceTest::test_changed_source_during_run_fails_before_completion` | **PASS** | 3 |

**Suite Total:** 20 tests passed (295 assertions).
