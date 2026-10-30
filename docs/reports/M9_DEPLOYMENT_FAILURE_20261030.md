# M9 deployment failure — 2026-10-30

**Status: NOT READY for a deployment retry.** This report separates the operator-supplied production log from local verification. No production connection or mutation was made during this investigation.

## Observed deployment result

The operator-supplied GitHub Actions log for `39ca8063fcd324548cdf0b33ccb90dfd3e4ed73e` shows successful preflight, a reported PostgreSQL backup, and successful application of additive migrations `2026_10_29_220000` and `2026_10_29_220100`. `binance:initialize-paper --force` then failed M9 with `UNREALIZED_MISMATCH` for six Bybit PRIMARY strategies: `support_resistance_bounce`, `support_resistance_breakout`, `funding_open_interest_squeeze`, `liquidity_sweep_reversal`, `volatility_compression_breakout`, and `btc_eth_lead_lag`. The workflow reported application rollback to `671f72f11ba76a978dc1a6f29ae0ceeeb63e9322` and a passing healthcheck. It explicitly did not roll back the migrations. The log contains neither account/position values nor proof that previously committed Binance initialization writes were absent.

## Local cause analysis and fix

M9 previously summed `unrealized_pnl` across **all** virtual positions while the account valuation writer sums active `OPEN` and `PARTIALLY_CLOSED` positions. This scope mismatch can produce `UNREALIZED_MISMATCH` when closed positions retain a mark. The six production cases cannot be classified from the log alone. Commit `2deae2121e7fc2c06b099eb07355b995697b3fc9` aligns M9 with active positions and reports a nonzero closed-position mark as `CLOSED_POSITION_UNREALIZED_NONZERO`; it still rejects real active/account mismatches.

The previous initializer committed provisioning, capital, and snapshot repair before M9. A later M9 failure could therefore leave PAPER records despite application-image rollback. The same commit places initialization writes and M9 in one PostgreSQL transaction so a new M9 failure rolls those writes back. This change does not reverse any records from the failed production attempt.

## Verification

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

**Severity: HIGH.** The actual six production mismatches and possible partial Binance writes remain unverified. Phase 0 stays BLOCKED; Phase 1 has not started. The prior local P1 V4 gate remains valid for its eight candle-integrity guarantees, but it cannot authorize a deployment retry after this distinct M9 failure.
