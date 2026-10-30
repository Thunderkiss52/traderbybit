# M9 deployment failure — 2026-10-30

**Status: WAITING FOR OPERATOR READ-ONLY DIAGNOSTIC.** This report separates the operator-supplied production log from local verification. No production connection or mutation was made during this investigation.

## Observed deployment result

The operator-supplied GitHub Actions log for `39ca8063fcd324548cdf0b33ccb90dfd3e4ed73e` shows successful preflight, a reported PostgreSQL backup, and successful application of additive migrations `2026_10_29_220000` and `2026_10_29_220100`. `binance:initialize-paper --force` then failed M9 with `UNREALIZED_MISMATCH` for six Bybit PRIMARY strategies: `support_resistance_bounce`, `support_resistance_breakout`, `funding_open_interest_squeeze`, `liquidity_sweep_reversal`, `volatility_compression_breakout`, and `btc_eth_lead_lag`. The workflow reported application rollback to `671f72f11ba76a978dc1a6f29ae0ceeeb63e9322` and a passing healthcheck. It explicitly did not roll back the migrations. The log contains neither account/position values nor proof that previously committed Binance initialization writes were absent.

## Local cause analysis and fix

M9 previously summed `unrealized_pnl` across **all** virtual positions while the account valuation writer sums active `OPEN` and `PARTIALLY_CLOSED` positions. This scope mismatch can produce `UNREALIZED_MISMATCH` when closed positions retain a mark. The six production cases cannot be classified from the log alone. Commit `2deae2121e7fc2c06b099eb07355b995697b3fc9` aligns M9 with active positions and reports a nonzero closed-position mark as `CLOSED_POSITION_UNREALIZED_NONZERO`; it still rejects real active/account mismatches.

The previous initializer committed provisioning, capital, and snapshot repair before M9. A later M9 failure could therefore leave PAPER records despite application-image rollback. The same commit places initialization writes and M9 in one PostgreSQL transaction so a new M9 failure rolls those writes back. This change does not reverse any records from the failed production attempt.

## Initial fix verification

All tests used task-owned PostgreSQL `bybit_test` in an isolated container, never production. Logs, JUnit, commands, exit codes, and hashes are in [M9 evidence](../progress/evidence/m9-deploy-failure-20261030/).

| Check | Result | Exit |
| --- | --- | ---: |
| Targeted PHPUnit: Binance initialization and PostgreSQL financial reconciliation | 7 tests, 140 assertions | 0 |
| Full PHPUnit | 1,070 tests, 10,588 assertions, 1 public Bybit API skip, 0 failures | 0 |
| Pint `--test` | 720 files pass | 0 |
| PHP syntax | 704 files pass | 0 |
| Read-only diagnostic SQL against isolated PostgreSQL | Parsed and executed | 0 |
| Roadmap tracker unit tests | 25 tests pass | 0 |
| Scorecard generation; strict validation against `39ca8063fcd324548cdf0b33ccb90dfd3e4ed73e`; `git diff --check` | All pass; provisional implementation/verification/product scores remain 0% | 0; 0; 0 |

The targeted regression proves a failed M9 transaction leaves no newly provisioned Binance accounts, capital events, or snapshots, and preserves the Bybit fixture. A closed nonzero mark yields the explicit closed-position issue without a false `UNREALIZED_MISMATCH`. The existing full suite also covers the eight P1 candle-integrity guarantees; its prior focused PostgreSQL and cross-process evidence is in the [V4 report](P1_CANDLE_INTEGRITY_V4_20261030.md). Frontend and Go source did not change in this fix; V4 verified their tests/build and Go race check on the same source.

## Required operator diagnostic before retry

With separate operator authorization, run the [read-only, repeatable-read SQL](M9_UNREALIZED_READ_ONLY_20261030.sql) on production and retain its row-level output securely. It identifies active versus closed position marks, account differences, latest snapshots, and existing Binance accounts/capital/snapshots. Inspect the six strategies and any existing Binance initialization writes before deciding whether code alone is sufficient. Do not run remediation SQL or alter PAPER history on the basis of this report.

