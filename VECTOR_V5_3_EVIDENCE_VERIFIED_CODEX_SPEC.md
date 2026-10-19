# VECTOR V5.3 — Evidence-Verified Research Integrity & Quantitative Audit Remediation

**Implementation specification for Codex (English). Evidence-first revision of V5.2.** Status: **development-ready for the mandatory core only (P0–P5 and P8)**, subject to P0 confirmation against the actual repository and PostgreSQL schema. This specification is not evidence of implementation completion or profitable trading.

**Scope decision (binding):** The user is the sole scope/release approver. **P6 and P7 are NOT AUTHORIZED and OUT OF SCOPE by default.** They are documented as future optional designs, not instructions to implement. After mandatory Gate B review, only a separate explicit user approval may authorize P6 and/or P7. Do not interpret general autonomy instructions, a green test suite, or this file as such approval. No changes to active strategy rules, risk limits, capital exposure, model authority policy, or deployment without their existing separate approval procedures.

## 0. Mission and boundaries

Act as senior Laravel/PostgreSQL/Go engineer and quantitative research validation engineer. Improve **the existing** VECTOR/Bybit research-and-forward-paper-trading system. Fix reproducible integrity defects and make point-in-time accounting and research auditable. **Do not implement a new SHADOW engine or trading experiment under the mandatory core scope.** Do not rebuild the system or implement hypothetical profit improvements as active paper rules.

**Hard invariants**

- **No real exchange orders** now or in the future; no API permission for trading/withdrawal; retain public/read-only market data policy.
- Preserve eight **independent** virtual strategy accounts, their equity, balances, fills, positions, versioned signals and histories. No account merging, live paper netting or unvalidated parameter change.
- Existing ML/advisory/promotion controls remain authoritative. Do not weaken Gate 6, risk caps, confidence bounds, walk-forward, OOS or prospective SHADOW requirements.
- SHADOW research must have no write authority over normal paper accounts, balances, positions, executions, active risk policy or live strategy configuration.
- Keep Laravel/PostgreSQL/Redis/Vue3/Quasar/Docker and existing Go service. **No k3s/Kubernetes**. Avoid a second ledger, execution engine, recommendation platform, or new microservice without proof of necessity.
- **NEVER modify/restart/prune/delete ECONOM/econom-group or shared/unknown Docker assets**. No global Docker cleanup.
- Do not commit, push, manually deploy, or run GitHub Actions without user authorization. User controls all production releases. Test in isolated environments. Production checks are read-only unless explicitly authorized.
- Never invent missing audit data, market prices, slippage, opportunity results, performance statistics or test runs. Preserve historical records; do not silently rewrite them.

**Explicit non-goals:** finding alpha now, optimizing existing strategy thresholds, loosening Risk Gate, extending autonomy, changing live capital exposure, adding real trading, authorizing production deployment or making a profitability claim. New shadow execution, model training, cost-aware entry rules, portfolio netting and new research strategies belong to the separately authorized optional scope. Gate C candidate research is experimental and off by default.

**Immutable safety baseline:** no real exchange execution, no impact on ECONOM/econom-group or potentially shared Docker resources, no changes to the eight independent paper account histories, and no hidden activation of strategy/model policy changes.

## 1. Evidence baseline and important caveats

These are **historical regression targets** for the 2026-10-18 14:45–2026-10-19 14:45 UTC audit. The archived source `vector-audit-1d-e534ac49-60de-5263-9685-4bea563094c2.zip` has SHA-256 `54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4`. Codex must independently reproduce each value from source CSVs before marking it VERIFIED, and must never hardcode these values in application logic.

**Evidence-specific parsing warning:** in this CSV export, rejected risk decisions have an empty `allowed` cell, while approved decisions have `1`. Normalize according to the actual exporter/DB contract; do not interpret an empty exported boolean as a missing risk event.

- Signals: 1,524 = 25 BUY + 60 SELL + 1,439 SKIP. Eight strategies, two symbols (BTCUSDT/ETHUSDT).
- Risk **decision rows**: 109 = 31 approved + 78 rejected. Of rejected: 54 associated with directional BUY/SELL; 24 associated with SKIP/late checks. Of the 54 directional rejections, 49 unique opportunities have exposure/position/correlation reasons, four losing-streak and one late (verify reason exclusivity from individual rows).
- Exposure-reason **incidences**: position_already_open=24, correlation_limit=23, maximum_positions_reached=3, position_conflict=2; 52 reason incidences across 49 distinct directional decision rows. In the verified source CSV exactly **three decision rows** have two exposure reasons each (none have three or more). **Do not confuse `risk_decisions.id` with `risk_decisions.signal_id`:**

  | `risk_decisions.signal_id` | `risk_decisions.id` (decision ID) | `reasons` |
  |---|---|---|
  | `aec279e4-e13e-5281-bdcd-e1d36b64bee3` | `78bb2282-4420-5e4a-8cdc-e47e645dcdc8` | `maximum_positions_reached`, `correlation_limit` |
  | `94ba88f1-e34b-56dc-9607-d328f4ad661c` | `ba8c9396-7532-55a0-9f73-587538c24d2d` | `maximum_positions_reached`, `position_already_open` |
  | `4d49920c-2ed6-543e-9f06-8dcaaf38448b` | `def1ee8d-68c1-54d0-9231-3092ca9e9545` | `maximum_positions_reached`, `correlation_limit` |

  Golden tests must join using the correct field, verify both UUIDs and the exact reason arrays, and fail on swapped identity columns.
