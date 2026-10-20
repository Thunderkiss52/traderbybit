# VECTOR PROFIT-1 (Revision 2.1)
## Profitability Measurement, Risk-Adjusted Returns & Capital Efficiency

**Implementation specification for Codex — cross-functional product and engineering contract**  
**Revision:** 2.1  
**Status:** **Only P0 (Read-Only Discovery) is authorized.** Every later package requires explicit owner review and acceptance of the preceding evidence bundle.  
**Primary language of the product UI and reports:** Russian; stable machine-readable English enum values in APIs and storage  
**Target stack:** Existing Laravel + PostgreSQL 17 (or the exact production-compatible version verified in P0) + Redis + Vue 3/Quasar + Docker. No new microservice by default.  
**Source audit:** VECTOR PROFIT-1 review dated 2026-10-20  
**Expected historical reference archive:** `vector-audit-1d-e534ac49-*.zip`  
**Expected archive SHA-256 (must be independently verified in P0):** `54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4`

> **Core objective:** establish a trustworthy, reproducible view of the economics of all eight strategies, identify where net results are lost, and measure capital efficiency without changing strategy behaviour. The product is a research and decision-support layer, not a promise of profitability and not an automated strategy optimizer.

## Revision 2.1 — material changes

- Added a cost-to-risk diagnostic and explicit notional/risk-capital and margin metrics where source data supports them.
- Defined time-weighted average equity using piecewise-constant intervals and a maximum carry-forward age.
- Fixed opposite-side overlap analysis as a bipartite graph so connected components cannot imply false same-side edges.
- Fixed the default generation-selection contract and split the dense summary table into three UX presets.
- Added idempotency payload hashes, worker heartbeats, stale-job failure semantics, canonical serialization and stable SQL ordering.
- Standardized monetary calculations on PostgreSQL `NUMERIC` and PHP `bcmath`; do not split formulas between Laravel and Go unless P0 proves an existing identical implementation.
- Added a guarded capital-recovery-per-100-trades estimate for registered hypotheses; it is not a promise of future profit.
- Expanded historical scope reconciliation. The audit's 24-hour execution-flow fees and position-lifecycle fees must not be mixed.
- Clarified that a 24-hour audit can verify accounting arithmetic but cannot establish strategy profitability or pass the minimum statistical evidence gate.

---

# 0. Instructions to Codex

Implement this specification **one reviewable package at a time**. Do not implement every package in one monolithic change set.

1. Start with **P0 — repository and evidence discovery only**. Do not change application code during P0; a necessary diagnostic script must be read-only.
2. Produce `reports/profitability/P0_baseline.md`, identify blockers and stop for owner review. Do not guess schema, formulas, runtime behaviour, archive identity, or service interfaces.
3. Only after P0 is reviewed and explicitly accepted, implement **P-1 — slippage attribution** as one isolated change set.
4. Only after P-1 is accepted with `evidence_p1.zip`, implement **P-2** and **P-5** in separate change sets. Implement **P-4** in its own change set after P-1 acceptance; no package may be bundled into one monolithic change set.
5. Implement **P-6** only after P-1, P-2, P-4 and P-5 have each passed their applicable acceptance gates.
6. **P-3 (rejected-signal SHADOW replay) is not authorized.** Do not create its tables, workers, migrations, endpoints, schedules or jobs unless the owner explicitly authorizes it in a separate instruction.
7. Do not alter trading behaviour: no strategy, risk, sizing, entry, exit, cooldown, correlation, Gate 6 or model-promotion changes.
8. Do not deploy, push, commit, trigger GitHub Actions, run production migrations or mutate production data without explicit owner authorization.
9. Never claim a test, SQL query, replay, archive check or provider operation succeeded unless its raw result was captured in the evidence bundle.
10. If an archive, table, service, price reference, timestamp or required data field is unavailable, mark the affected claim `NOT_VERIFIED` or `INSUFFICIENT_DATA`. Do not substitute zero, a fabricated value or a guessed fallback.
11. Monetary calculations must use PostgreSQL `NUMERIC` and exact PHP decimal arithmetic (`bcmath` or an already-verified project equivalent). Do not use binary floating-point for money aggregation.
12. A repeated request with an idempotency key must never silently restart or replace a failed generation. Stale jobs must be marked explicitly and retried only through a documented, auditable retry path.

**Naming warning:** `PROFIT-1 P-1` in this document means *slippage attribution*. It is not the same work item as `V5.3 P1` for strategy-observation slot idempotency. Keep their manifests, change sets, and evidence separate.

---

# 1. Product objective and decision principles

## 1.1 North-star objective

Improve the project's ability to grow capital sustainably by maximizing **risk-adjusted net performance** and improving **capital efficiency**, while keeping the existing eight paper accounts isolated and leaving trading behaviour unchanged during this project.

The product must answer, with traceable evidence:

- Which strategies produced positive or negative net results after recorded fees and signed funding?
- How much execution drag is recorded by the paper simulator, and how is it already reflected in PnL?
- Which strategies have stronger or weaker returns relative to their risk capital and drawdown?
- How much gross exposure and risk capital are used over time, and how often is capital actually deployed?
- Is PnL concentrated in a small number of positions or overlapping market episodes?
- Which conclusions are supported by sufficient independent evidence, and which remain uncertain?
- What additional measurement would be required before a proposed improvement could be considered?

**Do not optimize raw PnL or capital utilization in isolation.** A strategy that increases utilization by increasing concentration, turnover, or drawdown is not automatically an improvement.

## 1.2 Product outcome

Deliver a page named **Profitability Research** inside the existing application, plus versioned machine-readable exports and an evidence bundle. A user must be able to inspect a headline metric, understand its formula, select the accounting scope and time window, and trace the result to source rows and calculation output.

The page is an analytical product. It must not change signals, positions, account balances, risk decisions, or model authority.

## 1.3 Success criteria

The feature is successful when:

1. All eight strategies can be analysed independently using the same declared accounting rules.
2. Financial identities and scope boundaries reconcile from source events.
3. Execution slippage is attributed without being subtracted from PnL a second time.
4. Risk and capital-efficiency metrics have complete, testable definitions.
5. Dependence and concentration are visible; overlapping positions are not presented as independent experiments.
6. Inadequate samples are explicitly labelled, not ranked as profitable or unprofitable.
7. Every displayed result has a generation ID, cutoff, scope, source hashes, and a route to the evidence that supports it.
8. The new research layer cannot write to the paper-trading ledger or change the existing decision path.
9. A repeat run using the same immutable inputs, cutoff, code version, and configuration produces the same normalized analytical output.
10. No production deployment or strategy change occurs as a side effect of this work.

These criteria establish analytical correctness, not financial profitability.

---

# 2. Review of the current specification: issues that must be corrected

The current draft is strong in safety boundaries, historical provenance, and staged delivery. The following defects must be corrected in the implementation contract.

| Issue | Why the current wording is unsafe or incomplete | Required correction |
|---|---|---|
| Slippage appears beside fees as if both were deductions from the same gross PnL | Recorded fill prices already include simulator slippage. Subtracting slippage again double-counts it. | Separate actual paper-ledger accounting from a clearly labelled no-slippage counterfactual. |
| `profit_factor`, `win_rate`, `payoff_ratio`, drawdown, turnover and daily R are incompletely defined | Different developers could produce different numbers and both claim compliance. | Use the exact definitions in §8 and test boundary cases. |
| ESS is calculated but has no minimum threshold | A tiny effective sample could still be marked sufficient. | Require both minimum calendar coverage and minimum ESS; insufficient data blocks inference. |
| Primary keys ignore generation/version identity | Recomputing with a new algorithm could overwrite or conflict with earlier results. | Use immutable generations and uniqueness such as `(generation_id, source_entity_id)`. |
| Dependency graph says P-1/P-4 are independent although P-4 consumes P-1 outputs | P-4 could run before its required data exists. | Enforce the dependency graph in §4. |
| User-facing deliverables are not fully specified | Backend tables alone do not tell the owner what the product delivers. | Build the Profitability Research page and traceable exports described in §11. |
| Hypothetical rejected-signal replay has unresolved execution assumptions | Replay could turn a signal plan into an invented fill or falsely claim missed profit. | Keep P-3 unauthorized; if later approved, apply the separate contract in §10. |
| “Measured slippage” can be mistaken for real exchange execution evidence | The current source says `price_source=closed_5m_ohlc`; these are paper-simulator records, not real fills. | Use `RECORDED_SIMULATOR` / `SIMULATED_FROM_REFERENCE_PRICE`, not `REAL_EXCHANGE_MEASURED`. |
| Cost percentages can be misread as a PnL waterfall | Fees, slippage ratios and net/gross ratios are not additive when gross PnL already contains slippage. | Display the actual ledger waterfall and slippage-only counterfactual separately. |
| “Capital utilization” can incentivize unsafe exposure | High utilization is not itself evidence of better performance. | Show utilization alongside net return, drawdown, turnover, exposure and sample sufficiency; never auto-increase risk. |

## 2.1 Historical figures are fixtures, not hard-coded answers

The historical values in §5 are reference assertions from the audit. P0 must independently recompute them from the archive. They must not be embedded in application business logic or used as constants in tests that purport to prove source calculations.

If the archive is absent, its SHA-256 differs, the required columns are missing, or an independent calculation does not reproduce a value within a pre-declared decimal tolerance, mark the claim `NOT_VERIFIED`, explain the discrepancy, and stop the affected package. Do not force code to match a number by changing the source data or rounding rules.

---

# 3. Non-negotiable boundaries

1. **No real exchange orders.** Do not add trade or withdrawal credentials or real execution paths.
2. Preserve all eight independent paper accounts, balances, positions, executions, equity snapshots, journals, and histories. Do not merge accounts or net their actual ledgers.
3. Do not change strategy parameters, entry/exit rules, risk sizing, Risk Gate, correlation limits, cooldowns, Gate 6, promotion policy, or model authority.
4. Do not create an automatic strategy optimizer or auto-promotion path.
5. Research tables are append-only by generation. Do not rewrite source history to make totals reconcile.
6. Do not change the existing V5.3 accounting implementation. Reuse its verified accounting services through their actual interfaces; do not reimplement the same business rules in a second place.
7. Keep the existing Laravel/PostgreSQL/Redis/Vue 3/Quasar/Docker architecture. **The default calculation path for P-1/P-2/P-4/P-5 is Laravel + PostgreSQL `NUMERIC` in one calculation contour.** Reuse a Go worker only if P0 proves that the exact same relevant calculation already exists there and can be reused without duplicating formulas or decimal-rounding behaviour. Do not split financial formulas between PHP decimal arithmetic and Go `float64`; do not add a new microservice merely for this feature.
8. Do not use Kubernetes.
9. Never touch `ECONOM` / `econom-group`, its images, volumes, networks, build cache, files, backups, or related resources. Do not run global Docker prune commands.
10. Do not overwrite user changes. Record the starting Git SHA and `git status --short`; inspect all existing diffs before editing.
11. No Git commit, push, deployment, GitHub Actions run, production migration, or production data mutation without explicit owner authorization.
12. No fabricated price, slippage, PnL, sample size, confidence interval, test count, or test result.
13. No cost/fee/slippage filter may be added or enabled in this project.
14. No claim of profitability may be based on the 24-hour audit window alone.

