# BYBIT RESEARCH QUANT — UNIFIED EXECUTION & QUANT INTELLIGENCE SPECIFICATION V4

**Status:** Binding implementation and verification specification for You.  
**Mission:** Execute this action plan directly: restore demonstrable end-to-end operation, fix every applicable correctness defect described below, and implement ALL mandatory validated quantitative intelligence functionality in the SAME delivery.  
**Platform:** Existing Laravel/PHP + Go + Vue/Quasar + PostgreSQL + Redis + Docker, deployed through existing CI/CD.  
**Exchange market:** Bybit **linear USDT perpetual** BTCUSDT and ETHUSDT; inspect and confirm the actual configured market category.  
**Trading permission:** PAPER ONLY. **Never** place a real exchange order or request trading-capable credentials.  
**Capital model:** Exactly eight independent persistent PRIMARY virtual accounts, one per existing strategy. BTCUSDT and ETHUSDT share capital only within their strategy's account.  
**Autonomy:** Automatic research, genuine training, validation, promotion, suspension, parameter selection and rollback **within paper-only policies**. No self-modification of deployed application code, unauthorized deployments, risk-cap relaxation or live trading.  
**Output:** Implemented code, passing integration/end-to-end tests, observable forward operation, honest production verification status, and evidence-backed reporting in Russian.

---

## IMMEDIATE EXECUTION PLAN — START HERE

**Execute this plan now. This document is the complete, standalone implementation contract. Do not search for previous specification versions, prepare a replacement roadmap, or stop after documenting defects. Implement code, run tests, and record actual evidence at each gate.**

| Order | Mandatory action | Required observable result |
|---|---|---|
| **G1** | Audit current repository and runtime; reproduce and fix Redis, HTTP 500, dashboard latency, ingestion, scheduler and queue defects | Running services, reproducible regression fixes, fresh closed-candle data and healthy job execution |
| **G2** | Repair the single authoritative trade pipeline, atomic Risk Gate, eight isolated paper accounts, virtual executions and ledger | Deterministic end-to-end virtual trade with correct account attribution, money, PnL and no duplicate fills |
| **G3** | Validate continuous market operation, real API payloads, Vue pages, reconciliation, latency and fail-safe behavior | Observable consecutive forward decision cycles and consistent interface/health data |
| **G4** | Fix genuine ML datasets, point-in-time features, labels, training jobs, learned artifacts, inference and validation | Reproducible trained model and subsequent inference, with honest insufficient-data status |
| **G5** | Deliver adaptive multi-timeframe analysis, efficient scanner, grades A–E, meta-labeling, SKIP analysis, exit and pattern research | Implemented, causally valid quantitative features with independent evidence and tests |
| **G6** | Implement guarded autonomous shadow/challenger/paper champion promotion, versioned policy, suspension and rollback | Verified candidate acceptance/rejection and actual effect on subsequent paper decisions |
| **G7** | Run complete independent PHP/Go/Vue, PostgreSQL/Redis, adversarial and browser verification | Passing mandatory tests; documented failures and source-backed acceptance A01–A32 |
| **G8** | Prepare deployment, verify the exact deployed SHA after authorization and rerun safe read-only HTTP auditing | Verified release evidence, or explicitly `DEPLOYED_UNVERIFIED` if deployment is not authorized |

**Do not stop at G1–G4:** a working core is the first milestone, not completion of the full specification. Preserve all existing data and trading history. Never place real exchange orders.

---

## 0. CONTRACT: DO THE WORK, NOT ANOTHER PLAN

The owner has repeatedly received optimistic implementation reports without verified end-to-end results. **You must fix the actual repository, execute tests and supply verifiable evidence.** Do not respond with only architectural suggestions, another specification, mock screenshots, assumed metrics or claimed completion.

**Two milestone levels are mandatory and distinct:**

- **M1 / WORKING CORE:** Market ingestion → timestamp-safe strategy evaluation → authoritative BUY/SELL/SKIP → atomic account risk approval → virtual fill/position → ledger/reconciliation → API/UI; observable in actual running environment. The real feature/label/training pipeline and guarded candidate activation/rollback must also work on isolated integration fixtures before M1 is declared. If the production environment is inaccessible, mark live observations UNVERIFIED rather than falsely claiming M1 in production.
- **M2 / COMPLETE UNIFIED SCOPE:** M1 is preserved while every mandatory intelligence and correctness requirement in THIS document is implemented and tested: adaptive research timeframes, efficient scanner, A–E/UNRATED grades, ML meta-labeling, valid SKIP counterfactuals, time-exit research, pattern/experiment budgets, research lifecycle, account-specific controls, observability, full endpoint and browser QA. You may not mark FULL_SCOPE_COMPLETE while those requirements are simply "deferred." The absence of a statistically promotable model is NOT an incomplete feature.

**Gate discipline:** Fix foundational correctness first; do not let advanced R&D destabilize the working core. Implement critical reliability, finance, evidence, data-causality and protection requirements in the early gates. Implement all remaining intelligence functionality in its designated gate before final full-scope acceptance. A gate is not passed because only unit tests are green.

**Repository is source of truth.** Old audits are hypotheses, not verified facts. Never assume an old Git SHA is deployed, that a previously claimed fix is present, or that previous CURL totals reflect current production.

**Explicitly approved decisions, not questions:**

1. Eight current strategy IDs, no required ninth strategy.
2. Each PRIMARY strategy paper account holds its own independent capital; BTC and ETH compete for resources within that account only.
3. Research/shadow/challenger capital and ledgers are isolated from primary paper history.
4. Full automatic promotion of models and validated declarative strategy parameters is authorized in paper mode, never real execution.
5. Owner-controlled hard risk ceilings are immutable to ML, research agents and application-side automatic tuning.
6. Existing deployed source code is not automatically rewritten/released by the running research system. You may implement changes in its authorized local workspace.
7. No SSH, private DB access or Docker administration on production is granted to local model. Owner authorizes and performs deployment through existing process. Authorized safe HTTPS GET/HEAD afterward is allowed.
8. Never modify or clean ECONOM/econom-group or any unrelated/maybe-shared Docker images, cache, volumes, files, backups or data.

### 0.1 What must NOT be used as proof

- A 200 HTTP response without schema/business/freshness checks.
- `COMPLETED` / `SUCCEEDED` job status without actual output and lineage.
- A passing ML unit test without saved learned parameters and subsequent inference.
- Backtest gains without causal data and costs.
- Empty dashboard interpreted as no activity or HEALTHY.
- Synthetic/demo paper positions presented as real market forward outcomes.
- An approved configuration record that is not consumed by future decisions.
- Old remote audit output from an unknown deployed release.
- A compilation or CI pass represented as successful production operation.

### 0.2 Repository-first preflight

Before modifying any file, inspect `AGENTS.md`, README, source modules, strategies, migrations, Laravel route list, scheduler, queues and actual worker startup, Go jobrunner kinds, Vue consumers, CI, Docker Compose, environment templates and tests. Run and preserve the results of `git status --short`, `git rev-parse HEAD`, route/schedule lists, test baselines, database connectivity in ISOLATED TEST environments, and available logs.

Read available `vector-audit-1d-*.zip`, source audit snapshots, relevant audit reports, and remote curl artifacts (`remote-curl-audit.json`, `remote-curl-audit.md`, `remote-endpoint-coverage.md`) when present. **This document is self-contained; do not require any earlier specification.** Do not treat a missing supporting artifact as evidence of a defect.

Update contradictory docs: if `AGENTS.md` still says "signals only," correct it to authorize *simulated paper trading* while retaining the absolute prohibition on live orders. Preserve the project owner's local changes; don't overwrite a dirty worktree. Catalog each existing component as WORKING / PARTIAL / BROKEN / NOT_VERIFIABLE, with exact evidence.

Required initial artifact: `reports/00-baseline.md` including current SHA, system topology and actual data-flow traces, reproducible failure commands, environment assumptions, server-side/HTTP limitations, and P0–P3 defect inventory. Keep it factual and short enough to be actionable.

---

## 1. AGENT OWNERSHIP AND EXECUTION RULES

Use native subagents where possible; six to eight role-specific agents with **non-overlapping write ownership**, coordinated by one integrator. Parallel READ-ONLY audits are encouraged; integrate source/migration/schema changes serially. If subagents are not available, state that clearly and divide the work logically—do not invent subagent findings.

| Role | Scope | Independent signoff |
|---|---|---|
| Runtime/SRE | Laravel configuration, Redis, caches, queues, scheduler, 500s, latency, release SHA | QA reviewer |
| Market/Strategies | Bybit public data, closed candles, 8×2 evaluation, scanner, intervals, decision contracts | QA reviewer |
| Paper/Risk/Ledger | 8 accounts, position states, atomic risk, simulated fills, fees, reconciliation | Quant auditor |
| ML/Promotion | Features/labels, actual model training, artifacts, validation, promotion, rollback | Quant auditor |
| Quant Research | A–E, meta-label, SKIP, time stops, pattern discovery, experiment budgets | ML + Quant auditor |
| API/Vue | Dashboard, Market, Health, Opportunity, Learning, Performance, auth, browser tests | QA reviewer |
| Independent QA / Quant Audit | Adversarial tests, lineage, causality, finance, OOS methodology, release evidence | Must not self-approve own implementations |
| Integrator | Shared schema, migrations, CI, conflicts, final acceptance matrix | Final owner of interface compatibility |