- 1,203 counterfactual evaluation rows correspond to 1,199 unique associated signal IDs; four signal IDs have two evaluations each. The 40 vs 36 `five_minute_confirmation_conflicts` discrepancy is **associated with** four suspected duplicate baseline/ETHUSDT observation/evaluation slots at 2026-10-18 14:45, 15:00, 15:15 and 15:30 UTC. Inspect source IDs and exact lineage; the source of repeated writes has not been proven from the exported counts alone. The reported standardized mean R shifts from about +0.3253 to +0.3891 after logical dedup; duplicate records **decreased** this historical standardized mean, but neither figure is executable OOS return.
- Exported position-lifecycle data: 29 positions (28 fully closed, one partially closed); gross PnL ~+142.5411 USDT, position fees ~104.4998, signed funding ~−0.536, net PnL ~+37.5053. Execution-window fees ~96.7267; gap ~7.7731 attributed to boundary fills. Reproduce exact fill-ID reconciliation. Slippage **was not observed** in this export; any slippage value must be marked `ASSUMED`, never `MEASURED`.
- Sum of eight individual account equity changes within window ~+77.122 USDT; it is **not** one account's return and need not equal final lifecycle PnL for positions crossing the window.
- Historic naive cost-threshold test: fee/risk>0.18: 18 positions, net ~+44.86; <=0.18: 11 positions, net ~−7.36; corr(fee/risk, net R) ~+0.083. Do **not** add naive fee/risk entry gates.
- Same-side overlap clusters and correlated trades are not independent samples. Audit contains `equity_snapshots.csv` and `portfolio_correlation_snapshots.csv`; presence of these files does not by itself establish independently validated return correlations.
- Prior reports mention 632 ledger reconciliations PASS/zero discrepancies. Treat this as **REPORTED / NOT INDEPENDENTLY VERIFIED** until regenerated or traced to exact source rows. This count is not a release pass criterion until evidenced.
- Export was a bounded/non-repeatable read with mutable lifecycle records. Point-in-time state requires event/snapshot reconstruction, not final mutable position rows.
- 1R=1.5% and fixed TP/SL R values belong to specific **standardized counterfactual model settings**, not to all observed paper trades.

## 2. Delivery strategy, authorization, owners and dependency graph

**Authorization boundary:** Mandatory core = P0, P1, P2, P3, P4, P5, P8. **P6/P7 = DEFERRED, not implemented.** After the mandatory Gate B review, the user alone may explicitly approve a separate optional change set. No optional work can be inferred from code-agent autonomy.

Build in small reviewable increments, no unbounded megasprint. Suggested change sets; adapt file/module grouping to actual architecture. **Implementation owner** for each authorized package: Codex; **scope, release and acceptance owner**: user. During P0, identify actual repository module/code owners; do not invent team assignees. Package evidence must be independently reproducible, satisfy §15, and be submitted for user review. No unauthorized commits, pushes or deployments.

| Package | Dependencies | Deliverable | Relative effort |
|---|---|---|---|
| P0 — Discovery and forensic fixtures | none | Code/data map, read-only forensic scripts, golden fixture definitions, defect classification | M |
| P1 — Idempotency & slot integrity | P0 | Root cause fix only if reproducible, PostgreSQL constraints/upsert where appropriate, queue/slot regression | L |
| P2 — Risk funnel & time semantics | P0, ideally P1 | Stable opportunity/event identities, pre-execution rechecks, candle watermark and missing-state diagnostics | M–L |
| P3 — Accounting & point-in-time reconciliation | P0, P1, P2 identities | Immutable-event reconstruction, as-of/window reconciliation, boundary-fill matching | L |
| P4 — ML eligibility authority audit | P0 | ACTIVE vs advisory authority, baseline Brier comparison, Gate 6 integration tests | M |
| P5 — Quant/portfolio metrics & source-aware export | P1–P3 | Net R and cost metrics, overlap clusters, synchronized equity analysis, reproducible exports | L |
| P6 — OPTIONAL rejected BUY/SELL SHADOW | P2–P3, P5 contracts, separate user approval | **DEFERRED**; design only, no implementation in this scope | L |
| P7 — OPTIONAL 5m and expected-MFE research | P5–P6 or equivalent isolated infrastructure, separate user approval, stats preregistration | **DEFERRED**; design only, no experiments in this scope | M–L |
| P8 — Mandatory full project regression and release evidence | P0–P5 (not P6/P7) | PostgreSQL, PHP, Vue, Go, E2E/API, 3 passes, deployment readiness | M–L |

**Mandatory dependency graph:** `P0 → P1 and P2 → P3 → P5 → P8`; `P0 → P4 → P8` in parallel. Complete P1/P2 logical identities before finalizing P3 accounting. P5 metrics/export require P3 verified financial scopes; P8 tests the mandatory core only. **Optional future graph:** `Gate B review + explicit user approval → P6 → P7` (with additional existing eligibility requirements). Define export/provenance contracts at P0 and populate after reconciled metrics exist.

**Package-by-package Definition of Done / user-review evidence**:
- **P0:** verified source archive SHA, forensic CSV queries/results, actual code owners, repo commands, current-system feature inventory, measured performance baselines, independent fixture generator and §15 evidence plan; no production mutation.
- **P1:** dedup root-cause classification; PostgreSQL concurrent/retry regression; safe legacy-data plan and preservation proofs; no false merging of scenarios.
- **P2:** risk decision/event/opportunity funnel and missing-slot states; clock-skew/out-of-order tests; alerts verified with fixture data.
- **P3:** immutable/as-of accounting, exactly traced boundary fill IDs, property-based financial tests and account-by-account balance preservation.
- **P4:** traced ACTIVE versus advisory authority and training-only OOS baseline; bypass tests; existing Gate 6 unchanged unless confirmed unsafe path is repaired under current policy.
- **P5:** full-population cost and correlated-exposure reports; source-aware exports, synchronized equity caveats and deterministic golden comparisons.
- **P8:** three evidence-backed verification passes, negative/mutation controls, repeatability proof, complete applicable endpoint checks, performance gate, CI evidence artifacts, rollback plans and user-controlled release checklist.

Each package must include: change-set boundary, owner, explicit acceptance checks, touched files/migrations, tests with evidence, performance impact if relevant, safe enablement/disablement, and rollback/forward-fix plan. **One small user-reviewable change set per package or smaller**; no monolithic delivery. These are relative planning sizes only, not promised calendar deadlines. Estimate actual work from P0 discovery without committing anything unless authorized.

## 3. P0 — Investigation and verification-first policy

