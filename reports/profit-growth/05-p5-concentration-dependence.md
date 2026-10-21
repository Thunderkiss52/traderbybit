# P-5 — Concentration, overlapping positions and dependence

**Package:** P-5 (PROFIT-1) · **Normative source:** `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` §10 (with §6 contracts)
**Mode:** research only — no live orders, no trade keys, no paper-ledger mutation, no deployment
**Status:** IMPLEMENTED + VERIFIED (unit/feature/API) with a real-data archive evaluation
**Evidence file:** `reports/profit-growth/P5_evidence.json` (SHA-256 `d15ee41b18e1908190de4abbbe8df5c1052b132d521a2f30631434befe5cd230`)

---

## 1. What was implemented

| Area | File | Purpose |
|---|---|---|
| Interval contract | `app/Research/Domain/Concentration/PositionInterval.php` | Half-open `[opened_at, end_at)`, open-at-cutoff and partial-close flags, realized vs. unrealized separation |
| Interval rules (single source of truth) | `app/Research/Domain/Concentration/PositionIntervalFactory.php` | Shared by the Laravel service and the offline archive evaluator so the two cannot diverge |
| Cluster algorithm | `app/Research/Domain/Concentration/OverlapClusterBuilder.php` | `SAME_SIDE` connected components + `OPPOSITE_SIDE` bipartite overlap graph (§10.2) |
| Concentration metrics | `app/Research/Domain/Concentration/ConcentrationAnalyzer.php` | Top-K (1/3/5), share of total, day blocks, open-position accounting (§10.4) |
| Equity alignment | `app/Research/Domain/Concentration/EquityAlignment.php` | Causal grid alignment, carry-forward with fixed max age, `NOT_COMPARABLE` (§10.3) |
| Cross-symbol exposure | `app/Research/Domain/Concentration/CrossSymbolExposure.php` | Gross exposure per symbol and combined, never netted (§10.2) |
| Application service | `app/Research/Application/DependenceConcentrationService.php` | Generation, idempotency, account-level + research-combined views, persistence |
| Storage | `database/migrations/2026_10_21_110000_create_research_concentration_tables.php` | `research_concentration_clusters`, `research_concentration_cluster_members` (append-only research output) |
| Records | `app/Research/Domain/ResearchConcentrationClusterRecord.php`, `…ClusterMemberRecord.php` | Read models for the two tables |
| API | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php`, `routes/api.php` | `GET research/profitability/concentration-dependence`, `POST research/profitability/generations/p5` (research-token protected) |
| Offline evaluator | `scripts/p5_archive_evaluation.php` | Read-only evaluation of the local research export (no DB, no ledger, no order path) |

## 2. Design decisions (and why)

1. **Interval semantics.** `[opened_at, end_at)`; a position that is not fully closed at the cutoff is clipped to `as_of` and flagged `open_at_cutoff`; a position with `closed_at` but non-zero `remaining_quantity` is a **partial close** and does **not** end the interval. Realized net PnL of an open position is `0`; its unrealized PnL is reported separately and never mixed into concentration (§10.1).
2. **Two disjoint views.** `SAME_SIDE` creates edges only between overlapping positions of the same symbol *and* the same side (transitive within that side). `OPPOSITE_SIDE` is a **bipartite** graph per symbol: partitions `BUY`/`SELL`, edges only between opposite sides that overlap; a same-side edge is never created through a shared opposite-side neighbour. A reported component may contain several BUY and SELL nodes — that is inherent to a bipartite graph and is documented in the evidence (§10.2).
3. **Content-addressed cluster ids.** `cluster_id = P5SS/P5OS + sha256(definition version | view | scope | symbol | side family | sorted member position ids)`. Identical inputs therefore always yield identical ids and membership, independently of input order, while the *scope* (`ACCOUNT:<id>` vs `RESEARCH_COMBINED`) keeps the two views distinguishable.
4. **No netting, anywhere.** Cross-symbol and opposite-side exposure is reported **gross**; the ledger is never netted or modified, and no account is merged with another.
5. **Equity alignment fails closed.** The grid is a fixed UTC interval, the carry-forward age is fixed and recorded in the generation config, missing snapshots are never interpolated and never replaced by zero. If any account-grid observation is missing beyond that age, the cross-account comparison is reported as `NOT_COMPARABLE` instead of a fabricated synchronization (§10.3).
6. **Non-positive totals.** A share of a non-positive total net PnL is `NULL` with reason `TOTAL_NET_PNL_NOT_POSITIVE` — the ratio would be meaningless and is not printed (§10.4).
7. **Read-only ledger.** The service only reads `paper_accounts`, `virtual_positions`, `equity_snapshots`; all writes go to research-owned tables. The eight primary accounts are never created, merged or deleted.

## 3. Test evidence (all actually executed)

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Concentration      → 14 tests, 14 passed, 56 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P5DependenceConcentrationTest.php → 9 tests, 9 passed
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P5ConcentrationApiTest.php       → 3 tests, 3 passed, 16 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage                                                   → 488 tests, 453 passed, 35 skipped, 0 failed
./vendor/bin/pint --test app/Research app/Http/Controllers/Api/V1/ProfitabilityResearchController.php tests/Feature/Research tests/Unit/Research/Concentration database/migrations/2026_10_21_110000_create_research_concentration_tables.php routes/api.php → clean
```

