# P-4 — Cost structure, MFE/MAE and expected-excursion estimator

**Package:** P-4 (PROFIT-1) · **Normative source:** `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` §9 (with §6 contracts)
**Mode:** research only — no live orders, no trade keys, no paper-ledger mutation, no deployment
**Status:** IMPLEMENTED + VERIFIED (unit/feature/API) with a real-data archive evaluation
**Evidence file:** `reports/profit-growth/P4_evidence.json` (SHA-256 `6ac2aaa0f5ae72e6beb83e7448444c0c88651b87d0fdea05d226f816a36d7d13`)

---

## 1. What was implemented

| Area | File | Purpose |
|---|---|---|
| Candle value object | `app/Research/Domain/CostMfe/PriceCandle.php` | Closed OHLC candle used for excursion diagnostics |
| Excursion result | `app/Research/Domain/CostMfe/MfeMaeResult.php` | Observed MFE/MAE with source candles, ambiguity flag, `NULL` semantics |
| Excursion calculator | `app/Research/Domain/CostMfe/MfeMaeCalculator.php` | MFE/MAE over the observed holding interval, R multiples, stop/target ambiguity detection (§9.2) |
| Pre-entry estimator | `app/Research/Domain/CostMfe/PreEntryMfeEstimator.php` | Causal expected-MFE distribution with maturity + no-future-data proof (§9.2) |
| Cost attribution | `app/Research/Domain/CostMfe/CostAttribution.php` | Cost split by strategy / symbol / account / fill role with reconciliation (§9.1) |
| Application service | `app/Research/Application/CostMfeMaeService.php` | Generation, idempotency, persistence, read model (replaces the thin WIP stub) |
| Storage | `database/migrations/2026_10_21_113000_create_research_cost_mfe_tables.php` | `research_mfe_mae_records`, `research_cost_attribution_records` (append-only research output) |
| Records | `app/Research/Domain/ResearchMfeMaeRecord.php`, `app/Research/Domain/ResearchCostAttributionRecord.php` | Read models for the two tables |
| API | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php`, `routes/api.php` | `GET research/profitability/cost-mfe-mae`, `POST research/profitability/generations/p4` (research-token protected) |
| Offline evaluator | `scripts/p4_archive_evaluation.php` | Read-only evaluation of the local research export (no DB, no ledger, no order path) |

## 2. Design decisions (and why)

1. **Excursions are ex-post diagnostics over the *observed* holding interval.** Only closed candles whose close time lies strictly inside `[opened_at, end_at)` and is not after `as_of` are used. A position still open at the cutoff is clipped to `as_of` and flagged `open_at_cutoff`; its excursion is an observation so far, not a final outcome.
2. **Excursions are clamped at zero and never negative.** A favourable excursion cannot be negative, and a price that never moved against the position yields `MAE = 0` rather than a negative number.
3. **R multiples require a valid initial stop.** `mfeR` / `maeR` use `|entry − stop|`; when the stop is missing or not adverse, the R values are `NULL` and `risk_reason = MISSING_OR_INVALID_STOP` — never silently zero.
4. **Ambiguity is flagged, never resolved by assumption.** If one candle could have touched both the stop and the target, the intra-candle sequence is unknowable, so the record carries `ambiguous_path = true` with the reason. Every record also carries the explicit limitation `OHLC_DERIVED_ESTIMATE_NOT_TICK_LEVEL_PATH_TRUTH`.
5. **The pre-entry estimator is causal by construction.** An observation enters the distribution only when the position close time **and** the last source candle close time are strictly before the candidate entry. Excluded observations are counted by reason (`NOT_MATURED_BEFORE_CANDIDATE_ENTRY`, `SOURCE_CANDLES_AFTER_CANDIDATE_ENTRY`, `NO_ELIGIBLE_CANDLES`). An empty population yields `INSUFFICIENT_DATA` + `NO_MATURED_PRIOR_OBSERVATIONS` instead of a fabricated estimate. Every computed estimate carries the sample size, the lookback, the cutoff, the maximum included maturity, the maximum included source-candle time and a `no_future_data_proof` flag.
6. **Percentiles are nearest-rank, never interpolated.** Every reported quantile is an actually observed MFE value; the mean is computed with exact decimal arithmetic.
7. **One accounting identity, no double deduction.** `Net PnL = Gross PnL − Fees + Signed Funding`. Slippage is price distortion already contained in gross PnL, so it is reported as an attribution (drag in USDT and weighted bps) and never subtracted again. The attribution asserts the identity per group and reports `reconciles_gross_minus_fees_plus_funding`.
8. **Undefined ratios are `NULL` with a reason.** A commission share of a non-positive gross PnL is `NULL` (`GROSS_PNL_NOT_POSITIVE`); a weighted-bps figure over a zero notional is `NULL` (`ZERO_REFERENCE_NOTIONAL`).
9. **Fill roles are explicit.** `ENTRY`, `PARTIAL_EXIT` (any `PARTIAL*` execution type) and `FINAL_EXIT`; cost is reported per role, because entry cost and exit cost have different economic meaning.
10. **Data-quality gaps are reported, not smoothed.** Positions whose exported execution rows are incomplete (missing the entry leg) are counted explicitly (`positions_missing_entry_execution`, `position_ids_missing_entry_execution`) together with the fee delta they explain.
11. **Read-only ledger.** The service only reads `paper_accounts`, `virtual_positions`, `virtual_executions`, `position_funding_events` and `market_candles`; all writes go to research-owned tables. The eight primary accounts are never created, merged or deleted.

## 3. Test evidence (all actually executed)

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/CostMfe                              → 20 tests, 20 passed, 103 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeTest.php             →  5 tests,  5 passed, 33 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeApiTest.php           →  3 tests,  3 passed, 18 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage                                                         → 516 tests, 481 passed, 35 skipped, 0 failed, 2803 assertions
./vendor/bin/pint app/Research/Domain/CostMfe app/Research/Application/CostMfeMaeService.php ... → fixed, then clean
cd research-go && go build ./... && go vet ./... && go test ./...                                            → all packages ok
```