When a task is blocked, report: `BLOCKED: <criterion>; observed command/request and error; evidence-based root cause; smallest safe fix; exact missing permission/input.` Do not ask the owner to repeat information already in the repository. Do not promise asynchronous work after the session.

---

## 2. BINDING DOMAIN INVARIANTS (IMPLEMENT FROM THE START)

### 2.1 Eight independent persistent accounts

The authoritative strategy IDs are:

1. `baseline`
2. `trend_pullback`
3. `bollinger_mean_reversion`
4. `donchian_atr_breakout`
5. `regime_switching`
6. `momentum`
7. `support_resistance_bounce`
8. `support_resistance_breakout`

Maintain exactly one **PRIMARY** account per existing strategy, enforced with a DB uniqueness constraint appropriate to schema. Preserve current IDs, balances, starting capital, old positions, fills, realized results and account equity history. Idempotent migration/seeding must not create duplicates or reset losing accounts. Ambiguous legacy attribution requires quarantine/manual reconciliation, not invented ledger data.

BTC and ETH use the **same** capital, margin and risk constraints *inside each strategy account*. Accounts cannot borrow, transfer, net exposure, reserve collateral, share position or apply optimization against one another. A common **operational kill switch** may freeze new entries across all eight accounts during a system-wide integrity incident; it does not pool capital. A multi-account overview is REPORTING only; never call sums a real investable portfolio.

Every DB record/API/queue message that pertains to trading identifies the account explicitly. No `first()`, `oldest()` or arbitrary fallback account, and no implicit account creation in GET requests. Missing config = `NOT_CONFIGURED` and no new trades.

Experiment/shadow/challenger accounts have distinct account types and isolated ledgers. Their profit, losses and fees are not posted to PRIMARY accounts. For model comparisons, align market opportunity stream, dates, starting cash, execution assumptions and risk budget. An open position retains the model, strategy, exit, risk and cost methodology versions at opening.

### 2.2 Single authoritative proposal → decision → paper execution

Implement using existing services instead of duplicating microservices:

`New usable closed bars → Strategy PROPOSAL → Optional approved model inference / grade → Entry eligibility → Account Risk Gate → Atomic reservation → FINAL DECISION → Simulated execution → Position / immutable ledger → Reconciliation and eventual labels`.

Distinguish:

- **Proposal:** `PROPOSE_LONG`, `PROPOSE_SHORT`, `NO_SETUP`.
- **Entry decision:** `ENTER_LONG`, `ENTER_SHORT`, `SKIP` (legacy BUY/SELL/SKIP external compatibility preserved).
- **Position management:** `HOLD`, `REDUCE`, `CLOSE`, `STOP`, `TAKE_PROFIT`, `TIME_EXIT`, and supported liquidation events.

ML can veto, rank or risk-reduce a proposal if that rule was validated; it cannot silently reverse its side. A proposed trade that fails risk is `SKIP:RISK_REJECTED`, not a low-quality setup. `NO_SETUP` can be represented in the evaluation ledger without fabricating an entry plan.

All PRIMARY paper entries MUST pass one DB-transactional Risk Gate. Risk approval and collateral/risk reservation are atomic; unique business keys ensure retries do not create duplicate positions/fees. Redis locks are advisory, not the primary source of uniqueness. Crashes between transaction commit and job dispatch must be recovered via an outbox/inbox or equivalent persisted state machine. Independent management of existing positions continues during ML training outages.

Default when existing position mode is unspecified: **one-way, one net position per account+symbol, no automatic reversal**. Same-direction additional entry, hedging, pyramiding and reversal are DISABLED until individually justified and enabled through a validated versioned policy. An opposing entry when an open position already exists becomes `SKIP:POSITION_CONFLICT`; exiting existing positions belongs to the exit policy rather than entry proposals. Preserve already valid existing explicit settings; do not change behavior silently.

Idempotency identity covers `account_id`, `strategy_id`, `strategy/config version`, `symbol/market`, closed-bar decision timestamp, setup identity and decision intent. Distinguish evaluation event keys from the underlying proposed trade to avoid incorrectly collapsing legitimate re-evaluations. Duplicate deliveries, reconnects, retries and version switches cannot cause duplicate fills.

### 2.3 Point-in-time data and execution timestamps

Track, directly or reconstructably:

- `candle_open_at`, `candle_close_at`, `exchange_event_at` (where supported), `ingested_at`, `data_available_at`.
- `evaluation_started_at`, `decision_at`, `risk_approved_at`, `simulated_order_sent_at`, `simulated_fill_at`.
- `feature_available_at` for EVERY feature family; `label_matured_at` for outcomes.

All timestamps stored consistently in UTC with timezone-aware API representations. A feature must satisfy `feature_available_at <= decision_at`; closed at a historical timestamp **does not** imply it was available at that timestamp during a past simulated decision. Late arrivals cannot be given retroactive forward-paper fills at earlier unattainable prices. Replay/backfill jobs have `RESEARCH_REPLAY` provenance; their events must never write as real-time forward-primary executions. Last closed higher-timeframe candle is used only if genuinely available as of the decision.

Separate context/setup/decision/entry-trigger intervals from **holding period and exit horizon**. The standard 1h→30m→15m→5m relationship is a BASELINE hypothesis, not mandatory voting of all intervals. Unfinished 4h/1d bars and future feature snapshots are forbidden.

### 2.4 Ledger, margin, fees and correct R

Use decimal-safe financial amounts (exact decimal types / validated fixed-scale arithmetic, not binary floats for authoritative money writes), explicit units and versioned rounding per instrument metadata. Confirm category, tick size, quantity step, minimum quantity/notional, contract semantics, leverage/margin rules and position mode. **Paper simulation is not a claim of exchange fill realism**: persist actual known market observations separately from assumed spread/slippage/latency/funding/fee scenarios.

Define wallet/cash, reserved collateral, available collateral, initial/maintenance margin, realized price PnL, commission cash flow, funding, unrealized mark-to-market PnL, equity, snapshots and cumulative drawdown. Under chosen versioned accounting convention, `equity = wallet_balance + unrealized_PnL` (if costs have already been posted to wallet, do not subtract again). Reserving collateral must NOT be booked as an expense. `trade_net_PnL = realized_price_PnL - associated_execution_fees - allocated_funding - other modeled costs not already netted` with no double-counting.

For trades with valid saved initial monetary risk, `net_R = attributable_net_trade_PnL / saved_initial_monetary_risk`. Monetary initial risk derives from recorded planned entry, initial stop, executed quantity and chosen sizing convention; zero or unknown denominator must reject the plan or mark a counterfactual NON_EVALUABLE. A fixed **0.13%** cost is not **0.13R**. Maker/taker costs, slippage, spread, funding, latency, adverse selection and liquidation risk must use documented versioned assumptions, not universal unsupported constants.

Every unique fill/fee/funding/closing event must have an idempotent business key and immutable accounting effect. Verify partial fills, partial closes and funding settlement if the existing engine supports them; otherwise fail unsupported cases explicitly and do not overstate realism. Conservative and deterministic TP/SL collisions within one OHLC interval; no favorable hindsight ordering. Funding timestamps and charges must be applied exactly once.

Reconciliation is durable, scheduled, account-specific and independent of the ledger writer. Recompute from transactions and/or invariants and compare against snapshots; persist actual findings, discrepancy, last run and last SUCCESS. `NOT_RUN`/`NOT_EVALUATED` is not HEALTHY. On mismatch, stop NEW exposure for the affected account, keep safe exits active, and record evidence without resetting history.

### 2.5 Version and evidence contract

Each proposal, decision, fill, position, label, train run, model artifact, research result and promotion stores compatible IDs and versions of:

- Account, strategy, market category and instrument.
- Strategy/configuration and declared applicable context/setup/decision/trigger timeframes.
- Feature schema and point-in-time data range.
- Prediction target, label/outcome definition and horizon/exit policy.
- Cost and simulated execution methodology.
- Model artifact, preprocessing, calibration, training code Git SHA.
- Risk/promotion policy versions and decision reason codes.
- Evidence type: `FORWARD_PAPER`, `HISTORICAL_BACKTEST`, `LIVE_SHADOW`, `CAUSAL_COUNTERFACTUAL`, `MODEL_PREDICTION` (predictions are never outcomes).

Never rewrite existing real-time paper decisions to make new methodology appear historically profitable. If modeling methodology changes, recompute separate RESEARCH results and compare on equal methodology; preserve old primary ledger/evidence.

---
## 3. GATE 1 — BOOT, RELIABILITY, DATA INGESTION, SCHEDULER, API