Covered behaviours (unit + feature): same-side merging and transitivity; opposite-side bipartiteness (no same-side edge, component may span both partitions); half-open intervals that only touch do not overlap; content-addressed and order-independent cluster ids; exact decimal cluster PnL; `NULL` share with reason on a non-positive total; Top-K ranking with member position ids; unrealized PnL kept out of realized concentration; open position clipped to the cutoff; partial close not ending the interval; determinism across two generations; gross cross-symbol exposure without netting; equity gap → `NOT_COMPARABLE` with no zero fill; empty population → `INSUFFICIENT_DATA`; idempotent generation + `409 IDEMPOTENCY_CONFLICT`; read endpoint before/after a generation; research-token enforcement on the write endpoint.

## 4. Real-data evaluation (local research export, read-only)

```
php scripts/p5_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P5_evidence.json
```

* Source integrity: `paper_accounts.csv`, `virtual_positions.csv`, `equity_snapshots.csv` — SHA-256 **matches the archive manifest** (verified in `P5_evidence.json → source.file_integrity`).
* Population: **38 positions**, 6 of 8 primary accounts with positions, 0 excluded intervals, 2 UTC day blocks, 6 positions still open at the cutoff (unrealized PnL `5.084571500000` reported separately).
* Combined research view (`SAME_SIDE`): **11 clusters / 38 members**, total net PnL `-260.362805849568` → **not interpretable** (`TOTAL_NET_PNL_NOT_POSITIVE`), so all share-of-total values are `NULL` with a reason.
* Top-K (same-side, gross ranking): K=1 → `72.626276754777` (ETHUSDT SELL, 8 members); K=3 → `124.118803095609`; K=5 → `105.330689968632` across 5 clusters (K=5 < K=3 because the 4th/5th clusters are negative — no silent trimming).
* `OPPOSITE_SIDE`: **24 clusters, 16 opposite-side edges** (every edge is BUY↔SELL by construction).
* Cross-symbol gross exposure: BTCUSDT peak `26 423.932656300000`, ETHUSDT peak `37 121.127300000000`, combined peak `39 817.600096300000` — summed gross, `netting_applied = false`.
* Equity synchronization: **`NOT_COMPARABLE`** (`MISSING_SNAPSHOTS_BEYOND_MAX_CARRY_FORWARD_AGE`). The observed snapshot cadence is event-driven (median gap 60–900 s, **max observed gaps 5 400 s and 11 940 s**) and exceeds the fixed 3 600 s carry-forward age, so a compliant alignment does not exist. 4 simultaneous-drawdown periods were detected on the comparable subset and are reported with the `NOT_COMPARABLE` status attached; no missing observation was interpolated or zero-filled.

**Interpretation limit:** a 24 h forward-paper export of 38 positions is a description of a dependence structure, not a statistical sample and not evidence of future performance. No profitability, win-rate or expectancy claim is made or implied by any number above.

## 5. Acceptance checklist (§10.5)

| Requirement | Result | Evidence |
|---|---|---|
| Interval boundaries, partial exits, open-at-cutoff positions have tests | PASS | `test_open_position_is_clipped_to_the_cutoff…`, `test_partial_close_does_not_end_the_position_interval`, half-open unit test |
| Same inputs → identical cluster ids and membership | PASS | `test_cluster_ids_are_deterministic_across_runs`, order-independence unit test |
| No cross-symbol or cross-account ledger netting | PASS | `test_cross_symbol_exposure_is_reported_gross_without_netting`; `netting_applied=false` in service and evidence |
| Same-side and opposite-side clusters are distinct | PASS | `test_same_side_and_opposite_side_clusters_are_distinct…` (disjoint id sets) |
| Top-K traceable to source position ids | PASS | `test_top_k_concentration_is_traceable_to_source_position_ids`; member ids stored in `research_concentration_cluster_members` |
| Missing/misaligned equity snapshots visible, never zero returns | PASS | `test_missing_equity_snapshots_make_the_cross_account_view_not_comparable`; `zero_fill_applied=false`, `interpolation_applied=false` |
| Cluster membership, bounds, definition version stored | PASS | `research_concentration_clusters` / `…_members` + `cluster_definition_version` + generation id |

## 6. Not verified / limitations

* **PostgreSQL behaviour is unverified.** The test database is SQLite (`:memory:`); `NUMERIC` rounding, `ON CONFLICT` and concurrency semantics of the two new tables were not exercised on PostgreSQL (no reachable instance, unknown role).
* The real-data evaluation runs against a **bounded best-effort export** (`source_consistency = BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ`, `mutable_position_snapshots_may_change_after_cutoff = true`); position rows may have changed after the export cutoff.
* The equity view is `NOT_COMPARABLE` for the archive window; a longer, regularly sampled equity series is required before any cross-account drawdown dependence can be reported.
* Cluster membership is a **dependence group**, not proof of a single causal market episode and not a set of independent observations (§10.2).
* No P-6 decision was produced, and P-5 alone can never promote a strategy (§14 fail-closed gate).
* Frontend UI for the new views is not implemented; only the API surface exists.

## 7. Reproduction

```bash
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Concentration
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P5DependenceConcentrationTest.php tests/Feature/Research/P5ConcentrationApiTest.php
php scripts/p5_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P5_evidence.json
```

## 8. Next package

P-4 (§9 — cost structure, MFE/MAE) and then P-6 (§14 — research decision gate), each in its own change set with its own evidence bundle, as required by §4.1.