### Focused retry review

The failure log contains six instances of the **same** M9 check, `UNREALIZED_MISMATCH`; it does not identify six different failed invariants. M9 still enforces exact account unrealized PnL = sum of active position marks. It separately rejects nonzero closed-position marks. It also verifies account realized PnL = position gross + funding - fees (tolerance `0.000001`), equity = starting balance + realized + unrealized (same tolerance), position net PnL, execution quantities/gross/fees, funding source and amounts, exchange provenance, approved Binance capital, and the 26-account census. The new calculation does not make a true active-position difference pass.

The initializer uses one PostgreSQL transaction for Bybit account completion, Binance provisioning, approved capital, checkpoint preflight and M9. Nested service transactions share that connection. Unique account `(exchange_id, primary_strategy_id)`, capital `(paper_account_id, approval_reference)`, and snapshot `(paper_account_id, source_event_key)` indexes constrain retries. A cache lock reduces concurrent attempts; a duplicate after lock expiry can fail on a unique constraint, but cannot create a second account or allocation. The initializer previously applied snapshot repair automatically; local commit `519a0173e56e49ee109a3cbe5eccf364c2ff4a1b` now uses its dry-run path and stops on repair candidates without modifying those historical records. Separate authorization remains required for any repair.

Final-code isolated PostgreSQL checks are retained in [retry evidence](../progress/evidence/m9-retry-review-20261030/): targeted PHPUnit 16 tests / 423 assertions; full PHPUnit 1,073 tests / 10,605 assertions / one intentional public Bybit API skip; Pint 720 files; PHP syntax on both changed PHP files; read-only diagnostic against a synthetic six-mismatch fixture; old-source bootstrap and account read against the migrated schema. Each executed check exited 0. The synthetic fixture is a failure-shape test, not a copy of production balances or positions.

Both applied migrations only create new conflict tables and their constraints/indexes; they do not alter the previous application's tables. In `docker/scripts/update.sh`, the error trap restores the prior application images and runs a healthcheck; deployment-state promotion happens only after all steps pass. It explicitly leaves migrations applied. The operator log shows the previous image passed its healthcheck after rollback. An isolated run also booted source `671f72f` against a PostgreSQL schema containing both migrations and read the 13 Bybit accounts. This verifies basic schema compatibility, not every production path or the actual deployment-state files.

From a trusted operator workstation with this repository checked out, set `VECTOR_PROD_SSH` to the authorized SSH target and run the following **only when authorized for read-only production diagnostics**. The SQL is streamed to PostgreSQL; no file is written on the VPS. `PGOPTIONS` and the SQL transaction independently enforce read-only mode. Keep the output private because it contains PAPER account details.

```bash
umask 077
ssh -T "$VECTOR_PROD_SSH" \
  "docker exec -i -e PGOPTIONS='-c default_transaction_read_only=on' bybit-signals-postgres-1 sh -c 'exec psql -X -v ON_ERROR_STOP=1 -P pager=off -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\"'" \
  < docs/reports/M9_UNREALIZED_READ_ONLY_20261030.sql > m9-production-readonly.txt
```

Required output: `transaction_read_only=on`; each migration applied once with its table present; journal/conflict state counts; six account rows with corrected/original M9 differences, realized/equity differences, position and execution/funding totals; all relevant position rows; the 13-strategy Binance cohort with capital, snapshot, position and journal counts; and the overall account census. A missing account, nonzero corrected difference, closed mark, other ledger/provenance mismatch, unexpected Binance capital or history, or missing schema object blocks a retry pending classification. **The actual production rows have not been supplied, so the verdict is WAITING FOR OPERATOR READ-ONLY DIAGNOSTIC.**

