# VECTOR post-remediation implementation roadmap

Status: implementation specification, NOT authorization to start. Prepared 2026-10-27 from clean source HEAD `a12bac4a80f2af3baef00611a0ce6365721f165f`. No production verification or feature implementation in this documentation cycle. This replaces the task-text placeholder formerly at this path; the architecture-directory duplicate now links here.

## Program gates and dependencies

Phase 0 → Phase 1 → Phase 2 → Phase 3 → Phase 4. Phase 5 follows Phase 4 for scheduling purposes and requires Phase 1 identity, Phase 2 metric and Phase 3 dataset contracts. Phase 6 is documentation-only and may be reviewed after Phase 1; it grants no external execution implementation authority. Minimal exchange qualification is required in Phases 1–4 using current adapters; full adapter standardization must not delay correctness or assume unavailable capabilities.

Each future assignment names one vertical increment, allowed files, baseline SHA, acceptance evidence and stop condition. Complete three meaningful iterations for substantial changes: focused contract/fix tests, integration/concurrency/performance tests, then full applicable regression/build review. Do not mix new features into remediation. No arbitrary deadlines or effort estimates are assigned.

All performance limits below are proposed acceptance limits, not measured results, except explicitly cited existing gates. Record machine/runtime/database, fixture size, commit, warm/cold state, repetitions, P50/P95, peak RSS and SQL counts. Use at least 30 measured repetitions after warm-up for comparative latency checks. More than 5% PAPER P95 regression under mixed load blocks optional research admission. Insufficient environment parity means acceptance is pending, not passed.

## Phase 0 — Remediation and baseline readiness

**Objective:** establish a trustworthy accepted baseline without new platform features.

**Current reuse and governing evidence:** October 26 F01–F27 audit (`docs/verification/VECTOR_V26_UI_AND_AUDIT_DATA_INTEGRITY_AUDIT.md`, local reference, not tracked), F remediation report (`docs/verification/VECTOR_V26_F01_F27_REMEDIATION_REPORT.md`, local reference, not tracked), October 27 independent production re-audit (`docs/verification/VECTOR_V26_INDEPENDENT_REAUDIT_POST_F01_F25_20261027.md`, local reference, not tracked), and [I01–I09 report](../verification/VECTOR_V26_I01_I09_PRODUCTION_EVIDENCE_REMEDIATION.md). The last report's section 10 supersedes its earlier F09/F25 partial-status rows for LOCAL functional completion. Older HANDOFF F-numbers denote different findings and must not be merged by number alone.

**Exact remaining acceptance prerequisites at this source review:**

| Area | Recorded state, not a fresh test result | Required closure evidence |
|---|---|---|
| F01–F08, F10–F24 | Latest matrix reports FIXED_VERIFIED | Reconcile each finding to accepted SHA/tests and retained evidence; confirm no regression |
| F09 account selection | Section 10 records local completion, 5 PHP tests/49 assertions and 6 frontend tests | Verify latest source/browser/API/account/aggregate behavior at accepted environment; reconcile obsolete partial row |
| F25 Candidate Lab linkage | Section 10 records local completion, 4 PHP tests/37 assertions and UI lifecycle test | Verify bounded attempts, linked run, fail-closed evidence, kill switch and visible rejection; no automatic promotion |
| F26 / F27 | Matrix reports FIXED_VERIFIED | Retain rejected-promotion audit and pre-risk blocked-account lineage, including concurrency/negative cases |
| I01 / I09 | FIXED_LOCAL_PROD_PENDING | Downstream PAPER completion terminalizes upstream signals/5m candle; dispatch alone does not |
| I02 | FIXED_LOCAL_PROD_PENDING | Rounded-zero TP1 produces no invalid fill |
| I03 / I07 | FIXED_LOCAL_PROD_PENDING | Funding completeness with certified schedules and late closed-position settlement evidence; unknown remains incomplete |
| I04 / I06 | FIXED_LOCAL_PROD_PENDING | Partial account failures remain PARTIAL, retries target failures, provenance exception isolated |
| I05 | FIXED_LOCAL_PROD_PENDING | Binance gaps route to Binance recovery; Bybit history untouched |
| I08 | FIXED_LOCAL_PROD_PENDING | Snapshot freshness never substitutes for underlying mark freshness; flat verified case covered |
| Historical funding | Report describes five late-funding candidate positions and retracts synthetic cash estimates | Operator-directed read-only evidence of exact quantities/rates/marks and explicit disposition; any financial adjustment is a separate authorized task |
| Baseline tests | Section 10 reports 855 PHP passed/38 skipped of 893, 63 frontend passed, build/typecheck, Go and Pint passed | Trace reports to runtime/SHA, explain every relevant skip, rerun applicable acceptance tests in isolated environment; no inference that skipped PostgreSQL coverage passed |
| Resources / performance | Compose/config ceilings only; current deployed overrides/headroom not established | Approved read-only runtime evidence, effective worker-limit inventory and measured PAPER/research baseline |

