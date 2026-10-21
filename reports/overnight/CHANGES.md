# Changes — VECTOR v1.1 overnight run (2026-10-21)

All changes are local, uncommitted and preserve the pre-existing dirty worktree (QIM / G1 / P-1 /
quote-integrity work). No git commit, push, reset, clean, checkout or restore was executed.

## Phase 1A — audit residual (VERIFIED)

- `app/Backtest/Application/BacktestSimulationEngine.php`
  - `applyFunding()` now returns early unless `opened_at` is a `DateTimeImmutable`. The audit log
    (`php_backtest_nosignal_override.log`) showed `Undefined array key "opened_at"` at line 236
    followed by `InvalidArgumentException: Illegal operator and value combination.`: an expired
    PENDING position reached funding application and built `where('funding_at','>', null)`.
- `tests/Feature/Backtest/BacktestSimulationTest.php`
  - Added `test_apply_funding_never_builds_a_nullable_comparison_operand` (reflection on the private
    method): asserts no funding is booked and equity is unchanged.
  - Result: `XDEBUG_MODE=off vendor/bin/phpunit tests/Feature/Backtest/BacktestSimulationTest.php
    --no-coverage` -> PASS (5 tests, 24 assertions).
  - Checkpoint: `reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z`
    (snapshot SHA-256 `de9de7c9c177107a0daef160edac0ea2874ac892438d864ea22cb46779148244`,
    diff SHA-256 `2b9eaa494c1f3fdef62615695e8bb7ea84caf5e995a7c823d135574dcd05e024`).

## P-2 — risk & capital efficiency service (IMPLEMENTED + VERIFIED, data unavailable)

- `app/Research/Application/RiskCapitalMetricsService.php` — rewritten to schema/algorithm 1.1.0:
  no paper-account provisioning dependency, read-only primary-account resolution, four accounting
  scopes with a real `as_of` cutoff, source-recomputed scope net PnL with ledger cross-check,
  exact-decimal profit factor / win rate / payoff, proportional risk-capital allocation, P-1-matched
  `cost_to_risk_ratio` (diagnostic only), daily realized net R without zero-filling, Bartlett ESS
  with clamping, TWAE with capped carry-forward and coverage gaps, drawdown / time underwater,
  turnover, net return on equity, event-reconstructed exposure metrics, NULL+reason for margin and
  Sharpe-like, immutable generation with `config_hash` / payload-hash idempotency.
- `database/migrations/2026_10_21_100000_add_p2_generation_provenance_and_metric_reasons.php` — new
  additive migration for generation provenance and metric reason columns.
- `app/Research/Domain/ResearchGenerationRecord.php`, `app/Research/Domain/StrategyRiskMetricRecord.php`
  — casts for the new columns and exact-decimal casts for every metric field.
- `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` — P-2 scope validation extended
  to `FULL_LIFECYCLE`, `PIT_EVENT_TIME`, `PIT_KNOWN_AT`, `WINDOW_FLOW` (P-1 validation untouched).
- `tests/Unit/Research/RiskCapitalMetricsServiceTest.php` — rewritten: 7 tests / 23 assertions, PASS.
- `tests/Feature/Research/P2RiskCapitalMetricsTest.php` — new: 16 tests / 67 assertions, PASS
  (eight-account independence, exact decimals, undefined reasons, PIT cutoff, open/partial
  exclusions, window allocation, matched-P-1 cost-to-risk with PnL immutability, daily realized net R,
  TWAE cap, max drawdown, sample sufficiency, idempotency/conflict, invalid scope/window).
- Reports: `reports/profit-growth/03-p2-risk-and-capital-efficiency.md`,
  `reports/profit-growth/P2_evidence.json` (status `NOT_VERIFIED` — no real-data generation exists).

## Regression after the changes

- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 462 tests, 427 passed, 35 skipped, 0 failed.
- `vendor/bin/pint --test` on all touched files -> PASS (style fixes applied to the three files
  authored in this run only).

## Section 7 — five Go research candidate cohorts (IMPLEMENTED + VERIFIED, 0 of 5 eligible)