**Severity: HIGH.** The actual six production mismatches and possible partial Binance writes remain unverified. Phase 0 stays BLOCKED; Phase 1 has not started. The prior local P1 V4 gate remains valid for its eight candle-integrity guarantees, but it cannot authorize a deployment retry after this distinct M9 failure.

## Second failure (`c81d3974`) — MVCC snapshot fix

The second deployment failed with one `UNREALIZED_MISMATCH` for `multi_timeframe_trend_following`. The operator's read-only diagnostic at `2026-10-30 08:51:05 UTC` showed `m9_difference = 0` for all 13 Bybit accounts, including `2.408252280000` account unrealized PnL matched by one active position. This proves consistency at the diagnostic snapshot; it does not prove consistency at the earlier deployment instant. The deployment-time values were never captured.

### Supported mechanism

M9 previously ran inside the initializer transaction at PostgreSQL `READ COMMITTED` and read the account cohort and each account's positions in separate SQL statements. Every PAPER account-unrealized writer found in the source is `PositionLifecycleService::refreshAccountMetrics`, called inside the same `DB::transaction` that updates the position mark. That writer is atomic. A concurrent atomic valuation commit that lands between M9's account `SELECT` and position `SELECT` is therefore visible as an old account row plus a new position row at `READ COMMITTED`, producing a transient false `UNREALIZED_MISMATCH`. The later healthy diagnostic is consistent with that transient shape. The exact production instant remains unproven because the failed deployment did not log the compared values.

### Fix

- `BinanceDeploymentInitializer` now sets `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` before the first SQL statement of the top-level deployment transaction. Provisioning, capital, checkpoint preflight, Bybit checksums and M9 then share one MVCC snapshot.
- `ExchangePaperReconciliation::inspect()` opens its own `REPEATABLE READ READ ONLY` transaction when called without one. A runtime caller that supplies a transaction at `READ COMMITTED` fails closed instead of authorizing M9.
- M9 still compares exact `NUMERIC`/`BigDecimal` values, still rejects genuine account/position, closed-mark, realized, equity, fee, funding, provenance and census failures, and still includes BLOCKED accounts.
- Every failure now carries `account_id`, account status, account unrealized PnL, summed active-position unrealized PnL, exact difference, active-position count, closed nonzero marks, position count, reconciliation scope, UTC check time, PostgreSQL snapshot and isolation metadata. Values are taken from the same snapshot.
- No migration, account reset, financial-history rewrite, tolerance change or M9 bypass was introduced.

### Verification

Task-owned isolated PostgreSQL 17 (`bybit_test`) was used. The new integration test commits an atomic position+account valuation update from an independent connection between M9's account and position reads. At `REPEATABLE READ` M9 passes and observes the pre-commit values. A genuine account-only mismatch still fails with exact diagnostics. The deployment initializer is tested through the same interleaving. BLOCKED accounts remain included and are not a failure cause.

Recorded results: M9 integration 5 tests / 59 assertions; targeted regression 24 tests / 295 assertions; full PHPUnit 1,079 tests (1,078 passed, 1 intentional skip) / 10,669 assertions; Pint passed; PHP lint 701 files; both read-only SQL diagnostics exited 0; tracker generation, 25 focused tracker tests and `--strict --base-ref c81d3974` exited 0 with scores unchanged. Commits: `9917a61` (fix/tests/report), `8716521` (progress metadata), `ed969bd` (report clarification). No push or deployment was performed.

The three BLOCKED Bybit accounts (`baseline`, `regime_switching`, `trend_pullback`) were not modified. Their exact historical block reason cannot be established from equity alone; [the read-only diagnostic](BYBIT_BLOCKED_READ_ONLY_20261030.sql) returns account risk state, snapshots at or above the 5% entry limit, recent risk decisions and entry/position journal events for operator classification. Local policy tests confirm the 5% drawdown rule produces `BLOCKED`, blocks new entries, survives `resume`, and does not affect M9.
