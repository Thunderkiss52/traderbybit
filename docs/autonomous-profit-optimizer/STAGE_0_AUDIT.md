# APO Stage 0 — checkout audit and remediation baseline

Date: 2026-11-11 UTC. Decision: **NOT_RELEASE_READY**.

This is the Stage 0 deliverable. It does not certify APO implementation,
production readiness, forward PAPER performance, or profitability. Stage 1–6
implementation and the independent final audit remain outstanding. The requested
production goal means preparation for a separately approved release; no push,
deployment, production diagnostics, account activation, or capital change is
authorized by this audit.

## Source identity and custody

- Actual initial HEAD: `9615a620d693bf46d5465619e576db2e1e5b4016`, branch
  `main`, nine commits ahead of the local `origin/main` reference. No remote
  refresh was performed.
- Before the user's second file copy, only `.gitignore` was staged and additional
  audit source files were untracked. After the copy, **56 tracked files** differed
  from HEAD (2,095 insertions, 864 deletions), plus the staged ignore change and
  untracked additions. These are **inherited user changes**, not this stage's
  implementation. They must be reviewed and committed in the appropriate later
  stages; they are not silently included in the Stage 0 documentation commit.
- All five source/test hashes in `AUDIT_CLOSURE/ROUND8/CHANGED_FILES.csv` match the
  working tree. This establishes correspondence with V8, not correctness.
- Both requested inputs were read:
  `docs/agent/VECTOR-V8-RE-AUDIT.md` and
  `docs/agent/VECTOR-CODEX-POST-IMPLEMENTATION-AUDIT.md`. V7's F01–F24 table and the
  earlier closure report were also reviewed. Historical report results are not
  imported as current acceptance.
- An isolated source copy and manifest were created under
  `/tmp/vector-apo-stage0-20261111/`. Retained evidence is under
  `evidence/stage0-20261111/` relative to this document. Exact commands, exit
  codes, durations, source hashes and test results are recorded there.
- Root `AGENTS.md`, `SKILL.md` and completion scoring policy apply. `rtk` was
  unavailable (exit 127); unfiltered commands were used. No agents were delegated.
- The first broad PHP attempt was interrupted because the internal network blocked
  DNS used by mocked adapter validation. The next full run used noncanonical
  database names and a bridge host, exposing fixture prerequisites. Neither run
  certifies the final setup; final results use canonical `bybit_test`, task-owned
  loopback ports, Docker Compose CLI (without a Docker socket), prebuilt Go DSL
  and worker binaries, and a fresh source copy.

## Architecture to reuse

Laravel remains the authoritative writer for PAPER accounts, risk, fills,
funding, journals and orchestration. Go already provides deterministic
backtesting, strategy DSL execution, candidate families, walk-forward evaluation,
Monte Carlo simulation, decimal helpers, datasets and leased research workers.
Vue/Quasar already has Profitability, Learning, Monitoring, strategy comparison,
account sizing and audit views. PostgreSQL stores their durable state; Redis
coordinates queues and locks. Adding a parallel optimizer, ledger, API or Python
runtime would duplicate existing responsibilities.

Existing durable entities include `signals`, `risk_decisions`,
`paper_signal_states`, `paper_accounts`, `virtual_positions`,
`virtual_executions`, `equity_snapshots`, `paper_capital_events`,
`learning_feature_snapshots`, `learning_model_artifacts`,
`learning_candidate_strategies`, `research_jobs`, `research_workers`,
`research_candidate_hypotheses`, `research_challenger_experiments`,
`research_promotion_decisions`, `research_promotion_controls`,
`strategy_optimization_experiments`, `strategy_optimization_candidates`,
`strategy_shadow_subscriptions` and `strategy_shadow_evaluations`. Review the
existing migrations and contracts before proposing additional tables.

The inherited additive migration
`2026_11_11_000001_add_paper_account_block_origin.php` adds nullable block
provenance. NULL deliberately preserves unknown legacy blocks. Its `down()`
removes that provenance; it must never become an automatic production rollback.
Stage 0 adds no application migration and makes no financial data changes.

## Requirement and acceptance mapping

`REQUIREMENT_MATRIX.csv` maps all numbered specification sections/subsections
to existing source, test candidates, remaining gaps and owning stages.
`PRIOR_FINDINGS_RECHECK.csv` separately rechecks all 24 V7 findings without
transferring archive pass claims. Statuses
describe the entire row, not class existence. `IMPLEMENTED_AND_VERIFIED` is
reserved for the exact behavior covered by executed evidence. `test_references`
are discovery references; their existence is not a PASS.