**Missing capabilities:** not assumed missing code; missing unified, current acceptance evidence across source, isolated PostgreSQL, browser and operational environment. READY_FOR_OPERATOR_REVIEW is not production acceptance. No source review in this cycle closes these items.

**Dependencies:** owner-controlled remediation and explicit permissions for any later production diagnostics/deployment. Documentation does not authorize either. Production gates may only be closed with relevant evidence, not waived silently.

**Engineering tasks/files:** reconcile the existing verification reports; inventory account/exchange/version identities; verify source/API/CSV financial identities, fees/funding/mark coverage, missing intervals and SKIP reasons. Record real sample sizes separately from synthetic tests. Verify research jobs, PHP/Go parity, durable retries, rollback containment and image/build provenance. Keep `config/paper.php` risk thresholds unchanged.

**Database/API/frontend:** no roadmap-driven migration. Test existing remediation migrations on isolated PostgreSQL, existing account selection and Candidate Lab APIs/UI. Historical data repair is not part of baseline acceptance without separate scope.

**Compute/failure/safety:** one bounded research probe only in isolated infrastructure; failure/missing telemetry blocks readiness. Preserve all 26 accounts, financial history and unrelated shared-VPS resources. No runtime command is assumed read-only without source review.

**Tests/performance:** current complete applicable PHP, PostgreSQL concurrency, frontend tests/typecheck/build, PHP lint/Pint and Go tests/vet/race; browser/API/export reconciliation and provenance checks. Reassess existing ingestion gate including 500-row `<50 ms` P95 where required; record rather than inherit historical timings.

**Acceptance/deliverables:** one signed/actor-attributed baseline manifest mapping every F/I finding to evidence, source SHA, environment and disposition; no unresolved acceptance blockers; verified financial/account inventory and resource baseline. **Rollback:** remediation's own authorized recovery plan; never reverse migrations or balances automatically. **Out of scope:** every new platform feature, production changes without authorization, historical replay and funding mutation.

## Phase 1 — Strategy SDK and registry

**Objective:** introduce one new research strategy through logic plus descriptor/schema without edits across unrelated controllers/pages. [SDK specification](../specifications/VECTOR_STRATEGY_SDK_AND_REGISTRY_SPEC.md).

**Reuse:** StrategyInterface, StrategyRegistry, ResearchStrategy, StrategyInput, SignalDecision, existing indicators and 13 family constructors. **Missing:** typed descriptor/schema, source dependency manifest, automated approved registration, generator contract.

**Dependencies:** Phase 0 accepted; immutable identity and existing behavior characterized first.

**Tasks/increments:** (1) parameter validator and golden compatibility adapters for all 13; (2) immutable version manifest/storage and approved factory map integrated with metadata/backtest; (3) generator, docs and research-only registration workflow with UI visibility. Add a test-only example strategy rather than a new PRIMARY allocation.

**Files/database:** extend `app/Strategy/Contracts`, `Domain`, `Application/StrategyRegistry.php`; proposed StrategyManifestService, resources/strategies schemas, strategy:make command, contract tests. Additive strategy_versions/version_events with unique hashes and label conflicts; legacy references remain readable.

**API/UI:** version/schema read endpoint, existing metadata views; authenticated registration only, no UI code execution. **Compute:** pure evaluation, no new services or network. **Failure:** duplicate IDs, missing dependencies, invalid schema, dirty artifacts, source mismatch; reject registration without fallback.