- `research-go/internal/candidates/types.go` (new) — `Bar` with an explicit `known_at`, `FundingEvent`
  with the Bybit sign convention, `OpenInterestEvent`, `Dataset`, `Signal`, `Candidate`, causal lookups
  (`lastKnownBarIndex`, `countKnownBars`, `lastKnownFunding`, `openInterestAt`), `meanTrueRange`,
  `stopAndTarget` (precommitted ATR multipliers).
- `research-go/internal/candidates/funding_open_interest_squeeze.go` (new) — crowding reversal
  (default) and continuation (challenger) modes; funding and open interest are mandatory inputs;
  the funding and open interest cash-flow sign convention is documented in the type.
- `research-go/internal/candidates/liquidity_sweep_reversal.go` (new) — support and resistance are
  rolling extremes of a window that ends BEFORE the decision bar, so no hindsight swing point can
  enter a decision.
- `research-go/internal/candidates/volatility_compression_breakout.go` (new) — compression is the
  ratio between the mean true range of the most recent closed bars and the mean true range of the
  baseline window in front of them; the breakout is confirmed on the close of the decision bar.
- `research-go/internal/candidates/btc_eth_lead_lag.go` (new) — the peer is aligned on close time
  AND knowledge time with a staleness limit; the cohort is research-only and never labelled arbitrage.
- `research-go/internal/candidates/multi_timeframe_trend_following.go` (new) — the higher timeframe
  trend is taken from candles that were fully closed and known at the decision time; the warm-up gate
  counts the known candles and never uses the array position.
- `research-go/internal/candidates/lookahead.go` (new) — the section 7.7 controls: higher timeframe
  closure, causal mutation invariance (all `known_at > t` rows are mutated by 1.5 and the earlier
  decisions must not change), the deliberately leaky negative control, and the previous-bar cutoff.
- `research-go/internal/candidates/registry.go` (new) — versioned registry of the five research
  cohorts with the precommitted hypothesis text and the executability flag.
- `research-go/internal/candidates/pipeline.go` (new) — common versioned pipeline: deterministic
  chronological 60/20/20 split, the shared cost model, and the honest status values
  (`NOT_ELIGIBLE`, `INSUFFICIENT_EVIDENCE`, `NOT_EVALUATED_EXECUTION_NOT_VALIDATED`,
  `NOT_EVALUATED_DATA_UNAVAILABLE`, `LOOK_AHEAD_CONTROL_FAILED`).
- `research-go/internal/backtest/engine.go` — behaviour preserving refactor: the position state moved
  to package level and the exit and entry logic was extracted into `evaluateExit()` and
  `openPosition()`, so the candidate replay uses exactly the same rules.
- `research-go/internal/backtest/signal_runner.go` (new) — `Engine.RunSignals` replays candidate
  signals with the same cost model, risk sizing, conservative ambiguity rule and metrics as
  `Engine.Run`. The stop distance is measured from the FILLED price, a stop on the wrong side of the
  fill is rejected, and a fill that is not strictly after the decision bar is dropped.
- `research-go/internal/auditdata/loader.go` (new) — loader of the local research archive. The
  knowledge timestamp is derived (candle -> close time, funding -> funding time, open interest ->
  observation time), open candles are rejected, gaps are reported and never filled.
- `research-go/cmd/research-candidates/main.go` (new) — CLI that evaluates the five cohorts on the
  local archive and writes `reports/profit-growth/S7_evidence.json`. It never opens an order, never
  uses a trade credential and never touches a paper account.
- Tests: `research-go/internal/candidates/candidates_test.go` (golden parity per candidate),
  `research-go/internal/candidates/lookahead_test.go` (the section 7.7 controls incl. the negative
  control and the future higher timeframe bar), `research-go/internal/candidates/pipeline_test.go`
  (splits, status semantics, research-only guarantee),
  `research-go/internal/backtest/signal_runner_test.go` (fill model, cost model, look-ahead rejection),
  `research-go/internal/auditdata/loader_test.go` (knowledge timestamp, open candle rejection, gap report).
- Verification: `go build ./...` exit 0, `go vet ./...` exit 0, `gofmt -l .` empty, `go test ./...`
  exit 0, `go test -race ./internal/candidates/ ./internal/backtest/ ./internal/auditdata/` exit 0.