---

# 4. Packages, dependencies and delivery order

## 4.1 Correct dependency graph

```text
P0 — Read-only discovery, source/archive verification, contracts and baseline
 └── P-1 — Slippage attribution
      ├── P-2 — Risk-adjusted returns and capital efficiency
      ├── P-5 — Concentration and dependence
      └── P-4 — Cost structure and MFE/MAE (requires accepted P-1)

P-1 + P-2 + P-4 + P-5 — each separately accepted
 └── P-6 — Research decision gate, profitability UI and exports

P-3 — Rejected-signal SHADOW replay
 └── NOT AUTHORIZED; separate written approval required.
```

The execution order is strict: P0 → P-1 → separate P-2/P-5 change sets → P-4 → P-6. P-2 and P-5 may be scheduled in either order after P-1 acceptance, but each requires its own review and evidence bundle. P-4 cannot consume unaccepted P-1 outputs. P-6 cannot produce an evidence-backed decision until all applicable upstream packages pass.

P-3 is not part of the current implementation authorization. P-6 must state `NOT_EVALUATED` for conclusions that require rejected-signal replay if P-3 has not been separately authorized and completed.

## 4.2 Package ownership and required delivery

Every package must include:

- one reviewable change set, preferably one PR-sized unit;
- files and migrations changed;
- formulas and schema contracts;
- tests and raw test output;
- evidence manifest and source hashes;
- performance observations where relevant;
- feature flag and safe-disable instructions;
- rollback or forward-fix plan;
- known limitations and explicit `NOT_VERIFIED` claims.

P0 must estimate package size and identify the actual repository owner/path for each existing service. Do not promise calendar dates before the P0 inventory.

---

# 5. Evidence baseline and historical reference values

## 5.1 Source identity

Expected source archive:

- Name pattern: `vector-audit-1d-e534ac49-*.zip`
- SHA-256: `54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4`
- Reference window: `[2026-10-18T14:45:00Z, 2026-10-19T14:45:00Z)`

P0 must verify the exact archive file and checksum before using any of the following values as golden references. The filename and SHA above are **expected identifiers, not proof that the correct archive is present**. If the actual audit supplied for this project has a different filename or hash, stop and report both identities; do not mix records or force the supplied archive to match this expected hash.

P0 must also prove that each reference assertion uses a consistent population and scope. In particular, do not add the reported `35.1732 USDT` slippage attribution to the lifecycle net PnL `+37.5057 USDT` unless the two values cover the same executions/positions and the same accounting scope. Until this is proven, `+72.6789 USDT` is a provisional reference assertion, not an accepted counterfactual result.

## 5.2 Reference assertions to reproduce

| Claim | Audit reference | Interpretation / constraint |
|---|---:|---|
| Position population | `29 positions: 28 closed, 1 partially closed` | Verify whether the partially closed position remains open at extraction; do not call its final lifecycle PnL complete if it does. |
| Position-level gross PnL | `+142.5411 USDT` | Verify exact population and scope. |
| Lifecycle fees | `104.4998 USDT` | Verify position-lifecycle population; do not mix with window-flow fees. |
| Fees on executions inside the 24-hour window | `96.7267 USDT` across `65` executions | Window-flow scope, distinct from lifecycle fees. |
| Boundary-fee difference | `7.7731 USDT` across `4` boundary-crossing positions | Reconcile source executions and exact timestamps. |
| Signed funding | `-0.5356 USDT` | Preserve the sign convention. |
| Net PnL | `+37.5057 USDT` | Identity: gross − fees + signed funding. |
| Recorded simulator slippage drag | `35.1732 USDT` | Attribution value; already embedded in fill-price PnL. |
| Executions with slippage populated | `65/65` | Verify actual source column semantics and completeness. |
| Average reported slippage | `2.00 bps` | Recompute using the denominator defined in §7. |
| Ambiguous executions | `6/65` | Preserve ambiguity flags; do not silently treat as precise. |
| Gross-positive but net-negative positions | `6` | Diagnostic only; no threshold inferred. |
| Zero-slippage counterfactual net PnL | provisional `+72.6789 USDT` | Accept only after verifying that baseline PnL and slippage cover the same population and scope; hold fees/funding fixed only under the declared counterfactual mode. |
| Fee/risk split from the audit | `>0.18: +44.86 USDT`; `≤0.18: -7.36 USDT` | Evidence against a naive fee/risk threshold; never turn it into a filter. |
| Correlation between fee/risk and net R | `+0.083` | Recompute; do not interpret as causality. |
| Exposure/position/correlation rejections | `49 unique rows of 54 directional rejections` | P-3 target only; not 49 proven missed profitable trades. |
| Reason incidences for those rows | `52 incidences over 49 rows` | Three rows contain multiple reasons; reasons are not unique decisions. |
| Same-direction transitive clusters | `24/29 positions` | Cluster members are dependent, not independent samples. |
| Signal counts | `1,524 total: 25 BUY / 60 SELL / 1,439 SKIP` | Historical funnel context only; no instruction to loosen filters. |
| Risk-decision counts | `109 rows: 31 approved, 78 rejected; 24 rejected rows are SKIP and 54 are directional BUY/SELL` | Verify against source schema/exporter. |
| Directional risk rejections | `49 unique decisions among 54 directional rejections; 52 reason incidences` | P-3 target only; reason incidences are not unique decisions or proven missed profits. |
| `price_source` on fills | `closed_5m_ohlc` | Indicates simulator reference source, not real exchange execution. |

These are **reference assertions to reproduce**, not hard-coded outputs. The implementation must calculate them from the archived records and include raw SQL/Python output. If any value changes due to a clarified scope or corrected formula, document both the old and new definition and the source rows explaining the difference.

## 5.3 Correct financial interpretation

The actual paper-ledger identity is:

`net_pnl = gross_pnl - fees + signed_funding`

Here `gross_pnl` is already calculated from the recorded fill prices. If those fill prices include simulated adverse slippage, that slippage is already reflected in `gross_pnl` and `net_pnl`.

Therefore:

- Do **not** calculate `net_pnl - fees - slippage + funding` from the existing ledger.
- Do **not** present fees and slippage as two additive deductions from the same already-slippage-affected gross PnL.
- Do show the actual ledger waterfall: `gross PnL → minus fees → plus signed funding → net PnL`.
- Show slippage attribution beside it as an execution-drag diagnostic.
- Show the no-slippage scenario in a separate counterfactual panel, with its assumptions explicitly stated.

If the zero-slippage scenario holds fees and funding fixed, label it `SLIPPAGE_ONLY_COUNTERFACTUAL_FEES_FUNDING_FIXED`. If commissions are recalculated at reference prices, label that as a different scenario and recalculate them using the actual fee schedule. Never silently mix the scenarios.

Ratios such as `fees / gross_pnl` and `slippage_drag / gross_pnl` may be displayed as separate scale comparisons only when the denominator is positive. They are **not additive shares of a common cost waterfall**. If gross PnL is zero or negative, show the ratio as `NULL` with a reason.

---

## Additional P0 blocker — scope matching for counterfactuals

The reference assertions include both position-lifecycle figures and 24-hour execution-window figures. P0 must produce a population/scope reconciliation table before any counterfactual is accepted. In particular:

- lifecycle gross/fees/funding/net PnL must be recomputed from one explicitly declared lifecycle population;
- 24-hour execution fees/slippage must be recomputed from executions whose event timestamps fall in `[window_start, window_end)`;
- any bridge between those populations must list the boundary executions and positions by source ID;
- a no-slippage counterfactual may be computed only from a baseline PnL and slippage attribution that cover the same population and scope.

If these conditions are not satisfied, retain the historical values as unverified assertions and do not present the sum `37.5057 + 35.1732` as a proven counterfactual.

---

# 6. Cross-cutting data, accounting and provenance contracts

## 6.1 Accounting scopes

Every generation and every metric must declare exactly one scope:

- `PIT_EVENT_TIME`: source events whose market/event timestamp is at or before `as_of`, regardless of when the system registered them.
- `PIT_KNOWN_AT`: source events whose event timestamp **and** system registration timestamp are at or before `as_of`.
- `WINDOW_FLOW`: flows/events in the half-open interval `[window_start, window_end)`. State whether opening/closing inventory is valued at boundaries.
- `FULL_LIFECYCLE`: the complete lifecycle available in the immutable source snapshot as of its declared extraction timestamp. It must not imply knowledge beyond that extraction timestamp.

Do not mix these scopes in a single aggregate. A position opened before a cutoff and closed after it must not contribute its future final realized PnL to a point-in-time result. Use the V5.3 reconstruction/accounting services and their verified contracts.

## 6.2 Financial conventions

- Currency: USDT unless source data explicitly says otherwise.
- Time: UTC; store timezone-aware timestamps.
- Intervals: `[start, end)` unless a source contract requires a different convention, in which case document it.
- Fees: non-negative expense values in the ledger; subtract from gross PnL.
- Funding: signed cash flow; add the signed value to gross PnL after fees.
- Net PnL identity: `gross_pnl - fees + signed_funding = net_pnl`, within a declared decimal tolerance.
- PnL sign and quantity/multiplier semantics must be verified against the current execution engine. Do not copy a possibly incorrect long/short formula from an old report.
- Partial exits must be accounted for once, in the correct scope. Do not count both partial realized PnL and a final position total that already includes the same partial amount.
- Open-position mark-to-market must be separated from realized PnL.
- Undefined metrics must be `NULL` plus a reason/status, not zero.
- Missing slippage, price reference, fee, funding, or equity data must not be converted to zero.

## 6.3 Provenance status vocabulary

Use a controlled status field wherever data is displayed or exported:

- `VERIFIED_FROM_SOURCE`: directly present in source data and source semantics verified.
- `RECOMPUTED_FROM_SOURCE`: derived by deterministic code from identified source rows.
- `RECORDED_SIMULATOR`: recorded by the paper execution engine; not a real exchange fill observation.
- `ASSUMED`: explicit modelling assumption, including its owner/configuration and version.
- `HYPOTHETICAL`: counterfactual or rejected-signal replay outcome.
- `INSUFFICIENT_DATA`: required support is missing or too small.
- `NOT_VERIFIED`: not yet independently reproduced.
- `NOT_APPLICABLE`: metric does not apply to the selected scope.