**Goal:** A deployable system actually starts; workers run; closed market candles arrive; 8×2 evaluations are initiated; historical HTTP failures are fixed. **This gate takes precedence over speculative features.**

### 3.1 Real boot and process topology

Determine how Laravel app/API, PostgreSQL, Redis, queue worker(s), scheduler, Go worker and frontend start in the repository's actual Docker/CI configuration. Verify expected environment variables without exposing secrets. A task registered in Laravel schedule is NOT working until an actual running scheduler dispatches it and a worker completes it. Track named queues, routing, retry/backoff policies, dead-letter/failed jobs, event processing latency, heartbeat, job inputs and actual outputs. Make repeated restart/resume safe.

Use a separate test Compose stack/database and unique Redis prefix. Never run destructive migration/seeder/flush against production or ECONOM. Provide deterministic initialization commands and the expected nonsecret environment variables. Ensure migrations and config caching are release-compatible.

Investigate previous CI fragility such as missing `tests/bootstrap.php`, mismatched PHPUnit bootstrap, Laravel Pint import/style failures or Go build issues if they exist in CURRENT code. Fix root causes rather than disabling checks.

### 3.2 Market ingestion and freshness

From public Bybit market data, retrieve and store fresh valid closed candles for both instruments and all EXISTING enabled production intervals (historically 5m, 15m, 30m, 1h). Respect request limits, interval boundaries, exchange time, rate limits, replay gaps and source outages. Validate unique `(category, symbol, interval, close_timestamp)`; valid OHLC/volume; missing interval detection; gap repair; latest expected vs actual completed bar. Store both event close and data availability timestamps. Use incremental fetch and computation.

A stale or absent required candle blocks new dependent exposures and persists a clear reason. Do not return a misleading healthy empty dataset. Partial failure on ETH must not silently disable unrelated BTC evaluations when inputs/risk are independent.

### 3.3 Exactly one forward decision producer

Inspect existing `GenerateSignalJob` / 15-minute scheduler and possible other signal paths. Identify the authoritative owner of decision evaluation. The later efficient scanner must **augment or replace** that path, never operate as a second competing engine. Each active strategy/symbol/decision interval receives a persisted evaluation outcome (BUY/SELL/SKIP/NO_SETUP/DISABLED/BLOCKED/FAILED as applicable), not necessarily one trade signal row. Expect **16 evaluations or explicit status records** for eight enabled strategies × two symbols per qualifying 15m cycle. If some are disabled, report enabled counts with reasons; do not fake sixteen executed trades.

### 3.4 Reproduce/fix actual API and Redis defects

Prior historical findings to reverify, never blindly patch by old file line number:

- `/research/patterns/latest-matches` reportedly threw HTTP 500 when `symbol` missing. Make parameter optional and safe OR explicitly required with HTTP 422. Regression-test actual contract.
- Redis cache reportedly deserialized some cached ORM objects into `__PHP_Incomplete_Class_Name` and caused intermittent 500 on Dashboard, positions or indicators. Inspect actual `config/cache.php`, Redis serialization format, object payloads and current failure logs. Prefer stable versioned scalar/DTO cache payloads when justified; avoid global cache flush and blind `serializable_classes` changes. Test cold/warm/expiration/legacy key behavior with proper namespaces.
- Dashboard cold synchronous aggregation reportedly exceeded 27s. Measure query count/time, cache stampede, history volume and payload; implement bounded/persisted incremental aggregates and safe freshness metadata. Avoid unbounded request-path recomputation. Under documented representative data/host conditions target warm Dashboard/Market p95 < 1s and cold p95 < 3s; if unattainable, report real bottleneck, trace and `PERFORMANCE_BLOCKED` without inventing PASS.
- Recent BUY/SELL/SKIP lists, Opportunity, Health and Daily Performance reportedly showed missing/zero/misaligned data. Reproduce using actual filters/account scope and fix underlying API data pipeline rather than only cosmetic Vue fallbacks.
- Validate Go research job kinds: unknown or unimplemented kinds must FAIL with structured error, never ACK as completed. An actual PHP ML trainer is acceptable where appropriate.

Provide a minimal, safe, read-only version-identification endpoint or documented equivalent returning Git SHA, build timestamp/version and artifact identity where feasible, without secrets. Ensure HTTP exceptions surface honest schema/status/errors, and authorization is tested.

### 3.5 Gate 1 acceptance

- Local isolated environment starts required components and real PostgreSQL + Redis integration is demonstrated.
- Scheduler, each required queue consumer and Go job protocol execute at least one real integrated job; actual output/last-success recorded.
- BTC/ETH closed candles persist with freshness/gap checks; no incomplete candles affect live decisions.
- Enabled 8×2 evaluation coverage observable with explicit statuses and deduplication.
- Reproduced API 500s and Dashboard timeout defects are resolved with regression/latency evidence; suspected historical defects that no longer reproduce are clearly marked NOT_REPRODUCED, not "fixed."
- No invented success jobs, fake signal rows or missing-route assumptions.

**Deliverable:** `reports/01-runtime-and-reliability.md` including repro/result pairs and relevant commands.

---

## 4. GATE 2 — PAPER ACCOUNTS, AUTHORITATIVE EXECUTION, FINANCIAL CORRECTNESS

**Goal:** Every allowed PRIMARY virtual trade flows through the one Risk Gate and reconciles in its own account. **Gate 2 is a prerequisite for advancing the research system.**

### 4.1 Safe legacy account migration

Inventory actual paper accounts and historic ledger rows before writing migration code. Implement `PRIMARY` uniqueness and explicit strategy mapping without clearing or reseeding. Verify all eight names and existing account IDs remain stable. Different strategy accounts never share cash, orders or accounting records. For each account the two markets share available collateral. Account-specific settings may be stricter than owner risk ceilings, never looser.

Use immutable paper accounting event history or explicit corrective entries with reason/audit trail, not rewriting historical profit or PnL to match a new formula. Define position-to-trade grouping for partial closes. Maintain isolated EXPERIMENT/SHADOW/CHALLENGER identities for research rather than borrowing the primary account identity.

### 4.2 One-way default, transitions and exit invariants

Codify supported position state machine and ensure only valid transitions. Proposals to reverse an open position must not silently cause a reversal. When the existing mode is not clearly documented, default to one-way/no-auto-reverse for `(account, symbol)` and persist `SKIP:POSITION_CONFLICT`; no implicit averaging/hedging/pyramiding. Preserve validated explicit legacy behavior if present and test it.

Once opened, a position is bound to the strategy/model/exit/cost/risk methodology used at entry. A promoted model governs future proposals, not prior open exits. If a separately approved position migration policy exists, version and independently test it; otherwise keep prior exit rules.

### 4.3 Atomic risk and durable processing

Test two concurrent BUY/SELL proposals on BTC and ETH under the **same** account. They must not reserve more collateral or permissible loss than the account allows. Use DB transactions, row locking/unique constraints as appropriate and recoverable processing states. Model outages/retries/crashes between reservation, simulated order creation, fill, fee event and queue dispatch. Ensure idempotent updates based on immutable event IDs and a post-crash recovery reconciliation. Fail closed on unresolved account state.

A hypothetical simulated entry always uses causally available data and price assumptions realistic to fill time; a delayed source event cannot create a trade at a historical signal close. For historical market replay use research accounts/artifacts, NOT primary forward-paper fills.

### 4.4 Money and PnL evidence

Add independent test vectors for long, short, TP, SL, timed exit, adverse slippage, maker/taker commission, funding, partial executions where supported, low/free margin, zero stop distance, leverage/maintenance constraints where modeled, intrabar TP+SL ambiguity, daily equity/fees and account-level drawdown. Demonstrate independent replay of ledger events reproduces wallet, realized/unrealized PnL and equity with decimal-safe arithmetic and chosen explicit accounting convention.

Compare ledger totals against the API and Vue Daily Performance data for **exact same account, instrument scope, UTC period boundaries and configured reporting day**. Ensure 8-account all-strategies summary is labeled research/reporting aggregate only. Reconciliation must run separately per primary account and reveal missing/duplicate transactions; failed reconciliation stops only that account's new entries unless systemic incident requires global operational pause.

### 4.5 Mandatory deterministic end-to-end cases

At minimum, automated full pipeline tests must establish:

1. An eligible long proposal becomes ONE paper long position with correct simulated fill, fee and ledger records.
2. An eligible short proposal becomes ONE paper short and correct subsequent PnL/funding.
3. TP and SL each produce independently correct realized outcome, cash/equity and risk-multiple.
4. NO_SETUP, risk-rejected and model-rejected proposals remain distinguishable from an executed trade; no fills on SKIP.
5. Concurrent BTC/ETH entries cannot over-reserve a strategy account.
6. Same symbol/timeframe opportunities in two different strategy accounts remain fully isolated.
7. Duplicate events/retries charge once; replay and delayed bars never turn into retroactive forward fills.
8. A model promotion does not rewrite existing positions and cannot duplicate a setup.
9. Conflicting opposite proposals obey position policy; arbitrary reversal is forbidden.
10. Intentional test-ledger corruption is detected by an independently calculated reconciliation and Health becomes DEGRADED.
11. Read-only GET endpoints do not create accounts, dispatch trading, mutate model state or book fills.
12. A forbidden real-order API call is blocked and logged as a violation before network execution.