- Real-data result (local archive, 11 days of 5m/60m candles, 3 funding events, 95 open interest
  snapshots, 0 gaps in the 5m series): `liquidity_sweep_reversal` 333 signals, test 33 trades, net
  -2777.69, PF 0.221, avg R -0.972; `volatility_compression_breakout` 83 signals, test 9 trades, net
  -430.10; `multi_timeframe_trend_following` 58 signals, test 12 trades, net -649.95;
  `funding_open_interest_squeeze` 0 signals (the published funding rates are below the precommitted
  0.0001 threshold, the threshold was NOT tuned); `btc_eth_lead_lag` 53 signals, `EXECUTION_NOT_VALIDATED`.
  No cohort is eligible, no profitability is claimed.
- Reports: `reports/profit-growth/04-s7-research-candidates.md` (new) and
  `reports/profit-growth/S7_evidence.json` (new, SHA-256
  `316f05df4f869e9ae141afe8ea8d958793721080152b8013595a1d00b0e00efe`).

## P-5 — Concentration, overlapping positions and dependence (2026-10-21T09:37Z → 09:59Z)

- Implemented PROFIT-1 / P-5 (spec §10) end to end, replacing the thin WIP stub
  `app/Research/Application/DependenceConcentrationService.php`.
- New domain core (pure, no DB, exact decimals):
  `app/Research/Domain/Concentration/PositionInterval.php` (half-open interval contract, open-at-cutoff
  and partial-close flags, realized vs unrealized separation),
  `PositionIntervalFactory.php` (single source of truth for the interval rules, shared by the Laravel
  service and the offline archive evaluator),
  `OverlapClusterBuilder.php` (SAME_SIDE connected components + OPPOSITE_SIDE bipartite overlap graph,
  content-addressed cluster ids),
  `ConcentrationAnalyzer.php` (Top-K 1/3/5, share of total with NULL+reason on a non-positive total,
  day blocks, open-position accounting),
  `EquityAlignment.php` (causal grid alignment, fixed carry-forward age, `NOT_COMPARABLE` fail-closed),
  `CrossSymbolExposure.php` (gross per-symbol and combined exposure, never netted).
- New research-owned storage: `database/migrations/2026_10_21_110000_create_research_concentration_tables.php`
  (`research_concentration_clusters`, `research_concentration_cluster_members`) with the Eloquent records
  `app/Research/Domain/ResearchConcentrationClusterRecord.php` and
  `app/Research/Domain/ResearchConcentrationClusterMemberRecord.php`.
- New API: `GET /api/v1/research/profitability/concentration-dependence` (read-only) and
  `POST /api/v1/research/profitability/generations/p5` (research-token protected, idempotent, 409 on
  payload conflict) in `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` + `routes/api.php`.
- New offline research evaluator `scripts/p5_archive_evaluation.php`: reads the local archive only
  (manifest-verified SHA-256), writes `reports/profit-growth/P5_evidence.json`. It never opens a
  database connection, an order or a trade credential.
- Tests: `tests/Unit/Research/Concentration/{OverlapClusterBuilderTest,ConcentrationAnalyzerTest,EquityAlignmentTest}.php`
  (14 tests), `tests/Feature/Research/P5DependenceConcentrationTest.php` (9 tests),
  `tests/Feature/Research/P5ConcentrationApiTest.php` (3 tests).
- Verification: unit 14/14, feature 9/9, API 3/3, full PHP suite 488 tests / 453 passed / 35 skipped /
  0 failed, pint clean.
- Real-data result (local research export, read-only, 38 positions): combined `SAME_SIDE` 11 clusters /
  38 members, total net PnL -260.362805849568 (Top-K shares NULL with reason
  `TOTAL_NET_PNL_NOT_POSITIVE`), `OPPOSITE_SIDE` 24 clusters / 16 bipartite edges, cross-symbol gross
  peak 39817.600096300000, equity alignment `NOT_COMPARABLE`. Descriptive only: a 24 h export of 38
  positions is not a statistical sample and no profitability is claimed.
- Reports: `reports/profit-growth/05-p5-concentration-dependence.md` (new) and
  `reports/profit-growth/P5_evidence.json` (new, SHA-256
  `d15ee41b18e1908190de4abbbe8df5c1052b132d521a2f30631434befe5cd230`).

