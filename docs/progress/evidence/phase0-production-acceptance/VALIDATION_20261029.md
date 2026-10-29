# Phase 0 remediation validation — 2026-10-29

Companion to `REMEDIATION_20261029.md`. Base/deployed source reviewed locally:
`5b674960adc8ff6591134537763dce7a5bb0bb3c`. Worktree changes were uncommitted when
these commands ran. No production host, database, migration, Artisan mutation, API
mutation, deployment or Git push was used. All mutations were confined to the
isolated test PostgreSQL service `127.0.0.1:25432` (`bybit_test`) and test fixtures.

## Environment and scope

| Item | Observed |
| --- | --- |
| PHP CLI | 8.4.24 |
| PostgreSQL | 17.11 (isolated container `vector-phase0-integrity-pg-20261029`) |
| Node / npm | v20.20.0 / 10.8.2 |
| Scope | Phase 0 only; V3.3 roadmap and Phase 1 untouched |
| Safety | PAPER only; no real order path added or invoked |

## Root causes corrected in code

1. **Binance bootstrap equity.** Provisioning inserted a zero `account:initial:binance`
   snapshot before owner capital. Separately, `CapturePaperEquityJob` could snapshot an
   unfunded blocked account as zero. Performance readers treated that zero as the peak
   baseline, producing a false 100% drawdown. Provisioning no longer writes the zero,
   capture skips never-funded accounts, and proven pre-capital zeroes (legacy initial
   row or historical checkpoint) are excluded only when a linked positive
   `OWNER_APPROVED_PAPER_ALLOCATION`, matching funded snapshot, amount, time and insert
   order exist. A genuine post-funding zero remains a loss. Raw rows and audit exports
   are preserved.
2. **Legacy Bybit funding.** Ingestion reconciliation required `opened_at >= since`,
   so a position opened before the fetched window never retried after its funding data
   arrived. Retry now includes positions held across the interval and closed after the
   cutoff. Bybit no longer substitutes a trade-candle close for a settlement mark: it
   reads the OPEN of the exact closed 1-minute public mark-price candle. New provenance
   is recorded; legacy NULL provenance is not rewritten and conflicts fail closed.
   Repeated verified ingestion retries reconciliation even when no new event row was
   inserted (also applied to Binance).
3. **Pipeline terminalization and recovery.** Recovery now retries fresh `PARTIAL`/
   `FAILED` PAPER batches by failed/unprocessed account identities, preserves expired
   failure evidence without late execution, never overwrites a synchronous terminal
   status with `DISPATCHED`, acknowledges expired deliveries without fills, and commits
   each account's effects with its durable checkpoint. Legacy aggregates without
   identities are not replayed blindly.
4. **Temporal funding snapshot.** A late funding adjustment recorded current account
   equity at the historical funding timestamp. It now uses application time, while the
   funding ledger and journal retain the settlement economic timestamp.
5. **Autonomy training boundary.** Model training now enforces effective
   `retrain_models` permission plus both kill switches at service entry and again
   before champion/artifact writes; the scheduler skips denied training.

## Verification commands and actual results

| Command | Result |
| --- | --- |
| `XDEBUG_MODE=off php artisan test` | **PASS** — 973 tests, 972 passed, 1 skipped, 9710 assertions, 388 922 ms |
| `tests/Integration/BybitPublicApiIntegrationTest.php` (part of the 1 skip) | Skipped by design: `RUN_BYBIT_INTEGRATION=1` not set; no live exchange call |
| `XDEBUG_MODE=off ./vendor/bin/pint --test` | **PASS** — no style violations |
| `python3 -m unittest discover -s scripts/tests -p 'test_*.py' -v` | **PASS** — 32 tests (7 funding classifier, 25 tracker) |
| `tests/Feature/Exchanges/BootstrapEquityIntegrityTest.php` | **PASS** — 3 tests, 69 assertions (includes new historical-checkpoint regression) |
| `tests/Feature/PaperTrading/Phase0DiagnosticPackageTest.php` | **PASS** — 2 tests, 48 assertions after Query 14 broadened |
| Final targeted rerun after test-format/SQL-only edits | **PASS** — 5 tests, 117 assertions |
| `npm run test` (frontend) | **PASS** — 64 tests, 0 failures |
| `npx vue-tsc --noEmit` (frontend) | **PASS** — exit 0 |
| `npm run build` (frontend) | **PASS** — Vite production build, 9.13 s |
| `python3 scripts/vector_progress.py --generate` | **PASS** — projections regenerated; scores unchanged |
| `python3 scripts/vector_progress.py --strict --base-ref 5b674960adc8ff6591134537763dce7a5bb0bb3c` | **PASS** — exit 0 |
| `python3 -m unittest scripts.tests.test_vector_progress -v` | **PASS** — 25 tests |