**Deliverable:** `reports/02-paper-ledger-and-risk.md` with real integration DB engine, deterministic fixtures, account identities, amounts, reconciliation and concurrency results.

---

## 5. GATE 3 — CONTINUOUS FORWARD OPERATION, API/UI, RESILIENCE

**Goal:** Users can SEE what's happening, why no position opened, and whether jobs truly ran. Research cannot starve decisions or position management.

### 5.1 Actual forward observation

On a reachable running release, inspect **two successive newly closed 15-minute decision intervals**, not historical replay. For each enabled strategy/symbol pair record an evaluation status with current release identity, ingestion completion, decision timestamp, model state if applicable, risk/execution result and error/reason; do not require spontaneous BUY/SELL during two intervals. Persist heartbeats/last-success/failure for ingestion, scheduler, strategy evaluation, worker queues, Go research, reconciliation and learning label maturation. If runtime access is unavailable, use isolated local integration and status `LIVE_FORWARD_UNVERIFIED`, not PASS for live continuity.

### 5.2 Correct account/time-scoped interfaces

Preserve existing routes and Vue navigation whenever possible; fix underlying queries/contracts:

- **Dashboard:** one explicit selected PRIMARY account, optional BTC/ETH/ALL within it; latest actual BUY/SELL/SKIP/NO_SETUP outcomes; no disappearing lists or fabricated zeros, correct equity and daily PnL.
- **Market:** default **today** using a documented configured display day/timezone and UTC-backed predicates; optional rolling 7-day time interval with paginated server-side retrieval. A last-N-candle limit is NOT a day boundary. 7d of 1m candles requires pagination/bounded server responses. Display data freshness/errors.
- **Strategies:** all 8 registered strategies, 8×2 enabled/blocked/last-evaluation/latest-decision/BUY/SELL/SKIP/NO_SETUP/counters/failures, queryable by release/account.
- **Paper positions and executions:** real account-scoped positions, fills, commissions, funding, model-policy version, source-linked status and coherent pagination.
- **Daily Performance:** explicit selected account/reporting timezone; reconcile realized, unrealized, fees, funding, equity change and cash flow without mixing scope. Storage uses UTC; prior reports reference Europe/Moscow day reporting—verify current owner setting rather than hardcode if different.
- **Opportunity:** distinguish eligible counterfactual `PENDING`, `MATURED`, `NON_EVALUABLE`, reasons and evidence. Do not show default fictitious BUY or profit.
- **Health:** `HEALTHY`, `DEGRADED`, `UNHEALTHY`, `STALE`, `NOT_EVALUATED` with last execution and real reconciliation evidence. No-run ≠ healthy; empty queue ≠ processed job.
- **Learning:** real counts for snapshots, matured labels, train runs, artifact hashes, status `NO_MODEL`, `INSUFFICIENT_DATA`, `SHADOW`, `ACTIVE`, `FAILED`, rejected candidates and next possible action. No fake zero-active alert if policy correctly has a baseline and insufficient learning data; state explicitly.

HTTP input validation uses 400/422, auth uses 401/403, real server exceptions yield 500 with safe structured diagnostics. API and UI must not flatten 500/timeouts into successful empty datasets. Frontend tests must verify navigation persistence of selected account, scope and filters, data staleness and clear error states.

### 5.3 Degradation matrix (fully tested)

| Incident | Mandatory response |
|---|---|
| Required stale/unfinished bars | Block dependent new entries; retain safe management of existing positions; status STALE |
| Required model unavailable, invalid artifact/schema, inference NaN/Inf or timeout | Block NEW model-dependent entries unless a *prevalidated configured* fallback policy exists |
| Research Go worker down | Pause only research; approved independent current paper policy continues |
| Redis down | Enforce DB-backed uniqueness/recovery; pause new entries if safety cannot be proven |
| Ledger/account reconciliation mismatch | Suspend new risk on affected account; log/alert; continue safe exits |
| Account ceiling breached | Block new entries for that account; never autonomously raise ceiling |
| Source or time synchronization outage | Stop invalid decisions; retain transparent reasons |
| Release / worker schema incompatibility | Fail compatibility gate, retain last known good release |
| System-wide integrity emergency | Operational kill switch stops new entries on all accounts without pooling funds |
| No trained model / insufficient examples | Run separately validated existing baseline if explicitly configured; otherwise no new model-dependent entries |

### 5.4 Operating budgets and observability

Define realistic CPU/RAM/time/concurrency budgets for scan, backfill, Go research jobs, ML training, API and queue workers. Production scanner should update only affected features; one-minute polling is a **fallback**, not an excuse to reprocess months of history. Separate research and forward paper work queues, bound research parallelism, timeouts and retry amplification; preserve ability to manage existing positions when training stalls.

Expose minimal **authenticated read-only** diagnostic endpoints (or existing equivalent) for app SHA, component heartbeats, queue/job last-success and last errors, market freshness, strategy coverage, account reconciliation, model evidence and promotion status. Follow project auth/rate limits and never expose keys, credentials or private raw data unnecessarily. Remote production audit must make no mutating request.

**Gate 3 acceptance:** UI/API functional tests and browser E2E, actual timestamps and source-matched aggregates, no false HEALTHY, response payload and latency sampling, safe dependency failures, and two successive forward cycles when a running environment is accessible.

**Deliverable:** `reports/03-forward-ui-and-resilience.md`.

---

## 6. GATE 4 — GENUINE ML, POINT-IN-TIME EVIDENCE, MODEL LIFECYCLE

**Goal:** The application continuously creates honest training evidence and actually trains, evaluates and consumes models, with exact lineage. Gate 4 is part of the **MANDATORY WORKING CORE**, not a future research idea.

### 6.1 Source-to-label traceability

Establish typed and versioned evidence categories:

- `FORWARD_PAPER_EXECUTION` — genuinely paper-executed on a prospective market stream, with simulated fills recorded at available prices.
- `FORWARD_DECISION` — prospective signal that may be SKIP, BLOCKED, or NO_SETUP; NOT an execution.
- `SHADOW_PROSPECTIVE` — future outcome of a model that made an ex-ante prediction but did not direct the primary account.
- `RESEARCH_BACKTEST` — replayed historical simulation only.
- `COUNTERFACTUAL` — simulated evaluation of a previously recorded actionable rejected plan.
- `MODEL_PREDICTION` — a forecast, never an outcome label by itself.

Every example must include instrument/category, strategy version, account role, decision/proposal ID, originating model or base version, candle identifiers, event time, observation/availability time, feature schema/version/hash, label rule/version, outcome horizon, execution simulation policy, actual label maturation time, provenance and consistency flags. Immutable linkage is required from every training row to its original ex-ante observation. A GET request never synthesizes learning observations.

Do NOT mix historical/research and genuine forward-paper performance when displaying "live" results. If synthetic/fixture input is used in acceptance tests, mark it unequivocally `ISOLATED_TEST`; it must never reach live research performance tables.

### 6.2 Point-in-time features and matured labels

For a decision at `decision_at`, only a closed candle whose *data_available_at* <= `decision_at` can contribute. The mere fact that a backfilled candle now has `is_closed=true` does NOT make it ex-ante available at an earlier decision time. For research replay, reconstruct conservative historical availability rules and version the assumptions; do not misrepresent historical approximations as measured live ingestion latency. Prevent time-travel in 4h and 1d context and cross-symbol joins.

Explicitly distinguish a strategy's trade `direction`, an entry `side`, and the label's outcome type (`TP_FIRST`, `SL_FIRST`, `TIME_EXIT`, `NO_FILL`, `AMBIGUOUS`, `CENSORED`, `DATA_GAP`). Separate label eligibility, maturation and exclusion reasons. A timeout is not automatically −1R; a SELL candidate is not a hypothetical BUY. Missing bars or simultaneous TP/SL within a historical OHLC candle must invoke a documented conservative/unknown policy; do not choose the favorable order. Predictions/labels use a consistent *monetary initial risk* denominator. Never use a percentage net return as R without normalization.

Label maturity depends on complete necessary *later* observations and exit path, not only elapsed wall-clock time. Enforce outcome overlap-aware purging and gap validation.

### 6.3 Actual training and durable model artifacts

Inspect existing PHP `LearningPredictorService`, research job kinds (`MODEL_TRAIN`, `FEATURE_BUILD` etc.), Go runner and scheduler. Earlier code-audit snapshots claimed hardcoded logistic-regression weights, an absent Go `MODEL_TRAIN` handler, disconnected `ProcessAutonomousLearningJob`, and successful unsupported research kinds. **Verify each against CURRENT repository and mark FIXED_ALREADY / REPRODUCED / NOT_REPRODUCED**. Do not rewrite correct modern code or assume Go is required for every ML operation.

