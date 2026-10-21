# P-2: Risk-Adjusted Metrics & Capital Efficiency

**Status:** IMPLEMENTED + VERIFIED (local, evidence-gated); real-data evaluation `DATA_UNAVAILABLE`
**Package:** PROFIT-1 P-2 (spec sections 6, 8, 18)
**Generation model:** Append-only immutable `ResearchGenerationRecord` + `StrategyRiskMetricRecord`
**Accounting scopes:** `FULL_LIFECYCLE`, `PIT_EVENT_TIME`, `PIT_KNOWN_AT`, `WINDOW_FLOW`
**Metric schema version:** 1.1.0 | **Algorithm version:** 1.1.0 | **Git HEAD:** f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55

## What changed in 1.1.0

- The service no longer depends on `PaperAccountService`: a generation can never provision, merge or
  mutate paper accounts. Accounts are resolved read-only via `primaryAccountsReadOnly()`
  (`is_primary = true` and `primary_strategy_id` not null).
- `as_of` is now applied to every scope. `PIT_EVENT_TIME` and `PIT_KNOWN_AT` cut the population at the
  requested instant; `WINDOW_FLOW` uses the half-open interval `[window_start, window_end)`.
- Scope net PnL is **recomputed from source** (`virtual_executions` + funding events), never read from
  the stored position PnL, and is cross-checked against the ledger (`accounting_discrepancies`).
- Undefined metrics return `NULL` plus an explicit reason instead of a fabricated zero.
- Generation provenance (`config_hash`, `metric_schema_version`, `algorithm_version`,
  `coverage_manifest`, `missing_data_count`, `failure_code`, `completed_at`) is persisted and the
  generation is immutable; `request_payload_hash` + `idempotency_key` reject conflicting payloads.
- Migration `2026_10_21_100000_add_p2_generation_provenance_and_metric_reasons.php` adds the new
  columns additively (no destructive change, no rewrite of historical rows).

## Implemented metrics (per account x strategy dimension)

- Population: `n_positions`, `n_wins`, `n_losses`, `n_flat`, `n_days`, `exclusions_count`,
  `exclusion_reasons` (`OPEN_AT_CUTOFF`, `PARTIALLY_CLOSED_AT_CUTOFF`, `MISSING_OR_ZERO_RISK_CAPITAL`,
  `NO_REALIZED_QUANTITY_IN_SCOPE`), `position_population` (included/excluded detail).
- Exact-decimal (`Brick\Math\BigDecimal`) results: `profit_factor` (`UNDEFINED_NO_LOSSES`),
  `win_rate` (`UNDEFINED_NO_WINS_OR_LOSSES`), `payoff_ratio` (`UNDEFINED_NO_LOSING_POSITIONS`),
  `net_r_mean`, `net_r_median`, `net_pnl_per_risk_capital`, `net_return_on_equity`, `turnover`.
- Risk capital is allocated proportionally to the quantity actually closed
  (`risk_capital * closed_quantity / initial_quantity`); window scope uses the same rule.
- `cost_to_risk_ratio` = `(fees + matched P-1 slippage drag - signed funding) / allocated risk
  capital`. It is a **diagnostic only**: it never alters PnL. It is `NULL` with
  `INCOMPLETE_P1_MATCHED_SCOPE` when the P-1 attribution scope is incomplete, and `NULL` with
  `P1_ATTRIBUTION_NOT_WINDOW_SCOPED` in window scope.
- Equity: `twa_equity` (piecewise-constant step function, `max_carry_forward_age` capped and included
  in `config_hash`), `coverage_gap_seconds`, `max_drawdown_pct`, `time_underwater_pct`,
  `equity_observations`.
- Exposure: `average_gross_notional_exposure_pct`, `average_open_risk_capital_pct`,
  `time_with_open_exposure_pct`, `notional_per_initial_risk_capital` (event-reconstructed remaining
  quantity, basis `ENTRY_PRICE_X_REMAINING_QUANTITY_EVENT_RECONSTRUCTED`).
- `average_margin_used_pct_of_equity` = `NULL` + `NOT_RECORDED_IN_LEDGER`; `sharpe_like` = `NULL` +
  `NOT_COMPARABLE_EXTERNAL_FLOWS_NOT_IDENTIFIED_IN_LEDGER` (no silent zero-flow assumption).
- Daily realized net R series: realized net PnL booked on the execution/accounting event day
  (`registered_at` with `market_event_at` fallback), risk capital allocated to the closed quantity.
  Days without events are **not** zero-filled.
- ESS: Bartlett estimator, `K = floor(n / 4)`, method
  `BARTLETT_AUTOCORRELATION_LAG_FLOOR_N_DIV_4`, clamped to `[1, n]` with `ess_clamped` recorded.
  `sample_sufficiency` = `SUFFICIENT` only with >= 20 valid non-overlapping UTC days, ESS >= 20,
  complete coverage and no unresolved accounting discrepancy.

## API

- `GET /api/v1/research/profitability/risk-capital-metrics?accounting_scope=...` (read-only, latest
  generation).
- `POST /api/v1/research/profitability/generations/p2` (research token) — accepts the four scopes.

## Evidence (local, reproducible)

- `tests/Unit/Research/RiskCapitalMetricsServiceTest.php`: 7 tests / 23 assertions — PASS.
- `tests/Feature/Research/P2RiskCapitalMetricsTest.php`: 16 tests / 67 assertions — PASS.
  Covers eight-account independence, exact decimals, undefined reasons, PIT cutoff (no look-ahead),
  open/partial exclusions, window proportional allocation, matched-P-1 cost-to-risk with PnL
  immutability, daily realized net R, TWAE carry-forward cap, max drawdown, sample sufficiency,
  idempotency/conflict, invalid scope and invalid window.
- Full suite: `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 462 tests, 427 passed, 35 skipped,
  0 failed (~46 s).
- `vendor/bin/pint --test` on all touched PHP files -> PASS.
- Negative control: the §1A `applyFunding` nullable-operand regression test is in
  `tests/Feature/Backtest/BacktestSimulationTest.php`.

## Limitations and honest gaps

- **No real-data generation exists.** The isolated test database contains 0 rows in
  `paper_accounts`, `virtual_positions`, `virtual_executions`, `equity_snapshots`,
  `slippage_attribution_records`, `market_candles`, `market_funding_events` and
  `market_open_interest_events`. Every real-data metric is therefore `NULL`/`DATA_UNAVAILABLE`; no
  performance, expectancy or capital-efficiency number can be claimed from this environment.
- **PostgreSQL isolation is unverified.** The local PostgreSQL instance does not answer on 5432 and
  password authentication on 5433 failed for every attempted role, and `pg_hba.conf` is not readable
  from this session. All verification therefore ran on the SQLite driver (`phpunit.xml`, `:memory:`)
  plus the untracked `bybit_test` SQLite file. Numeric behaviour on PostgreSQL (`numeric` vs
  SQLite NUMERIC affinity) still needs one verification run.
- Margin usage and Sharpe-like remain `NULL` until external flows and margin are recorded in the
  ledger; they are never approximated.
- Sample sufficiency gates are evidence-readiness guardrails, not proof of expectancy.
- Production was not touched: no deployment, no production migration, no live orders.