**Safety/audit:** no change to old signal IDs, strategy rules or accounts; append-only version evidence and pinned child strategies. **Tests:** all 13 golden outputs, schema/property tests, generator traversal, PostgreSQL registration race, API contracts and frontend missing-capability states. **Performance:** ≤5% evaluation P95 regression, zero added per-candle DB calls.

**Acceptance/deliverables:** documented eight-step developer workflow, working safe generator and approved manifest registry, all 13 compatibility fixtures unchanged, no account creation. **Rollback:** disable descriptor dispatch for future registrations, retain immutable versions/history. **Out of scope:** new risk rules, automatic admission, rule builder, additional PRIMARY strategies.

## Phase 2 — Unified strategy analytics and audit

**Objective:** one trustworthy financial and lineage view across strategy/version/exchange/account. [Analytics/audit specification](../specifications/VECTOR_STRATEGY_ANALYTICS_AND_AUDIT_SPEC.md).

**Reuse:** MetricRegistry, PerformanceStatisticsService, DailyPerformanceService, comparison/reconciliation/export services and existing pages. **Missing:** unified dimensions/metric DTO, version comparison and complete audit read model with explicit evidence states.

**Dependencies:** Phases 0–1; version identity and certified valuation/funding semantics.

**Tasks/increments:** (1) registry definitions/cohort queries and API/CSV reconciliation fixtures; (2) comparison/equity/calendar/version UI with freshness and request cancellation; (3) audit lineage/drill-down and bounded report exports.

**Files/database:** proposed StrategyAnalyticsQuery and StrategyAuditReadModel under `app/Research/Application`; extend MetricRegistry and existing frontend pages. Add indexed version references/rebuildable read projections only when needed; never rewrite accounting history. **API/UI:** bounded filters, common response envelope, per-metric adequacy and population, report POST plus read-only retrieval.

**Compute:** bounded SQL and existing audit worker, no research engine replacement. **Failures:** stale marks, missing opening equity/funding, partial export, mixed currencies, undefined denominator; null with reasons, no fabricated totals.

**Safety/audit:** independent accounts remain independent; exact ledger identity and no double slippage deduction; stored replay is not recomputation. **Tests:** isolated PostgreSQL aggregation, metric/API/UI/export parity, timezone/DST, partial exits, missing data, browser races, no GET writes. **Performance:** 26-account/90-day P95≤500 ms warm/1 s cold, ≤20 SQL queries; export≤192 MiB/1800 s; PAPER regression≤5%.

**Acceptance/deliverables:** all required metrics/dimensions implemented with definitions, source-linked audit UI, matching hashes/populations across exports; invalid evidence visibly invalid. **Rollback:** disable read projections/views; preserve financial source data and definitions. **Out of scope:** ranking unreliable strategies as profitable, financial repair, fictional shared capital.

## Phase 3 — Deterministic evolution orchestration

**Objective:** bounded reproducible candidate search integrated into Candidate Lab. [Evolution specification](../specifications/VECTOR_DETERMINISTIC_EVOLUTION_ENGINE_SPEC.md).

**Reuse:** StrategyEvolutionService, EvaluateStrategyCandidateJob, ResearchJobService, learning_candidate_strategies and Go job contract. **Missing:** frozen experiment manifests, exact-grid local RNG, complete trial accounting, durable fenced stage orchestration.

**Dependencies:** Phases 1–2; data availability/provenance and metric contracts. Unsupported exchange capability blocks affected experiment.

**Tasks/increments:** (1) frozen dataset/split/cost manifests and trial budget; (2) deterministic mutation/grid/random generators with deduplication and restart; (3) durable attempts, cancellation/backpressure, UI lineage/progress. Bayesian/genetic/crossover/grammar modes are separately approved later increments after equal-budget benchmarks.

**Files/database:** extend current services/jobs and `research-go/internal/contracts/job.go` only compatibly; proposed ExperimentManifestService, CandidateTransitionService, ResearchBudgetService; additive manifest/trial/evidence/event tables and attempt fencing fields. **API/UI:** existing Candidate Lab generate/validate routes extended with manifest reference/idempotency; bounded authenticated experiment creation/cancel commands; no competing lab.

