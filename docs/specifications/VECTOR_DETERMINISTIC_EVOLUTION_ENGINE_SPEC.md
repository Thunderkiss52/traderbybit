# Deterministic evolution engine specification

Status: proposed extension of Candidate Lab, gated by Phase 0 and SDK identities. No new engine is implemented here.

## Existing foundations and gaps

Reuse `app/Research/Application/StrategyEvolutionService.php` (eight mutation families, bounded parameter diff, seed and configuration hash), `EvaluateStrategyCandidateJob.php` under `app/Research/Jobs` (backtest linkage and attempts), `LearningCandidateStrategyRecord.php` under `app/Research/Infrastructure/Persistence`, `ResearchJobService.php` and `PromotionPolicyService.php` under `app/Research/Application`. Reuse `frontend/src/pages/ResearchLearningPage.vue` and existing `/api/v1/research/learning/candidates` routes.

Current mutation uses global `mt_srand`, floating parameter arithmetic and mutable search metadata; current evaluation derives a rolling date range from the clock. These are extension points for frozen input identity, local seeded RNG, exact parameter grids and durable attempt records. Do not claim the present implementation already meets the new repeatability contract. Five additional PRIMARY families need reviewed parameter schemas before optimization. `research-go/internal/contracts/job.go` already supplies job states, seeds, dataset hashes and limits; do not invent a competing scheduler.

## Experiment manifest and candidate lineage

Before scheduling, persist immutable manifest fields: experiment/family ID; parent strategy ID/version/source hash; all parent candidate hashes for crossover; candidate version/config hash and parameter diff; algorithm/version/RNG version/seed; ordered search space and trial budget; exchange-qualified symbols; dataset ID/hash, available-time cutoff and coverage; chronological train/validation/sealed-test windows; purge/embargo; cost, funding, instrument and simulation versions; regime classifier version; objective definitions; all acceptance thresholds; statistical family/alpha allocation; job limits; actor, creation time and manifest hash.

Every result references this manifest, job/attempt IDs, artifact checksum, compiler/runtime versions, metrics with sample sizes, completion state, rejection reasons, governance decision and prospective PAPER cohort. No result without input identity is promotable. Deduplicate identical manifest+parameter+stage keys; count all attempted hypotheses in family selection accounting, including failed and abandoned trials. Retries of identical work are attempts, not independent hypotheses.

Proposed additive tables: `research_experiment_manifests`, `research_candidate_events`, `research_validation_evidence`, `research_trial_registry`; extend existing candidate/job tables with manifest references and fenced attempt generation. Reuse existing dataset/artifact storage where possible after migration inventory. Immutable content and append-only events need database constraints; mutable projections are reconstructable. Do not copy financial tables into a competing ledger.

## Algorithms and bounded sequencing

| Stage | Algorithm | Constraints / rationale |
|---|---|---|
| First | Exact-grid mutation, grid enumeration, seeded random search | Reuse current spaces; integer grid indices avoid floating off-grid values; grid only if combinations fit budget |
| Later | Bayesian search | Only after measured benefit versus equal-budget random baseline; fixed surrogate/version, sequential update order, bounded memory; no GPU |
| Later | Evolutionary/genetic multi-objective search | Fixed population/generation cap and selection order, reproducible RNG; retain all rejected trials |
| Later | Compatible crossover | Same schema, family, execution model and parameter dependencies; record both parents; reject invalid cross-field combinations |
| Later | Regime-specific/WFO reoptimization | Regime labels fitted using training data only; rolling training windows; each reoptimization is a new immutable experiment |
| Deferred | Rule composition | Approved typed AST grammar only; maximum nodes/depth/lookback, no loops, arbitrary code or runtime imports |

Initial cap: 100 attempted configurations per experiment, one compute job globally, at most 8 active candidates and 2 per family. These are ceilings, not minimum search targets. Pin trial ordering independent of worker completion order. Timeout, exhausted budget or inadequate history produces an explicit terminal/incomplete outcome, never a substituted best-so-far acceptance.

## Validation pipeline and selection

PRIMARY version → candidate generation → schema validation → training backtest → validation selection → sealed out-of-sample test → walk-forward evidence → robustness/cost stress → separately admitted prospective research PAPER → governance → controlled PRIMARY PAPER rollout or rejection.

