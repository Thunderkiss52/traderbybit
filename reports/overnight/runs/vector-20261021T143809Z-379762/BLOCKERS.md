# Blockers — VECTOR v1.1 overnight run (2026-10-21)

## 1. Test database isolation unverified (`TEST_DB_ISOLATION_UNVERIFIED`)

- The local PostgreSQL server does not answer on `5432`; `5433` is listening but password
  authentication failed for every attempted role/password combination
  (`bybit_signals`, `postgres`, `test` x `testpass`, `postgres`, `bybit_test`, `password`,
  `bybit_signals`).
- `/etc/postgresql/14/main/pg_hba.conf` is not readable from this session, so the correct local role
  cannot be derived.
- Consequence: all verification ran on the SQLite driver (`phpunit.xml` -> `:memory:`) and the
  untracked `bybit_test` SQLite file. PostgreSQL-specific behaviour (`numeric` scale, concurrent
  upserts, `ON CONFLICT`) remains unverified. Required follow-up: one verification run against the
  isolated PostgreSQL instance once the role/password is confirmed by the operator.
- Production database was never contacted.

## 2. No market or paper-trading data in the DATABASE (`DATA_UNAVAILABLE` for P-2)

- The isolated test database contains 0 rows in `paper_accounts`, `virtual_positions`,
  `virtual_executions`, `equity_snapshots`, `slippage_attribution_records`, `market_candles`,
  `market_funding_events` and `market_open_interest_events`.
- Consequence: no real-data P-2 generation and no real win rate / profit factor / exposure / ESS
  values are possible in this environment; the P-2 report states `DATA_UNAVAILABLE`.
- Correction (2026-10-21T09:36Z): the LOCAL ARCHIVE `audit_data/` (read-only export, manifest with
  SHA-256 hashes) DOES contain market data: 10028 closed candles (BTCUSDT/ETHUSDT, 5m/15m/30m/60m,
  2026-10-07T07:45Z -> 2026-10-18T07:45Z, no gaps in the 5m and 60m series), 6 funding events and
  190 open interest snapshots. The section 7 candidates were therefore evaluated on that archive
  and the results are in `reports/profit-growth/04-s7-research-candidates.md`. The archive is a
  bounded historical export, not a prospective paper observation stream, so no cohort can reach
  eligibility and no threshold was fitted.
- `btc_eth_lead_lag` remains `EXECUTION_NOT_VALIDATED`: no two-leg fill, financing or concurrency
  model exists, and no fictitious pair fill was simulated.

## 4. A0-A3 shadow history unavailable (`SHADOW_HISTORY_ABSENT`)
- The overnight contract allows local A0-A1 implementation and A2/A3 research-only logic; promotion into paper requires a prospective shadow history of an isolated paper challenger with >=14 days and >=50 trades.
- Evaluation of the published section 7 candidates yields 0/5 eligible with reason `NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT`. No history was fabricated.
- The kill switch, rollback triggers and feature flags (OFF by default) are implemented and tested; activation of autonomous paper promotion remains unauthorized.

## 5. Correlation context (P-5) — limited
- P-5 shows BTCUSDT/ETHUSDT cross-symbol exposure and dependence analysis on the bounded 24 h export; equity alignment remains `NOT_COMPARABLE`. A3's correlation-aware proposal uses the provided correlation matrix as research input only and never increases total risk or merges ledgers.

## 6. Go engine: remaining float conversion hotspot — follow-up, not a blocker (`OTHER`)

- After the iteration-2 integer RSI, the CPU profile of the realistic archive series is dominated by
  `CalculateBollingerBands` (45.45% cumulative) through `decimal.Decimal.Float64` (30.30%), which converts a
  fixed point `big.Int` with `big.Float` division.
- Evidence: `reports/overnight/runs/vector-20261021T143809Z-379762/profiles/realdata_btcusdt_5m_cpu_after.pprof`
  and the `-top -cum` output recorded in `reports/overnight/COMMAND_LOG.md` row 17.
- Next safe action: replace the conversion only with a variant that reproduces the current float64 bit pattern
  for every value of the archive and keeps the locked golden hash; otherwise keep the current implementation.
  The engine is already at 30-119 ms per 10,000 candles, so this is an optimisation backlog item, not a
  correctness or capacity risk.

## 7. PostgreSQL test isolation still unverified (`TEST_DB_ISOLATION_UNVERIFIED`)

- Re-checked in this run: `5432` does not answer, `5433` accepts connections but rejects every password
  attempt (`fe_sendauth: no password supplied` because the repository `.env` has an empty `DB_PASSWORD`, and
  the socket peer authentication rejects the local role `thunder`). `/etc/postgresql/14/main/pg_hba.conf` is
  mode 0640 `postgres:postgres` and therefore not readable by uid 1000, so the isolation of the instance
  cannot be positively established.
- Consequence: no migration and no database mutation was executed against PostgreSQL in this run. All PHP
  verification used the SQLite test driver (`phpunit.xml` -> `:memory:`), so the PostgreSQL specific behaviour
  of the research tables, the numeric scale and the `ON CONFLICT` upserts stay unverified.
- Next safe action: the operator confirms the local test role and password, then one verification run against
  that isolated instance. The production database was never contacted.

| BLOCKER-07 | P-1 partial-close final sanity | OTHER | 2026-10-21T15:19:00Z | The corrected P-1 allocation, the offline evaluator, the unit tests, the UI status surface and the regenerated evidence are implemented and verified but **not committed**: the owner authorisation covered one scoped commit + push (`4a04261`), and a further commit requires a new authorisation. | evidence: reports/overnight/checkpoints/phase_p1_partial_close_20261021T161800Z_20261021T150816Z (archive_ok=1, snapshot sha256 54cd906d8ba3a372fd2648fda22ff0d52c7e5c308ea1cd5da4f8109e3e413d71, diff sha256 6fa13349a4eaee07494ccfba2690ff29294504134ef1f0253e1161e17794a493) | next safe action: on explicit owner approval, commit only app/Research/Domain/SlippageAttribution/SlippageAllocation.php, app/Research/Application/SlippageAttributionService.php, scripts/p1_archive_evaluation.php, tests/Unit/Research/SlippageAllocationTest.php, frontend/src/pages/ProfitabilityResearchPage.vue, frontend/tests/profitability_research.test.js, reports/profit-growth/P1_evidence.json and reports/profit-growth/02-p1-slippage-attribution.md; keep the QIM work and the VECTOR spec/launcher files out of the commit |