Document current service/classes/migrations/unique indexes for MarketData, candle closure, scheduler/queues, signals, observations, counterfactuals, risk checks and pre-execution revalidation, paper accounting, equity, model eligibility, research recommendations and audit exporter. Record ownership of existing implementations and do not duplicate them.

Capture reproducible pre-change performance baselines on identical fixtures and environment: API warm/cold latency p50/p95, scheduler evaluation completion time, queue delay p50/p95, worker throughput, DB query load, audit export wall time. Check existing documented SLOs and actual current 15-minute strategy cadence; the stricter of existing SLO and the relative regression budget in §12 applies. Document baseline sample count and variance.

For each alleged issue, classify **CONFIRMED_CURRENT_DEFECT / HISTORICAL_ONLY / MISSING_CAPABILITY / DATA_LIMITATION / NOT_REPRODUCED**. Reproduce with golden historical fixture where possible. Do not modify current production behavior for a historical-only anomaly unless a correctness guard or explicit missing capability is justified.

Verify the historical reference values directly from CSV using read-only scripts. Preserve a manifest of file names, SHA-256 digests, source `id`, closed-candle time, account/strategy, extraction metadata and fixture-generation code. Fixtures must contain no credentials. Keep user data private by default. **Golden reference fixtures must be generated from the authentic, hash-verified archive by a checked-in/reviewable independent extraction script**; also use synthetic/property fixtures for edge cases, clearly labeled synthetic. Never hand-author historical “golden CSV” rows or make the fixture generator call the same production analytics being validated. Capture source row counts and per-output hashes as specified in §15. During P0 inspect whether `evaluation_context` and `explicit_scenario_version` are independent dimensions or duplicate representations; record schema-backed evidence before choosing a logical unique key.

## 4. P1 — Idempotency and lineage

Trace **scheduler → strategy evaluation → observation → signal → counterfactual** with queue attempt/correlation IDs. Forensic starting evidence: baseline/ETHUSDT 2026-10-18 14:45, 15:00, 15:15, 15:30 UTC. For each of the four slots, build fixture rows containing actual `strategy_observations.id`, `signals.id`, `counterfactual_evaluations.id`, queue attempt and strategy version where available. Verify exact links and identify whether repeated observations caused evaluation duplication, or are merely correlated symptoms; do not assume a causal mechanism from counts alone.

Use a *candidate* logical identity `(account_id, strategy_id, strategy_version, symbol, timeframe, candle_close_time, evaluation_context, explicit_scenario_version)` subject to verification against actual schema; do not blindly impose it. Distinct accounts, variants, candidate parameter sets and research scenarios must remain distinct. PostgreSQL nullable fields in composite indexes require explicit normalized/non-null semantics where appropriate.

Ensure atomic writes with an appropriate unique constraint and conflict handling, e.g. `INSERT ... ON CONFLICT ...` where compatible. `DO NOTHING` alone is not sufficient if downstream needs the canonical row: retrieve it and validate immutable payload identity. Treat payload mismatch as a deterministic conflict, not silent success. Use transactions and bounded retry/backoff on recoverable deadlocks; use advisory locks only if database uniqueness and transactions cannot protect a demonstrated multi-row race. Document transaction isolation actually relied on.

Migration prerequisites: non-destructive discovery query for existing conflicts; deterministic canonical/superseded identity and explanation (`canonical_of`, `superseded_by` or compatible existing audit representation); dry run with seeded legacy duplicates; preserve every source row/foreign key; verify backfill, new index and rollback. Prefer forward-compatible corrective migrations where reversing a constraint would corrupt new writes. If using `CREATE INDEX CONCURRENTLY`, account for PostgreSQL's non-transactional migration requirements.

Tests: sequential rerun, simultaneous workers, successful-job retry, crash between write stages, deadlock/retry, alternative legitimate scenario/version, unique-index violation, historical golden replay. Assert one **logical result**, while preserving all audit attempts. Report 40 raw rows, 36 logical signals and the correct deduplicated aggregate; never call correlated signals independent. Capture a failing-before/fixed-after proof where the current defect can be reproduced, plus an isolated negative control that demonstrably fails on duplicate insertion or an intentionally absent uniqueness guard (see §15).

## 5. P2 — Closed candles, missing slots, full Risk Gate funnel

Use exchange-consistent closed-candle/event-time watermark plus configurable completion grace; normalize UTC. Handle clock skew, late/out-of-order data, delayed WebSocket events, REST backfill, duplicate candles and partially formed candles. Reprocess only according to existing versioned policies; never use unclosed/future candles. Missing slots must have traceable states: EVALUATED_DIRECTIONAL, EVALUATED_SKIP, PENDING, MARKET_DATA_UNAVAILABLE, FAILED, SUPPRESSED_BY_POLICY, MISSING_UNEXPLAINED. Do **not** invent SKIP for failed/missing evaluations. Retain current 8×2 cadence and discover any legacy regime-switching gaps. Add metrics/alerts: `missing_unexplained_slots_total`, `oldest_missing_slot_age_seconds`, `repeated_logical_evaluations_total`; flag a MISSING_UNEXPLAINED slot after two full expected evaluation intervals (default 30 min for the current 15-minute cadence), with explicit scheduled grace/data-unavailable suppression rules. Flag repeated logical evaluation immediately; avoid alert storms via deduplicated notification keys and rate limits. Test alert transitions without changing trading decisions.

Model **opportunity ID** separately from **risk decision event ID**. Record strategy signal creation, first risk evaluation, initial result, pre-execution revalidation, final eligibility, paper opening or final expiration/failure. Multiple checks of one signal are multiple **events**, not multiple missed trades. Include reason arrays and policy versions. Distinguish SKIP late diagnostics from the single directional late rejection. **Do not assume the previously claimed four post-initial-approval rejections are established**: the exported `risk_decisions.csv` has 109 distinct `signal_id` values for 109 rows and does not independently establish any repeated approval→rejection sequence. Investigate pre-execution logs, execution events and other tables using exact signal IDs; report post-approval rejections only with source events and counts, otherwise NOT_VERIFIED.