Covered behaviours (unit + feature): MFE/MAE for long and short positions from candles inside the holding interval only; candles after `as_of` and outside the interval excluded; excursions clamped at zero; R multiples `NULL` with a reason when the stop is missing; stop/target ambiguity flagged; determinism for identical input; `NO_ELIGIBLE_CANDLES` → `NULL` metrics (never zero); pre-entry estimator excludes observations matured after the entry and observations whose source candles reach past the entry; `INSUFFICIENT_DATA` for an empty matured population; lookback window honoured; nearest-rank percentiles; cost attribution grouped by strategy/symbol/account/fill role; `Gross − Fees + Funding = Net` reconciliation; `NULL` commission share and bps with reasons; gross-positive/net-negative counting; ambiguous-execution counting; partial exits mapped to their own role; positions with missing execution rows reported; idempotent generation + `409 IDEMPOTENCY_CONFLICT`; read endpoint before/after a generation with zero write queries; research-token enforcement on the write endpoint.

## 4. Real-data evaluation (local research export, read-only)

```
php scripts/p4_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P4_evidence.json
→ P-4 archive evaluation written to reports/profit-growth/P4_evidence.json (positions=38, pre_entry_computed=34)
```

Source integrity: the SHA-256 of `paper_accounts.csv`, `virtual_positions.csv`, `virtual_executions.csv`, `position_funding_events.csv` and `market_candles.csv` matches the archive manifest for every file.

| Observation | Result |
|---|---|
| Positions evaluated | 38 (6 open at the cutoff, 32 closed) |
| Positions with computed excursions | 38 / 38 (no position lacked eligible candles) |
| MFE in R | min `0`, median `0.513573561064`, max `2.638615172591` |
| MAE in R | min `0`, median `0.720161997701`, max `0.949937934681` |
| Ambiguous paths | 0 (no candle spanned both stop and target) |
| Pre-entry estimates | 38 evaluated — 34 `COMPUTED`, 4 `INSUFFICIENT_DATA` (no matured prior observation) |
| Matured population per candidate entry | 0 (4×), 1, 2, 5, 7, 8, 13, 14, 15, 17, 21 observations |
| Cost attribution groups | 6 strategies, 2 symbols, 6 accounts, 2 fill roles |
| Accounting identity | `reconciles_gross_minus_fees_plus_funding = true` for **every** group |
| Weighted slippage | `2.0000` bps in every group (the configured simulator rate) |
| Gross-positive but net-negative positions | 3 (momentum 2, donchian_atr_breakout 1) |
| Ambiguous executions | 6 (momentum 1, donchian_atr_breakout 4, bollinger_mean_reversion 1) |
| Incomplete export | 2 positions (both `momentum`, account `1b36e922…`) have **no entry execution row**; the group-level execution commission is `4.385208466320` below the position-level fees, which is exactly the missing entry-leg commission. Reported explicitly, never smoothed. |