Implement / verify a real, deterministic fitting algorithm with learned weights that change appropriately when evidence changes, numerical stability, explicit missing-feature handling, train-only transformation, regularization, class-balance handling, and saved inference-compatible parameters. An acceptable implementation may be in PHP, Go or a justified existing component; no fake "trained" status.

Persist: model identity, strategy/symbol applicability, target label and horizon, model type and hyperparameters, training dataset window/hash, sample exclusions, feature order/schema, preprocessing, calibration method, fitted coefficients/weights, training metrics, validation split info, code SHA, artifact checksum and serialized inference artifact. Fail unsupported job kinds loudly and persist structured errors. Model training jobs are idempotent, checkpointable where supported, bounded in memory/time and scheduled independently from decisions. Don't retrain needlessly on an unchanged dataset hash.

Tests must demonstrate: (i) a controlled learnable dataset yields parameters different from initialization; (ii) predictions vary correctly on unseen examples; (iii) an artifact round-trip preserves output; (iv) permuted/future labels cannot improve a causal training pipeline undetected; (v) a disabled/failed trainer cannot report success; (vi) model prediction uses the deployed artifact, not code constants.

### 6.4 Validating models without repeated holdout abuse

Use chronological *train → tune/validation → calibration → final holdout* separation, purging of overlapping outcome windows and embargo when justified, plus rolling/walk-forward periods. Fit scalers and calibrators only on permitted data. Save the exact experimental configuration *before* running selection. Track **all** candidate trials, including failures, rejected parameterizations and multiple candidate comparisons; avoid repeated tuning on final holdout. Include appropriate block-based uncertainty estimation for serially dependent trading decisions and matching exposure/opportunity assumptions.

Report, where applicable: Log Loss, Brier versus baseline, reliability/calibration, sample and effective sample size, net R and account-level net return after fees/slippage/funding, turnover, tail risk, maximum drawdown, uncertainty bounds, confidence procedure and failure/insufficient-data conditions. A good Brier Score or high win rate alone is never a sufficient promotion condition. Do not assume normal iid observations.

### 6.5 Model Applicability Contract

A candidate may direct a paper proposal only when the active, versioned applicability contract matches:

`strategy_id + market_category + symbol_set + feature_schema_version + context/setup/decision/trigger timeframes + label/holding_horizon + execution_policy_version + fee/funding_policy_version + risk_contract_version + inference_contract_version`.

Some dimensions may explicitly declare validated transferability across symbol/timeframe, supported by independent evidence; do not silently infer it. A BTC-only model cannot control ETH by default. Changing exit horizon or fee policy can invalidate outcome definitions and require retraining/validation. An artifact must be rejected for incompatible feature order, NaN/Inf prediction, invalid probability or corruption.

**One primary account per strategy remains authoritative**. Different symbol-specific champions within an account are allowed if approved and if all share the same account-wide risk and ledger.

### 6.6 Working autonomous learning loop

Run a bounded, observable learning cycle via the existing scheduler/worker infrastructure:

`new decision evidence → point-in-time snapshots → matured outcome labels → dataset integrity check → training when eligible → validation → challenger registry → prospective shadow → isolated paper challenger → guarded promotion/rollback`.

Specify scheduling frequency based on existing system cadence and resource budgets; no hardwired training every 15m if no new mature samples. Log last attempted/successful cycle, counts of eligible/new/matured observations, reason no train occurred (`INSUFFICIENT_DATA`, `UNCHANGED_DATASET`, `DATA_INVALID`, `TRAIN_FAILED`, `NO_VALID_CHALLENGER`), model artifact references and errors. A zero-active-model state may be correct at cold start; label it honestly while the configured verified baseline continues if allowed.

**Gate 4 acceptance:** Real trained coefficients/artifact on isolated data, real inference, correct point-in-time features/labels, scheduler/queue execution, reproducible validation, refused fake metrics and explicit data shortage state. A fixture tests valid promotion and rollback, including a *subsequent* decision using the new version, but no real-market model must be force-promoted just to pass this gate.

**Deliverable:** `reports/04-training-evidence-and-validity.md` with actual dataset/run/artifact IDs from tests, checksums, metrics, negative-case tests and live-state visibility.

---

## 7. GATE 5 — COMPLETE QUANT INTELLIGENCE (MANDATORY, NOT DEFERRED)

**Goal:** Implement all quantitative intelligence requirements specified in Sections 7.1–7.7 without sacrificing Gates 1–4. Implementation order may defer this gate until the working core is reliable, but **these features are mandatory for final full-scope acceptance**.

### 7.1 Incremental adaptive multi-timeframe market analysis

Support research and correctly bounded data access for `1m`, `5m`, `15m`, `30m`, `1h`, `4h`, `1d` for Bybit linear USDT perps. **Preserve existing enabled production intervals initially** (historically 5m,15m,30m,1h); add the new ones without silently changing currently active champion behavior. Distinguish strictly:

- `context_tf` — higher-level regime evidence.
- `setup_tf` — opportunity recognition.
- `decision_tf` — when a strategy emits its authoritative proposal.
- `trigger_tf` — optional lower-timeframe entry validation.
- `holding_horizon` / `exit_policy` — independent from decision timeframe.

A strategy need not use all timeframes; lack of alignment is not necessarily a veto. All used higher-timeframe feature values must be based on closed, causally available bars. Choose research configurations using fair, purged walk-forward comparisons and untouched prospective evaluation; restrict and log hypothesis counts, multiple-testing correction and resource spend. Allow per-strategy and per-symbol selected combinations, with exact applicability contract and rollback.

Implement a lightweight **single authoritative scanner**: react to newly closed relevant candles and configured setup transitions; a 1–2 minute bounded polling cadence is a fallback for missed source events, not a second engine or requirement to generate signals on every poll. Incrementally compute affected indicators. Deduplicate `(strategy, strategy_version, symbol, decision_tf, candle_close_at, setup_id)` under DB constraints. Fill historical gaps without retrospectively booking primary forward-paper fills. Reconcile 1m-derived bars against source intervals and detect missing source slices; beware exchange daily session boundaries, rate limits and 1m seven-day size. Event lateness must not become backdated entries.

Mandatory research comparisons (when valid sufficient evidence exists): existing 1h/30m/15m/5m, a simpler 1h/15m, strategy-dependent 5m confirmation, optional 4h/1d regime context and optional 1m triggers. **If no statistically defensible evidence exists, retain baseline; functionality is complete if experiments correctly return NOT_PROMOTED/INSUFFICIENT_DATA with reproducible outputs.**

### 7.2 Signal Quality A–E / UNRATED

Use a **versioned signal-quality policy**, preferably mapping calibrated expected net outcome, tail risk and uncertainty to `A`, `B`, `C`, `D`, `E`, or `UNRATED`. Do not add a second ML model simply to render letters. Define thresholds before candidate evaluation; require sufficient sample and quality calibration by account/strategy/symbol/regime when feasible. A grade is an assessment of candidate quality, not trade eligibility or reason for risk rejection.

Persist and expose separately:

`direction`, `original_proposal`, `quality_grade`, `quality_policy_version`, `eligibility_status`, `rejection_reason`, `expected_net_r`, `confidence_or_uncertainty`, `model_artifact_id`, `data_snapshot_id`, `decision_id`.

Retain compatibility for any existing three-grade UI but avoid confusing legacy A/B/C labels with verified new grade semantics. First run the scoring policy SHADOW: no primary trading changes. Evaluate observed frequency, costs-adjusted net R, sample count, discrimination, calibration and uncertainty, stratified by symbol/strategy/regime. Filtering by grade requires same promotion gates as a model change.

### 7.3 Meta-labeling and ML decision advisory

The primary strategy alone proposes direction and initial trade plan. A meta-label model estimates acceptance/expected net R and risk conditional on the **original proposed action**. It may reject or reduce size within account hard limits, never invent an opposite trade side or override a hard risk rejection. Avoid arbitrary fixed `P(success) < 0.40` gating independent of reward/risk and fees.

Evaluate paired policy candidates on the SAME decisions, price history and exposure/fee conventions:

1. Base strategy.
2. Base + ML Advisor.
3. Base + validated Quality Grade policy.
4. Base + Advisor + Grade.

The comparison includes rejected candidate outcomes where ex-ante executable plans exist and counts non-evaluable ones honestly; consider selection bias. Candidates remain in SHADOW/isolated paper before automatic promotion.

### 7.4 Honest SKIP Opportunity and counterfactual analysis

Represent at least: `NO_SETUP`, `STRATEGY_FILTER`, `ML_REJECTED`, `GRADE_REJECTED`, `RISK_REJECTED`, `DATA_REJECTED`, `NON_EVALUABLE`; evaluation lifecycle `PENDING`, `MATURED`, `CENSORED`, `AMBIGUOUS`, `FAILED` as justified. Never assume a missing plan was BUY, fabricate stop/target or pick a direction based on future price movement. Only a *previously persisted* actionable plan with original entry, stop, target, side, decision/availability time, horizon and versioned execution assumptions is eligible for a counterfactual outcome.