The single suite skip is the opt-in live Bybit public API test. No test was skipped to
avoid a failure. The full suite result predates only a Pint import-order change, a
test-local SQL broadening already covered by the final targeted rerun, and the new
documentation; application behavior was unchanged after the full run.

## Artifact hashes (SHA-256)

```text
b9a5fff38fbcb71de79be4e04aadf6cd6fd3c9c3c976648e0acaca3ea7f14b2b  scripts/diagnostics/phase0_production_acceptance.sql
38b68c9b7ebfdf32103e51a01ba42c199e3e079f1a9e5392833bc6700dc980db  scripts/diagnostics/classify_phase0_funding.py
6974c389e5508b1652442637d2261bf14ee41b2f35ffca23d53cc26b2d11f1da  database/migrations/2026_10_29_000001_add_funding_mark_source.php
e167a36b5a0037cbb4f6d7da0d779a90e176c37e382596f94436441302177dc7  app/PaperTrading/Infrastructure/Persistence/EquitySnapshotRecord.php
78f82f7f8e00e67336330fd1b676b52547ad0383d23e1b100fce12173d51404d  app/Trading/Jobs/ProcessPaperPipelineJob.php
```

The SQL hash predates only the final operator read; regenerate hashes from the
committed revision before attaching production output. The new migration was exercised
only by the isolated test suite (`RefreshDatabase`), not on production.

## Changed file groups

- Equity/bootstrap: `BinancePaperProvisioner`, `BinancePaperCapital`,
  `CapturePaperEquityJob`, `EquitySnapshotRecord`, statistics/daily/API/audit/research
  readers.
- Funding: `FundingMarkPriceProvider`, `BybitRestClient`, `FundingHistorySynchronizer`,
  `BinanceFundingIngestion`, `PositionLifecycleService`, additive provenance migration.
- Pipeline/autonomy: `TradingPipelineEventService`, `TradingPipelineRecoveryService`,
  `ProcessPaperPipelineJob`, `LearningPredictorService`, `ProcessAutonomousLearningJob`.
- Evidence/operations: diagnostic SQL Queries 1, 4, 9–16; operator checklist addendum;
  offline classifier plus tests; this validation and the remediation report.

## Unresolved production-only evidence

- Per-position export of the 379 incomplete Bybit positions with executions,
  settlements and market events; none was supplied, so all 379 remain unclassified.
- Independently validated historical Bybit schedules, rates and boundary marks; the
  classifier does not prove exchange coverage.
- Row-level identities for the 316 `STALE` and four `PARTIAL` events, plus matching
  deployment timestamps and worker logs.
- Production account census rows (Queries 1 and 13), historical snapshot IDs/source
  keys (Query 14), and effective autonomy configuration/approval audit.
- Authentic drawdown rejection and open-position servicing logs for the three blocked
  accounts (Query 9 predicates alone do not prove behavior).

Historical record repair, mass funding backfill, account reset and any production
migration/deployment still require separate owner approval. **Release acceptance:
NOT READY**; all 45 V2 production obligations remain PENDING and the scorecard remains
I=0%, V=0%, P=0% because prior evidence is stale and none of this local verification
is product acceptance.