Do not use `REAL_EXCHANGE_MEASURED` for paper-simulator slippage.

## 6.4 Immutable generation contract

Create or reuse a research-owned generation registry after P0 confirms there is no adequate existing one. Each generation must record:

- `generation_id` (UUID);
- `package_code`, metric-schema version, algorithm version and Git SHA;
- `idempotency_key` (unique) and `request_payload_hash` (SHA-256);
- source archive name and SHA-256;
- source extraction timestamp and `as_of`;
- `accounting_scope`, window boundaries, and UTC convention;
- configuration hash, random seed if any, statistical method versions and `max_carry_forward_age`;
- status: `QUEUED`, `RUNNING`, `SUCCEEDED`, `PARTIAL`, `FAILED`, `NOT_VERIFIED`;
- `heartbeat_at` and `stale_after_seconds` (default `600`);
- input/output row counts, coverage manifest and missing-data count;
- normalized output SHA-256;
- creation and completion timestamps;
- errors, warnings and machine-readable failure codes.

A rerun creates a new generation. Do not overwrite a previous generation's results. Do not use `position_id` or `risk_decision_id` alone as the primary key for data that can be recomputed. Prefer an independent row ID plus uniqueness such as `(generation_id, position_id)` or `(generation_id, execution_id)`.

Idempotency contract: a repeated request with the same `idempotency_key` and same `request_payload_hash` returns the canonical generation; the same key with a different payload returns HTTP `409 Conflict` with `IDEMPOTENCY_PAYLOAD_MISMATCH`. Validate payload identity after conflict handling; `ON CONFLICT DO NOTHING` without reading and validating the canonical row is prohibited.

Every displayed aggregate must be traceable to source file/table, source record IDs, and the calculation/query version.

---

# 7. P-1 — Slippage attribution

**Authorization:** mandatory.  
**Purpose:** quantify recorded paper-simulator execution drag by fill, position, strategy, instrument, and account without double-counting it in PnL.

## 7.1 P0 discovery prerequisites

Before implementing:

1. Trace the exact `virtual_executions.slippage`, fill-price, reference-price, quantity, fee, position, execution-side, and `price_source` semantics in code and database.
2. Identify whether the source stores a price delta, a bps value, or another representation. Do not infer the unit from the column name.
3. Verify how `PaperExecutionEngine` computes adverse slippage for BUY and SELL execution sides and for entry, partial exit, and final exit fills.
4. Verify how `gross_pnl` is calculated from fills and whether contract multipliers or linear-contract rules apply.
5. Verify the six `ambiguous_execution` cases and identify what is ambiguous: source candle ordering, entry/exit touch order, or another issue.
6. If the reference price cannot be reconstructed from source data and the existing engine deterministically, mark the affected attribution `NOT_VERIFIED`. Do not use a future candle or an arbitrary nearby price.

## 7.2 Sign convention and formula

Use the **execution side** (BUY/SELL), not merely the position's original side.

For a fill with a verified reference price and fill price:

- BUY execution: positive drag when `fill_price > reference_price`.
- SELL execution: positive drag when `fill_price < reference_price`.

The canonical signed adverse drag in USDT is:

`slippage_drag_usdt = side_signed(fill_price - reference_price) * quantity * contract_multiplier`

where `side_signed(x)` is `x` for BUY and `-x` for SELL. Validate the exact multiplier/quantity units against the existing execution engine. Positive means adverse; negative means price improvement. Do not clamp favorable fills to zero unless the source field is proven to record adverse magnitude only; if the stored field is magnitude-only, preserve that fact and do not pretend it is signed execution impact.

For a group of fills:

`slippage_bps = SUM(slippage_drag_usdt) / SUM(reference_price * quantity * contract_multiplier) * 10000`

The denominator is the total reference notional of the fills included in that group. Report entry, partial-exit, and final-exit components separately. If the denominator is zero or unavailable, return `NULL` with a reason.

## 7.3 Storage model

Use research-owned, generation-versioned tables. Exact migration names may follow repository conventions, but semantics are mandatory.

### `slippage_attribution_fills`

At minimum:

- `id` UUID primary key;
- `generation_id`;
- `execution_id`;
- `position_id`;
- `paper_account_id`;
- `strategy_id`;
- `symbol`;
- `execution_side`;
- `fill_role` (`ENTRY`, `PARTIAL_EXIT`, `FINAL_EXIT`, or the actual source-equivalent);
- `quantity`, `contract_multiplier` where applicable;
- `reference_price`, `fill_price`, `reference_notional`;
- `slippage_drag_usdt`;
- `slippage_bps`;
- `price_source`;
- `provenance_status`;
- `ambiguous_execution`;
- source timestamp(s) and `created_at`.

Required uniqueness: `(generation_id, execution_id)`. A source execution must appear no more than once in a generation.

### `slippage_attribution`

Position-level aggregate with:

- independent UUID primary key;
- `generation_id`, `position_id`, `paper_account_id`, `strategy_id`, `symbol`, `position_side`;
- entry, partial-exit, and final-exit slippage totals;
- total slippage drag USDT;
- total reference notional and weighted slippage bps;
- count of fills and ambiguous fills;
- `provenance_status`, algorithm version, and `created_at`.

Required uniqueness: `(generation_id, position_id)`. This permits reprocessing with a new generation without overwriting history.

Use foreign keys to source records only if P0 confirms they can be added safely without mutating or locking the trading tables in an unsafe way. Source tables remain read-only for this feature.

## 7.4 Required outputs

- Full-population slippage drag in USDT and weighted bps.
- Entry/exit/partial-exit decomposition.
- Per-strategy, per-symbol, per-account and portfolio-research-only aggregates.
- Fill count, missing reference count, ambiguous count, and source coverage.
- Actual ledger PnL and no-slippage counterfactual displayed separately.
- A trace from every position aggregate to its fill rows and source execution IDs.

## 7.5 P-1 acceptance criteria

1. Independent source recomputation either reproduces the reference total `35.1732 USDT` within a tolerance declared before running the test, or reports and explains a discrepancy. It must not hard-code the target.
2. The weighted bps figure is calculated using §7.2's denominator; compare with the audit's `2.00 bps` only after verifying that the audit used the same denominator.
3. `net_pnl = gross_pnl - fees + signed_funding` remains unchanged by the attribution job.
4. Slippage is not subtracted again from existing ledger PnL.
5. Missing or ambiguous references are flagged, never converted to zero.
6. Two runs with identical source hash, cutoff, configuration and code produce the same normalized output hash.
7. A negative control that double-subtracts slippage must fail a test; restored code must pass.
8. No writes occur to `paper_accounts`, `virtual_positions`, `virtual_executions`, `equity_snapshots`, strategy rules, or risk decisions.

---

# 8. P-2 — Risk-adjusted metrics and capital efficiency

**Authorization:** mandatory after P0.  
**Purpose:** compare strategies using consistent, risk-aware measures and show whether apparent returns depend on drawdown, a few outcomes, or high exposure.

## 8.1 Reuse existing accounting services

P-2 must consume the actual V5.3 accounting/reconciliation services. P0 must identify the exact classes, methods, and contracts available in the repository. Use an adapter if needed. Do not duplicate ledger reconciliation or point-in-time reconstruction.

If the required service or scope is not available in the current branch, stop P-2 and report the missing contract; do not implement a second, slightly different accounting engine.

## 8.2 Required metrics and exact definitions

All position metrics must declare their position population, accounting scope, cutoff, and treatment of partial exits/open positions.

### Position-level R and cost-to-risk ratio

For each position with positive, source-backed initial risk capital:

`net_r_i = net_pnl_i / initial_risk_capital_i`

`mean_net_r` is the arithmetic mean of eligible position-level `net_r_i`; `median_net_r` is the median. If initial risk capital is absent, zero, or cannot be allocated consistently, exclude that position from R metrics and report the excluded count and reason. Do not estimate risk capital from a later outcome.

The required diagnostic `cost_to_risk_r_i` is:

`cost_to_risk_r_i = (fees_i + slippage_drag_usdt_i - signed_funding_i) / initial_risk_capital_i`

This diagnostic uses the P-1 slippage attribution for the **same population and accounting scope** as the other inputs. Positive signed funding reduces the diagnostic drag; negative signed funding increases it. If any required component or the denominator is unavailable, return `NULL` with an explicit reason. Because slippage is already embedded in fill-price PnL, this ratio is a cost diagnostic only: it must never be subtracted from ledger PnL a second time. Do not label it as a guaranteed recoverable amount.

For partial exits, use the actual lifecycle total only in `FULL_LIFECYCLE`; in event/window scopes allocate each realized component to the event time and quantity actually closed. Never use a future final outcome in a prior cutoff.

### Profit factor

Let `epsilon = 1e-8` in the verified currency precision, or use the existing ledger precision if stricter:

`profit_factor = SUM(net_pnl_i where net_pnl_i > epsilon) / ABS(SUM(net_pnl_i where net_pnl_i < -epsilon))`

- The unit is net PnL under the selected scope.
- Outcomes with `abs(net_pnl_i) <= epsilon` are flat.
- If there are no losing positions or the denominator is zero, return `NULL` with `UNDEFINED_NO_LOSSES`; do not return zero or infinity.
- Report wins, losses, and flat outcomes separately.

### Win rate

`win_rate = n_wins / (n_wins + n_losses)`

A win has `net_pnl > 0`, a loss has `net_pnl < 0`, and a flat outcome is zero within the declared decimal precision/tolerance. Flat outcomes are excluded from the denominator but their count must be shown. If there are no wins or losses, return `NULL` with a reason.

### Payoff ratio

`payoff_ratio = mean(net_pnl of winning positions) / ABS(mean(net_pnl of losing positions))`

If either side is absent or the denominator is zero, return `NULL` with a reason.

### Maximum drawdown

Use the declared equity series for the selected scope, including unrealized mark-to-market only where the existing ledger/equity snapshots provide it consistently.

For each valid timestamp `t`:

`running_peak_t = MAX(equity_s) for s <= t`

`drawdown_t = (running_peak_t - equity_t) / running_peak_t`

`max_drawdown_pct = MAX(drawdown_t) * 100`

If the equity series or positive running peak is unavailable, return `NULL`. Do not derive max drawdown from closed trades alone when the account equity series is available.

### Time underwater