Use correct original risk denominator, cost-adjusted execution/exit, label maturity and conservative intrabar logic. Counterfactual gains/losses NEVER change primary account cash/equity and are not "live paper fills." Keep aggregate diagnostics segmented by reason, symbol, strategy, cohort and model/policy version. Distinguish "saved loss" and "missed gain" as counterfactual estimates with uncertainty and costs. If historical data lacks candidate plan, mark NON_EVALUABLE rather than constructing imaginary trades.

Implement resilient bounded backfill for eligible decisions, unique evaluation keys, freshness/cursors, worker observability, and repair of stuck evaluation status. Do not backfill invalid old SKIPs as winners or losers.

### 7.5 Research actual exits and holding durations

Compare fixed horizons 15/30/60 minutes with existing TP/SL, time-stop, and any validated trailing rules. Holding horizon ≠ signal timeframe. Encode long/short path, executable fill, fees, funding, adverse slippage, exchange constraints, partial fills as supported, intrabar TP/SL collisions, missing bars and ambiguous outcome policies. Calculate R using fixed entry-time initial monetary risk, with explicit rules for positions modified later; do not hardcode `0.13 R` or assume fee percent directly equals R.

Every candidate exit policy has a versioned experiment, common matched scenario, resource cap, full cost assumptions, and no change to an open position's original exit contract at champion promotion. If no policy improves net outcomes credibly, retain existing validated policy.

### 7.6 Pattern discovery and research campaigns

Search for validated conditional effects in trend, momentum, price extension, volume, volatility, breakout, support/resistance and regime features, with minimum support, controls for data snooping, temporal out-of-sample checks and cost normalization. Pattern discoveries are initially metadata/hypotheses, not entry rules. Use the existing research jobs where possible; unsupported kinds fail explicitly. Log all hypotheses, negative and positive outcomes, exact dataset/window, feature/label versions, model versions and campaign ID. Limit trials, CPU/RAM, concurrency and wall time. Study one family at a time (timeframe, entry, exit, filter) before testing combinations.

### 7.7 Make intelligence visible in API and Vue

Keep existing routing/UI; no extra frontend shell. Correct dashboards to show true grades/uncertainty, signal distributions, experiments, research registry, patterns, skip maturity, chosen timeframe configurations, model versions and shadow/challenger comparisons. Include sample sizes, uncertainty, sources and reason for every `NO_MODEL` or `NOT_PROMOTED`; don't label pure historic backtest results "live". Pagination, time range, account scope and auth remain consistent with Gate 3.

**Gate 5 acceptance:** All seven supported intervals can be fetched/researched/validated in controlled fixtures, scanner is incremental and non-duplicating, A–E/UNRATED classifications are reproducible and separate from risk, meta-labeling has explicit shadow output and paired baseline comparison, valid SKIP counterfactual pipeline works, exit research handles ambiguous outcomes, experiment budgets/research audit exist, and relevant Vue/API pages show honest evidence. **No measured profit improvement is a technical acceptance criterion.**

**Deliverable:** `reports/05-multi-timeframe-signal-and-skip-research.md` with actual commands/test identifiers, data quality checks, experimental results and all limitations.

---

## 8. GATE 6 — AUTONOMOUS PAPER PROMOTION, DRIFT, ROLLBACK AND INDEPENDENT RISK

**Goal:** The system is autonomously permitted to promote validated models and parameter policies only inside isolated virtual trading, and only when precommitted gates pass.

### 8.1 Promotion states and atomic activation

Canonical lifecycle:

`CANDIDATE → RESEARCH_VALIDATED → SHADOW → ISOLATED_PAPER_CHALLENGER → PAPER_CHAMPION` or `REJECTED / RETIRED / SUSPENDED`.

An immutable `PromotionPolicyVersion` must exist *before* evaluation. It defines exact metrics, uncertainty method, minimum effective sample size, comparative opportunity cohort, trading budget and benchmark capital, account-specific risk/fees, expected net advantage, acceptable drawdown/tail risk, minimum prospective horizon, model applicability identity and comparison correction for multiple candidate trials. The active model/policy pointer is swapped transactionally with validation of scope and artifact. Stale concurrent promotion must fail via optimistic concurrency/row lock and unique active-champion identity. Record who/what decided, evidence run IDs, SHA and reason; expose rollback. Production/source-code deployment is NOT authorized by a trading-promotion event.

**Model promotion does not retroactively change past decisions or existing positions.** Previous-version positions retain their original TP/SL/exit conditions and remain in the same primary account's risk calculation. Only new proposals use the promoted artifact. Suspended strategy may still need existing position management.

### 8.2 Initial conservative numeric promotion policy

Implement the following explicit **versioned initial default**, not an immutable universal theorem. The owner controls policy revisions; ML/agents cannot lower thresholds autonomously:

- >= **300 matured training examples** with relevant positive/negative outcomes, with additional sample-quality checks.
- >= **100 effective non-overlapping OOS labeled opportunities** on a documented matched comparison. Effective N <= raw N; account for serial dependence/regime clustering.
- >= **100 fresh prospective SHADOW opportunities** for the candidate in an unbiased incoming decision cohort.
- Isolated paper challenger observed for >= **14 calendar days** AND >= **50 matured prospective candidate opportunities** (both conditions), with full exposure/turnover and risk history.
- Incumbent and challenger matched over dates, starting benchmark virtual capital and *incoming opportunity set*, with equivalent fee/funding/slippage assumptions and risk ceiling. Primary metric is **paired improvement in net account-level risk-adjusted outcome**; report net account return and R, drawdown, downside risk, turnover, effective sample and uncertainty.
- Adjust for multiple concurrent candidates; require a **strictly positive multiplicity-adjusted 95% lower confidence bound for incremental primary metric**, computed by a defensible block bootstrap or precommitted alternative for time-dependent observations.
- Challenger drawdown cannot exceed champion drawdown by **more than 2 percentage points**, may never breach hard account ceilings, and may not have unresolved data/financial/reconciliation defects.
- Reject unknown/unsupported jobs, absent lineage, in-sample-only wins, manually uploaded metrics, insufficient prospective observations, missing artifact, stale parameters, or model performance measured on a mismatched methodology.

These defaults intentionally favor caution and may result in **zero active promoted ML models** for long periods, especially low-frequency strategies. That is **not** permission to create synthetic signals or relax statistics. Reporting must distinguish `NOT_TRAINABLE`, `INSUFFICIENT_DATA`, `TRAINED_SHADOW`, `CHALLENGER`, `PROMOTED`, and `REJECTED`, each with reason. To evaluate the core code promptly, use carefully isolated deterministic `ISOLATED_TEST` fixtures that demonstrate both a candidate which can pass and one which must fail; never pass fixture statistics off as market-derived validation.

The chosen primary metric needs a **concrete, implementable definition** in a locked config/schema, before any candidate run. A candidate without a valid comparator, meaningful exposure or appropriate matching returns `COMPARISON_INVALID`. Confidence intervals alone cannot guarantee non-overfitting; holdout isolation and fresh prospective challenger runs remain mandatory.

### 8.3 Safe fallback, drift and suspension

Guard candidate and active model inference by checksum, feature schema and applicability. Missing/corrupt model, invalid probability, timeout, NaN/Inf, stale bar or ledger mismatch must not auto-convert validated model gating into unvalidated baseline execution. Support explicit *previously validated* fallback configuration only. Independent Go research outage does not halt working champions if not a runtime dependency. Keep existing positions under their entry-time execution rules even when entries pause.

Monitor forward Brier/logloss calibration, model-feature drift, costs-adjusted expectancy, opportunity count and statistically supported risk deterioration. Use minimum evidence and hysteresis to prevent switching on random noise. On proven rule breach automatically suspend new entries or roll back to last compatible approved model; if no compatible model remains, pause that scope. Preserve incident/recovery audit.

### 8.4 Single account Risk Gateway and no cross-account capital

All candidate and champion PRIMARY entries execute through the same database-backed Risk Gateway from Gate 2. Owner-defined maximum risk per trade, daily/weekly loss, account drawdown, account gross exposure, leverage, symbol correlation and open-position count are strict *per primary account* ceilings. Research may choose more conservative settings but not raise owner ceilings. A global operational integrity pause may prevent new entries across all accounts; it does NOT pool cash or risk. Keep independent experimental accounts out of PRIMARY PnL.

### 8.5 Evidence of real effect

A promoted candidate is not active merely because a DB row says `PROMOTED`. Demonstrate subsequent new eligible *strategy evaluations* load the promoted model/version and record its prediction and filter decision through the authoritative pathway. Controlled tests must demonstrate one that changes a paper entry outcome, and a rollback that changes subsequent outcomes back; any primary fill remains only when authorized by risk. Confirm retired models never govern new decisions and existing positions retain their original exit version.

**Gate 6 acceptance:** Precommitted policy, genuine promotion evidence, atomic version switch and rollback, model applicability, invalid-candidate rejection, inference fail-safe, independent strategy accounts, and isolated prospective validation with explicit user-approved owner ceilings. Prove positive/negative transitions on fixtures; actual market superior challenger is not required.