Golden regression: 109 decision rows, 78 rejection rows, 24 SKIP late, 54 directional rejected, 49 distinct exposure-limited directional rejection decisions; recalculate directly, including overlapping reason codes. Detect and explain discrepancies rather than forcing counters to match.

Tests use frozen/mock clock, clock skew, delayed candle, watermark boundary, late signal and replayed risk evaluation, with PostgreSQL concurrency tests.

## 6. P3 — Correct financial and time-window accounting

Keep **lifecycle accounting** separate from **window `[start,end)` accounting** and **point-in-time valuation `as_of`**. Prefer immutable execution/funding/valuation events, otherwise mark unable-to-reconstruct. Every report declares `generation_id`, `generated_at`, `as_of`, window boundaries, data extraction cutoffs and consistency class (repeatable snapshot vs bounded best-effort). Final mutable `virtual_positions` row is not historical truth for prior cutoffs.

Per position and per fill, where available: identifiers, account, strategy/strategy version, symbol, side, entry/exit time+price, quantity/notional, risk capital, realized gross/net, realized and unrealized valuations, entry/exit fees, signed funding, measured or assumed slippage (explicit status), MFE/MAE provenance, R, exit reason and partial-close lifecycle. Verify actual ledger sign conventions: under the reported convention `net = gross - fees + signed_funding`.

Reconcile position-fee total ~104.4998 and bounded execution total ~96.7267 to specific boundary execution IDs totaling ~7.7731. Do not alter source records to zero the gap. Reconcile the **sum of changes in eight account equities** (~+77.122) independently from closed-position net PnL (~+37.5053). Include carry-in/out open positions and partial closes; prevent look-ahead by cutoff valuation. Mark unresolved positions/missing snapshots transparently. Do not claim 632 prior reconciliations verified until source rows or an isolated equivalent are checked.

Tests: exact property-based/mutation-style accounting invariants over partial fills, long/short, funding signs, empty windows and crossing boundaries; PostgreSQL golden CSV test, as-of invariance when later fills are appended, no forced balancing entries.

## 7. P4 — ML authority and proper scoring

Audit all paths: ML model provision, artifact ACTIVE administrative state, effective eligibility, advisory selection, risk decision changes, SHADOW, promotion, rollback. Expose `advisory_authority` as **computed eligibility under the current policy/version**, not an editable ACTIVE alias. Preserve deterministic operation when ML is optional and fail-closed when the policy mandates eligible ML.

A validation Brier threshold of `<=0.28` is insufficient alone. For a fair constant-probability baseline, estimate class prevalence from **training-only** data (possibly smoothed as pre-registered), freeze that baseline forecast, and evaluate its observed Brier on identical chronological OOS labels. **Do not fit baseline probability on OOS labels**. Note `p_hat(1-p_hat)` describes in-sample/idealized climatology under compatible sampling, not necessarily realized OOS baseline loss when class balance shifts. Require candidate-vs-baseline paired OOS improvement and uncertainty in addition to the unchanged existing Gate 6 constraints. Report log-loss, reliability/calibration diagrams, ECE and sample support as diagnostics; guard against leakage and low sample classes.

Inspect the **actual configured** Gate 6 requirements (previously reported: training>=300, OOS>=100, SHADOW>=100, prospective>=14 days and 50 trades, LCB95>0, drawdown/calibration restrictions). Do not invent missing values, weaken them, or replace them with simpler Brier-only criteria. Test legacy ACTIVE/ineligible/missing models, policy changes and rollback. Verify exact model IDs that could influence decisions with a direct trace.

## 8. P5 — Trading economy, concentration, and exports

Economic research by account/symbol/version/exit/regime: gross and net PnL, fees, signed funding, **measured** slippage only if recorded, assumptions separately, gross/net R, median/mean R, win/loss, profit factor (defined/undefined), payoffs, cost/risk, turnover, time underwater, max DD, gross-positive/net-negative count, fee contributions by fill and MFE/MAE with reliable provenance. Require full position population, not only three illustrative rows. Respect lifecycle vs window scopes.

Treat historical `fee/risk>0.18` result as evidence **against the naive threshold**, not against researching cost-aware entries. Do not tune or enable any cost gate. Potential prospective research is expected *pre-entry* favorable excursion relative to fees/spread/funding/slippage under transparent assumptions.

Add shared-market-event analysis using actual position times and synchronized equity return sequences: overlapping same/opposite-side positions, transitive clusters, total cluster contribution, drawdown co-occurrence, concentration, risk by underlying symbol, independent account vs research-only combined summary. Correlation of signal directions is not return correlation. Handle partial positions and changes after audit cutoff. Never treat cluster members as independent experiments.

Export machine-readable versioned research data including `generation_id`, `as_of`, source file hashes, scope/cutoffs/consistency and all aggregates traceable to exact source CSV+record ID. Clearly distinguish `sample_records` from `complete_population`; do not imply that three representative positions are all positions. Never expose secrets. Cross-archive merges key on immutable event identity and time; overlapping 24-hour archives cannot be counted as independent days.

## 9. P6 — OPTIONAL future Risk rejection SHADOW (NOT AUTHORIZED)

**IMPLEMENTATION STATUS: DEFERRED.** This section is a design contract for a later separately authorized package. Under the current mandatory scope: do not add shadow tables, DB roles, workers, simulation engines, migrations, endpoints, schedules or jobs. Review its prerequisite data contract at Gate B, then ask for the user's explicit approval before any implementation.

Only directional candidates rejected at a specified Risk Gate stage may enter a dedicated shadow evaluation. Exclude SKIP and ineligible stale candidate plans from executable-opportunity statistics. Retain initial/final risk reasons and policy versions, exact intended direction, entry/SL/TP, candidate size/risk, decision timestamp and subsequent expiry/maturity. No invented missing plans. Keep pending/censored/non-replayable outcomes distinct. Use point-in-time historical features and feasible next-market fill/fees/funding assumptions; label all simulated fills **HYPOTHETICAL**. Report separate unconstrained signal outcome and portfolio-constrained hypothetical outcome, never mix them.