The existing tracker uses a different, older Phase 0–6 scope from APO Stage 0–6.
The two are not interchangeable. Relevant IDs include VEC-P0-001–009,
VEC-P2-001–010, VEC-P3-001–011, VEC-P4-001–010 and VEC-P5-001–008. Its scope,
weights, acceptance criteria and historical evidence are preserved. Baseline
scores are **implementation 0%, verification 0%, product acceptance 0%** because
recorded evidence is stale, unavailable or failed. This is a freshness result,
not a claim that the repository contains no implementation.

## Confirmed gaps and safety dependencies

| ID | Severity | Finding and source evidence | Required closure |
|---|---|---|---|
| APO-001 / F19 | HIGH | `SignalQualityAnalyticsService::analyze()` loads all matching signals and positions with `get()` and materializes rejected/observation IDs. Explorer export is streamed, but overview analytics remain unbounded. | SQL aggregates or bounded streaming with exact decimal reconciliation, bounded cohort dimensions, large-fixture memory/query benchmarks. |
| APO-002 / F20 | HIGH | `config/market_data.php` ingests 5/15/30/60; `ProcessClosedCandleEventJob` dispatches decisions for 15 and fills for 5. Six display rows do not implement six source engines. | Separate source timeframe and evaluation horizon; causal, idempotent generation and outcome maturity tests for 1/3/5/15/30/60. |
| APO-003 / F05/F08 | HIGH | `LearningFeatureStoreService` uses direction-aware `SIGNAL_PROXY_V2` four-hour barrier labels. These are not the requested independent elapsed-time horizon evaluations or actual executed profits. | Immutable, versioned, cost-aware outcome evaluations; separate hypothetical/shadow/actual sources and per-horizon attribution. |
| APO-004 / F06/F13 | HIGH | `EnsureStrategyHistoryJob` still loops over Bybit/Binance and has venue-specific recovery. Other venue readiness is not established by adapter registration. | Capability-aware immutable recovery, warmup, event publication and venue-specific integration fixtures. |
| APO-005 / F07 | HIGH | `ProcessAutonomousLearningJob` discovers active exchanges for baseline/15m model cohorts, but candidate generation still calls `generateCandidate(family)` with default Bybit scope. | Schedule candidates by eligible exchange/strategy/version with bounded fair exploration and independent provenance. |
| APO-006 | HIGH | `PromotionPolicyService::promoteCandidate()` refuses automatic promotion without operational rollback; `rollbackCandidate()` refuses reversal of PAPER_ELIGIBLE candidates. This is a necessary safety gate, not a completed autonomous lifecycle. | Transactional champion versions, paired comparable evidence, candidate-bound forward verification, idempotent promotion and operational rollback/suspension, owner-block preservation. |
| APO-007 | HIGH | `AdaptiveTimeframeResearchService::analyzeTimeframes()` filters symbol/interval but not exchange/category, so its market statistics can combine venue rows. | Explicit exchange/category and as-of scope, negative cross-venue fixtures and caller/API propagation. |
| APO-008 / F11/F12/F16 | HIGH | Spot settlement is intentionally unsupported by `PaperExecutionModelContract`; `market_data.funding_schedules` only declares Bybit/Binance. Fresh native context/funding for all ten venues is not certified. | Audited market-specific execution contracts and effective-dated schedules; incompatible venues stay BLOCKED/research-only. |
| APO-009 | HIGH | Independent prediction training/statistical aggregation still runs substantially in PHP. `MarketRegimeClassifier` returns TREND/RANGE/HIGH_VOLATILITY/UNCERTAIN, without separate direction and mixed volatility attributes. | Reuse Go for bounded feature/statistical computation; versioned mixed regime attributes with causal validation. |
| APO-010 | HIGH | `.github/workflows/deploy.yml` named by the policy does not exist. The actual entrypoint is the `deploy` job in `.github/workflows/ci.yml`, with a production environment and conditional push/dispatch triggers. | Resolve the policy/workflow discrepancy in a focused reviewed stage; prove exact-SHA gates and mandatory manual approval. Do not trigger or rename anything during audit. |
| APO-011 | HIGH | Full imported-source PHPUnit and Pint baseline is not green. Source-only V7/V8 probe passes are insufficient to close integration findings. | Diagnose the retained failures, distinguish setup defects from runtime defects, fix root causes and repeat focused/regression checks without weakening mandatory coverage. |
| APO-012 | MEDIUM | Three sizing modes exist: fixed_notional, fixed_margin and risk_percent. Percent-of-equity is not a distinct implemented mode. No current evidence certifies every imported metadata/lot-size path. | Preserve 5 USDT exposure default and owner settings; complete explicit supported semantics and per-instrument constraints. |
| APO-013 | HIGH | `StrategyCompletenessService.php:41` resolves unimported `PaperExecutionEligibilityService` in the Strategy namespace. `SystemUiApiReconciliationAuditTest::test_strategy_completeness_matrix_covers_all_registered_strategies_and_exchanges` reproduces the container resolution exception. | Import the existing PaperTrading service; rerun completeness/API coverage and preserve all eligibility gates. |