**Deliverable:** `reports/06-autonomous-promotion-and-risk.md` with state machine, locked policy, artifact hashes, tests, example eligible/ineligible decisions, rollback and operational safety evidence.

---

## 9. GATE 7 — FULL AUTOMATED INTEGRATION, BROWSER E2E, INDEPENDENT AUDIT

**Goal:** Verify ALL functionality required in this document with the existing architecture, real PostgreSQL/Redis integration and deterministic full pipelines. No disabling lint/tests to get green output.

### 9.1 Commands and test topology

Adapt to actual repo scripts and environments; examples:

```bash
php artisan about --no-ansi
php artisan route:list --json
php artisan schedule:list
php artisan test --no-ansi
vendor/bin/pint --test
(cd research-go && go test -count=1 ./...)
(cd frontend && npm ci && npm run build)
```

If the actual repository requires an explicit bootstrap, test database, Go executable or more build targets, use the current verified procedure, not guessed invocations. Check the existing `tests/bootstrap.php` reference, CI PHP extensions and PHPUnit configuration instead of modifying tests to hide missing bootstrap. Run available `vue-tsc`, TypeScript, Vitest, Playwright/E2E, static analyzers and dedicated integration suites; do not invent scripts. Pin compatible toolchain versions where needed.

Use an ISOLATED integration stack with PostgreSQL and Redis, no connection to prod and no shared ECONOM resources. Validate migrations, uniqueness, job consumers, scheduler, Go protocol, rollback-safe schemas and restart/crash recovery. Keep production tests read-only. Use real HTTP requests against an isolated running app for endpoint business assertions, not only mocked controller tests.

### 9.2 Required adversarial verification

- Closing candle and actual `available_at` enforcement; delayed and out-of-order market source.
- 8 primary accounts, existing IDs/history, BTC/ETH shared capital only within a strategy, concurrent admission and other account independence.
- Duplicate dispatch, retry and app crash after risk reservation but before fill and after ledger booking.
- One-way existing-position conflicts, close/reduce/hold, model upgrade while positions remain open.
- Long/short exits, partial fills where supported, stop/TP collision, time exits, fee/funding and decimal accuracy.
- Independent reconcilers catch deliberate malformed fixtures, no false HEALTHY, status age limits.
- Redis deserialization cold/warm/expiration, Dashboard cold and warm performance, actual pattern symbol validation.
- Unsupported Go job returns FAILED, not fake SUCCEEDED; real training changes coefficients and predictions.
- No train/validation/holdout contamination; no look-ahead in 4h and daily features; OOS candidates below gates rejected.
- A–E grades vs eligibility; skip counterfactuals without candidate plan non-evaluable; R denominator correct.
- ML artifact corrupted/inapplicable or data stale blocks new dependent trades with honest reason.
- Production real-order pathways impossible via API, queue, client and credentials; test negative network calls using mocks/firewall.
- All API routes checked for authorization, input validation, expected schema; browser navigation and account scope.
- Load/resource protection, queues, Go workers, scanner and UI under bounded representative datasets.

### 9.3 Independent signoff

A separate QA/quant reviewer challenges developer claims, reruns evidence, inspects model/candidate lineage and tests the negative path. QA signoff is a real independent review result; if native subagents unavailable, report alternative manual separation and do not fabricate independent agent approvals. End-to-end acceptance is tied to actual executed commands/logs and result IDs, not a narrative assertion.

### 9.4 Performance acceptance and measurement

Record environment, database volume, cold/warm state, iterations, rate-limited concurrency, HTTP authorization, query counts and timings, and payload sizes. Engineering target warm Dashboard p95 < 1000ms and cold p95 < 3000ms on representative bounded requests. Treat these as *measurable targets*; if missing representative environment, status `PERFORMANCE_UNVERIFIED`. No artificial cache warming and no claim p95 from one sample. Optimize DB indices/aggregates/query bounds based on traces, not guesswork.

**Gate 7 acceptance:** Tests, formatter, Go/frontend build and isolated end-to-end scenarios pass with evidence. Any failed P0 invariant means `NOT_READY`; optional missing browser capabilities must be `UNVERIFIED` and cannot be silently passed. All mandatory functionality in this document has traceable tests and source implementation.

**Deliverable:** `reports/07-independent-qa-and-performance.md` with pass/fail/block matrix, actual commands, timings and reviewer evidence.

---

## 10. GATE 8 — RELEASE, EXACT SHA AND REMOTE READ-ONLY VERIFICATION

**Goal:** Distinguish valid local code from what is actually deployed. You has no authorized SSH or production Docker/DB control and cannot auto-deploy without owner permission.

### 10.1 Deploy handoff

Prepare backward/forward compatible DB migrations where required, image tags + immutable digest, secrets handling, reversible rollout/rollback plan, preflight route/schema checks, and manual release instructions using existing GitHub Actions/GHCR/Compose as found. Preserve full paper history and caches of other projects. Never run blanket Docker prune, database truncate, destructive migrations or production cache flush; do not modify ECONOM/econom-group and any potentially shared resources. Account for mixed-version workers during rolling deployment; incompatible processes must fail safely.

The owner performs or explicitly authorizes deployment. Until then the honest status is `CI_VERIFIED`/`DEPLOYED_UNVERIFIED`, not live fixed.

### 10.2 Safe remote audit

Derive actual routes with `php artisan route:list`; reuse existing `remote-curl-audit.sh` and endpoint-coverage inventory where present. Previous host reportedly `https://bybit.144.91.122.100.nip.io`: verify that it is still the correct, authorized target. Remote requests are only authenticated read-only GET/HEAD. Never issue production POST/PUT/PATCH/DELETE, login brute force, aggressive load testing or state-changing requests disguised as GET. Honor 401/403 when credentials unavailable: `ACCESS_BLOCKED`, not a false API failure. Avoid leaking auth tokens or secret response bodies into reports.

For every authorized safe route sample: HTTP status, JSON/schema validity, expected account and time scoping, result provenance, error/warning behavior, pagination, freshness, payload, latency and a release/build hash when accessible. Validate daily PnL/ledger identities, 8×2 evaluation coverage, counterfactual status, learning run and promotion evidence, and retry if only a known short-lived transient is observed (document every attempt). A successful empty JSON document or 200 is not proof of autonomous processing.

Watch at least **two consecutive new forward 15m decision intervals** from the newly deployed build via visible timestamps or sampled safe diagnostics. Do not wait for a BUY/SELL; an honest SKIP/NO_SETUP is valid when the full evaluation ran. Cold/warm performance reports require bounded samples and honest comparability with prior audits. Report which internal assertions cannot be verified from authorized HTTP alone.

### 10.3 Separate release statuses

Use only: `NOT_STARTED`, `FAILED`, `BLOCKED`, `ACCESS_BLOCKED`, `FIXED_LOCAL`, `LOCAL_FUNCTIONAL_VERIFIED`, `CI_VERIFIED`, `DEPLOYED_UNVERIFIED`, `LIVE_HTTP_VERIFIED`, `PERFORMANCE_UNVERIFIED`, `LIVE_FORWARD_UNVERIFIED`.

Do not represent an old remote running version as proof of the current Git commit. When deployment is not possible, deliver a tested release candidate plus unambiguous owner deployment steps and the report `PRODUCTION_NOT_VERIFIED`.

**Gate 8 acceptance:** Authorized deployed SHA matches the tested artifact, safe live HTTP endpoints are validated by business assertions and monitored for forward freshness/continuity. Without authorization/reachability, this gate remains accurately unverified; local and CI outputs can still be delivered.

**Deliverable:** `reports/08-release-and-live-http-audit.md`, safe curl script, source-derived endpoint inventory and summarized evidence.

---

## 11. MASTER ACCEPTANCE MATRIX — ALL REQUIRED FUNCTIONALITY

Each requirement has an owner, running automated test(s), proof path, status and blockers. **No checkbox is complete without observable evidence.** At a minimum use this matrix, expanding source-specific IDs where necessary.