Isolation must be enforced, not merely described: dedicated shadow database schema/tables and where feasible DB connection role with **no INSERT/UPDATE/DELETE permission** on normal `paper_accounts`, `virtual_positions`, `virtual_executions`, `equity_snapshots` or strategy configuration. Strict namespace/connection guard in research worker. Experiments OFF by default; per-experiment enablement, bounded queue/rate budgets and a kill switch that stops all SHADOW workers without stopping normal paper execution. Compare hashes or stable full-row snapshots of normal paper financial tables **before/after concurrent SHADOW tests**; expect identical account/position/equity records. Avoid claiming byte-for-byte equality of unrelated timestamps/audit logs or DB page internals.

No promotion of a rejection simulation simply because net returns look favorable.

## 10. P7 — OPTIONAL future research and mandatory statistical policy specification

**IMPLEMENTATION STATUS: DEFERRED.** Keep the following statistical requirements as a documented policy and acceptance criteria for future authorized experiments; do not implement 5m experiments, change strategy rules, extend the promotion pipeline or run new SHADOW research in the current core scope.

**Mandatory prespecification** before reading prospective OOS results: hypothesis ID, owner, candidate version, intended direction, primary paired endpoint, control, entry/exit simulation semantics, event clustering scheme, lookback, parameter values, date split, sample thresholds, one-sided alternative, cost sensitivity, drawdown/noninferiority guard, multiplicity correction, **RNG seed(s)**, bootstrap resampling procedure/block-length choice, and stopping policy. Freeze a signed experiment configuration/hash and preserve prior rejected versions. Hash alone does not prove correctness; record the fully serialized frozen configuration and execution environment for reproduction.

**Default research-readiness gate, intentionally conservative and adjustable only by new preregistered version before OOS starts:** at least 30 distinct non-overlapping UTC observation days, at least 100 matured eligible candidate/control paired opportunities, and estimated ESS >=50; at least 20 independent calendar-day/market-event blocks for uncertainty estimation. These are engineering defaults for moving COLLECT_MORE_DATA → READY_FOR_TEST, **not statistical guarantees**, and stricter existing policy requirements take precedence. If candidate incidence is too low, remain COLLECT_MORE_DATA; do not lower thresholds after viewing results to pass. ESS must be reported with estimator, sensitivity and dependence assumptions, not as raw row count. Sensitivity should include event clustering and serial autocorrelation, and warn when ESS cannot be stably estimated.

For repeated/overlapping positions, use paired daily or market-event PnL/R differences, with moving/stationary block bootstrap or hierarchical day→event cluster bootstrap where justified. State block length and sensitivity chosen before OOS. Cluster-robust SE may be a cross-check, not a substitute with too few clusters. When independent block support is too small, provide no significance claim. Control the four initial hypothesis families with pre-registered Holm family-wise correction (or other documented policy, but not post-hoc switching). Across repeated looks, apply a preregistered sequential-testing/alpha-spending policy or prohibit peeking.

**Promotion is not granted solely by these statistics.** Candidate must pass unchanged configured Gate 6, separate chronological OOS and prospective SHADOW requirements, operational safety and risk tests. For a candidate proposing incremental net improvement, require multiplicity-adjusted one-sided lower confidence bound on **paired incremental net R > 0**, and credible positive absolute candidate net performance after realistic costs, with risk matched to control and pre-registered drawdown guard. Additionally require robustness by BTC/ETH, time blocks, volatility regimes and adverse cost assumptions; do not demand both symbols if the hypothesis was explicitly pre-registered for one symbol. If any required support cannot be estimated, report INSUFFICIENT_EVIDENCE, not automatic REJECTED. Reject confirmed negative effects and operationally unsafe candidates. An inconclusive but valid test remains COLLECT_MORE_DATA, not evidence of superiority.

**Future only after explicit approval:** initialize registry entries (using existing research recommendation/state machinery, no duplicate platform):
1. Cost versus **expected** MFE, explicitly rejecting a naive fee/risk entry threshold as supported policy.
2. Counterfactual efficacy of directional Risk Gate rejections.
3. `baseline_5m_soft_confirmation` (only where 5m is the sole veto and original ex-ante entry plan is recoverable); illustrative 0.5× shadow risk frozen before forward testing, not enabled in normal paper accounts.
4. Common-market-event exposure/combined research concentration (NOT live account merging or netting).

All four begin COLLECT_MORE_DATA/PROPOSED according to current evidence; NONE start promoted. Portfolio netting is research-only and optional; do not use it to cancel actual commissions that would still be incurred.

## 11. Reversibility, release controls and safe database changes

Every package includes a feature flag default OFF for new analytical/shadow behavior where applicable, compatibility strategy, isolation/monitoring, rollback plan and verified recovery path. A migration rollback is **not automatically safe** after new data is written; where destructive reversal would lose audit lineage, use backward-compatible schema and a tested forward-fix or read-path fallback instead. Report which reversals are safe and which require backup/restore or corrective migration.

Test legacy backfill on a copy of data with conflicts, under PostgreSQL, before rollout. Preserve audit/event IDs and all financial histories. Keep production rollout user-authorized via GitHub Actions only. No hidden auto-deploy, no changes to unrelated projects.

## 12. Verification matrix (three passes)

**Pass 1 — deterministic and property tests:** candle closure/clock skew, logical uniqueness, duplicate fixture 40→36, risk reason multiset vs unique decisions, R and fees/funding identities, boundary fills, partial closes, as-of reconstruction, overlap clusters and ML scoring baselines. Golden-file CSV regression; report actual calculations vs reference and tolerances.

**Pass 2 — isolated PostgreSQL integration:** real unique constraint and concurrent workers, duplicate legacy migrations, rollback/forward-fix, retries/deadlocks, immutable ledger reconstruction, unchanged active paper account isolation in the core workload, gate authority bypass attempts. **Do not start or build a new SHADOW worker under the mandatory scope.** If existing SHADOW code is touched, verify its pre-existing isolation; the P6-specific after-race financial hash test is required when and only when P6 is separately approved. Verify ledger reconciliation count/result from fixtures or live read-only source if available; otherwise `NOT VERIFIED` (632 is never assumed). Every material accounting total must have an independent calculation with raw output and original source-event IDs. Do not use SQLite to validate PostgreSQL-specific semantics.