**Compute:** one global optional compute admission, ≤100 trials/experiment, ≤8 active/2 per family, ≤512 MiB/1 CPU/3600 s. Dataset storage quota must be approved before enablement; reuse immutable datasets. **Failures:** worker loss, duplicate dispatch, stale lease, result hash mismatch, disk pressure, kill switch; bounded retries or explicit terminal status, never silent success.

**Safety/audit:** no PAPER account mutations; source-controlled parameter grammar only; old trial evidence immutable. **Tests:** determinism/order/restart, exact bounds, PostgreSQL races/fencing/outbox, cancellation, artifact tampering, trial-budget exhaustion and PHP/Go parity. **Performance:** cancellation≤30 s, no >5% PAPER P95 degradation, bounded RSS/trials proven under interruption.

**Acceptance/deliverables:** one full reproducible candidate-to-validation run and one negative failure/recovery run with manifest/trial/evidence exports; replay identical inputs yields same result hash. **Rollback:** stop new admission, cancel/revoke leases, retain candidates/evidence. **Out of scope:** PRIMARY promotion, LLMs, arbitrary code, extra infrastructure platform.

## Phase 4 — Validation and PAPER governance

**Objective:** statistically justified rejection/admission and verified controlled PAPER rollout/rollback.

**Reuse:** BacktestRunService, ChronologicalSplitter, Go WFO/Monte Carlo, PromotionPolicyService, AutonomyPermissionService, ShadowExperimentService and rejection audit. **Missing:** complete sealed-test/family accounting, candidate-bound prospective evidence and operational version-restoring rollback acceptance.

**Dependencies:** Phase 3 immutable manifests, Phase 2 financial certification, Phase 1 artifact lookup. No operational autonomous promotion until a later explicit policy authorization.

**Tasks/increments:** (1) causal split/OOS/WFO and bootstrap/cost/funding stress evidence; (2) isolated prospective research PAPER admission with minimum duration, ESS and champion comparison; (3) locked operator rollout, halt, rollback and interrupted-transition recovery. Each increment requires negative controls before proceeding.

**Files/database:** extend existing promotion/shadow/degradation services, PAPER eligibility and admission binding only as separately scoped; append immutable policy/evidence/admission events with expected-version compare-and-swap. No historical financial updates. **API/UI:** existing evaluate/promote/rollback endpoints expose verifiable states; operator confirmation binds exact candidate/account/evidence hashes; Candidate Lab shows reasons and monitor state.

**Compute:** existing bounded research workers; Monte Carlo count explicitly pre-budgeted in manifest; no unlimited resampling. **Failures:** insufficient power, leakage, missing funding, expired approval, kill-switch race, rollback crash; reject/hold or remain halted, never label rollback successful without binding verification.

**Safety/audit:** current risk gates unchanged; new entries stop independently of open-position servicing; positions retain entry version. Forward results start at cohort creation and never include historical replay. **Tests:** null hypothesis multiplicity, leakage mutation controls, genuine-versus-synthetic evidence, candidate/champion scope mismatch, PG promotion/kill-switch concurrency and crash at each rollback boundary, account ledger invariants.

**Performance:** same research envelope; policy evaluation P95≤500 ms on bounded evidence summary, no unbounded trade scan in account transaction; ≤5% PAPER regression. **Acceptance/deliverables:** preregistered evidence/policy contracts, prospective minimum window and sample gates met for each actual admission, operational rollback verified with unchanged history. Passing synthetic tests alone cannot authorize a candidate. **Rollback:** halt new entries and restore verified prior binding for future entries; failed restoration stays halted. **Out of scope:** automatic PRIMARY promotion enablement without separate approval, real-money promotion, historical account reset.

## Phase 5 — Multi-exchange SDK

**Objective:** add future public-data adapters without rewriting strategy/research/PAPER domains. [Adapter specification](../specifications/VECTOR_MULTI_EXCHANGE_ADAPTER_SPEC.md).

