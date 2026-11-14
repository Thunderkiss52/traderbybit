# VECTOR — ROUND 4 independent code audit and remediation

**UTC:** 2026-11-11T07:10:13.971031+00:00  
**Input:** `VECTOR-AUDIT-CLOSED-V3.tar.gz`  
**Input SHA-256:** `352e10064ae0d0f26b06997b940b01b8ac165789d1d5a334b05d3e49b2a7b1a0`  
**Represented HEAD in original snapshot:** `9615a62`, without `.git`.  
**Status:** SOURCE PATCHES APPLIED — **NOT PRODUCTION ACCEPTED**.

## Confirmed findings and changes

1. **F4-01 / P1 — last-write-wins PnL for shared signals.** `SignalQualityAnalyticsService::analyze()` and its comparison methods used `keyBy('signal_id')`, silently dropping every but one virtual position when multiple independent PAPER accounts executed a single signal. These methods now use `groupBy('signal_id')` and count **all** matching positions. Exchange, strategy, timeframe, quality-risk matrix and directional diagnostics derive counts and PnL from every position. Overview no longer uses `firstWhere()` for best/worst strategy/timeframe PnL. Regression tests include two distinct accounts executing the same signal, with different PnL.
2. **F4-02 / P1 — CSV export truncated after 1,000 signals.** `signalExplorerExport()` previously exported only `paginateSignals(..., 1000)->items()`. It now uses `iterateSignalExplorer()` with stable, bounded database cursor pages (250 signals per page), preserving all accepted filters. New feature test inserts more than 1,000 records and checks the downloaded row count.
3. **F4-03 / P1 — misleading single-account decisions and positions.** Signal Explorer formerly selected the arbitrary last position/risk record for multi-account signals. The row now includes position/risk-decision counts and total posted Net PnL. The detail endpoint exposes per-account `positions[]` and `risk_decisions[]`, and never represents one arbitrary account as the only position or single definitive Risk Gate verdict. Vue displays all positions and the distinct risk verdicts.
4. **F4-04 / P1 — PARTIALLY_CLOSED and OPEN realized amounts omitted.** `PositionLifecycleService` posts entry fees, funding and TP1 realized cash while the position is still OPEN or PARTIALLY_CLOSED. Analytics previously counted only CLOSED positions, misleadingly omitting already-posted cash losses/profits. All posted `net_pnl`, `gross_pnl`, `fees`, and `funding` now contribute to reported realized-to-date totals, while closed-trade win rates and profit factors still require completed trades. Explicit `pnl_scope` metadata distinguishes realized-to-date from unrealized valuation. Added regression test simulating TP1 partial close.
5. **F4-05 / P2 — fake profit factor 999.** When no losing completed trades exist, PF is undefined, not `999.0`. Summary and timeframe PF return null, and Vue renders `—`. Added feature coverage.
6. **F4-06 / P1 — low-quality signals conflated with observation-only gate outcomes.** `observation_only_signals` previously equaled the number of LOW-quality signals even without a persisted gate decision, and OBSERVATION_ONLY was also counted as rejected when `allowed=false`. Observation-only signals now require the persisted gate snapshot and are excluded from rejected signal counts. Added feature coverage.
7. **F4-07 / P2 — filtered cohort drawdown misleading when Gate/Execution filter active.** Account-level maximum drawdown is now identified as unavailable under gate_decision or execution_status cohort filters rather than being displayed as a cohort-level statistic.
8. **F4-08 / P3 — redundant identical whereIn.** Removed duplicate account strategy `whereIn()` in `ProfitabilityResearchController::getPrimaryAccountsReadOnly()`.

## Files changed relative to V3

- `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` (MODIFIED)
- `app/Research/Application/SignalQualityAnalyticsService.php` (MODIFIED)
- `frontend/src/pages/ProfitabilityResearchPage.vue` (MODIFIED)
- `tests/Feature/Research/SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest.php` (MODIFIED)

## Verification actually executed

- SHA-256 verification of input archive: **PASS**.
- PHP syntax: **938 / 938 PASS**.
- Python compilation: **44 / 44 PASS**.
- Native exchange safety: **28 / 28 PASS** (no network).
- Signal cohort SQL builder smoke: **13 / 13 PASS** (mock Builder only; not PostgreSQL).
- Targeted Python funding/Phase0 tests: **18 / 18 PASS**.
- Frontend Node: **53 tests PASS; 2 test modules failed to load because `typescript` is absent**; entire suite is **NOT PASS**.
- Complete Python discovery: **INCOMPLETE**, exceeded 20 seconds.
- Go race: **BLOCKED**, `github.com/lib/pq` absent and offline module resolution.
- PHPUnit feature and finance integration tests: **NOT RUN**. Existing runtime PHP 8.4.24 does not meet Composer PHP ~8.5.12 and `vendor/` is not bundled.
- PostgreSQL migration/reconciliation: **NOT RUN**. No PostgreSQL/PDO driver available.
- Real Laravel cURL / Playwright browser: **NOT RUN**. Required backend/frontend dependencies unavailable.
- Five new regression methods were **authored and syntax checked, but NOT EXECUTED**. No production readiness assertions are made from these tests.

## Remaining high-priority gaps

- `SignalQualityAnalyticsService::analyze()` still loads all matching signals and positions into memory. Replace with indexed SQL aggregates, bounded streams or materialized rollups and benchmark on realistic production-sized PostgreSQL fixtures.
- New multi-account behavior requires full PostgreSQL integration verification, especially uniqueness constraints and historical ledger reconciliation.
- Exchange-specific immutable candle recovery, research pipeline independence beyond Bybit/Binance, cross-market spot/linear/inverse execution, contract multipliers and currency conversion remain unverified or incompletely implemented.
- Real historical 26-account ledger and 130-pair topology, quote/funding provenance, 786 Model Artifacts, and live 10-exchange end-to-end PAPER behavior were **not** verified in this round.
- CSV exports of operator-controlled strings should be reviewed for spreadsheet formula-injection resistance before external distribution.
- Complete PHP, frontend, Go, and browser tests must run in an environment with required dependencies before acceptance.

## Deployment boundary

Source archive only. No local Git commit (input lacks `.git`), no push/deployment, no live trading, no production database modifications, no ECONOM changes.