**Pass 3 — full regression / E2E:** inspect actual repo scripts first, then run applicable `php artisan test --no-ansi` or composer test script, `vendor/bin/pint --test`, configured frontend unit test (`npm run test:unit` only if it exists), Vue typecheck and Vite build, `go test ./...` for affected modules, Docker Compose isolated test configuration, and registered endpoint inventory/API schema smoke tests. Do not blindly execute commands that repository does not support. Read-only production smoke tests only if authorized; mutation tests use isolated fixtures. Test all 8 strategy account isolation and PAPER/RESEARCH UI safety labels. Profile queue latency, scheduler, DB queries and export performance before/after without made-up SLA values.

**Measured performance acceptance (P0 baselines required first):** use matched hardware, realistic fixed input fixtures, warmed services where appropriate, at least three runs with identical measurement windows. Flag as **FAIL / requires explicit user performance waiver** when (a) an existing documented SLO is breached, (b) API p95 regresses more than **20% AND 50 ms absolute**, (c) scheduler/queue p95 regresses more than **20%** or fails to complete a full currently scheduled evaluation cycle before its next 15-minute interval, (d) throughput drops more than **10%** on the same workload, or (e) the new analytics produces unbounded queues/database growth. The numeric relative budgets are project **proposed regression acceptance defaults**, not historical performance claims. **Before using them as contractual PASS/FAIL thresholds, request explicit user sign-off of the measured baseline, workload and numeric budget.** P0 discovery and independent correctness fixes may proceed without that sign-off; do not silently treat provisional numbers as user-approved. Existing authoritative SLOs still apply. If sign-off is unavailable at final handoff, mark the proposed performance-budget verdict NOT_VERIFIED / AWAITING_USER_SIGN_OFF, provide raw measurements and do not assert performance acceptance. If a metric is too noisy or a baseline is unavailable, mark NOT_VERIFIED and request user sign-off rather than inventing a passing measurement.

For each pass provide exact commands, environment, pass/fail/skip/assertions, sample timestamps, hashes or evidence IDs, and unresolved limitations. Re-run relevant suites after fixes. **Every authorized package must also pass the non-test evidence gates in §15.** Green test output alone never establishes statistical advantage.

## 13. Final acceptance: gate outcomes

**Gate A / mandatory correctness:** current duplicate defect fixed or proven historical-only; no new logical duplicates in retry/concurrent PostgreSQL tests; missing slots traceable without invented SKIP; risk decision events properly distinguished from opportunities; historical finance lifecycle/window/generation-time accounting reconciles with source fills; migration data preserved; every disputed fact has traceable source IDs.

**Gate B / mandatory research observability:** complete-population economics; synchronized exposure/correlation research; source-aware export and source record IDs; ML advisory authority derived and unable to bypass existing Gate 6; no unsafe current-account writes. **No new SHADOW engine is part of mandatory Gate B.** Gate B review records what additional evidence would be required to justify optional P6/P7, but it does not authorize them.

**Gate B-optional / NOT AUTHORIZED:** the P6 directional Risk Gate shadow engine, its isolated database role, kill switch and concurrent read/write guard tests. Must be separately approved by the user after mandatory Gate B review; cannot block mandatory Gate B acceptance.

**Gate C / OPTIONAL / NOT AUTHORIZED:** P7 preregistered 5m/cost/portfolio research experiments with separate explicit user approval. If ever authorized, configure them OFF by default, do not change normal paper trading parameters and do not claim proven improvement without prospective evidence.

**Rollout acceptance:** every authorized package has explicit feature flags where needed, migration/backward-compatibility plan, audited rollback or corrective-forward path; PHP/PostgreSQL/Vue/Go suites pass for touched modules; all relevant endpoints pass schema/authorization expectations; measured performance budgets pass or user signs an explicit waiver; ECONOM untouched; no real exchange execution paths; no user-unauthorized commit/push/deploy. **P6/P7 must remain unimplemented/disabled, not marked incomplete defects of the mandatory core.**

**Final report in Russian**, with sections: root causes, defect classifications, exact changed files/migrations, evidence provenance, accounting identities and boundary fills, signal/risk funnels, statistical policy & hypothesis statuses, all test commands/results, performance comparison, unresolved issues and rollout/rollback checklist. Each major item tagged VERIFIED, FIXED, TESTED, HISTORICAL_ONLY, NOT_VERIFIED, BLOCKED or REQUIRES_MORE_DATA. Never call the project “profitable” based on these remediation steps.


## 14. Required final handoff: review checkpoints and no-guess rules

For each authorized package deliver a compact acceptance manifest plus the independently inspectable §15 evidence bundle with: package ID, accountable implementer (Codex), user reviewer/approver, exact current defect/capability evidence, repository paths, migrations and compatibility, fixture provenance, pass/fail criteria, test commands and results, before/after timing, rollback or safe forward-fix, and unresolved assumptions. Do not declare a package done if an essential proof is missing. `NOT_VERIFIED` is not interchangeable with `PASS`.

**Mandatory core final result:** P0–P5 plus P8 may be delivered as done after all their own acceptance checks pass. P6 and P7 are documented optional backlog items and **must not be executed** or block that result. A source-backed exception (e.g., a current ML authority bypass) may be fixed safely within P4; any new ML trading-policy threshold requires separate approval even if the new threshold is stricter.

**Known analytical traps:** 52 exposure-reason incidences are not 52 rejected trades; the 49 unique directional rejections are not 49 profitable missed trades; 24 SKIP-late events are not actionable entries; 40 counterfactual rows are not 40 independent baseline opportunities; source row duplication and causal root cause are different questions; standardized 1R and hypothetical outcomes are not comparable to actual trade R without compatible models; 29 position lifecycle records and in-window fills have different scopes; +77.122 USDT aggregates eight equity changes, not a single account or the sum of lifetime closed trade PnL; overlapping directional clusters are not independent statistical evidence; missing slippage is never measured slippage; historical ACTIVE does not imply current advisory authority.