Interpretation limits: the excursion figures are OHLC-derived estimates over 5-minute candles, not tick-level path truth. The matured population for the pre-entry estimator is tiny (median single-digit), so the distribution is a descriptive diagnostic only. Nothing here is evidence about future returns, and no cost, fee, slippage or MFE gate was added or tuned by this package.

## 5. Acceptance checklist (§9.3)

| Requirement | Status | Evidence |
|---|---|---|
| Observed MFE/MAE per position with source candle ids/timestamps | Met | `research_mfe_mae_records.mfe_candle_id/…_close_time`, `source_candle_ids`, `source_candle_close_times`; unit + feature tests |
| Explicit OHLC ambiguity flags | Met | `ambiguous_path`, `ambiguity_reason`; unit test for the stop+target span case |
| Pre-entry expected-MFE distribution from matured observations only | Met | `PreEntryMfeEstimator`; tests for the maturity and source-candle exclusions; `no_future_data_proof` in the evidence |
| `INSUFFICIENT_DATA` instead of an estimate on an insufficient population | Met | 4 of 38 candidates reported `INSUFFICIENT_DATA` in the real evaluation |
| Cost attribution by strategy / symbol / account / fill role | Met | `cost_attribution` in `P4_evidence.json`; `research_cost_attribution_records` |
| Sample size, lookback, cutoff and provenance on every estimate | Met | `sample_count`, `lookback_start`, `cutoff`, `max_included_matured_at`, `max_included_source_candle_close_time` |
| No fee / slippage / MFE gate added or tuned | Met | No configuration change; the package only observes and attributes |
| No claim that cutting a cost raises future profit | Met | No such statement in code, evidence or this report |
| No look-ahead | Met | Candle and observation boundaries are enforced in the domain layer and covered by tests |

## 6. Not verified / limitations

- **PostgreSQL is still unusable** in this environment (`5432` unreachable, `5433` auth fails) ⇒ `TEST_DB_ISOLATION_UNVERIFIED`. All P-4 verification ran on SQLite `:memory:`. PG `NUMERIC` rounding, `decimal(36,12)` scale behaviour and the `research_*` table constraints are therefore unverified on PG. The DB-level assertions compare with `BigDecimal` instead of a fixed string so that the scale difference between SQLite and PG cannot produce a false failure or a false pass.
- The archive is a **bounded best-effort export** (`BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ`); position snapshots are mutable and may change after the cutoff. The two positions with a missing entry execution are an export gap, not a ledger defect (their position-level fees reconcile).
- The archive has no explicit candle close column; the 5-minute close time is derived from `open_time + 5m`, which is recorded in the evidence config.
- The candle population covers 10 warm-up days before the 24-hour research window, but the excursion windows themselves are fully covered for all 38 positions.
- MFE/MAE on 5-minute OHLC candles cannot see intra-candle paths; a position that touched its stop and then its target inside one candle would be under-reported as an excursion and is flagged as ambiguous.
- The pre-entry estimator was evaluated **per symbol** on a 24-hour window; the matured population is too small for any statistical statement about excursion distributions.
- No live/paper trading path was exercised, no order was created, and no deployment was performed.

## 7. Reproduction

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/CostMfe
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeTest.php
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeApiTest.php
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
php scripts/p4_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P4_evidence.json
sha256sum reports/profit-growth/P4_evidence.json
```

## 8. Next package

P-6 (§14 research decision gate) — fail-closed evidence assembly that must never auto-modify a live or paper strategy and must report `NOT_ELIGIBLE` while no prospective shadow history exists.