## P-4 — Cost structure, MFE/MAE and expected-excursion estimator (2026-10-21T10:00Z → 10:40Z)

- Implemented PROFIT-1 / P-4 (spec §9) end to end, replacing the thin WIP stub
  `app/Research/Application/CostMfeMaeService.php`.
- New domain core (pure, no DB, exact decimals):
  `app/Research/Domain/CostMfe/PriceCandle.php`,
  `MfeMaeResult.php`,
  `MfeMaeCalculator.php` (MFE/MAE over the observed holding interval, R multiples, stop/target ambiguity flag,
  `NULL` semantics for undefined values),
  `PreEntryMfeEstimator.php` (causal expected-MFE distribution, nearest-rank percentiles, maturity and
  source-candle exclusions, `no_future_data_proof`),
  `CostAttribution.php` (strategy / symbol / account / fill role, `Gross - Fees + Funding = Net` reconciliation,
  slippage as attribution only, `NULL` ratios with reasons, data-quality counters).
- New research-owned storage: `database/migrations/2026_10_21_113000_create_research_cost_mfe_tables.php`
  (`research_mfe_mae_records`, `research_cost_attribution_records`) with the read models
  `app/Research/Domain/ResearchMfeMaeRecord.php` and `app/Research/Domain/ResearchCostAttributionRecord.php`.
- New API: `GET /api/v1/research/profitability/cost-mfe-mae` (read-only) and
  `POST /api/v1/research/profitability/generations/p4` (research-token protected, idempotent, 409 on payload
  conflict) in `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` + `routes/api.php`.
- New offline research evaluator `scripts/p4_archive_evaluation.php`: reads the local archive only
  (manifest-verified SHA-256), writes `reports/profit-growth/P4_evidence.json`; it never opens a database
  connection, an order or a trade credential.
- Tests: `tests/Unit/Research/CostMfe/{MfeMaeCalculatorTest,PreEntryMfeEstimatorTest,CostAttributionTest}.php`
  (20 tests), `tests/Feature/Research/P4CostMfeMaeTest.php` (5 tests),
  `tests/Feature/Research/P4CostMfeMaeApiTest.php` (3 tests).
- Verification: unit 20/20, feature 5/5, API 3/3, full PHP suite 516 tests / 481 passed / 35 skipped /
  0 failed / 2803 assertions, pint clean, Go build/vet/test ok.
- Real-data result (local research export, read-only, 38 positions): excursions computed for 38/38 positions
  (median MFE 0.513573561064 R, median MAE 0.720161997701 R, 0 ambiguous paths); pre-entry expected MFE
  34 COMPUTED / 4 INSUFFICIENT_DATA; cost attribution over 6 strategies / 2 symbols / 6 accounts / 2 fill roles
  with the accounting identity reconciling in every group; 3 gross-positive/net-negative positions;
  6 ambiguous executions; 2 positions with a missing entry execution in the bounded export (fee delta
  4.385208466320) reported explicitly. Descriptive only; no profitability is claimed and no cost/fee/MFE gate
  was added or tuned.
- Reports: `reports/profit-growth/06-p4-cost-mfe-mae.md` (new) and `reports/profit-growth/P4_evidence.json`
  (new, SHA-256 `6ac2aaa0f5ae72e6beb83e7448444c0c88651b87d0fdea05d226f816a36d7d13`).

## P-6 — Research decision gate (2026-10-21T10:23Z → 10:32Z)

- Implemented PROFIT-1 / P-6 (spec §14) end to end, replacing the thin WIP stub
  `app/Research/Application/DecisionEvidenceService.php`.
- New pure domain core: `app/Research/Domain/Decision/DecisionState.php` (the eight states and the allowed
  append-only transitions), `DecisionInputPackage.php` (explicit NULL semantics), `DecisionGate.php`
  (deterministic fail-closed gate order), `DecisionOutcome.php`, `HypothesisPriority.php` (§14.4 recovery per
  100 trades, `HYPOTHETICAL` status, ranking by the conservative lower bound, no composite score).
- New research-owned storage: `database/migrations/2026_10_21_104500_create_research_decision_records_table.php`
  and `app/Research/Domain/ResearchDecisionRecord.php`; the model rejects updates and deletes so a transition
  can only be inserted.