**Reuse:** ExchangeAdapter/ExchangeRegistry, Bybit legacy market client and Binance public ingestion/context/funding. **Missing:** typed capability discovery, uniform provenance/instrument/schedule DTOs and shared conformance harness; Bybit common-adapter funding/OI/discovery are explicitly incomplete.

**Dependencies:** accepted prior contracts; official API documentation reviewed at implementation time. Phase 4 rollout controls available before any additional account admission.

**Tasks/increments:** (1) capability DTO/harness with honest unsupported states; (2) Bybit/Binance parity adapters and effective instrument/cost/funding metadata; (3) test-only third adapter proving extension boundary and onboarding checklist. Do not activate a real third exchange in this phase.

**Files/database:** `app/Exchanges/Contracts`, registry/reference adapters, proposed Domain DTOs and `tests/Contract/Exchanges`; additive effective metadata/provenance fields only. **API/UI:** capability visibility and unsupported-feature errors in existing exchange views. **Compute:** existing ingestion workers; ≤500 rows/batch, provider budgets and bounded backfills.

**Failures:** delisting, rate limit, schedule change, malformed precision, missing source timestamps, conflicting duplicate; quarantine/degrade/recover without cross-exchange substitution. **Safety/audit:** exchange-qualified every record, public-data methods only, no account provisioning side effects. **Tests:** adapter fixtures, PostgreSQL races, recovery, variable funding, minimum size/fees and all 26 account/history preservation.

**Performance:** ≤5% adapter overhead vs direct fixture path, existing Stage B batch gate where applicable, no unbounded pagination. **Acceptance/deliverables:** explicit capability matrix, two verified reference adapters, portable contract harness and onboarding checklist. **Rollback:** disable new capability/admissions, preserve data and existing position servicing; no down migrations. **Out of scope:** live routing, third-exchange production activation, inverse/spot PAPER semantics without independent tests.

## Phase 6 — External execution readiness documentation

**Objective:** specify responsibility and message boundaries only. [External boundary](../specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md).

**Reuse:** immutable strategy/decision identity and audit correlation. **Missing:** independently approved external security/system design; deliberately no implementation. **Dependencies:** Phase 1 identities; separate future owner program for any real service.

**Tasks/files/deliverables:** refine the boundary document with versioned schema proposal, trust/ownership matrix, revocation/acknowledgment semantics and fake-service test scenarios. **Database/API/frontend:** none. **Compute:** none. **Failures:** duplicate/expired/tampered intent, wrong audience, acknowledgment loss, halt race and callback confusion are specified, not connected to a real service.

**Safety/audit:** no transport, credentials, publisher, endpoint or toggle; real fills cannot mutate PAPER evidence. **Tests:** documentation/schema consistency checks only now; future fake-service security contract tests require a separate assignment. **Performance:** runtime overhead exactly zero because no runtime change; real-service latency targets deferred pending design. **Acceptance:** responsibility separation and independent authorization explicit, current execution path remains absent from this program. **Rollback:** documentation-only revision. **Out of scope:** every real-money implementation or activation.

## Delivery protocol for future assignments

Each phase records changed files, actual commands/exit codes, environment identity, fixtures and hashes, failed/skipped tests, residual risks and commit. Run isolated destructive tests only after resource ownership is established. Local commits do not authorize push; push does not authorize deployment. Deployment remains operator-triggered `.github/workflows/deploy.yml` only. ECONOM/econom-group and unrelated VPS resources remain untouched.

The first feature phase after verified remediation is **Phase 1, increment 1: strategy descriptor/schema contract and all-13 compatibility fixtures**. Until Phase 0 acceptance is complete, the next engineering action is remediation evidence closure, not SDK implementation.

## Documentation-cycle verification

The eight requested specifications, one duplicate-roadmap redirect and three root-reference additions are the only intended commit contents. Markdown local-link existence and fenced-block balance checks passed; Laravel Pint reported `passed`; Git whitespace checks passed. Application tests, backtests, browser checks and production diagnostics were not run for this documentation-only change. Earlier report test counts are attributed historical evidence, not new results. Unrelated untracked `scripts/diagnostics/` content was observed during the session and excluded.