Calculate the duration-weighted fraction of the valid observation interval during which equity remains below its previous running high-water mark. Use actual timestamp intervals, not a simple count of irregularly spaced snapshots. Declare the treatment of equality with the high-water mark and report coverage gaps.

### Time-weighted average equity (TWAE)

Use a piecewise-constant step function over ordered equity snapshots within the selected window. For each valid snapshot at `t_k`, define the interval to the next snapshot as:

`delta_t_k = MIN(t_(k+1) - t_k, max_carry_forward_age)`

`TWAE = SUM(equity_tk * delta_tk) / SUM(delta_tk)`

Only valid intervals contribute to the denominator. `max_carry_forward_age` must be fixed in the generation configuration and included in `config_hash`. Any interval longer than this age is capped for the calculation and the uncovered remainder is recorded as `coverage_gap_seconds`; do not fill gaps with zero, linear interpolation, or an unlimited carry-forward. If there are no valid intervals, return `NULL`. The implementation must declare how window start/end clipping is applied and test irregularly spaced snapshots.

### Turnover

`turnover = SUM(ABS(fill_notional)) / TWAE`

Use all fills in the selected scope and do not annualize. Define notional using the verified contract multiplier and price convention. If equity is unavailable or non-positive, return `NULL`.

### Daily return and Sharpe-like diagnostic

`daily_return_t = (equity_t - equity_(t-1) - external_net_flows_t) / equity_(t-1)`

Use the existing equity snapshots and actual external-flow fields if any. If external flows cannot be identified, mark the metric `NOT_COMPARABLE` rather than silently treating them as zero.

`sharpe_like = MEAN(daily_return) / SAMPLE_STDEV(daily_return)`

- No annualization or annualized Sharpe claim.
- Require at least 10 complete daily returns for this descriptive diagnostic.
- If standard deviation is zero or data coverage is incomplete, return `NULL` with a reason.
- This is a descriptive metric, not a standalone promotion criterion.

### Daily realized net R

For each UTC day, attribute realized net PnL to the day on which the corresponding execution/accounting event was booked. For partial exits, allocate risk capital proportionally to the quantity actually closed using the source-backed original risk capital and original quantity. Then:

`daily_realized_net_r_t = daily_realized_net_pnl_t / daily_risk_capital_exited_t`

If the daily denominator is zero, the day's realized R is undefined for this metric; do not manufacture a zero ratio. If the project needs a dense daily series for dependence analysis, use a separate series with a clearly defined zero for a verified no-exit day and distinguish it from a missing-data day. Do not mix daily realized R with daily equity returns.

### Effective Sample Size (ESS)

Use the declared daily series and the Bartlett-style estimator:

`ESS = n / (1 + 2 * SUM(rho_k, k=1..K))`

where `rho_k` is the sample autocorrelation at lag `k` and `K = floor(n / 4)`. Record the exact estimator implementation and the series used.

- Missing data days are not zero-return days.
- If the required series is incomplete, too short, constant, or yields a non-finite/invalid denominator, ESS is unavailable and `sample_sufficiency=INSUFFICIENT_DATA`.
- Clamp the reported ESS to the interpretable range `[1, n]` and record when clamping occurs.
- `sample_sufficiency=SUFFICIENT` requires at least **20 valid, non-overlapping UTC days**, `ESS >= 20`, complete required coverage, and no unresolved accounting discrepancy.
- This threshold is an evidence-readiness guardrail, not proof of profitability. V5.2's statistical policy and any stricter existing Gate 6 conditions remain authoritative; this specification must not weaken them.

If the current V5.2 policy defines a stricter requirement, use the stricter requirement. If it conflicts with this contract, stop and document the conflict rather than silently choosing the weaker one.

## 8.3 Capital-efficiency metrics

Show raw metrics separately. Do not invent a composite “capital efficiency score”.

Required where source fields permit:

1. `net_return_on_equity`: net PnL divided by TWAE for the selected scope; not annualized.
2. `net_pnl_per_risk_capital`: net PnL divided by the sum of source-backed initial risk capital for the positions/components in the selected scope; disclose the denominator definition.
3. `average_gross_notional_exposure_pct`: `SUM(open_notional_t * valid_delta_t) / (TWAE * SUM(valid_delta_t)) * 100`, using absolute marked notionals and only valid observation intervals.
4. `average_open_risk_capital_pct`: time-weighted open risk capital divided by TWAE, only if open risk capital is available and meaningful.
5. `time_with_open_exposure_pct`: `SUM(valid_delta_t where open_positions_t > 0) / SUM(valid_delta_t) * 100`.
6. `notional_per_initial_risk_capital`: source-backed entry notional divided by source-backed initial risk capital per position, with population and units shown. This is not itself a leverage or margin metric.
7. `average_margin_used_pct_of_equity`: time-weighted actual recorded margin used divided by TWAE, only if the source records margin consistently. **Never infer margin from notional without source-backed leverage/collateral semantics.**
8. `turnover` as defined above.
9. `max_drawdown_pct` and `time_underwater_pct` alongside all utilization/efficiency metrics.

Do not call `time_with_open_exposure_pct` “capital utilization” without explaining its meaning. Do not infer that time with no position is waste: it may be an intentional risk decision. If free balance, margin, or available capital is not present in the existing ledger, do not invent an “idle capital” metric.

Keep the eight accounts independent. Any combined strategy view is labelled `RESEARCH_ONLY_COMBINED_VIEW`, must not sum or net live balances as if they were one account, and must not imply that opposing positions cancel commissions or actual account-level exposure.

## 8.4 Storage and acceptance

Use a generation-versioned `strategy_risk_metrics` table or existing research result store with at least:

- independent row ID;
- `generation_id`;
- `paper_account_id`, `strategy_id`, and `symbol` where applicable;
- `window_start`, `window_end`, `as_of`, `accounting_scope`;
- `metric_schema_version`;
- counts (`n_positions`, `n_wins`, `n_losses`, `n_flat`, `n_days`, exclusions);
- metric values;
- `ess`, `ess_method`, `sample_sufficiency`;
- `twa_equity`, `max_carry_forward_age`, `coverage_gap_seconds`;
- source coverage, status/reason, and creation timestamp.

Required uniqueness must include generation and all dimensions that distinguish a result. A position ID must not be the only primary key for a metric that can be recomputed.

Acceptance:

- All eight strategies are reported separately.
- Golden tests cover formulas, undefined cases, partial closes, open positions, cutoffs, missing days, zero denominators, TWAE with irregular timestamps, maximum carry-forward age and drawdown from equity snapshots.
- A synthetic autocorrelated series validates the ESS implementation against a separately calculated reference.
- `cost_to_risk_r_i` uses matched-scope P-1 attribution and cannot alter PnL.
- Notional, risk capital and actual margin are reported as separate concepts.
- No metric is labelled “profitable” merely because it is positive in a short sample.
- If the required history is too short, the page displays `INSUFFICIENT_DATA` and the missing coverage explicitly.

---

# 9. P-4 — Cost structure, MFE/MAE and potential recovery levers

**Authorization:** mandatory after P-1 acceptance.  
**Purpose:** describe where costs arise and test whether there is a measurable opportunity to improve net outcomes. This package measures and explains; it does not change filters or execution behaviour.

## 9.1 Cost attribution

Use P-1 slippage attribution and source-backed fees/funding to report, by strategy, symbol, account, fill role and selected scope:

- commissions and their share of gross PnL where the denominator is valid;
- recorded simulator slippage drag and weighted bps;
- signed funding;
- gross and net PnL;
- number and share of gross-positive/net-negative positions;
- ambiguous and missing cost records;
- cost contribution by entry, partial exit, and final exit where source data supports it.

Do not describe a cost as “recoverable” solely because it is large. A cost may be a necessary consequence of the strategy or a modelled assumption. The report may identify candidate levers; causal recovery requires a separately designed test.

## 9.2 MFE/MAE contracts

Use the following distinct concepts:

- **Observed-position MFE/MAE:** an ex-post diagnostic calculated from eligible closed candles over the actual observed holding interval, bounded by the selected `as_of` and position lifecycle.
- **Pre-entry expected MFE distribution:** a distribution estimated only from observations that were available and matured before the candidate entry time. It must not use the candidate trade's future path or later outcomes.

For a long position, favourable excursion is based on highs above the entry reference and adverse excursion on lows below it. For a short position, favourable excursion is based on lows below the entry reference and adverse excursion on highs above it. Convert to USDT/R only using verified quantity, contract multiplier, and source-backed initial risk capital.

OHLC-derived excursions are estimates, not tick-level path truth. If a candle could have touched both stop and target or the sequence cannot be known, flag ambiguity. Never use candles after the selected cutoff.

For the pre-entry distribution:

- require prior observations with outcomes matured before the candidate entry;
- group only by pre-registered, available dimensions (strategy, symbol, and any already-recorded regime/market-state label);
- do not create a new regime classifier in this package without a separate contract;
- record sample count, lookback, cutoff, feature availability, and excluded observations;
- if there is no valid prior population, return `INSUFFICIENT_DATA`.

## 9.3 Research questions and prohibited actions

The package may answer:

- Which cost component is largest in absolute USDT and relative to the correct denominator?
- Does recorded slippage vary by strategy, symbol, fill role, or volatility state?
- What does historical MFE/MAE say about exit outcomes, without hindsight leakage?
- Is the distribution of prior MFE plausibly larger than a declared round-trip cost scenario, and how uncertain is that estimate?

The package must not:

- add or tune a fee, slippage, volume, spread, or expected-MFE entry gate;
- change maker/taker routing or order simulation;
- use the historical `fee/risk > 0.18` split as a trading rule;
- claim that reducing a cost by an assumed amount will produce the same increase in future profit;
- promote a strategy based on this package alone.

## 9.4 Acceptance

- P-4 cannot run its final calculations before P-1 is accepted.
- Actual ledger accounting and any cost counterfactual are separate.
- MFE/MAE calculations include source candle IDs/timestamps and state their OHLC limitations.
- The pre-entry estimator proves that no observation later than the candidate entry was used.
- Every estimate includes sample size and provenance.
- No trading rules or strategy configuration are changed.

---

# 10. P-5 — Concentration, overlapping positions and dependence

**Authorization:** mandatory after P0.  
**Purpose:** reveal whether results depend on a small number of overlapping positions or common market episodes, and whether high capital usage coincides with concentration or drawdown.

## 10.1 Position interval contract

Use position intervals `[opened_at, closed_at)`. An open position ends at the generation's `as_of`. A partial close does not end the position if a non-zero quantity remains. The treatment of a zero remaining quantity must follow the existing ledger.

Build separate views for:

1. same-symbol, same-side overlapping positions;
2. same-symbol, opposite-side overlapping positions;
3. synchronized cross-symbol exposure (e.g. BTC and ETH) without netting;
4. simultaneous drawdown periods based on aligned equity observations;
5. concentration of realized net PnL by deterministic overlap cluster.

## 10.2 Deterministic cluster algorithm

For the primary overlap-cluster report:

- nodes are eligible position lifecycles, clipped to `as_of` when still open;
- all intervals are half-open `[start, end)`;
- `SAME_SIDE`: create edges only between overlapping positions of the same symbol and the same side (`BUY`–`BUY` or `SELL`–`SELL`). Connected components may be transitive within that side.
- `OPPOSITE_SIDE`: create a **bipartite overlap graph** per symbol. The two partitions are `BUY` and `SELL`; edges are allowed only between opposite-side positions whose intervals overlap. Never add a same-side edge through a shared opposite-side neighbour. If reporting connected components, explain that a component may contain multiple BUY and SELL nodes because the graph is bipartite; the edge relation itself remains opposite-side only.
- `CROSS_SYMBOL_EXPOSURE`: use synchronized snapshots to show BTCUSDT and ETHUSDT gross exposure separately and together without netting.
- store the exact member position IDs, interval bounds, edge list or enough information to reconstruct it, cluster-definition version, and generation ID;
- a cluster is a dependence group, not proof of a single causal market event and not a set of independent observations.

If a chain of overlaps creates a long transitive component, report its full duration and membership. Do not silently split it to produce a more attractive result.

For cross-symbol exposure, use synchronized timestamped snapshots and display the separate gross exposures of each symbol. Never net BTC and ETH notionals or opposing positions in the actual accounts.

## 10.3 Equity synchronization and missing data

P0 must inspect the actual equity-snapshot cadence. Use a common UTC time grid only if a deterministic, no-look-ahead alignment is possible. Any carry-forward rule must have a fixed maximum age and be recorded in the generation config. Do not interpolate unseen prices or fill missing snapshots with zero.

If reliable alignment is impossible, show cross-account simultaneous drawdown/correlation as `NOT_COMPARABLE` rather than fabricate synchronization.

Signal direction correlation is not return correlation. Compute dependence from timestamped returns or overlap data, not from BUY/SELL counts alone.

## 10.4 PnL concentration

Report:

- cluster count and member count;
- net PnL by cluster;
- share of total net PnL contributed by the largest clusters, only where total PnL makes the ratio interpretable;
- top `K` concentration for `K = 1, 3, 5`;
- separate results for account-level and research-only combined views;
- cluster-level and day-block sample counts.

If total net PnL is zero/negative, explain why a “share of positive total” ratio is not meaningful. Do not present cluster members as independent trades in statistical intervals.

## 10.5 Acceptance

- Interval boundaries, partial exits, and open-at-cutoff positions have tests.
- Re-running with the same generation inputs yields identical cluster IDs and membership.
- No cross-symbol or cross-account ledger netting occurs.
- Same-side/opposite-side clusters are distinct.
- Top-K values are traceable to source position IDs.
- Missing or misaligned equity snapshots are visible and do not become zero-return observations.

---

# 11. Product interface and API

## 11.1 Profitability Research page

Build into the existing Vue 3/Quasar UI; do not create a separate application or microservice.

### Summary table and view presets

One row per strategy and a separate `RESEARCH_ONLY_COMBINED_VIEW` row where valid. Avoid a single 18+ column view. Provide three switchable Quasar table presets:

1. **Economics and costs:** gross PnL, fees, signed funding, net PnL, recorded simulator slippage drag (attribution only), matched-scope zero-slippage counterfactual, `cost_to_risk_r`, and gross-positive/net-negative count.
2. **Risk and capital efficiency:** max drawdown, time underwater, profit factor, win rate with wins/losses/flat counts, mean/median net R, net return on equity, average gross exposure, average open risk capital, notional per initial risk capital, actual margin-used percentage where available, turnover and time with open exposure.
3. **Evidence quality and concentration:** position count, valid UTC days, ESS, sample sufficiency, same-side cluster concentration, reconciliation status, source coverage and ambiguity count.

The page's default generation is the most recent `SUCCEEDED` generation ordered by `completed_at DESC` (then `created_at DESC`, then `id ASC` as a deterministic tie-breaker). `PARTIAL`, `FAILED`, `RUNNING`, `QUEUED` and `NOT_VERIFIED` generations must never silently become the default. If no `SUCCEEDED` generation exists, show the empty state and offer the user an authorized generation request. An explicit `generation_id` always takes precedence and must display its actual status.

Do not rank strategies by raw PnL alone. If samples are insufficient or metrics are not comparable, show the status and suppress “best strategy” conclusions.

### Detail view

For a selected strategy/generation, show:

- actual ledger waterfall;
- separate slippage-only/reference-price counterfactual;
- equity and drawdown timeline;
- risk-adjusted metrics and formulas;
- cost components by fill role;
- MFE/MAE distributions with sample size;
- concentration clusters and member positions;
- excluded rows, missing-data reasons, and ambiguity counts;
- exact `generation_id`, `as_of`, scope, source archive SHA-256, algorithm version, and output hash.

### Provenance drill-down

Every metric must have a “How calculated / Source records” action that shows:

- formula and units;
- input table/file and source record IDs;
- SQL/query or calculation version;
- relevant timestamps and scope;
- provenance status;
- assumptions and exclusions;
- the raw evidence file or export row identifier.

### Filters

At minimum:

- strategy;
- account;
- symbol;
- start/end time;
- accounting scope;
- generation;
- provenance/sample status.

### UI states

Provide explicit states for:

- no generation exists;
- generation queued/running;
- successful generation;
- partial generation;
- failed generation;
- source hash mismatch;
- insufficient data;
- not comparable scopes;
- stale generation;
- accounting discrepancy.

Do not render missing metrics as zero. The UI must use Russian labels and concise explanations, while keeping stable machine-readable enum values in API/export payloads.

## 11.2 Exports

Provide versioned CSV and JSON exports. Every export must include:

- `schema_version`;
- `generation_id`;
- source archive SHA-256;
- code/algorithm version;
- `as_of`, window, and accounting scope;
- counts and coverage;
- formulas/metric version;
- provenance statuses;
- assumptions and warnings;
- references to source record IDs.

Exporting a summary must not omit the metadata needed to interpret it. Never include secrets, API keys, environment variables, or raw credentials.

## 11.3 API contract

P0 must align paths and naming with existing API conventions. The following are proposed semantic endpoints; adjust paths only to fit established project conventions and document the final routes:

- `GET /api/v1/research/profitability/summary`
- `GET /api/v1/research/profitability/strategies`
- `GET /api/v1/research/profitability/strategies/{strategyId}`
- `GET /api/v1/research/profitability/generations/{generationId}`
- `GET /api/v1/research/profitability/generations/{generationId}/export?format=json|csv`
- `POST /api/v1/research/profitability/generations` — authenticated, authorized, idempotent, queued operation only.

The POST operation must not execute heavy analysis in the HTTP request. It must create a generation/job using the existing queue architecture, enforce an idempotency key, and return a generation ID. A repeated request with the same key and same payload returns the canonical generation; a conflicting payload must return a clear conflict rather than silently reuse it.

No endpoint may mutate paper-trading state. All generation and export endpoints must enforce the existing authentication/authorization conventions.

---

# 12. Data model, idempotency and migrations

## 12.1 General rules

- Research tables are additive and owned by the research feature.
- Never add analytics columns to the trading ledger solely for convenience.
- Each result row that can be recomputed must include `generation_id`.
- Each generation must be immutable after completion. Corrections create a new generation.
- Use database constraints for logical uniqueness; application-level checks alone are insufficient under concurrency.
- Use transactions and conflict handling that retrieves and validates the canonical row. `ON CONFLICT DO NOTHING` without validating payload identity is not sufficient.
- Record payload/config hash conflicts; never silently overwrite an immutable historical result.
- PostgreSQL-specific uniqueness and concurrency semantics must be tested against PostgreSQL 17 or the repository's verified production-compatible PostgreSQL version, not SQLite alone.
- Migrations must be safe on existing populated tables. Do not delete source rows or change trading tables to make analytics easier.
- For large indexes, assess lock impact and use the repository's established safe migration approach. If `CREATE INDEX CONCURRENTLY` is used, account for its non-transactional behaviour in Laravel migrations.
- Rollback is not automatically safe after new research data is written. Prefer disabling the feature flag and a tested forward-fix over destructive reversal.

## 12.2 Suggested research-owned tables

P0 must verify whether equivalent tables already exist. Reuse them if their contracts satisfy this specification; do not create duplicates.

- `research_generations`
- `slippage_attribution_fills`
- `slippage_attribution`
- `strategy_risk_metrics`
- `position_excursion_metrics` or an equivalent versioned cost/excursion result store
- `strategy_cost_metrics`
- `research_position_clusters` and `research_position_cluster_members`, or equivalent normalized storage

Any schema may be adjusted after P0, but the immutable-generation, provenance, uniqueness, and no-write-to-trading-ledger rules are mandatory.

---

# 13. P-3 — Rejected-signal SHADOW replay (NOT AUTHORIZED)

This section is a future design contract only. **Do not implement P-3 now.** It requires explicit owner approval after P0/P-1/P-2/P-4/P-5 review.

## 13.1 Target population

The historical target query from the audit is expected to identify 49 unique directional risk-rejected rows. Re-run it against the verified archive; do not hard-code the count.

```sql
SELECT rd.*
FROM risk_decisions rd
JOIN signals s ON s.id = rd.signal_id
WHERE (rd.allowed IS NULL OR rd.allowed = '')
  AND s.side IN ('BUY', 'SELL')
  AND rd.reasons::jsonb ?| ARRAY[
    'position_already_open',
    'correlation_limit',
    'maximum_positions_reached',
    'position_conflict'
  ];
```

The exporter contract reportedly uses `'1'` for approved and an empty value for rejected; empty is not the same as a missing event. Verify this against the actual schema/exporter. Exclude `SKIP`-side rows and `late_signal_not_executable` from this target population. Reason incidences may exceed unique rejected rows because one decision can contain multiple reasons.

## 13.2 Replay rules if later authorized