| ID | Requirement | Concrete proof | Target gate |
|---|---|---|---|
| A01 | Build and isolated environment boot | SHA, Postgres/Redis/Go/Laravel/Vue start, commands/logs | G1 |
| A02 | Scheduler/queues and Go protocol | Real job persisted with meaningful output/status | G1 |
| A03 | BTC/ETH closed-market data | Freshness, gaps, `available_at`, no incomplete input | G1 |
| A04 | Strategy×symbol evaluation | 8×2 explicit enabled/disabled/blocked/evaluated statuses per cycle | G1 |
| A05 | API 500 and Redis fixes | Reproduction/regression, auth/schema tests | G1 |
| A06 | Dashboard/Market request latency | Measured repeat p50/p95 cold/warm, bounded queries | G1/G3/G7 |
| A07 | Exactly eight primary accounts | DB uniqueness, unchanged legacy IDs/history/capital | G2 |
| A08 | Correct trade decisions / conflict policy | BUY, SELL, SKIP, NO_SETUP, reverse conflict fixtures | G2 |
| A09 | Atomic account-local risk and retries | Concurrent BTC/ETH reservation, idempotent ledger | G2 |
| A10 | Actual forward paper fills only | Causal simulated fills, no retroactive trading, no real orders | G2 |
| A11 | Financial ledger and reconciliation | Fee/funding/cash/equity/R vectors; deliberate corruption detected | G2 |
| A12 | Account-scoped UI and Daily Performance | Same source data through API/Vue, no false zeros | G3 |
| A13 | Health + forward cycle evidence | Honest last-success/age and 2 new 15m intervals | G3/G8 |
| A14 | Fail-safe operational behavior | ML/Redis/Go/data/ledger failures tested | G3/G6 |
| A15 | Point-in-time Feature Store and labels | Timestamp/leakage/label maturity tests | G4 |
| A16 | Real trained ML coefficients/artifact | Saved hash, trained parameters, inference parity | G4 |
| A17 | Honest model jobs and autonomous training | Scheduler/job outputs, no fake SUCCEEDED, low-data states | G4 |
| A18 | Reproducible independent model validation | Chronology, purge, OOS/calibration, trial ledger | G4/G6 |
| A19 | Adaptive 1m–1d research timeframes | Valid closed bars; interval tests; causal TF configurations | G5 |
| A20 | Single efficient Market Scanner | Incremental updates, idempotent non-duplicate decisions | G5 |
| A21 | Signal grade A–E/UNRATED | Shadow classification, grade-vs-risk split, calibration | G5 |
| A22 | Meta-labeling | Paired base/advisor/grade comparisons; no direction flip | G5 |
| A23 | Valid SKIP counterfactuals | Original plan needed, no fake BUY, true R/maturity | G5 |
| A24 | Exit-horizon and pattern research | Reproducible 15/30/60m experiments, limits, negative results | G5 |
| A25 | Full automatic guarded promotion | Precommitted numeric policy and matched comparator | G6 |
| A26 | Paper challenger actually produces next decisions | Prospective version-scoped inference and isolated accounting | G6 |
| A27 | Champion switch and rollback | Atomic pointer, existing position policies unchanged | G6 |
| A28 | Real-only safety | Tests block all real order API paths and credentials | G1–G8 |
| A29 | Full CI + independent QA | Executed PHP, Go, Vue, integrated and browser tests | G7 |
| A30 | Exact release and remote audit | SHA, source-derived route GET audit, auth and business checks | G8 |
| A31 | Complete experiment/evidence provenance | Link decision→snapshot→label→train→artifact→approval | G4–G6 |
| A32 | No destructive cleanup or historical fabrication | Data integrity checks, migration rehearsal and audit | All |

**Meaning of acceptance:**

- **`M1_CORE_WORKS_LOCAL`**: A01–A05, A07–A18, A28, A32 PASS in isolated integration; A06/A12–A14 measured and passed if relevant. A13 live two-cycle evidence is a separate live-only subcriterion, not pretended locally. A fixture-proven positive and negative model activation/rollback is mandatory, but no live model success must be fabricated.
- **`M2_FULL_SCOPE_LOCAL`**: All M1 requirements plus A19–A27, A29, A31 implemented and verified, and production handoff docs complete. A30 can remain pending authorized deployment only if clearly labeled, not silently PASS.
- **`RELEASE_VERIFIED_LIVE`**: The exact tested commit is deployed and A13 forward live observation and A30 read-only HTTP evidence pass with compatible source data. A profitability claim is never implied by this status.

Every acceptance row must cite file/test/command/result and choose `PASS`, `FAIL`, `BLOCKED`, `NOT_APPLICABLE_WITH_REASON`, or `UNVERIFIED`; do not downgrade a mandatory requirement to not applicable without explaining the actual architecture.

---

## 12. ARTIFACT INTAKE, BASELINE AUDITS AND NO-HINDSIGHT REQUIREMENTS

The owner may provide additional source/audit artifacts. Treat these as **evidence inputs**, never runtime truth. When present, parse every archive manifest, checksum, collection window, methodology note and significant anomaly. Cross-reference the corresponding current source and actual running data; produce an **Artifact Evidence Register** with provenance, inspected file members, date/window, limitations, exact reproduced issue, latest code status and test linked to its fix.

Historical accessible evidence includes:

- `vector-audit-1d-*.zip` with a 2026-10-17→2026-10-18 UTC window, `BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ`, potentially mutable position snapshots. The 1-day archived snapshot cannot prove later production health.
- `bybit-research-quant-audit*.zip` source snapshots with limited `audit_data/summary.json` showing **8 paper accounts**, **1495 signals**, **38 positions** and at that time no learning snapshots/artifacts/reconciliation/counterfactual outcomes. This does NOT mean the current release still has zero models or SKIP evaluations. In a later remote audit, 500 SKIP evaluations were reportedly observed, but that statement likewise needs fresh version-linked verification.
- `bybit_quant_full_code_audit_2026-10-18.md` describing possible old-code defects in candidate metrics, Go `MODEL_TRAIN`, scheduler, hardcoded weights, candidate paper wiring, SKIP causality, Health and Market. Investigate current source, don't blindly reuse old line numbers.
- Earlier reports of `/research/patterns/latest-matches` missing-symbol HTTP 500, Redis deserialization failures and cold Dashboard latency over 27s. All require current confirmation.

**If additional attachments claimed in the current exchange are not actually accessible**, explicitly list them as `ARTIFACT_NOT_AVAILABLE`, continue with the visible artifacts and baseline code, and do not pretend to have inspected them. Integrate them only when available. A missing supplementary attachment must not block already testable implementation.

---

## 13. DELIVERABLES, COMMIT GATES AND FINAL REPORT

Create versioned evidence in existing report structure (avoid duplicating working tools):

1. `reports/00-baseline.md` — source SHA, source/runtime map, inventories, evidence register and defect ledger.
2. `reports/01-runtime-and-reliability.md` — real boot, data/queues, 500/root causes and latency.
3. `reports/02-paper-ledger-and-risk.md` — eight account identities, risk, accounting and deterministic E2E.
4. `reports/03-forward-ui-and-resilience.md` — API/UI, Health, freshness and fault injection.
5. `reports/04-training-evidence-and-validity.md` — features, labels, actual model, promotion preconditions.
6. `reports/05-multi-timeframe-signal-and-skip-research.md` — complete quantitative intelligence tests and outputs.
7. `reports/06-autonomous-promotion-and-risk.md` — promotions, challenger effect, rollback and fail-safe.
8. `reports/07-independent-qa-and-performance.md` — suites, adversarial tests, metrics and reviewer findings.
9. `reports/08-release-and-live-http-audit.md` — deployment handoff, exact SHA and authorized remote results.
10. `reports/final-acceptance.md` — A01–A32 status and evidence table plus milestone summary.

For every gate: add targeted tests, run the actual executable commands, collect red→green proof for reproduced defects, integrate API/Go/Vue contracts, have QA assess adverse cases, and provide a checkpoint commit if the user's workflow allows. Never claim a non-executed command passed. Keep edits focused, preserve uncommitted owner changes, protect historical data, and provide ready-to-run deployment/rollback steps.

**Required final Russian report:** summary of changes and unimplemented items; current/target/deployed SHA; exact migrated records and preserved accounts; all eight primary accounts and enabled BTC/ETH coverage; source file list; model training artifacts and lineage; real API/HTTP and browser results; observed forward intervals; all test commands with PASS/FAIL/SKIP; performance sample size and comparison; verified risk/financial invariants; A01–A32 with file/test/output evidence; failed/blocked and not-verifiable parts; release plan and rollback; explicit statement that REAL ORDERS WERE NEVER PLACED.

If a gate is blocked: state the precise reproducible blocker, smallest safe fix and exact input/permission needed. Do NOT stop all independent work because one remote endpoint is inaccessible. Do not substitute status reports for implementation. Do not claim full scope if Gate 5 items are merely deferred.

---

## 14. FINAL DIRECTIVE

**Implement the existing project, not another plan.** Start by inspecting source, available audit attachments, tests, routes, scheduler and history. Reproduce defects where possible. Allocate subagents with non-conflicting code ownership, fix the roots, and complete Gates 1–8 in order without abandoning any mandatory intelligence requirements. Keep M1 continuously working while delivering M2. Do not add unnecessary ninth strategies or infrastructure. Report actual evidence and refuse to fake trading performance or promotion.

**Authorized autonomous scope:** validated paper-trading decisions, training, research hypotheses, safe parameter changes, promotion, suspension and rollback. **Never:** exchange order execution, self-deployed source changes, raising immutable owner risk ceilings, destructive cleanup or touching ECONOM and possibly shared resources.

The final answer must clearly distinguish `M1_CORE_WORKS_LOCAL`, `M2_FULL_SCOPE_LOCAL`, and `RELEASE_VERIFIED_LIVE`, with unresolved checks reported honestly. Every change must be implemented and verifiable, not merely described.