The initial repository audit required by Stage 0 is complete at a source/baseline
level. These gaps prevent treating any later APO stage as already verified.

## Rechecking V7 and V8 claims

- R8-01: null expectancy guard exists; standalone empty diagnostics run passes
  2/2. Backend feature coverage is included in the recorded full PHPUnit run.
- R8-02: empty outcome percentage is nullable and the UI renders an undefined
  value. Frontend regression passes; full backend results remain independently
  classified.
- R8-03: explorer defaults are 10 in service, controller and Vue. Explicit
  pagination remains supported.
- R8-04: diagnostic wording no longer claims an unobserved filtering action.
- V7 fail-closed policy probes pass 26/26. Native exchange probes pass 28/28;
  topology smoke checks 11/11; historical cost isolation smoke checks 11/11.
- Later source already adds DNS address pinning in `AbstractExchangeAdapter` for
  declarative endpoints. The older blanket claim that DNS pinning is missing is
  stale. Real HTTP/TLS/IPv6/proxy/redirect validation is still required.
- Approval uses persisted PAPER approval and lifecycle state; unknown legacy,
  risk and zero-funded blocks are excluded from automatic resumption. This
  source review is not a concurrent approval/risk-state acceptance test.
- F24 hard limits remain 5% entry block, 10% halt, loss cooldown and owner limits.
  No adaptive risk authority is inferred from the optimization specification.
- Cold-start remains an explicit dependency: pre-entry evidence requires at
  least ten scoped closed PAPER positions. A new account cannot manufacture
  that history. Observation-only labels, separately authorized exploration and
  existing promotion policy must be reconciled before claiming autonomous
  bootstrap; never invent trades or bypass the entry gate.

## Exchange, strategy, instrument and timeframe coverage

Registry coverage includes Binance, Bybit, Coinbase, Upbit, OKX, Bitget, Gate,
KuCoin, Kraken and HTX. Each has an adapter path under `app/Exchanges/`.
Registration, technical execution support, owner approval, funding, capital and
live data readiness are separate states. No venue receives an end-to-end or
production PASS from this audit; Coinbase/Upbit spot remains incompatible with
the current linear-perpetual settlement contract.

The registry preserves these 13 built-in PRIMARY identities: baseline,
trend_pullback, bollinger_mean_reversion, donchian_atr_breakout,
regime_switching, momentum, support_resistance_bounce,
support_resistance_breakout, funding_open_interest_squeeze,
liquidity_sweep_reversal, volatility_compression_breakout, btc_eth_lead_lag and
multi_timeframe_trend_following. Dynamic registrations must be audited without
hard-coded topology totals. BTCUSDT and ETHUSDT are configured; native venue
symbol mapping, market type and minimums require individual verification.

The default Compose declares a 512 MiB / 1 CPU Go research worker and a
2 GiB / 2 CPU PHP backtest worker; the general queue consumes
`market-ingest,default`, with dedicated Bybit/Binance trading queues. These are
source defaults, not measured VPS reservations, queue health or capacity proof.

Source engine coverage is 15m decisions / 5m execution, with 30m setup and 60m
trend inputs. 1m/3m independent engines and the full elapsed-time horizon set
are not certified. No 130-account existence claim implies 130 trading-ready
portfolios or preservation verification of the original production histories.

## Existing API reuse and validation targets