- Every output is `HYPOTHETICAL`; it is not an actual missed trade or realized profit.
- Use the original signal's versioned entry/SL/TP and strategy-specific timeout/exit policy. Do not invent a generic timeout if the strategy's policy is recoverable.
- Entry must follow a pre-registered, deterministic rule using only candles closed after the decision time. If the next candle opens outside the entry zone, do not force a fill at that price. Record `ENTRY_ZONE_MISSED` or use a separately pre-registered zone-retest rule.
- Use half-open intervals and no future data.
- If the same OHLC candle can touch both SL and TP and order is unknown, mark the primary outcome `AMBIGUOUS`; a separately labelled conservative sensitivity run may assume SL first. Do not choose the favourable order.
- Use the source-backed fee schedule and funding data. Missing funding is not zero.
- Historical paper-simulator slippage must not be transferred to hypothetical fills as if it were observed for them. Any replay slippage is a declared `ASSUMED` scenario based on a frozen methodology.
- Separate unconstrained candidate replay from replay under a frozen portfolio policy. Do not infer actual capacity without a deterministic portfolio state.
- Deduplicate overlapping position/market clusters; 49 decisions are not necessarily 49 independent opportunities.
- Use block bootstrap with pre-registered block unit, repeat count, random seed, confidence interval method, and multiplicity correction.
- The SHADOW role, if later approved, must be technically unable to write to `paper_accounts`, `virtual_positions`, `virtual_executions`, or `equity_snapshots`. Test before/after table hashes and a real failed write attempt.

P-3 results alone must never promote a strategy or change Risk Gate.

---

# 14. P-6 — Research decision gate

**Purpose:** synthesize accepted measurement packages into an evidence-backed decision record. P-6 is not an automatic trading-rule changer.

## 14.1 Inputs

P-6 consumes only accepted generations from:

- P-1 slippage attribution;
- P-2 risk-adjusted and capital-efficiency metrics;
- P-4 cost structure/MFE/MAE;
- P-5 concentration/dependence analysis;
- P-3 only when the proposed conclusion concerns risk-rejected opportunities and P-3 has been separately authorized and accepted.

P-6 must reject stale, partial, scope-mixed, or unreconciled inputs for any conclusion that depends on them.

**Fail-closed sample gate:** P-6 cannot set `ELIGIBLE_FOR_OWNER_REVIEW` for a profitability or improvement claim unless P-2 reports `sample_sufficiency=SUFFICIENT` and every inferential input package used by that claim (P-4 and/or P-5) meets its own declared sample and coverage requirements. P-1 is an attribution/accounting package: it must pass source completeness and reconciliation, but it must not be assigned an ESS requirement merely to prove an arithmetic attribution. If a stricter blanket rule requires a `sample_sufficiency` field on P-1, P0 must define its semantics for a non-statistical attribution package before implementation. The 24-hour archive cannot support a profitability ranking.

## 14.2 Decision states

Use append-only state transitions in `research_decision_records`. Inserts are allowed; `UPDATE` and `DELETE` of prior transition records are prohibited. Each record includes actor/source, timestamp, reason, previous/new state, input generation IDs, preregistration hash and evidence-bundle hash.

- `PROPOSED`
- `COLLECT_MORE_DATA`
- `INSUFFICIENT_EVIDENCE`
- `EVIDENCE_NEGATIVE`
- `ELIGIBLE_FOR_OWNER_REVIEW`
- `OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT`
- `REJECTED`
- `ROLLED_BACK`

The state transition record must contain timestamp, actor/source, reason, input generation IDs, preregistration hash, evidence-bundle hash, and previous/new state. Never update the past transition in place.

Meanings:

- `COLLECT_MORE_DATA`: required coverage or sample has not accumulated.
- `INSUFFICIENT_EVIDENCE`: data or design is inadequate to support the claim.
- `EVIDENCE_NEGATIVE`: a pre-registered test provides evidence against the proposed improvement under the specified metric and scope.
- `ELIGIBLE_FOR_OWNER_REVIEW`: all required evidence gates passed; this is not authorization to change strategy behaviour.
- `OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT`: a human explicitly authorized a separate paper-only experiment. This project still does not implement the experiment or alter the active strategy path.
- `REJECTED`: the hypothesis was rejected under the registered decision rule, not merely because evidence was insufficient.

## 14.3 Statistical requirements

Before any inferential test:

1. Register the hypothesis, rationale, target population, primary metric, expected effect direction, control, OOS window, inclusion/exclusion rules, cost assumptions, sample sufficiency, bootstrap/block method, random seed, and multiple-testing family.
2. Freeze and hash the registration before viewing the OOS result.
3. Use chronological, disjoint OOS windows and prospective forward-only evidence when the claim concerns future performance.
4. Use day- or cluster-aware uncertainty intervals; do not treat overlapping positions as independent.
5. Apply the existing V5.2 statistical/promotion policy and Holm family-wise error control where applicable. Do not weaken any existing Gate 6 requirements.
6. Report the estimate, interval, sample size, ESS, sensitivity analysis, and all failed/insufficient checks.
7. If a valid test is inconclusive, retain `COLLECT_MORE_DATA` or `INSUFFICIENT_EVIDENCE`; do not relabel it `REJECTED` or claim superiority.
8. A favourable retrospective result alone cannot authorize a strategy change.

No strategy change, account merge, risk increase, or promotion is performed by P-6.

## 14.4 Capital-efficiency decision rule

A proposed change cannot be described as an improvement solely because it increases utilization, turnover, gross PnL, win rate, or one-day net PnL.

At minimum, the owner-facing review must consider:

- net PnL after source-backed costs;
- maximum drawdown and time underwater;
- net return per equity/risk capital under a declared denominator;
- utilization/exposure and concentration;
- ESS and OOS/forward coverage;
- robustness to reasonable, pre-registered cost assumptions;
- whether the result depends on one symbol, a few days, or a few overlap clusters.

If an applicable input is missing, say which conclusion cannot be made. Do not invent a composite score or silently change metric weights.

### Economic prioritization of registered hypotheses

P-6 must provide a hypothesis-priority table when there are accepted, pre-registered candidate hypotheses. It must not invent a recovery estimate for an untested idea.

For each eligible completed position lifecycle `i` in a matched baseline/counterfactual population:

`delta_net_pnl_i = counterfactual_net_pnl_i - baseline_net_pnl_i`

`historical_counterfactual_recovery_usdt_per_100_trades = 100 * MEAN(delta_net_pnl_i)`

The term “trade” here means one eligible position lifecycle, not one fill. Both populations must use the same scope and include the same costs except the explicitly changed, pre-registered assumption. Display sample size, source generation IDs, assumption/version, and status `HYPOTHETICAL`; this scaled historical estimate is not a promise of future recovery.

A forward/OOS `expected_capital_recovery_usdt_per_100_trades` may be reported only for a frozen hypothesis evaluated on an eligible OOS or prospective sample, with a day-/cluster-block uncertainty interval and the existing V5.2 statistical gates. If the sample is insufficient, display `NOT_ESTIMATED` or `INSUFFICIENT_DATA`. Do not rank untested ideas by a made-up point estimate.

When multiple eligible hypotheses exist, sort by the conservative lower confidence bound of recovery per 100 trades (descending), but display the point estimate, interval, drawdown impact, cost assumptions, ESS, sample sufficiency and concentration alongside it. A non-positive or unavailable lower bound cannot be described as expected positive recovery. Do not collapse these dimensions into an opaque composite score.

---

# 15. Testing and acceptance matrix

Every package must pass the applicable test categories below. Report actual test names/counts and raw outputs; this specification does not assert that any tests have already run.

## 15.1 Deterministic and property tests

Required cases include:

- `net_pnl = gross_pnl - fees + signed_funding`;
- positive and negative funding;
- BUY and SELL execution-side slippage sign;
- favourable fills, adverse fills, zero slippage, missing reference price;
- partial exits and multiple fills per position;
- ambiguous execution/candle flags;
- point-in-time cutoff with a position closed after the cutoff;
- event-time vs system-known-at cutoff;
- full lifecycle vs window flow scope;
- zero and missing denominators;
- undefined profit factor and payoff ratio;
- win/loss/flat classifications;
- drawdown from equity snapshots;
- missing daily snapshots versus genuine zero-return days;
- ESS for known synthetic autocorrelation patterns;
- deterministic cluster boundaries and transitive membership;
- generation-specific uniqueness and repeat-run idempotency;
- no cross-symbol netting;
- no writes to trading tables.

## 15.2 PostgreSQL integration tests

Run against an isolated PostgreSQL instance matching the project's supported version. Do not rely on SQLite for PostgreSQL-specific index, JSON, locking, or concurrency semantics.

Test:

- migrations on an existing populated database copy;
- uniqueness `(generation_id, source_entity_id)`;
- concurrent generation requests with identical payload;
- conflict when the same idempotency key has a different payload;
- rollback/forward-fix and feature-flag OFF behaviour;
- foreign-key/index behaviour if those are added;
- source tables unchanged by the research pipeline;
- read-only permissions for source data where feasible;
- retry/deadlock behaviour for the actual transaction design.

## 15.3 Negative/mutation controls

For each critical invariant, demonstrate:

`Baseline PASS → Mutated implementation FAILS → Restored implementation PASSES`

At minimum:

1. Mutate funding sign; reconciliation test must fail.
2. Double-subtract slippage; accounting/counterfactual test must fail.
3. Shift an event across an `as_of` boundary; the PIT result must change or reject the invalid future row.
4. Remove or bypass a generation uniqueness constraint; the concurrency test must detect duplicate rows.
5. Replace missing data with zero; a provenance/sample-sufficiency test must fail.
6. Change cluster interval boundary semantics; golden cluster membership must fail.
7. Attempt a trading-table write from research code; it must be denied or the isolation test must fail.
8. Let a P-6 output imply an approved strategy change; an authority test must fail.

Store the exact mutation diff, failed test output, exit code, and restored-file SHA-256. Do not use a generic repository diff as the mutation patch; each patch must contain only the intended mutation.

## 15.4 Full regression

P0 must inspect the actual repository scripts and `composer.json` before selecting commands. Run the real commands used by the project, likely including:

- `php artisan test` or `vendor/bin/phpunit`;
- `vendor/bin/pint --test`;
- the isolated PostgreSQL integration suite;
- `npm test --prefix frontend`;
- `npm run build --prefix frontend`;
- `cd research-go && go test ./...` if the changed code uses or affects the existing Go worker.

Capture command, working directory, environment/version summary, exit code, stdout and stderr. Report skipped tests and why. A green unit suite does not prove source values, end-to-end operation, concurrency, or evidence provenance.

---

# 16. Evidence bundle and claim-to-source traceability

## 16.1 Required output

Produce a new versioned `evidence.zip` for each accepted package. The bundle must be generated from actual outputs and contain, at minimum:

```text
evidence/
  README.md
  package_manifest.json
  source_manifest.json
  generation_manifest.json
  claims.jsonl
  commands.jsonl
  raw_logs/
    <command>_stdout.log
    <command>_stderr.log
  sql/
    <query_id>.sql
    <query_id>_output.json
    <query_id>_stdout.log
    <query_id>_stderr.log
  fixtures/
    generator source
    generated golden fixtures
    source archive hash
  tests/
    test_summary.json
    negative_controls.json
    mutation_patches/
    mutation_failure_traces/
  reproducibility/
    run_1_payload.json
    run_2_payload.json
    comparison.json
  code/
    git_sha.txt
    git_status.txt
    changed_files.json
    diff.patch
    checksums.json
  outputs/
    normalized metrics exports
    coverage manifest
    source-record lineage
```

Do not include credentials, `.env` values, API keys, passwords, or sensitive production data. Redact secret values without suppressing the fact that a command failed.

## 16.2 `claims.jsonl` contract

Every material claim in the report must have a machine-readable record containing:

- `claim_id`;
- exact claim text;
- value/unit where applicable;
- status from §6.3;
- source archive/file SHA-256;
- source record IDs or SQL query ID;
- formula/calculation version;
- scope and cutoff;
- output artifact path/hash;
- test(s) supporting the calculation;
- limitations or exclusions.

A claim without reproducible source/query output must be `NOT_VERIFIED`.

## 16.3 Reproducibility proof

Run the same analytical command twice with identical:

- source archive hash;
- source snapshot/extraction timestamp;
- `as_of` and window;
- accounting scope;
- code SHA;
- config hash;
- random seed and statistical method version.

Compare normalized outputs. Canonicalize JSON with sorted object keys (`sort_keys=true`) and stable numeric serialization. SQL exports must use explicit deterministic ordering, at minimum `ORDER BY id ASC` where IDs define row order; otherwise document the stable composite ordering. Volatile metadata such as run timestamp may be excluded only by a documented normalization rule. Metric values, row identities, source coverage, and ordering must match. Save both payloads and the exact comparison output.

## 16.4 Evidence integrity

The build script must:

1. regenerate manifests from source artefacts rather than manually editing totals;
2. compute SHA-256 for the archive and each critical artefact;
3. package the actual raw logs, SQL outputs, patches and fixtures;
4. serialize JSON deterministically with sorted keys and stable decimal strings;
5. ensure SQL result exports have explicit stable ordering such as `ORDER BY id ASC`;
6. scan `app/` for hard-coded audit-only reference values (`142.5411`, `104.4998`, `96.7267`, `37.5057`, `35.1732`, `72.68`, `72.6789`, `7.7731`) and fail if they appear as business-logic constants;
7. run a changed-input fixture test proving that output metrics are recalculated dynamically rather than copied from the audit;
8. calculate and write `evidence.zip.sha256`;
9. run `sha256sum -c evidence.zip.sha256`;
10. run `unzip -t evidence.zip`;
11. inspect the archive listing and assert that all required paths are present;
12. fail closed if a required log, query output, test summary, or hash is missing.

The archive is not accepted merely because it opens or has a checksum. Its contents must prove the claims made in the report.

---

# 17. Operational, DevOps and release requirements

## 17.1 Execution model

- Use the existing Laravel queue/background processing for heavy analytics. The calculation implementation remains in one Laravel + PostgreSQL `NUMERIC` contour unless P0 proves that an identical, already-implemented calculation must be reused from Go.
- Do not execute full portfolio analytics inside a web request.
- Use a dedicated queue name only if the existing queue configuration supports it without affecting trading jobs.
- Do not introduce a new service or scheduler by default.
- All research jobs must be idempotent, bounded, observable, cancellable and unable to write to trading tables.
- Update `research_generations.heartbeat_at` at a documented cadence while a job is active. The default `stale_after_seconds` is `600`, configurable only through a versioned configuration included in `config_hash`.
- A `RUNNING` generation whose heartbeat is stale beyond the configured limit must be marked `FAILED` with machine code `WORKER_TIMEOUT`, completion timestamp and last known progress. It must not remain `RUNNING` forever or silently be returned as active.
- A repeat POST with the same idempotency key/payload returns the canonical generation and current status. It does not silently restart a failed job. A retry must use a documented, auditable retry operation or a new idempotency key and generation linked to the failed generation.
- Capture generation status, duration, heartbeat, input rows, output rows, missing-data count, failures and source hash.
- Do not log secrets or raw environment configuration.

## 17.2 Feature flags and permissions

- New analytics feature flag defaults to `OFF` until the package is accepted.
- Generation trigger and raw evidence views are restricted using existing application authorization conventions; raw source records should be admin/research-operator only.
- A failed or partial generation must not replace the last accepted generation as the default result.
- Disablement must stop new research jobs without touching trading jobs.
- There must be no route from this feature into order placement, strategy config writes, Risk Gate writes, or Gate 6 promotion.

## 17.3 Performance baseline

Do not invent a performance SLA. During P0:

1. measure the current baseline for relevant API latency, scheduler duration, queue delay, database load, and export time;
2. record environment, sample count, and measurement method;
3. identify any existing SLOs;
4. propose a numeric regression budget for owner sign-off before heavy analytics are enabled.

Until a numeric budget is agreed, report performance measurements but do not claim a formal performance pass/fail against an invented target.

## 17.4 Rollback and production safety

- No deployment or production migration is authorized by this document.
- Verify current migration state and compatibility with the currently deployed image in read-only mode where possible.
- Use additive, backward-compatible migrations.
- Test schema changes on an isolated database copy before any production approval.
- Feature-flag OFF is the first disablement path.
- Do not assume `down()` is safe after research rows have been written. Document whether each migration is reversible, requires a forward-fix, or requires a backup/restore.
- Never clean Docker resources globally. Never touch ECONOM resources.
- Do not push or deploy automatically.

---

# 18. Package-by-package acceptance gates

## P0 — Discovery and contract freeze

**Deliverables**

- `reports/profitability/P0_baseline.md`;
- source archive/hash verification;
- repository and schema inventory;
- actual V5.3 accounting service interfaces;
- current data semantics for slippage, fees, funding, positions and equity;
- source-to-metric lineage map;
- formula/units register;
- dependency and risk register;
- baseline performance measurements;
- actual command/test inventory;
- proposed numeric performance regression budget for owner sign-off.

**Pass only if**

- source archive identity is verified or missing-source status is explicit;
- all material schema/service uncertainties are recorded;
- no business logic was changed;
- package dependencies and formulas are frozen for P-1;
- no unsupported historical claim is marked verified.

## P-1 — Slippage attribution

**Deliverables:** migrations/tables or equivalent store, attribution service, CLI/job, tests, exports, evidence bundle.

**Pass only if:** §7 acceptance criteria pass and the actual ledger identity is unchanged.

## P-2 — Risk and capital-efficiency metrics

**Deliverables:** versioned metric store/service, formulas, API/export, tests, UI data contract, evidence bundle.

**Pass only if:** §8 formulas and undefined cases are covered, V5.3 services are reused, all eight accounts remain independent, and insufficient sample is handled fail-closed.

## P-4 — Cost structure and excursions

**Deliverables:** versioned cost/excursion analysis, provenance, API/export, tests, evidence bundle.

**Pass only if:** P-1 is accepted, no look-ahead is possible, and no trading filter/configuration changes occur.

## P-5 — Concentration

**Deliverables:** deterministic cluster analysis, exposure/concentration metrics, API/export, tests, evidence bundle.

**Pass only if:** cluster membership is reproducible, synchronized exposure is not fabricated, and no account netting occurs.

## P-6 — Decision/reporting layer

**Deliverables:** Profitability Research UI, decision-state display, source drill-down, exports, append-only decision history, acceptance evidence.

**Pass only if:** all applicable upstream packages are accepted; missing/insufficient inputs are visible; there is no automated strategy change or promotion path; all displayed conclusions link to evidence.

## P-3 — Rejected-signal SHADOW

**Status:** `NOT_AUTHORIZED / OUT_OF_SCOPE`.

No implementation work is permitted until explicit owner approval. If approved later, it receives a separate plan, schema review, isolation review, and evidence bundle.

---

# 19. Definition of Done — global checklist

A package is not “done” because the tests are green. It is done only when all applicable items are true:

- [ ] Formulas, units, scope, cutoff, and rounding/tolerance were fixed before implementation.
- [ ] Existing source services were reused; duplicate accounting logic was not introduced.
- [ ] The package works against the verified source schema and real archive fixture.
- [ ] Golden tests were generated from source data by a checked-in generator; they were not manually invented.
- [ ] Financial identities and source-row counts are independently recomputed.
- [ ] Negative/mutation controls demonstrate that critical tests fail on broken implementations.
- [ ] PostgreSQL integration and concurrency tests ran against PostgreSQL, not only SQLite.
- [ ] Same inputs/config/cutoff/seed reproduce the same normalized output hash.
- [ ] Every metric has source IDs, formula version, scope, generation ID and provenance status.
- [ ] Missing/ambiguous values are not converted to zero.
- [ ] Open positions and partial closes obey point-in-time and lifecycle semantics.
- [ ] Research writes cannot mutate paper-trading accounts, positions, executions, equity, strategies, or risk decisions.
- [ ] Eight accounts and their histories remain unchanged.
- [ ] UI/export distinguishes recorded, recomputed, assumed, hypothetical, insufficient, and unverified values.
- [ ] All raw logs, SQL, mutation patches, source hashes and manifests are present in the evidence bundle.
- [ ] `evidence.zip.sha256` verifies and `unzip -t` passes.
- [ ] Rollback/disablement and operational risks are documented.
- [ ] Skipped tests and unverified claims are listed explicitly.
- [ ] No production deployment, Git push, or strategy behaviour change occurred.

---

# 20. Final report format required from Codex

After each package, report in Russian using this structure:

1. **Verdict:** `PASS`, `PARTIAL`, `BLOCKED`, or `FAIL`.
2. **What changed:** exact files, migrations, endpoints, UI components.
3. **What was independently verified:** claim → source/query → output artifact.
4. **Tests:** actual command, exit code, test/assertion counts, skipped tests and reasons.
5. **Financial reconciliation:** exact scope, population, formulas, output and discrepancies.
6. **Reproducibility:** run hashes and comparison result.
7. **Safety:** proof that paper accounts/ledger and strategy behaviour were not changed.
8. **Known limitations:** `NOT_VERIFIED`, `INSUFFICIENT_DATA`, assumptions, unavailable sources.
9. **Evidence bundle:** exact filename, byte size, SHA-256, archive contents check.
10. **Next authorized package:** identify the next package but do not begin it in the same change set.

Do not write “all correct”, “fully verified”, or “profitable” without the evidence supporting that exact statement. A `PASS` for software correctness does not prove strategy profitability.