Design chronological outer WFO folds before search; optimize only within each fold's training/validation ranges. The final sealed test is not reused for tuning after inspection: further research needs new untouched data and a new experiment. Purge overlapping outcome horizons and embargo at least the maximum relevant label/holding horizon across partitions; preserve causal warm-up context without permitting future observations. Cross-symbol joins use available-at times. Point-in-time instrument universes include delistings when claiming broad universe results; otherwise label fixed-symbol scope and make no universe-wide claims.

Record net PnL, drawdown, PF, net expectancy and confidence bounds, trades and effective sample size, regime coverage, parameter-neighborhood stability, cost/funding/slippage sensitivity and fold dispersion. Financial formulas come from the [metric registry specification](VECTOR_STRATEGY_ANALYTICS_AND_AUDIT_SPEC.md). Hard gates precede Pareto ranking: incomplete funding/coverage, invalid provenance, nonpositive cost-adjusted expectancy, confidence failure, risk ceiling breach or insufficient sample → reject or insufficient evidence. Rank surviving candidates on expectancy lower bound, drawdown, stable fold outcomes and sensitivity; deterministic tie-break by manifest hash. No single historical-PnL winner automatically advances.

Reuse `PromotionPolicyService::computeMultiplicityAdjustedLcb` and its HAC treatment, with a preregistered family definition and actual cumulative trial count rather than a hardcoded optimistic count. Familywise error budget defaults to a proposed 0.05 per registered family; allocate across sequential rounds before execution, never reset on a renamed experiment. Validate statistical implementation with null simulations and correlated returns. Block bootstrap contiguous return/trade blocks for dependence-aware confidence and Monte Carlo, not naive IID shuffling alone. Monte Carlo simulates uncertainty/stress, not additional independent observed trades. Report all tested candidates, not only survivors. Explicitly test leakage controls, matured labels, survivorship scope and shared-data overlap.

## Durable candidate state machine

Keep three separate axes: immutable candidate identity; current research stage; governance/admission state. Existing `RESEARCH_ONLY`, `PAPER_ELIGIBLE`, `REJECTED` remain compatibility projections, not synonyms for job completion.

| State | Allowed next state | Transition evidence |
|---|---|---|
| GENERATED | VALIDATING_SCHEMA, CANCELLED | Registered manifest and budget reservation |
| VALIDATING_SCHEMA | BACKTEST_QUEUED, REJECTED | Schema/requirements result |
| BACKTEST_QUEUED | BACKTEST_RUNNING, CANCELLED, EXPIRED | Valid attempt lease |
| BACKTEST_RUNNING | OOS_PENDING, RETRY_WAIT, FAILED, REJECTED, CANCELLED | Complete result checksum or typed failure |
| OOS_PENDING | WFO_PENDING, REJECTED, INSUFFICIENT_EVIDENCE | Frozen OOS evidence |
| WFO_PENDING | ROBUSTNESS_PENDING, REJECTED, INSUFFICIENT_EVIDENCE | All required folds terminal |
| ROBUSTNESS_PENDING | FORWARD_ADMISSION_PENDING, REJECTED, INSUFFICIENT_EVIDENCE | Stress and statistical gates |
| FORWARD_ADMISSION_PENDING | FORWARD_RUNNING, REJECTED | Explicit authorization for isolated research PAPER cohort |
| FORWARD_RUNNING | GOVERNANCE_PENDING, HALTED, INSUFFICIENT_EVIDENCE | Mature candidate-bound prospective observations |
| GOVERNANCE_PENDING | PAPER_ELIGIBLE, REJECTED | Immutable policy decision |
| PAPER_ELIGIBLE | ROLLOUT_PENDING, REVOKED | Explicit operator decision |
| ROLLOUT_PENDING | PAPER_ACTIVE, HALTED | Verified account/version binding |
| PAPER_ACTIVE | HALTED, ROLLBACK_PENDING, RETIRED | Monitoring or operator action |
| ROLLBACK_PENDING | ROLLED_BACK, HALTED | Verified restored admission binding and audit |
| RETRY_WAIT | Prior queued stage, FAILED, CANCELLED | Same manifest, bounded attempt count |

