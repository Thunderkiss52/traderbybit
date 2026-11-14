# VECTOR — Round 6 independent remediation and re-audit

UTC: 2026-11-11T08:32:45.570378+00:00  
Source: `VECTOR-AUDIT-CLOSED-V5.tar.gz` (SHA-256 `5f0eb9a3dccf4fb2f5522594901068f653dfc379e8f8652ea156e50b2a871cbc`)  
Represented upstream source HEAD: `9615a62`; no `.git` metadata is in the supplied archive.  
**STATUS: PATCHED SOURCE, INTEGRATION ACCEPTANCE BLOCKED — NOT A PRODUCTION RELEASE.**

## Reproduced findings and code changes

| ID | Severity | Reproducible problem | Remediation |
|---|---|---|---|
| R6-01 | P0 | `BacktestRunService::run()` accepted a third-party exchange with missing/partial cost model and silently kept global Bybit fees/slippage. | New `HistoricalExecutionCostPolicy` requires the venue's own nonnegative, bounded maker/taker/slippage rates and linear-perpetual market type; failures stop the run before saving simulated economics. |
| R6-02 | P1 | `StrategyMonitorController` collapsed many account-scoped RiskDecision/VirtualPosition rows to one with `keyBy(signal_id)`. | Add full `risk_decisions`/`positions` arrays with account IDs and counts; legacy singular object populated only if exactly one record exists. |
| R6-03 | P1 | Strategy monitor position and approved-risk aggregates were filtered by exchange only and mixed strategies and quality/risk cohorts. | Apply the same filtered signal-ID subquery to position/risk aggregates. |
| R6-04 | P1 | Strategy completeness falsely certified Bybit and conditionally Binance based on exchange ID/config rather than actual execution eligibility. | Evaluate the authoritative `PaperExecutionEligibilityService::accountEntryRejectionReason` per account with fail-closed exception handling and explicit reason codes. |
| R6-05 | P1 | The Vue monitor could render late responses from abandoned filter requests and retain a selected signal from an old page. | Capture immutable AbortController per request, guard response assignment, invalidate timeline loads using request IDs, reset selection on filter changes and avoid duplicate page-1 fetch. |
| R6-06 | P1 | UI displayed a guessed 10x margin when no persisted sizing evidence existed. | Remove fabricated margin fallback and label only persisted margin. |
| R6-07 | P2 | Missing entry price plan was converted into an apparent 0% stop distance by using stop price/`1` as fake entry midpoint. | Only compute distance when both entry bounds exist and are positive. |
| R6-08 | P2 | Timeline labelled every candle as 15m even when interval varied, and returned unbounded journal history for one signal. | Use stored signal close timestamp and limit recent journal events; show all account risk decisions. |

## Executed verification — no hypothetical PASS

- PHP lint: **977 files, 0 failures**.
- Python AST: **50 files, 0 failures**.
- Bash lint: **27 files, 0 failures**.
- Frontend Node tests: **98/98 PASS** after temporary reuse of globally installed TypeScript; symlink was removed. These are primarily component/source-level tests, not browser acceptance.
- Python unittest: **18/18 PASS**.
- Historical cost-policy standalone execution: **11/11 PASS**.
- Strategy/exchange assignment policy standalone execution: **11/11 PASS**.
- PHP integration/feature tests: **NOT RUN** (missing PHP 8.5.12 + vendor + PostgreSQL).
- Go tests and race: **BLOCKED** (`github.com/lib/pq` not available offline; local Go 1.23.2).
- `npm run build`, TypeScript/Vue SFC compilation, and Playwright browser acceptance: **NOT RUN** (no project node_modules/server).
- Operator's historical 26 accounts, the N×M topology in PostgreSQL, actual market freshness and continuous PAPER fills: **NOT VERIFIED** here.

Added Laravel feature tests to verify multi-account monitor records and corrected topology assertions; added PHPUnit cost isolation tests. These PHPUnit cases were linted but cannot be claimed as executed without vendor and a test DB.

## Remaining release blockers

1. Run complete PHPUnit and all relevant migrations against an isolated PostgreSQL database using PHP 8.5.12; verify that tests added in this round pass.
2. Install project dependencies and run full Quasar/Vue TypeScript typecheck/build and Playwright against a real local backend.
3. Validate 26 historical Bybit/Binance accounts (IDs, balances, immutable ledger), 130+ automatic N×M accounts and explicit BLOCKED statuses on actual database snapshots; no synthetic history or auto-unblocking.
4. Demonstrate each approved venue's market constraints, candles, bid/ask context, funding, PAPER execution and reconciliation with non-zero trades. Other eight exchanges still have research/candle-recovery paths with hardcoded two-venue assumptions; do not claim 10/10 production readiness.
5. Verify real historical Model Artifacts pagination and UI responsiveness with representative population and cache misses.
6. Confirm correctness of data-backed analytics for large cohorts: `SignalQualityAnalyticsService` still materializes complete cohorts in memory.

**Operational boundaries:** no production deployment, no Git push, no live orders, no interaction with ECONOM/econom-group, no historical financial writes.

See `PATCH_FROM_V5.diff`, `CHANGED_FILES.json`, `VERIFICATION.json`, and `LOCAL_RELEASE_GATE.md` in this directory.