- New API: `GET /api/v1/research/profitability/decision-gate` (read-only) and
  `POST /api/v1/research/profitability/generations/p6` (research-token protected, idempotent, 409 on payload
  conflict) in `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` + `routes/api.php`.
- New offline evaluator `scripts/p6_archive_evaluation.php`: runs the same pure gate over the published
  P-1/P-2/P-4/P-5 evidence files (SHA-256 recorded per file); no database, no ledger, no order path.
- Tests: `tests/Unit/Research/Decision/DecisionGateTest.php` + `HypothesisPriorityTest.php` (30 tests),
  `tests/Feature/Research/P6DecisionGateTest.php` (7 tests), `tests/Feature/Research/P6DecisionGateApiTest.php`
  (4 tests).
- Verification: P-6 tests 41/41 (112 assertions); full PHP suite 557 tests / 522 passed / 35 skipped /
  0 failed / 2917 assertions; pint clean.
- Real-data result (published evidence, read-only): every claim fails closed — PROFITABILITY/IMPROVEMENT/
  CONCENTRATION_RISK/COST_STRUCTURE -> COLLECT_MORE_DATA (P-2 is not an accepted generation; P-1/P-5 publish no
  reconciliation flag; P-4/P-5 publish no sample-sufficiency declaration), RISK_REJECTED_OPPORTUNITY_CLAIM ->
  INSUFFICIENT_EVIDENCE (P3_NOT_AUTHORIZED). No claim is eligible for owner review, no hypothesis has a matched
  population, and no recovery estimate is shown. No strategy change, no order, no ledger mutation.
- Reports: `reports/profit-growth/07-p6-decision-gate.md` (new) and `reports/profit-growth/P6_evidence.json`
  (new, SHA-256 `7937435fa9414ad17123d82a44fa69976454b27cb63ee8cb59f664a538eb9a78`).

---

## 2026-10-21T16:30:00Z — run `vector-20261021T143809Z-379762` (VECTOR v1.1, Go profiling iteration 2 + final regression)

- Fixed the CI blocking formatting defect left by the previous session: `scripts/p5_archive_evaluation.php`
  now passes `./vendor/bin/pint --test` (fixers `unary_operator_spaces`, `not_operator_with_successor_space`,
  `blank_line_before_statement`). No logic change.
- `research-go/internal/algorithms/indicators.go`: replaced the exact-rational RSI hot path with exact
  integer arithmetic in the internal scale 24 (`roundHalfEvenDiv` mirrors the rational quantisation,
  `roundHalfAwayDiv` mirrors the rational to string rounding). RSI = `100 * ag / (ag + al)` computed from
  the scaled integers, so no GCD normalisation remains per bar.
- `research-go/internal/decimal/decimal.go`: added `Decimal.ScaledInt()` and `decimal.FromScaledInt()` so
  fixed point values can be used in exact integer arithmetic without a rational normalisation.
- `research-go/internal/algorithms/rsi_parity_test.go` (new): oracle test that compares the integer RSI
  against the previous exact-rational implementation bar by bar (periods 2/7/14, three lengths, deterministic
  random series) plus a flat-series degeneracy test.
- `research-go/internal/decimal/scaled_test.go` (new): round trip and aliasing tests for the new accessors.
- `research-go/cmd/research-bench/main.go` (new): measures the engine on the local realistic archive
  (wall time, process CPU time, peak RSS, allocation bytes and count, 3 comparable trials, optional CPU and
  heap pprof profiles) and writes a machine readable JSON report.
- Evidence: `reports/overnight/GO_PROFILE_ITER2_REALISTIC.md` (copy in
  `reports/overnight/runs/vector-20261021T143809Z-379762/`), pprof profiles and the before/after measurement
  JSON in `reports/overnight/runs/vector-20261021T143809Z-379762/profiles/`,
  `reports/overnight/COMMAND_LOG.md` (created, append only), `reports/overnight/RUN_STATUS.md` (appended),
  `reports/overnight/BLOCKERS.md` (appended), `reports/overnight/NEXT_SESSION_PROMPT.md` (rewritten).