Terminal rejection/failure/cancellation is immutable; renewed evaluation creates a linked new run, not erased history. Each OOS/WFO/robustness/forward stage has subordinate existing job states `QUEUED/CLAIMED/RUNNING/SUCCEEDED/FAILED/CANCELLED/EXPIRED/RETRY_WAIT`. Stage success requires every required child, not dispatch success. Incomplete evidence is not negative profitability evidence; show both distinctly.

PostgreSQL compare-and-swap version plus lease token fences stale workers. Reserve trial budget and outbox entry in one transaction. Retry transient transport/worker failures up to three attempts with bounded exponential backoff; invalid schema, checksum, forbidden capability and leakage are non-retryable. Worker heartbeat loss permits lease recovery, not duplicate acceptance. Cancel increments generation/revokes lease and prevents late result publication. Poll cancellation within one second of compute chunks; terminate within existing 30-second Go grace period. Recheck kill switch at generation, claim, artifact acceptance and admission. Redact internal errors from UI while retaining correlation IDs.

## Governance and safe rollout

Existing policy defaults in `config/research.php` include 14 shadow days, 50 shadow trades, 20 valid UTC days, ESS 20, 300 train and 100 OOS observations, and nonnegative improvement LCB with no drawdown/concentration degradation. Preserve applicable stricter gates. A proposed minimum forward window is max(14 elapsed days, 20 valid UTC days) plus 50 matured trades and ESS≥20; these floors alone never establish adequate power. Require candidate-bound paired champion comparisons with aligned time/exchange/symbol populations, dependence-adjusted intervals and predeclared economically meaningful effect. Insufficient power extends evaluation; never lower thresholds to increase admissions.

Research PAPER admission creates a separate approved cohort and does not replace PRIMARY. For PRIMARY rollout, record actor, policy/hash, evidence hashes, expected account/version and effective boundary; recheck them under lock before activation. No retrospective fills. Open positions retain their original version and exit/cost policy. Stop new candidate entries on kill switch, stale data, reconciliation failure, risk violation, evidence revocation or sustained predefined degradation. Continue safety servicing of open positions according to existing risk/lifecycle rules.

Rollback restores the prior admission/version binding for future entries only, after verifying its artifact and eligibility; it never restores balances or deletes executions. If restoration fails, remain halted and record failure. `PromotionPolicyService::rollbackCandidate` currently refuses promoted-account rollback; automatic promotion remains disabled until this path, crash recovery and audit are accepted. Operator overrides require reason/actor and cannot bypass risk/provenance. No automatic real-money admission.

## Tests, performance and delivery

Proposed files extend current services with `app/Research/Application/ExperimentManifestService.php`, `CandidateTransitionService.php`, `ResearchBudgetService.php` and approved generator classes under `app/Research/Domain/Evolution`. UI adds stage/attempt progress, lineage, trial population, missing evidence and cancellation to Candidate Lab. Existing validate/evaluate/promote routes retain explicit compatibility responses; new manifest endpoints must use authentication, authorization, idempotency keys, body size limits and 409 conflicts.

Tests: deterministic seeds across restart/order changes; exact bounds; duplicate scheduling and lease races on isolated PostgreSQL; kill-switch races; retry budget; artifact tampering; sealed-test contamination; null-data multiplicity controls; missing funding; PHP/Go golden parity; operator admission/rollback crash matrix; all 26 PRIMARY histories unchanged. E2E follows candidate generation to rejection and separately to synthetic PAPER admission, labelled synthetic. Synthetic evidence cannot satisfy real prospective gates.

Accept at most 512 MiB RSS/1 CPU/3600 seconds per initial job, bounded 100 trials, cancellation ≤30 seconds and no >5% PAPER P95 regression versus Phase 0 under mixed load. Unknown telemetry pauses research. Preserve manifests/results on restart. Storage retention and remote-worker path follow the [architecture resource envelope](../architecture/VECTOR_STRATEGY_PLATFORM_ARCHITECTURE.md). No new containers by default. Bayesian/genetic/grammar modes ship only as separately tested later increments.