| Specification capability | Existing route/service boundary | Outstanding verification |
|---|---|---|
| 18.1 overview | GET research/profitability/summary | Reconcile ledger, mark freshness, date/source scope. |
| 18.2 exchange profitability | GET exchanges/comparison; signal-analytics | Comparable account/cohort semantics and costs. |
| 18.3 strategy profitability | GET research/profitability/strategies/{strategyId} | Versions, partial closes, symbol/date filters. |
| 18.4–5 signal quality and drill-down | GET research/profitability/signal-analytics, signal-explorer, signal-explorer/{id} | Horizon contract, classification and bounded aggregates. |
| 18.6 comparisons | GET research/comparison; exchanges/comparison | Paired data/cost comparability. |
| 18.7 optimizer config | GET/POST research/learning/autonomy-settings | Versioned optimizer policy beyond current flags. |
| 18.8–10 experiments/champions/history | research/learning/candidates, models, audit-trail; research/autonomy/* | Operational rollback and candidate-bound evidence. |
| 18.11 scheduling | research/jobs; research/experiments | Retry/cancel/restart, concurrency and fencing. |
| 18.12 capital | paper/accounts; strategy account fund/authorize routes | Owner funding events and no historical resets. |
| 18.13 notional | GET/PUT paper/accounts/{id}/sizing; effective-sizing | Preserve strict fixed-notional bounds. |
| 18.14 risk policy | paper config and existing kill-switch/autonomy controls | Separate authorized adaptive recommendations; no risk relaxation. |
| 18.15 export | audit/exports; signal-explorer/export | Full provenance, CSV formula safety, pagination/date/source reconciliation. |
| 18.16 health | research/health/*; research/jobs | Resource/latency metrics and explicit blocked reasons. |

Paths above are relative to `/api/v1/`. Exact route inventory and endpoint tests
must accompany each affected stage. Basic Auth is implemented at the web server
boundary; research-token middleware and controller-level checks need route-by-
route negative coverage. A valid HTTP 200 is not endpoint correctness.

## Sequential implementation and regression plan

1. **Stage 1:** fix the reproduced completeness dependency failure (APO-013),
   reconcile imported source with executable failures, and retain
   V8 empty/null/pagination protections. Fix bounded analytics (APO-001), explicit
   source/horizon outcomes (APO-002/003), classifications and source separation.
   Reuse signal/explorer routes. Cover rejected/observation/multi-account/partial
   close cases and exchange/version/timeframe attribution. Commit only verified
   stage-owned changes and reviewed inherited dependencies.
2. **Stage 2:** fix cross-venue timeframe analysis and candidate scope
   (APO-005/007); Go features/regimes/objective, pinned experiments, causal
   chronological splits, purging/embargo, independent holdout, WFO, baseline and
   multiplicity controls. Benchmark deterministic bounded workloads.
3. **Stage 3:** implement operational champion/rollback before enabling any
   automatic promotion (APO-006). Validate independent forward/shadow evidence,
   owner blocks, retries, concurrent promotion and worker termination.
4. **Stage 4:** preserve fixed 5 USDT notional, independent capital and 5%/10%
   protections. Add only authorized recommendation/allocation semantics; test
   rounding, minimums, fees, partial exits, concentration and exact ledger
   identities. Funding recommendations never mutate old balances.
5. **Stage 5:** extend existing views and exports, source/horizon/regime filters,
   comparison and uncertainty, 10-row server pagination, loading/error/empty/stale
   behavior. Verify real HTTP and browser flows and reconciliation.
6. **Stage 6:** close venue-specific ingestion/context/funding and deployment
   policy discrepancies (APO-004/008/010), full migrations/regressions, Go
   race/integration, resource benchmarks, malformed input/security, restart
   recovery and final independent audit. Produce the requested safe archive and
   applicable patch with checksums and honest readiness classification.

Every stage must pass its focused and applicable regression checks before the
next stage, with separate local commits. Passing Stage 0 documentation checks
does not waive failing application baseline tests.

Required E2E scenarios A–Q remain **NOT_VERIFIED as complete scenarios**. Existing
test candidates include `PreExecutionQualityRiskGateTest` (A/B),
`ExchangeAndInstrumentIsolationTest` (C),
`SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest` (D/O),
`Gate6PromotionPolicyTest` and `CandidateLabValidationLifecycleTest` (E/F),
`LearningPredictorRollbackTest` (G; model artifacts only),
`StrategyTopologyIsolationAndAssignmentTest` (H/K/L),
`RiskAndSizingTest` and `ExchangePositionSizingTest` (I/P),
`FinancialAccountingAndReconciliationPostgresIntegrationTest` (J),
`ResearchWorkerConcurrencyPostgresIntegrationTest` (M) and
`StrategyInputIntegrityTest` (N). Q requires a new integrated proof of the entire
chain, not concatenated unit-test totals. Discover exact test paths before reuse.

## Verification and remaining evidence

See `evidence/stage0-20261111/RESULTS.md` and retained raw logs/JUnit for current
results. Infrastructure is task-owned, uses disposable PostgreSQL storage and
an isolated Docker network, and does not share a production database, queues,
secrets or `.env`. The copied source uses `.env.example`; the original workspace
and staged files remain intact. PHP tests run with 8.5.12 and PostgreSQL 17;
frontend uses Node 22.23.4. Dependency directories are reused read-only for PHP;
frontend dependencies are resolved from the installed local directory.

No actual persisted forward-paper sample or production ledger was queried.
Sample size, SKIP counts, returns, profitability, VPS resource use, hosted CI,
10-exchange live readiness, browser acceptance and long-horizon forward
validation therefore remain **NOT_VERIFIED / NEEDS_PRODUCTION_EVIDENCE /
NEEDS_PAPER_FORWARD_EVIDENCE**. No production or profitability acceptance is
claimed. The final audited release archive is not yet an available deliverable.