- Verification: PHP 600 tests / 565 passed / 35 skipped / 0 failed / 3271 assertions; pint clean; Go build, vet,
  full test suite and race detector ok; frontend 51/51; synthetic and realistic benchmark outputs (trades,
  win rate, net PnL) identical to the previous engine, the RSI golden fixture `70.46413502` and the locked
  backtest golden hash unchanged, section 7 candidate evaluation unchanged for all five cohorts.
- Performance: synthetic 2k 52-53ms -> 20-22ms, 5k 131-140ms -> 48-54ms, 10k 241-275ms -> 101-119ms;
  realistic BTCUSDT 5m backtest mean 78.7ms -> 37.0ms, patterns mean 80.7ms -> 31.7ms, allocated bytes
  85.8MB -> 34.5MB, allocations 2.71M -> 1.43M, peak RSS unchanged.
- Unresolved risks: the remaining hotspot is `CalculateBollingerBands` -> `Decimal.Float64` (45.45% / 30.30%
  cumulative) and was deliberately not changed, because the replacement has to reproduce the current float64
  bit pattern exactly for the PHP decimal parity; it is documented as the next iteration with its acceptance
  rule. PostgreSQL remains unusable, so the new research tables are still unverified on PostgreSQL.
- Safety: no production access, no live orders, no deployment, no docker, no ECONOM, no destructive git, the
  eight primary paper accounts, their ledgers and balances are untouched, no P-3 replay, all autonomy flags
  remain OFF and no promotion was applied.

### Git closeout (2026-10-21T14:58:00Z)

- Commit `4a04261` pushed to `origin/main` (`f2e3ace..4a04261`, exit 0): 152 files, +34,990 / -223 lines.
  Scope and the excluded set are documented in `reports/overnight/RUN_STATUS.md` (section "Git closeout of
  this run") and in `reports/overnight/COMMAND_LOG.md` rows 22-29.
- The four files that mixed contract and QIM hunks were committed in a contract-only form; the QIM hunks
  remain in the worktree and were restored byte identical after the commit.
- The committed tree was re-verified in isolation before the push: PHP 557/524/33/0 failed, Go build+vet+
  test ok, frontend 46/46 + production build ok.

### P-1 partial-close final sanity (VECTOR v1.1 contract §3, 2026-10-21T15:09:00Z)

- **Defect fixed**: the P-1 counterfactual credited the whole drag of every non-partially-closed
  position, so a still open position (entry fill only) had its *unrealized* entry drag counted as
  realized. The published evidence also still carried the withdrawn `72.6789` USDT headline that
  mixed the open entry drag into the realized counterfactual.
- **Implementation**: new pure domain class `app/Research/Domain/SlippageAttribution/SlippageAllocation.php`
  (partial close -> proportional entry allocation, open position -> no realized entry drag,
  full-fill figure kept separate as `NOT_VERIFIED_SCOPE_MISMATCH`), delegation from
  `app/Research/Application/SlippageAttributionService.php`, new read-only evaluator
  `scripts/p1_archive_evaluation.php` and new unit tests
  `tests/Unit/Research/SlippageAllocationTest.php` (8 tests, negative controls included).
- **Archive recomputation** (`audit_data/`, 8 primary accounts, as-of 2026-10-18T07:45:00Z):
  38 positions / 76 fills, full-fill drag 60.86131918 USDT (2.0000 bps), realized-scope net PnL
  -260.362805849568 USDT, realized allocated drag 55.81139068 USDT, open unallocated drag
  5.0499285 USDT, hypothetical realized-scope net PnL -204.551415169568 USDT, raw full-fill
  counterfactual -213.386012583893 USDT -> `NOT_VERIFIED_SCOPE_MISMATCH`.
- **UI**: the P-1 counterfactual card now displays the verification `status`, a
  `NOT_VERIFIED_SCOPE_MISMATCH` banner with the reconciliation note, the realized-scope baseline and
  the drag that remains unallocated on open positions.
- **Evidence**: `reports/profit-growth/P1_evidence.json` regenerated,
  `reports/profit-growth/02-p1-slippage-attribution.md` updated (section 3.1).
- **Verification**: full PHPUnit suite 608 tests / 573 passed / 35 skipped / 0 failed (exit 0),
  allocation tests 8/8, service tests 4/4, frontend 51/51, pint clean, PostgreSQL integration
  2 skipped (no reachable test instance).