---

# 21. Execution order summary and immediate Codex prompt

1. **P0 only:** inspect the real repository, schema, archive identity, service interfaces and current state. Read-only only.
2. **P-1:** after P0 acceptance, implement and prove recorded simulator slippage attribution in its own change set.
3. **P-2 and P-5:** after P-1 acceptance, implement each in a separate change set.
4. **P-4:** after P-1 acceptance, implement cost/MFE/MAE analysis using only prior information for pre-entry estimates.
5. **P-6:** build the user-facing synthesis and evidence-backed decision gate after all applicable upstream packages pass.
6. **P-3:** do not implement without explicit owner approval.

The sequence is intentionally evidence-first. The project should discover whether capital is being used efficiently and where net results are lost before proposing any change to the active trading system. A future improvement may be accepted only after its effect is tested under the existing statistical policy and separately approved by the owner.

## 21.1 Ready-to-copy instruction for Codex

> Accept `VECTOR PROFIT-1 (Revision 2.1)` as the working contract. **Only P0 is authorized now.** Perform read-only discovery: record `git rev-parse HEAD` and `git status --short`; inspect existing diffs without overwriting them; verify the exact audit archive and SHA-256; inventory the real PostgreSQL schema and V5.3 accounting/reconciliation service interfaces; verify the semantics and units of `virtual_executions.slippage`, fill/reference prices, fees, funding, initial risk capital, equity snapshots and margin fields; reconcile lifecycle scope against 24-hour window-flow scope; measure baseline API/queue/database performance using existing project commands. Do not change application code, create migrations, add jobs/endpoints, change strategy behaviour, commit, push, trigger CI or deploy. Do not touch ECONOM/econom-group or shared resources. Produce `reports/profitability/P0_baseline.md` with source-to-metric lineage, exact formulas/units, archive/hash results, discrepancies, unknowns, performance baseline and recommended next steps. Mark unsupported claims `NOT_VERIFIED`. Stop and wait for owner review before P-1.


---

# Appendix A — Expert review informing Revision 2.1 (informative)

This appendix preserves the four-role critique supplied with the Revision 2.1 notes. Normative implementation requirements are defined by the specification sections above.

# Часть 1. Экспертный разбор текущего ТЗ (`VECTOR_PROFIT_1_Full_Implementation_Spec_v2.md`) с четырёх ролей

### 1. Взгляд Product Owner (PO) — *Фокус: ROI, рост капитализации и защита от скрытого слива*

* **Сильные стороны:**
* Правильно задана Полярная звезда (North-Star Objective): рост капитала не через слепое раздувание загрузки депозита, а через максимизацию риск-скорректированной чистой доходности и эффективности использования капитала при сохранении изоляции 8 бумажных счетов.


* Вскрыта главная точка утечки доходности: на 24-часовом срезе при валовом профите `+142.5411 USDT` комиссии забрали `104.4998 USDT`, а симулированное проскальзывание уже ухудшило цену исполнения ещё на `35.1732 USDT` (при этом без проскальзывания контрфактический чистый PnL составил бы `~+72.68 USDT` вместо `+37.5057 USDT`).


* Зафиксирован жесткий поэтапный гейтинг: запрет на монолитную разработку, старт строго с read-only разведки `P0`, затем изолированный `P-1` с приёмкой через `evidence.zip`.




* **Слабые стороны и бизнес-риски:**
* **Слепая зона по марже и плечу:** В метриках эффективности капитала (§8.3) есть `average_gross_notional_exposure_pct` и `average_open_risk_capital_pct`, но для капитализации на фьючерсах Bybit критично соотношение между **риск-капиталом ($1R$)** и **задействованной маржой (Margin / Notional)**. Когда стратегия ставит сверхкороткий стоп, система набирает огромный номинальный объём (`notional`), который генерирует гигантскую комиссию (5.5 bps с объёма) при том же $1R$. В ТЗ не хватает явной метрики **`Cost-to-Risk Ratio` ($\text{Total Drag} / 1R_{\text{initial}}$)** как ключевого индикатора неэффективного сжигания капитала.


* **Отсутствие экономической приоритизации после P-6:** В `P-6` описаны статусы переходов (`PROPOSED`, `COLLECT_MORE_DATA`, `ELIGIBLE_FOR_OWNER_REVIEW`), но не задана матрица ранжирования гипотез по потенциальному возврату капитала (Expected Capital Recovery in USDT per 100 trades).





### 2. Взгляд Product Manager (PM) — *Фокус: UX страницы Profitability Research и принятие решений*

* **Сильные стороны:**
* Страница **Profitability Research** спроектирована как полноценный аналитический продукт с русскоязычным интерфейсом, машинными enum-константами в API, фильтрами по скоупу (`PIT_EVENT_TIME`, `PIT_KNOWN_AT`, `WINDOW_FLOW`, `FULL_LIFECYCLE`) и панелью «Как рассчитано / Исходные записи» (Provenance drill-down) для каждой цифры.


* Грамотно разведены два экрана: реальный бухгалтерский водопад (`gross PnL → minus fees → plus signed funding → net PnL`) и отдельная контрфактическая панель без проскальзывания (`SLIPPAGE_ONLY_COUNTERFACTUAL_FEES_FUNDING_FIXED`).


* Запрещено отображать `NULL` и нехватку данных как `0` — введены явные UI-состояния для `INSUFFICIENT_DATA`, `NOT_COMPARABLE`, `PARTIAL` и `FAILED`.




* **Слабые стороны:**
* **Риск перегруза таблицы Summary (§11.1):** В сводной таблице заявлено более 18 колонок одновременно. На одном экране в Quasar `QTable` это превратится в нечитаемый горизонтальный скролл. Нужно разделить таблицу на **3 сменных режима отображения (View Presets)**: *«Финансовый результат и издержки»*, *«Риск и эффективность капитала»*, *«Качество выборки и концентрация»*.


* **Не определена UX-логика выбора «Текущего поколения» (`Default Generation`):** В §17.2 сказано, что упавшее поколение не должно заменять последнее принятое, но в §11.3 для `GET /api/v1/research/profitability/summary` не описано, как выбирается дефолтный `generation_id`, если пользователь открыл страницу впервые без параметров фильтра.





### 3. Взгляд Senior Fullstack Developer (Laravel / PostgreSQL 17 / Vue 3 / Go) — *Фокус: Архитектура и данные*

* **Сильные стороны:**
* Иммутабельная модель поколений (`generation_id` UUID) и композитные уникальные ключи `(generation_id, execution_id)` / `(generation_id, position_id)` полностью решают проблему перезаписи истории при смене формул.


* Исключен двойной вычет проскальзывания: чётко зафиксировано, что `fill_price` в `PaperExecutionEngine` уже содержит сдвиг от `reference_price`, поэтому `gross_pnl` уже включает slippage.


* Честно обработаны краевые математические случаи: `profit_factor = NULL` (`UNDEFINED_NO_LOSSES`) при отсутствии убыточных сделок, клампинг `ESS` в диапазон `[1, n]`, исключение нулевых («flat») сделок из знаменателя `win_rate` с обязательным выводом их счётчика `n_flat`.




* **Слабые стороны и архитектурные дыры:**
* **Потенциальный конфликт при графовой кластеризации в P-5 (§10.2):** В ТЗ сказано, что две позиции соединяются ребром в графе, если их интервалы `[opened_at, closed_at)` пересекаются, они принадлежат одному символу и одному семейству (`SAME_SIDE` или `OPPOSITE_SIDE`). Однако для `OPPOSITE_SIDE` отношение «противоположная сторона» **не является транзитивным**: если `Позиция A (BUY)` пересекается с `Позицией B (SELL)`, а `Позиция B (SELL)` пересекается с `Позицией C (BUY)`, то в связной компоненте окажутся `A` и `C` (обе `BUY`!). Нужно уточнить алгоритм: для `OPPOSITE_SIDE` это **двудольный граф (bipartite overlap graph)**, где ребро ставится только между пересекающимися во времени `BUY` и `SELL`.


* **Расчёт Time-Weighted Average Equity (TWAE) в §8.2/§8.3:** Формулы `turnover`, `net_return_on_equity` и `average_gross_notional_exposure_pct` делятся на `time_weighted_average_equity`, но сама формула кусочно-постоянного интегрирования (step-function integration: $\sum E_k \cdot \Delta t_k / \sum \Delta t_k$) по нерегулярным снимкам `equity_snapshots` с ограничением максимального возраста кэша (`max_carry_forward_age`) явно не выписана.


* **Интеграция с Go-воркером (§17.1):** Оставлена развилка «использовать Laravel queue или существующий Go worker, если P0 докажет необходимость». Для объёма в 29–1000 позиций расчёт на PHP/PostgreSQL занимает миллисекунды, а размазывание формул P-1/P-2 между PHP и Go создаст дублирование логики округления (`bcmath`/decimal vs `float64`). Нужно жестко закрепить: в рамках `PROFIT-1` все расчёты P-1, P-2, P-4, P-5 выполняются в едином контуре (Laravel + PostgreSQL `NUMERIC`), если P0 не обнаружит уже готовый расчёт тех же метрик в Go.





### 4. Взгляд DevOps / SecOps — *Фокус: Надёжность, изоляция, CI/CD и доказательная база*

* **Сильные стороны:**
* Железные инварианты безопасности: запрет на трогание `ECONOM` / `econom-group`, запрет на глобальный `docker system prune`, запрет на несанкционированный `git commit/push/deploy` и жесткая проверка `evidence.zip` через `sha256sum -c` и `unzip -t`.


* Обязательные мутационные контроли (§15.3) по схеме `Baseline PASS → Mutated implementation FAILS → Restored implementation PASSES` с сохранением изолированных патчей мутаций.




* **Слабые стороны:**
* **Защита от зависших поколений (`RUNNING` zombie states):** Если воркер очереди упадёт по OOM или таймауту во время расчёта `research_generations`, статус останется `RUNNING` навсегда, а повторный POST с тем же `idempotency_key` вернёт зависшую генерацию. Нужен механизм `heartbeat_at` / `stale_after_seconds` и перевод зависших задач в `FAILED` с кодом `WORKER_TIMEOUT`.


* **Контроль размера и сериализации `evidence.zip`:** При упаковке сырых `stdout/stderr` и JSON-выгрузок важно принудительно сортировать ключи JSON (`sort_keys=True`) и строки выборок по `ORDER BY id ASC`, иначе на разных ОС/локалях PostgreSQL выдаст разный порядок строк и хеш воспроизводимости в `reproducibility/comparison.json` не сойдётся.





---
