# VECTOR — ROUND 3 independent audit and source remediation

**Date (UTC):** 2026-11-11T07:03:16.668639+00:00  
**Input:** `VECTOR-AUDIT-CLOSED-V2.tar.gz` / SHA-256 `0f632557183e87b5c2f02dd33d1983a75d64829aa66377ae74caca7a23fc57c2`  
**HEAD represented by archive:** `9615a62` (source snapshot without `.git`)  
**Status:** SOURCE FIXES APPLIED / **NOT CERTIFIED FOR DEPLOYMENT**

## Independently confirmed defects and changes

1. **P1 — Signal cohort/PnL scope contamination.** `SignalQualityAnalyticsService::buildFilteredPositionsQuery()` filtered positions independently by exchange/symbol/side/opened_at but ignored strategy, signal timeframe, quality, risk, retrospective outcome, and decision-time filters. Positions are now constrained by the identical filtered signal-ID subquery. Regression feature tests cover a second strategy with intentionally different PnL and a nonexecuted 3m signal.
2. **P1 — Historical Risk Gate explanation was rewritten by current policy.** `getSignalDetail()` previously called the current `PreExecutionQualityRiskGate` to explain an earlier immutable risk decision. It now returns persisted `risk_decisions` verdict, approval boolean, reasons and snapshot, explicitly identifying whether evidence exists. Regression test constructs a historically blocked signal with positive edge and demands `REJECTED`.
3. **P1 — Gate/execution filters were accepted but ignored.** `gate_decision` and `execution_status` now constrain the signal query using persisted risk decisions and virtual positions. Unsupported status strings yield zero records, not a silently widened cohort. Added actual Vue dropdowns for both filters.
4. **P1 — Rejected signals were invented from the absence of executions.** Rejected counts now require a persisted `allowed=false` risk decision; nonexecuted signals are not automatically classified as rejected. Risk/quality matrix uses the same evidence source.
5. **P1 — Unknown exchange silently became ALL.** Profitability signal endpoints now return HTTP 422 for an unregistered exchange instead of showing cross-exchange aggregate results.
6. **P1 — PAPER approval resurrected when exchange catalog failed.** `ExchangeRegistry::paperApprovedIds()` previously returned `['bybit']` if the database or `exchanges` table was unavailable. `activeIds()` previously fell back to every configured venue. Both now fail closed with empty lists. Added PHPUnit regressions and executed four dependency-free PHP runtime assertions with stubbed facade failures.
7. **P2 — Fake best/worst segments without trades.** The analytics overview no longer invents default Bybit/HTX/strategy/timeframe winners when no closed trade supports a ranking.
8. **P2 — Drawdown attribution mislabeled.** Account-level maximum drawdown was displayed as a filtered symbol/timeframe/quality drawdown. Unsupported cohort-specific drawdown is returned as null with explicit scope metadata, and Vue now displays an em dash, not 0%. Exchange comparison no longer uses the first arbitrary account as the exchange max. When the value exists it is labeled as maximum *account* drawdown, not aggregated portfolio drawdown.
9. **P2 — Strategy comparison leaked unrelated zero rows.** Comparisons are now derived from the actual selected signal cohort, instead of treating the configured strategy list as performance observations.
10. **P2 — UI lacked controls for existing status variables.** Added visible Gate and Execution Status dropdowns, reusing existing reactive query state.

**Note:** Query builder smoke checks prove query construction and fail-closed behavior only. Real SQL execution, cardinality, financial attribution and browser rendering remain unverified until the integration environment is available.

## Files changed

- `app/Exchanges/ExchangeRegistry.php` — MODIFIED
- `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` — MODIFIED
- `app/Research/Application/SignalQualityAnalyticsService.php` — MODIFIED
- `frontend/src/pages/ProfitabilityResearchPage.vue` — MODIFIED
- `tests/Feature/Research/SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest.php` — MODIFIED
- `tests/Unit/Exchanges/ExchangeRegistryFailClosedTest.php` — ADDED

## Verification carried out

- SHA-256 of input archive: PASS.
- PHP syntax: **938/938 PASS** (app, database, tests, config, routes, bootstrap).
- Python syntax: **44/44 PASS**.
- Isolated registry runtime assertions: **4/4 PASS**.
- Real SignalQualityAnalyticsService query-construction smoke, using a lightweight Eloquent Builder double: **13/13 PASS**.
- Python funding classifier: **7/7 PASS**.
- Python Phase 0 evidence classifier: **11/11 PASS**.
- Frontend Node: **53 PASS, 2 test modules FAILED TO LOAD** (`typescript` dependency absent). Initial run from repository root had 13 additional cwd-dependent failures; correct invocation from `frontend/` resolved those.
- Go `go test -race -count=1 ./...`: **NOT COMPLETED**, missing `github.com/lib/pq` with offline module resolution.
- PHPUnit, Pint, native Laravel/cURL, PostgreSQL migrations and financial reconciliation: **NOT RUN**, missing compatible PHP 8.5, Composer dependencies, PostgreSQL/PDO driver.
- Playwright end-to-end: **NOT RUN**, local full application cannot boot.
- New PHP feature and PHPUnit cases were authored and syntax-checked but **not executed**.

## Outstanding high-priority findings

- **Exchange-specific candle gap recovery/warmup** remains functionally Bybit/Binance-focused. V2 containment fails explicitly for unknown exchange instead of wrongly calling Bybit, but a general immutable exchange-specific backfill/writer is not yet implemented.
- **Autonomous candidate evaluation** (`EvaluateStrategyCandidateJob`, `StrategyEvolutionService`, `ResearchJobService`, `PortfolioResearchLifecycleService`) still has explicit Bybit/Binance restrictions. Do not remove them without proving Go backtesting and candidate validation can consume each new venue's actual dataset and market semantics.
- **Signal-quality aggregate query memory** remains unbounded (`get()` across all selected signals and positions); high-cardinality analytics needs bounded/SQL-based aggregation and benchmark evidence.
- **Multiple positions for one signal** are permissible across different account IDs, while comparison views still reduce positions with `keyBy(signal_id)`; multi-account aggregate PnL should be redesigned and integration-tested before claiming full accounting correctness.
- **Spot/inverse/per-contract multipliers and currency conversion** remain not verified end-to-end; PAPER approval must remain fail-closed where an execution model is absent.
- **Production financial reconciliation, long/short breakdown, 786 Model Artifacts, UI filters and current production endpoint latency** have NOT been verified against live production in this round. Earlier audit screenshots cannot certify these source changes.
- **Changes not committed**: the archive does not contain `.git`. No push/deployment performed.

## Next acceptance gates

1. Apply patched sources to a real clone at matching HEAD; review diff and run Pint and complete PHP feature/integration tests against isolated PostgreSQL 17.
2. Run the five new analytics feature scenarios and two ExchangeRegistry regressions. Verify cross-account aggregation and 5 USDT fixed-notional behavior.
3. Validate 10 exchange/instrument/strategy/market-type execution paths with genuine venue-specific market data in PAPER-only mode, without falsely approving unsupported venues.
4. Repeat local cURL and browser audits using a real Laravel runtime; retain GET-only restrictions on production.
5. No production deployment before independent ledger and risk sign-off.

## Safety

- No real orders, no production database writes, no push, no deployment, no ECONOM modifications.
- The archive is an edited source snapshot, **not an independently verified working release**.