Do not add a new trading feature to "fix" incomplete evidence. The authorized outcome is a correct, traceable and tested research/accounting system, not a claim of profitability.

## 15. Mandatory Evidence of Development — provenance, negative controls and reproducibility

This section applies **only to the authorized mandatory core P0–P5 and P8**. It is an acceptance requirement, not permission to implement P6/P7. **Tests passing is necessary but not sufficient.** Claim the narrow result actually demonstrated: correct code mechanics do not prove financial edge, and retrospective replays do not prove forward profitability.

### 15.1 Per-package machine-checkable evidence bundle

Produce one independently inspectable evidence bundle for **each** authorized package, plus one final aggregate bundle. Where an existing GitHub Actions workflow supports it, publish a **non-deployment CI artifact**; otherwise provide an equivalent local evidence directory and the commands necessary to reproduce it. Artifacts must be available to the user and **must not contain secrets, credentials, personal information or sensitive raw database extracts**. Do not publish unredacted trade logs or protected application configuration to public CI artifact storage.

Include at minimum:

- `manifest.json`: package ID, scope, UTC capture time, authenticated actor/test identity where relevant, repository revision (`git rev-parse HEAD` if available), **dirty working-tree state and diff/patch SHA-256** if uncommitted, source archive SHA-256, fixture-script hash, fixture/output hashes, test environment versions, PostgreSQL version, schema/migration fingerprint, feature flags, experiment seed and as-of/window parameters, and CI run ID when applicable. **Do not commit merely to obtain a commit SHA.** A prior `HEAD` is not proof that uncommitted code was tested; bind the evidence to the patch digest too.
- `commands.txt` and `runs/`: exact shell commands, flags and parameters, start/finish timestamps, exit codes, **raw sanitized stdout/stderr** from successful and failed runs, executable/runner version and reproducible invocation instructions.
- `source_manifest.json`: every relevant input basename, SHA-256, row count, UTC extraction/as-of semantics, known exclusions, primary keys and representative record IDs; identify whether the source was an archived file or a snapshot-read from PostgreSQL.
- `claims.jsonl`: one record per material claim with `claim_id`, statement, status, source IDs, SQL or independent calculation script, parameters, raw output path, expected-versus-actual formula, tolerance, method, revision/fixture hash, and reviewer decision. For claims made from CSV data, an independently executable CSV analysis script plus raw output is appropriate; do **not** invent SQL merely to create a citation. Database-derived claims must include the actual parameterized/read-only SQL and result.
- `fixtures/` provenance manifest, regeneration script, checksum of generated golden data and explicit separation of golden-derived versus synthetic/property-based edge-case fixtures. The original source archive may be referenced by digest rather than redistributed if it is confidential.
- `negative_controls/`: mutation/fault attempted, isolated environment, expected failure, raw failed assertion/denial, clean baseline result, restoration proof and any limitations.
- `reproducibility/`: two independent runs and normalized-output SHA-256 values (see §15.4).
- `unverified_paths.md`: important branches/behaviors not exercised, why, risk impact and exact follow-up prerequisite. A missing proof requires `NOT_VERIFIED` or `BLOCKED`, not success.

CI uploads must occur on failure as well as success where the CI system permits; redaction and artifact access/retention are part of the manifest. Evidence is **an artifact, not another application service or database**. Avoid embedding mutable UI screenshots as the sole source of proof.

### 15.2 Independent golden fixture generation and claim-to-source proof

- Golden fixtures must be produced from real, SHA-256-verified historical archive data via a deterministic, reviewable extractor independent of the production code under test. Capture exact source row IDs, SQL/CSV filter, time window and the extracted row count. **Do not hand-write historical fixtures to match expected totals.** Synthetic test records remain allowed for property-based or adversarial scenarios, explicitly marked `SYNTHETIC` and never cited as historical evidence.
- At least one independent computation path (e.g. standalone CSV Python reference and database SQL using loaded source rows) must check critical financial and funnel aggregates against application output. Do not copy the application's aggregator into its supposed independent oracle.
- Numerical claims about commission differences, risk-decision funnels, deduplicated research or equity reconciliation need **source evidence and raw command/query output**, not solely an application assertion. Where DB records exist, provide actual SQL with bind parameters and raw result. Include the original CSV record IDs and the aggregation rule (scope, signed funding convention, time predicate, status filtering and currency units). Source artifacts may need restricted access, but the manifest must stay inspectable.
- Evidence must distinguish `risk_decisions.id` from `risk_decisions.signal_id`, event counts from distinct opportunities, `NULL`/empty exported risk booleans, position-lifecycle totals from time-window flows, and standardized counterfactual R from realized paper R.
- Golden expected values/UUIDs may occur in test fixtures and independent reference scripts, but **not as hidden constants, fallback returns, query predicates or bypass branches in production analytics**. Run a reproducible static grep/AST rule against a scoped list of known historical values/IDs, classify matches by code location, and save raw findings. An empty grep alone does not prove absence of hardcoding: pair the check with an independent changed-input test that changes source data and demands a corresponding change in computed outputs.

### 15.3 Negative and mutation controls — tests must detect incorrect behavior

For each critical invariant, in an **isolated disposable test database or test build only**, intentionally introduce a bounded erroneous condition and prove the relevant assertion or authority guard rejects it. Record both clean PASS and mutated FAIL. Restore the pristine baseline before continuing. **Never sabotage production data, schema, deployment or ECONOM/other Docker resources.** Accept an equivalent deterministic fault-injection harness when directly dropping an index would be unsafe or misleading.

Minimum negative controls where the related module is implemented/touched:

1. **Financial signs:** invert or omit signed funding/fees in a disposable reference computation; reconciliation must fail with an explainable delta.
2. **Time boundaries:** shift `[start,end)` by one second or insert a boundary fill; strict window reconciliation/golden test must detect changed membership; it must not silently rebalance totals.
3. **Idempotency:** remove/disable the *test-only* uniqueness guard, inject conflicting same-slot writes, or race real PostgreSQL transactions in a disposable clone; concurrent duplicate attempt must produce exactly one logical canonical result under the correct implementation, while an intentionally broken implementation is caught. Use actual overlapping connections/processes, barriers and repeated randomized interleavings; sequential function calls alone do not prove concurrency safety.
4. **ML authority:** try a legacy `ACTIVE` but Gate-6-ineligible model and an unauthorized promotion request; the system must refuse influence/promotion while preserving legitimate deterministic-only strategy behavior. Capture denial and unchanged paper-account state.
5. **SHADOW isolation, scope-sensitive:** for **existing SHADOW code affected by the mandatory changes**, attempt forbidden INSERT/UPDATE/DELETE with its **actual configured restricted PostgreSQL role** in a disposable DB; capture PostgreSQL permission-denied output and unchanged financial table hashes/rows. **Do not build a new SHADOW role, schema or P6 worker as part of the core solely to pass this control.** When P6 is later explicitly authorized, this exact fail-closed role test, kill-switch test and after-concurrent-run paper financial-state comparison become mandatory before release.
6. **Source variation:** change or remove a legitimate *test fixture* position/execution/reason and prove outputs react according to the documented formula, preventing hidden historical constant substitution.

If an invariant cannot be fault-injected safely, provide an equivalent independently exercised rejection path and classify the missing mutation proof explicitly. **Do not claim a negative control was run simply because there is a test with “fail_closed” in its name.**

### 15.4 Deterministic reproducibility proof

Repeat the same independent import/analytic/export command **twice** under the same frozen source archive SHA, time cutoff, strategy/model versions, PostgreSQL snapshot fixture, timezone, RNG seed and ordered query semantics. Normalize explicitly declared volatile fields (e.g. fresh audit `generation_id`, runtime timestamp, temporary directory, host/CI run ID) **without normalizing away actual financial values, identifiers, row order where canonical, or outcomes**. Hash the canonical analytic payload and show equality in `reproducibility/`. Keep complete original payloads too and document every normalized field. Unexplained mismatches are `FAIL` or `NOT_VERIFIED` rather than being discarded as noise.

Replaying overlapping historical audit archives must deduplicate **source record identity**, avoid counting calendar days more than once and distinguish later mutable snapshot revisions. A reproduced historical average is not evidence that it was prospective, independent or statistically significant.

### 15.5 Append-only evidence of research state transitions

At P0, inspect the **existing** research recommendation, hypothesis state and autonomy-approval journal. For state changes performed by the mandatory core, preserve or minimally extend its existing event mechanism so every transition is **append-only and traceable**, with event ID, hypothesis/recommendation ID, prior/new status, actor/service identity, UTC timestamp, candidate/config version, source evidence references and SHA-256 of the supporting frozen evidence or report. For an actual promotion/rollback event, also link the existing Gate-6 authorization result. Make event creation idempotent by event identity, and deny unauthorized status changes. Do not silently update away prior decisions; avoid creating a separate recommendation service or an unrelated new hypothesis platform.

Where the existing journal already provides equivalent provenance, reuse it and prove it with source-backed tests. If a new append-only constraint or event table is truly necessary for **existing authorized status transitions**, include it in P4/P5 as a small independent reviewable change-set; **do not register/promote new P6/P7 hypotheses**. A hash provides tamper-evidence only to the extent the storage and access controls are trustworthy; do not claim cryptographic immutability against database administrators without stronger evidence.

### 15.6 Prospective superiority is a different acceptance claim

The mandatory core can establish code correctness, accounting consistency, permission boundaries and research reproducibility. It **cannot** establish improved net returns merely by replaying known historical data. Statistical superiority requires a separately authorized, frozen and preregistered **forward-only prospective SHADOW experiment** on non-overlapping days/market-event blocks, with actual prospective costs/assumptions labeled, comparison to a control, adjusted uncertainty and the unchanged existing Gate 6 promotion checks. P6/P7 remain **NOT AUTHORIZED**. Do not state or imply that successful core remediation is evidence of alpha.

### 15.7 Gate and per-package acceptance consequences

**P0:** independently recomputed source summary + exact evidence manifest + isolated fixture-generation program; establish provisional performance budgets and ask the user to accept or replace them before they are used as contractual thresholds.

**P1:** reproducible defect classification; source-linked before/after proof when current defect exists; live PostgreSQL-style concurrent test; duplicate/constraint fault-injection proof; preserved legacy rows and retrievable IDs.

**P2:** event-versus-opportunity SQL/CSV funnel trace, exact `signal_id`/decision-ID join, missing-slot/alarm negative control and clock-boundary proof.

**P3:** independent accounting oracle, raw boundary-fill ID trace, signed-funding/window-boundary mutation results and immutable as-of proof.

**P4:** explicit ACTIVE-versus-authority query/trace, attempted unauthorized model authority or promotion and raw denial, proper scoring baseline reference and Gate-6 status journal proof.

**P5:** all-row economic and cluster calculations, source-to-aggregate claims, independent no-hardcode check, deterministic two-run export proof and journal provenance for affected existing research statuses.

**P8:** **verify all P0–P5 evidence bundles exist and are complete**; publish a checksum-indexed sanitized CI/local aggregate bundle, record pass/fail/blocked separately for tests and non-test evidence, show measured performance and user budget approval status, and enumerate unverified paths. Missing required provenance/fault-injection proof fails acceptance of the specific claimed property, even when unit tests pass.

**Release decision:** a package is `ACCEPTED` only when its required proof exists, is independently reproducible and passes applicable safety/performance gates. Otherwise label the specific claim `NOT_VERIFIED`/`BLOCKED`, publish the missing-evidence checklist and do not claim complete release readiness. Only the user can authorize scope changes, user sign-off of proposed performance budgets, commits/pushes and GitHub Actions deployment.

