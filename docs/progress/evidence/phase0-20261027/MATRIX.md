# Phase 0 requirement-to-evidence matrix

All 45 requirements / 99 obligations are represented. `V1` is isolated PostgreSQL, `V2` is production, and nine rows also have browser `V3`. Every V2/V3 is UNAVAILABLE. PASS below applies only to V1. Exact executed case names, assertion counts, source lines and obligation wording are in [matrix.json](matrix.json); admitted records also retain command, SHA, timestamps and artifact hashes. Each listed class was run in full-backend unless otherwise noted. Frontend source tests never count as browser evidence.

A pending row means insufficient proof, not necessarily missing implementation. All rows require operator action for V2 and explicit product acceptance. Runtime code changes are not established by a test gap. Proposed test extensions belong to a subsequent scoped verification assignment.

## VEC-P0-001 — 26 independent PAPER account topology

**V1: PASS; V2: UNAVAILABLE.**

Fresh synthetic census is exactly 13+13; initialization/repeat preserves seeded Bybit and active Binance history; foreign exchange and missing-account controls fail; all strategy pairs remain isolated.

Tests: `tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php`, `tests/Feature/Exchanges/BinancePaperIsolationTest.php`, `tests/Feature/PaperTrading/AllThirteenStrategiesExecutionTest.php`, `tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php`.

Next local procedure / acceptance: No additional local test needed for this obligation. Q1 must independently establish the real 26-account census and history continuity.

Production procedure / acceptance: Q1: require exactly the 13 canonical IDs on each exchange, unique qualified account IDs, preserved statuses and before/after historical record hashes. Q2/Q3 trace BUY/SELL/SKIP, entries and exits per account; zero activity is not automatically a defect.

## VEC-P0-002 — PAPER pipeline terminalization and recovery

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Completion, PARTIAL, stale recovery and targeted selection pass; no assertion directly exercises all-accounts-failed -> FAILED or recovery retry of failed accounts after a mixed batch without repeating successes. The frozen obligation says every transition.

Tests: `tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php`, `tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php`, `tests/Feature/Trading/TradingPipelineEventContractTest.php`, `tests/Feature/Trading/TradingPipelineRecoveryAndRollbackTest.php`, `tests/Feature/Trading/TradingPipelineStageATest.php`.

Next local procedure / acceptance: Add an isolated PostgreSQL test with two accounts, one failure then targeted recovery; assert the successful account ledger is byte-identical and the failed account executes once. Add all-failed -> FAILED and upstream terminal-state assertions; rerun tests/Feature/Trading.

Production procedure / acceptance: Q4: correlate all SIGNALS_EVALUATED and 5m CANDLE_CLOSED parents with PAPER_PROCESSED by exchange/symbol/close time. Read existing worker logs for stale/partial batches and targeted retries; require no false completion or repeated successful-account fills.

## VEC-P0-003 — Financial ledger and funding completeness

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Exact synthetic fee/funding identities and negative controls pass. The atomic criterion also asks for reconciliation of the current account population, unavailable here.

Tests: `tests/Feature/PaperTrading/FundingScheduleSafetyTest.php`, `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php`, `tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php`, `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php`.

Next local procedure / acceptance: Retain the named fixture results; obtain Q1/Q3 plus certified historical schedules and independently recalculate the current population, including all five previously reported late-funding candidates. No repair command.

Production procedure / acceptance: Q1/Q3: independently reconcile exact decimal execution gross PnL, commissions, signed settlements and account journals. Obtain certified historical exchange/symbol funding schedules, held quantities at every boundary and actual marks/rates for the five reported late cases. Missing data remains incomplete; repairs need separate authorization.

## VEC-P0-004 — Risk gates and account isolation

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

5%/10%, cooldown, correlation, sizing and blocked-position servicing pass. Reviewed RiskAndSizingTest lacks direct RiskEngine zero-equity, class-limit and portfolio-notional boundary assertions required by the second atomic criterion.

Tests: `tests/Feature/PaperTrading/AccountSafetyRegressionTest.php`, `tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php`, `tests/Feature/PaperTrading/PaperLifecycleTest.php`, `tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php`, `tests/Unit/PaperTrading/RiskAndSizingTest.php`.

Next local procedure / acceptance: Extend riskFailures with equity=0, negative free capital, risk above each A/B/C limit and notional above portfolio cap; assert allowed immediately below and denied at applicable boundaries. Run RiskAndSizingTest plus AccountSafetyRegressionTest on isolated PostgreSQL; never relax config.

Production procedure / acceptance: Q1/Q3 plus persisted risk/journal records: establish effective 5% block and 10% halt, each account cooldown, no bypass on BLOCKED accounts, and continued exits/funding on existing positions. Observe existing boundary cases; do not manufacture production drawdowns.

## VEC-P0-005 — Binance execution approval configuration

**V1: PASS; V2: UNAVAILABLE.**

False approval, missing costs, foreign provenance, blocked and unfunded accounts reject; explicit valid local approval/costs permit guard evaluation. Existing active/history fixtures survive initialization; equity recalculation cannot activate accounts.

Tests: `tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php`, `tests/Feature/Exchanges/BinancePaperIsolationTest.php`, `tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php`, `tests/Unit/Exchanges/BinancePaperSafetyTest.php`.

Next local procedure / acceptance: No additional local test needed. Read effective deployed cached approval/cost configuration through the operator procedure; do not infer it from defaults or account ACTIVE status.

Production procedure / acceptance: Operator inspects effective cached exchanges.binance.paper_execution_approved, paper_taker_rate, paper_slippage_rate, research.forward_paper.signal_only_strategies and running release identity; export only these non-secret fields. If cache absent, trace only these environment/config keys without printing the environment. Q1/Q3 verify ACTIVE status and history remain unchanged. Do not run initializer/activation.

## VEC-P0-006 — Support/resistance statistical bootstrap

**V1: PASS; V2: UNAVAILABLE.**

Forecast persists despite SKIP and unapproved execution; closed price bars mature outcomes without trades; 29 observations remain insufficient and 30 permit RESEARCH_BASELINE; Bybit 35 observations do not inflate Binance 5.

Tests: `tests/Feature/Strategy/LevelProbabilityBootstrapLifecycleTest.php`.

Next local procedure / acceptance: No additional local test needed for the stated bootstrap criteria. Q5 and a decision-time cohort review must establish real Binance sample maturity; retain minimum_sample_size=30.

Production procedure / acceptance: Q5: compare timestamp-qualified prior outcomes to the actual decision-time population/cohort fields and effective maximum_history/minimum_sample_size. Require >=30 eligible observations per actual Binance event population before statistical readiness; RESEARCH_BASELINE is not permission to execute or proof of profitability.

## VEC-P0-007 — PostgreSQL acceptance infrastructure

**V1: PASS; V2: UNAVAILABLE.**

Workflow service declaration reviewed; fresh PostgreSQL 17 cluster identity verified before fixtures. Real child-process candle insertion races, uniqueness negatives, migration preservation/idempotency and PHP-to-Go persistence executed with zero PostgreSQL skips.

Tests: `tests/Integration/BinanceCandleInsertionRaceTest.php`, `tests/Integration/CounterfactualSlotIdempotencyPostgresIntegrationTest.php`, `tests/Integration/GoResearchWorkerIntegrationTest.php`, `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php`, `tests/Integration/SlippageAttributionPostgresIntegrationTest.php`, `tests/Integration/StrategyObservationSlotMigrationPostgresIntegrationTest.php`.

Next local procedure / acceptance: No additional local infrastructure test needed. Obtain the operator-approved deployed release/schema provenance separately; local execution does not prove GitHub Actions ran.

Production procedure / acceptance: Provide release SHA/image digest, migration inventory and retained CI PostgreSQL job output for that release. Local cluster results do not establish deployed schema compatibility or remote workflow success.

## VEC-P0-008 — Production acceptance gate

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Both atomic criteria require operator-authorized current-release evidence and independent acceptance. None was collected.

Tests: Frontend source tests only; no qualifying PostgreSQL case mapped..

Next local procedure / acceptance: Collect the production packets below, map each acceptance ID, obtain explicit responsible-actor acceptance and retain authorization. Local fixtures cannot satisfy this obligation.

Production procedure / acceptance: Assemble all authorized packets with capture timestamp, actor, release SHA, exact procedures, hashes, limitations and acceptance IDs. Independent responsible actor must explicitly accept every Phase 0 requirement; no automatic acceptance.

## VEC-P0-009 — Resource and performance baseline

**V1: FAIL; V2: UNAVAILABLE.**

Ingestion benchmark failed the <50ms gate: P95 65.641ms overlapping lint, then 74.860ms without that task load. Effective shared-host limits and PAPER latency under research load remain unmeasured.

Tests: `tests/Integration/BinanceCandleInsertionRaceTest.php`.

Next local procedure / acceptance: Repeat scripts/benchmark-binance-ingestion.php --runs=30 --warmup=5 in the verified environment with PHP lint idle; retain every run. Separately measure PAPER P50/P95, RSS and SQL counts with/without bounded research over 30 repetitions and effective task-owned limits. A timing failure needs a separately scoped fix, not altered thresholds.

Production procedure / acceptance: Read only VECTOR service effective CPU/memory/worker limits and existing PAPER latency/RSS/SQL telemetry. Capture >=30 observations under documented normal and bounded research load; require the roadmap latency limits and <=5% PAPER P95 regression. Do not launch production load or inspect unrelated projects under this task.

## VEC-P0-101 — F01 — False Decision Replay Verification & State Mutation [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Stored-only status is asserted. The global GET write detector uses a nonexistent replay ID; successful replay test does not count writes. This does not prove zero writes for an existing observation.

Tests: `tests/Feature/Api/ReadOnlyGetEndpointsTest.php`, `tests/Feature/Research/StageTwoApiTest.php`, `tests/Unit/Research/DecisionReplayServiceTest.php`.

Next local procedure / acceptance: Seed an existing observation; attach DB::listen after setup; GET its replay twice and assert NOT_RECOMPUTED_STORED_ONLY, false is_reproducible and zero INSERT/UPDATE/DELETE plus unchanged replay-record count.

Production procedure / acceptance: From an already-captured successful replay GET, compare stored-only label and existing SQL/write audit around repeated requests. If no write trace exists, evidence remains pending; do not treat the missing-ID GET test as successful-path proof.

## VEC-P0-102 — F02 — Profitability Response Envelope Chain Breakdown [CRITICAL]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

API success/error shapes pass; frontend tests are source-contract tests, not executed Vue-to-service integration.

Tests: `tests/Feature/Research/ProfitabilityResearchApiTest.php`.

Next local procedure / acceptance: Mount ProfitabilityResearchPage with real api.ts response handling against isolated API success and 404/error fixtures. Assert one envelope unwrap and rendered error; retain browser network/DOM evidence for V3.

Production procedure / acceptance: Capture profitability summary/detail/error response and the corresponding rendered page from the same release; require a single data envelope and visible errors. Review controller/middleware GET side effects first.

## VEC-P0-103 — F03 — Fabricated Financial Fallback Values in UI [CRITICAL]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Absent-account API control passes; no rendered profitability null-vs-real-zero financial test.

Tests: `tests/Feature/MarketData/MarketExchangeIsolationTest.php`, `tests/Feature/Research/ProfitabilityResearchApiTest.php`.

Next local procedure / acceptance: Render missing/null equity, PnL and counts beside legitimate zero fixtures. Assert unavailable labels for null and numeric zero only for actual zero; retain API and DOM for V3.

Production procedure / acceptance: Compare existing absent/null and real-zero financial API examples to rendered cells. No invented equity, PnL or sample counts; absence of a null example leaves the negative case pending.

## VEC-P0-104 — F04 — Reconciliation Findings Misread in Controller [HIGH]

**V1: PASS; V2: UNAVAILABLE.**

Injected ledger mismatch produces actual discrepancy_count and detail findings; an account without a reconciliation run returns NO_RECORDED_RUN with null count; clean-account service control passes.

Tests: `tests/Feature/Research/ProfitabilityResearchApiTest.php`, `tests/Unit/Research/LedgerReconciliationServiceTest.php`.

Next local procedure / acceptance: No additional local test needed. Compare deployed persisted reconciliation output to summary/detail responses without running reconciliation.

Production procedure / acceptance: Read existing reconciliation_runs records and corresponding summary/detail responses. Require identical findings/counts and NO_RECORDED_RUN for missing data; never invoke reconciliation to obtain a prettier result.

## VEC-P0-105 — F05 — Outdated Roadmap Module Statuses [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Tests assert fixed module statuses, not their consistency with available evidence or negative changes in module readiness.

Tests: `tests/Feature/Research/ProfitabilityResearchApiTest.php`.

Next local procedure / acceptance: Exercise implemented, missing evidence and prohibited modules; compare each API status to source-backed availability and assert missing evidence cannot be IMPLEMENTED_VERIFIED merely because a constant says so.

Production procedure / acceptance: Compare every profitability gated_modules status to current deployed source and retained module-specific evidence; static labels alone cannot establish verification.

## VEC-P0-106 — F06 — Reconciliation Ignores Drawdown Mismatch [HIGH]

**V1: PASS; V2: UNAVAILABLE.**

Clean account passes; observed drawdown above stored max is a discrepancy without mutation, while a larger historical maximum is preserved and missing history is incomplete; injected balance/execution controls also fail.

Tests: `tests/Unit/Research/LedgerReconciliationServiceTest.php`.

Next local procedure / acceptance: No additional local test needed. Q1 plus account snapshot histories must reproduce drawdown residuals without modifying maxima.

Production procedure / acceptance: Read account peak/current/max drawdown plus snapshot history. Independently calculate observed drawdown and compare persisted findings; retain higher legitimate historical maxima, never rewrite them.

## VEC-P0-107 — F07 — Audit ZIP Export Silent Column Dropping [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

ZIP tests check members and some headings; they do not compare every required column or inject a missing source column.

Tests: `tests/Feature/AuditExportTest.php`.

Next local procedure / acceptance: Generate an isolated export with representative nullable fields; compare every CSV header to the declared dataset schema. Remove a required field only in a disposable schema and assert explicit missing-field failure/metadata, not silent omission.

Production procedure / acceptance: Download an already-generated authorized ZIP after GET review; compare every header against source schema, manifest missing-field declaration and checksums. Do not create an export job during production read-only inspection.

## VEC-P0-108 — F08 — Strategy Funnel Cohort & Status Discrepancy [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Half-open window and risk funnel tests pass separately; no comparison of exported strategy funnel populations to scoped source queries.

Tests: `tests/Feature/AuditExportTest.php`, `tests/Integration/SlotIntegrityAndRiskFunnelPostgresIntegrationTest.php`.

Next local procedure / acceptance: Seed BUY/SELL/SKIP and open/partial/closed states on both exchanges and two adjacent windows. Parse exported funnel CSV and compare exact cohorts/counts to qualified SELECTs; reject cross-exchange rows.

Production procedure / acceptance: Compare the same retained ZIP funnel to bounded qualified SELECT counts over identical half-open window and lifecycle cohort; require no cross-exchange/account or partial-exit population mismatch.

## VEC-P0-109 — F09 — Frontend Single-Account Display Default Blinds Multi-Account System [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Selector tests seed Bybit only. Separate 26-account census does not prove all 26 selectable or distinct aggregate/account economics.

Tests: `tests/Feature/Api/MultiAccountSelectionTest.php`, `tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php`.

Next local procedure / acceptance: Seed 13+13 accounts with distinct balances; request dashboard/statistics/risk for every account and exchange plus aggregate. Assert IDs, sums, foreign-ID rejection and no provisioning; exercise selectors in browser for V3.

Production procedure / acceptance: After GET review, select all 13 accounts on each exchange in dashboard/statistics/risk and aggregate mode. Capture each account ID and exact numeric values; compare to Q1/current persisted data and independently summed aggregates.

## VEC-P0-110 — F10 — Signal Annotation Query Reversal and Account Scope Defect [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

HTTP success smoke coverage lacks competing annotation rows and qualified newest-match assertions.

Tests: `tests/Feature/Api/CompleteApiEndpointAuditTest.php`.

Next local procedure / acceptance: Seed older/newer matching decisions and a newer foreign-account/foreign-exchange decision for one signal. Read annotations and assert newest matching row only, plus missing-match unavailable state.

Production procedure / acceptance: Use an existing signal with multiple risk annotations; read matching account/exchange rows ordered by decision time, compare the displayed newest qualified record; foreign/newer records must not win.

## VEC-P0-111 — F11 — Statistics Page Lifecycle Population Mixing [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Open economics exclusion passes. P2 partial-population test targets a different service; no direct partial-exit negative for the Statistics page service.

Tests: `tests/Feature/PaperTrading/PaperLifecycleTest.php`, `tests/Feature/PaperTrading/PerformanceStatisticsTest.php`, `tests/Feature/Research/P2RiskCapitalMetricsTest.php`.

Next local procedure / acceptance: Exercise PerformanceStatisticsService and statistics API with OPEN, PARTIALLY_CLOSED, CLOSED_TP and CLOSED_SL, multiple partial executions per position. Assert only closed positions form winrate/PF/R denominators and one closed position is one trade.

Production procedure / acceptance: Compare statistics API/DOM with closed-position population and separately open/partial records for the same account/window. Count a position once regardless of partial execution legs.

## VEC-P0-112 — F12 — Daily Performance Marks Missing Opening as Complete [HIGH]

**V1: PASS; V2: UNAVAILABLE.**

Closing-only history returns null PnL and incomplete in API service and calendar CSV; inception is a justified opening control; complete period reconciles commissions/funding.

Tests: `tests/Feature/PaperTrading/DailyPerformanceTest.php`.

Next local procedure / acceptance: No additional local test needed. Compare deployed daily API/CSV to opening/closing snapshots, including a missing-opening day.

Production procedure / acceptance: Compare daily API and retained CSV to exact opening/closing snapshots. Missing opening must be incomplete/null except documented account inception; fresh rendering cannot certify absent history.

## VEC-P0-113 — F13 — Research Health Funnel False Healthy Parent Status and Hardcoded Lookback [MEDIUM]

**V1: PASS; V2: UNAVAILABLE.**

Observed BUY/SKIP counts are asserted; all-empty children produce INSUFFICIENT_DATA parent; requested 7d changes expected slots to 672.

Tests: `tests/Unit/Research/StrategyHealthServiceTest.php`.

Next local procedure / acceptance: No additional local test needed for the tested contract. Compare real 24h/7d source observations and child/parent health; do not infer activity from HEALTHY alone.

Production procedure / acceptance: Compare existing 24h/7d health responses to qualified observation counts; inspect every child and parent status and effective cadence/symbol set. Do not infer coverage from hardcoded theoretical 192/1344 totals.

## VEC-P0-114 — F14 — Research Report Freshness Shape Mismatches [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Freshness service and API shapes tested separately; report-to-page stale/unknown shape regression is not asserted.

Tests: `tests/Feature/Research/AutonomousLearningApiTest.php`, `tests/Unit/Research/SystemFreshnessServiceTest.php`.

Next local procedure / acceptance: Exercise the report endpoint with fresh, stale and missing underlying marks; mount its consumer and assert actual field names plus stale/unknown UI. Capture V3 DOM and API together.

Production procedure / acceptance: Compare report freshness response fields with actual underlying mark timestamps and rendered stale/unknown state; capture same release, request and account.

## VEC-P0-115 — F15 — Portfolio Correlation Invalid Status Check and Trade-Order Pairing [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Lifecycle inclusion and aligned fixture correlation pass; no deliberately permuted trade-order/time alignment negative.

Tests: `tests/Unit/Research/PortfolioCorrelationServiceTest.php`.

Next local procedure / acceptance: Create time-overlapping and non-overlapping trades with permuted insertion order and missing intervals. Assert correlation uses common time observations and never pairs by row order.

Production procedure / acceptance: Use existing common-time return series with retained sample IDs; independently align timestamps and recompute correlation. Do not use trade insertion order; missing common intervals remain unavailable.

## VEC-P0-116 — F16 — Strategy Degradation Cross-Exchange Pollution [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Degradation fixtures use Bybit only; P5 exchange comparisons do not directly test degradation isolation.

Tests: `tests/Feature/Research/P5DependenceConcentrationTest.php`, `tests/Unit/Research/StrategyDegradationServiceTest.php`.

Next local procedure / acceptance: Seed the same strategy on Bybit with gains and Binance with losses; evaluate degradation for each exchange and assert each sample/metric ignores the other; repeat with swapped insertion order.

Production procedure / acceptance: Read degradation source populations for the same strategy on both exchanges. Independently recompute each result using only its exchange and compare snapshots/API.

## VEC-P0-117 — F17 — Learning Feature Store Missing Exchange Filter [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Point-in-time snapshot/label pass with Bybit only; missing cross-exchange contamination negative.

Tests: `tests/Unit/Research/LearningFeatureStoreServiceTest.php`.

Next local procedure / acceptance: Seed opposing Bybit/Binance candles and outcomes at identical times; build each feature snapshot and assert only its exchange supplies all features and labels.

Production procedure / acceptance: Trace stored feature snapshot exchange and source candle/outcome IDs; verify identical-time rows on the other exchange cannot enter features or labels.

## VEC-P0-118 — F18 — Model Training Purging Fallback Leakage [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Training pipeline succeeds but does not drive insufficient purged samples through the fallback path.

Tests: `tests/Feature/Research/AutonomousLearningPipelineTest.php`.

Next local procedure / acceptance: Construct overlapping labels whose purge removes most samples; train and assert insufficient-data failure instead of unpurged fallback. Verify no label with outcome_at after cutoff enters training.

Production procedure / acceptance: Inspect a retained training manifest and exact sample label/cutoff times, purge/embargo and fallback outcome. Require no post-cutoff label and no disabled purge fallback; do not trigger retraining.

## VEC-P0-119 — F19 — Autonomy Settings Raw Flags vs Permissions [LOW]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Service restrictions pass; settings API asserts raw capabilities but not the effective permission map under conflicting flags.

Tests: `tests/Feature/Research/AutonomousLearningApiTest.php`, `tests/Unit/Research/AutonomyPermissionServiceTest.php`.

Next local procedure / acceptance: Set raw permission true with global enable false or kill switch true in isolated fixtures; GET settings and assert effective permission false alongside the raw value and reason. Render in browser for V3.

Production procedure / acceptance: Capture raw and effective permission maps and existing config/switch state from the same release. Effective action must be false under either blocking authority even if raw flag is true; do not toggle flags.

## VEC-P0-120 — F20 — Dual Kill-Switch Divergence & Blindness [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Each kill-switch has tests; no matrix toggles each authority independently and asserts both effective views agree.

Tests: `tests/Feature/Research/AutonomyResearchTest.php`, `tests/Unit/Research/AutonomyPermissionServiceTest.php`.

Next local procedure / acceptance: Exercise four combinations of both switch stores; assert effective blocking in both APIs/services and display both authorities without writes on GET. Browser V3 requires rendered disabled controls.

Production procedure / acceptance: Read both switch authorities and effective views; compare existing denial logs and rendered controls. Do not toggle production switches to manufacture cases; unavailable combinations stay pending.

## VEC-P0-121 — F21 — Compute Workers Static Capability Map [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Static routing and real worker success pass; missing unsupported-version/offline-worker capability negative.

Tests: `tests/Feature/Research/ResearchJobApiTest.php`, `tests/Integration/GoResearchWorkerIntegrationTest.php`, `tests/Unit/Research/ResearchJobServiceTest.php`.

Next local procedure / acceptance: Register supported, unsupported-version and expired-heartbeat workers; query capabilities and assert only actual compatible availability, with unavailable reasons for others.

Production procedure / acceptance: Read registered worker versions, heartbeat timestamps, capabilities and routed task history. Require unsupported/stale workers not advertised as available; no worker restarts or new tasks.

## VEC-P0-122 — F22 — Quote Integrity Silent Error Swallowing [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

quote_integrity.test.js checks source contracts, not an executed HTTP failure reaching the page.

Tests: Frontend source tests only; no qualifying PostgreSQL case mapped..

Next local procedure / acceptance: Mock rejected HTTP, timeout and malformed success envelope in the real quoteIntegrityApi consumer; render visible error and preserve last-good/stale distinction. Verify with local API and browser for V3.

Production procedure / acceptance: Retain an existing failed quote-integrity request with matching visible error/unknown state; do not induce production outage. Local browser fault injection is separate from production evidence.

## VEC-P0-123 — F23 — UI Pagination Truncations [LOW]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Some backend pagination and frontend source checks pass; no rendered navigation beyond first page on each affected page.

Tests: `tests/Feature/PaperTrading/DailyPerformanceTest.php`.

Next local procedure / acceptance: Seed 25 distinct journal/history rows; request pages 1/2/3 with per_page=10 and assert 25 unique IDs, stable order and no loss. Navigate all three pages in the browser for V3.

Production procedure / acceptance: After GET review, page beyond the first ten journal/history rows and compare unique IDs, stable ordering and backend totals. Missing >10-row population remains pending.

## VEC-P0-124 — F24 — Slippage Position Trace Scope [MEDIUM]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Latest/explicit generation, no-writes and missing-position controls pass; qualified account/exchange mismatch is not covered.

Tests: `tests/Feature/Research/SlippageAttributionApiTest.php`.

Next local procedure / acceptance: Seed two accounts/exchanges and two generations for each position; request position trace with correct and foreign account/exchange filters. Assert zero foreign fills and only requested generation.

Production procedure / acceptance: Compare position/account/exchange/generation-qualified slippage rows to the trace response. Require latest or explicitly requested generation and no foreign fills.

## VEC-P0-125 — F25 — Candidate Lab Immediate Evaluation Rejection Due to Missing Backtest Linkage [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE; V3: UNAVAILABLE.**

Bounded queued attempts, kill-switch and rejection pass; completed run is manually supplied. No test executes EvaluateStrategyCandidateJob and proves durable linkage to the correct run.

Tests: `tests/Feature/Research/AutonomousLearningPipelineTest.php`, `tests/Feature/Research/CandidateLabValidationLifecycleTest.php`.

Next local procedure / acceptance: Execute EvaluateStrategyCandidateJob on a bounded synthetic historical dataset with a competing unrelated run. Reload candidate and assert exact owned backtest_run_id, outcome, missing/failure state, retry bound and no promotion; render transitions for V3.

Production procedure / acceptance: Q7 plus existing backtest run records and job logs: trace queued -> exact completed run linkage -> fail-closed verdict/rejection. Observe existing bounded retries/kill-switch denials; do not generate, validate, promote or execute candidates in production.

## VEC-P0-126 — F26 — Autonomy Approval Audit Trail Loses Rejected Promotions [HIGH]

**V1: UNAVAILABLE; V2: UNAVAILABLE.**

Rejection event persists after exception, but test does not assert append-only enforcement or concurrent retry behavior.

Tests: `tests/Feature/Research/Gate6PromotionPolicyTest.php`.

Next local procedure / acceptance: Reject promotion, verify persisted event after rollback; attempt UPDATE/DELETE with application role and require denial. Race identical rejected requests on separate PostgreSQL connections and verify documented immutable audit semantics.

Production procedure / acceptance: Read existing CANDIDATE_PROMOTION_REJECTED audit events and rollback/error logs for the same attempt. Verify database append-only controls from schema metadata; never test production UPDATE/DELETE.

## VEC-P0-127 — F27 — Account-Level Entry Block Observability Void [HIGH]

**V1: PASS; V2: UNAVAILABLE.**

Blocked drawdown SELL persists REJECTED state and PRE_RISK_ENTRY_BLOCKED journal, no RiskEngine row/fill; existing blocked-account positions still close and preserve accounting.

Tests: `tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php`, `tests/Feature/PaperTrading/PaperLifecycleTest.php`, `tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php`.

Next local procedure / acceptance: No additional local test needed for this obligation. Verify real blocked-account journal/state lineage and continued open-position servicing without reactivation.

Production procedure / acceptance: Read blocked-account paper_signal_states and PRE_RISK_ENTRY_BLOCKED journal alongside existing-position exits/funding. Require visible reason and no new fill; do not reactivate the account.

## VEC-P0-201 — I01 — Lifecycle: `SIGNALS_EVALUATED` never correctly terminalizes

**V1: PASS; V2: UNAVAILABLE.**

Signals remain DISPATCHED before PAPER; completion acknowledges signals; injected unacknowledged upstream is repaired without redispatch of completed PAPER.

Tests: `tests/Feature/Trading/TradingPipelineStageATest.php`.

Next local procedure / acceptance: No additional local regression needed. Q4 correlate signals and PAPER terminal status by exchange/symbol/close time.

Production procedure / acceptance: Q4 and existing recovery logs: signals remain dispatched until PAPER terminal acknowledgment; stale upstream with completed downstream is acknowledged without duplicate PAPER execution.

## VEC-P0-202 — I02 — TP1 fractional exit can round to zero

**V1: PASS; V2: UNAVAILABLE.**

Minimum-step half exit creates no zero fill and leaves position open; full SL closes; normal TP1/TP2 quantity control passes.

Tests: `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php`.

Next local procedure / acceptance: No additional local regression needed. Q3 inspect exact lot steps and zero-quantity execution count; lack of affected examples is unavailable operational evidence.

Production procedure / acceptance: Q3 plus lot-step metadata: inspect smallest partial exit and subsequent full exit. Require zero invalid/zero-quantity fills and unchanged residual quantity when TP1 rounds to zero.

## VEC-P0-203 — I03 — `funding_complete=false` persists for all positions

**V1: PASS; V2: UNAVAILABLE.**

Unknown schedule and 25-minute offset fail closed; certified 8h/dynamic 4h schedules and zero-settlement closed lifetime work; late observed settlement completes an exact held-quantity fixture.

Tests: `tests/Feature/PaperTrading/FundingScheduleSafetyTest.php`, `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php`.

Next local procedure / acceptance: No additional local regression needed. Q3 and certified schedules must explain each production incomplete position; missing events/marks remain unknown.

Production procedure / acceptance: Q3 plus certified schedules: enumerate expected settlement boundaries; compare actual event and settlement IDs. Unknown schedules, missing marks or late-offset events cannot certify completeness.

## VEC-P0-204 — I04 — `PAPER_PROCESSED=COMPLETED` despite per-account failures

**V1: PASS; V2: UNAVAILABLE.**

Mixed batch reports PARTIAL with one failure/one processed; targeted account selection processes only one account and records counts.

Tests: `tests/Feature/Trading/TradingPipelineStageATest.php`.

Next local procedure / acceptance: No additional local regression needed for mixed-failure finding; broader all-failed/recovery matrix remains under P0-002. Q4 verify real partial-batch counters and logs.

Production procedure / acceptance: Q4 and per-account failure logs: mixed success must be PARTIAL with exact processed/failure counts; confirm successful peers and targeted failed-account retry without duplicate ledger entries.

## VEC-P0-205 — I05 — Binance 5m candle gap invokes Bybit-only recovery

**V1: PASS; V2: UNAVAILABLE.**

Binance gap job never dispatches Bybit SyncCandlesJob; Bybit control does; Binance candle conflict rolls back rather than overwriting observations.

Tests: `tests/Feature/Exchanges/BinanceImmutableMarketIngestionTest.php`, `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php`.

Next local procedure / acceptance: No additional local regression needed for routing. Q6 and already-recorded recovery logs must show Binance-only retrieval; never trigger production recovery for this audit.

Production procedure / acceptance: Q6 and already-recorded recovery job logs: Binance gaps use Binance public market data; compare retained Bybit row hashes before/after the recovery window. Do not enqueue recovery.

## VEC-P0-206 — I06 — Binance provenance exception outside per-account catch

**V1: PASS; V2: UNAVAILABLE.**

Invalid Binance account provenance does not abort valid peer; event contains one failure and one processed account with PARTIAL status.

Tests: `tests/Feature/Trading/TradingPipelineStageATest.php`.

Next local procedure / acceptance: No additional local regression needed. Q4 plus existing account-specific failure logs must show surviving peers completed.

Production procedure / acceptance: Q4 and existing invalid-provenance log: identify failed Binance account and successful peer, confirm isolated exception and PARTIAL batch; absence of such a real sample remains pending.

## VEC-P0-207 — I07 — Late funding may never settle on closed positions

**V1: PASS; V2: UNAVAILABLE.**

Late event uses held quantity after TP1, exact -0.03 fixture amount, preserves closed status/equity identity and settles once. Missing closed mark is never fabricated.

Tests: `tests/Feature/MarketData/FundingHistorySynchronizerTest.php`, `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php`.

Next local procedure / acceptance: No additional local regression needed. For five historical candidates obtain exact entry/exit timing, held quantity, event rate/mark and settlement records; report unresolved amounts, never synthesize them.

Production procedure / acceptance: Q3: locate each of the five historical late-funding candidates from the prior incident report; obtain IDs, exact rates/marks, held quantities after partial exits and actual settlement timestamps. Classify settled/pending/insufficient without amounts invented or any mutation.

## VEC-P0-208 — I08 — Fresh snapshot timestamp can certify stale market valuation

**V1: PASS; V2: UNAVAILABLE.**

Fresh checkpoint with 25-minute-old position valuation remains STALE/incomplete with valued_through retained; no-open-position case is FLAT_VERIFIED.

Tests: `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php`.

Next local procedure / acceptance: No additional local regression needed. Compare real snapshot recorded_at to valued_through and last_processed_event_at; a fresh snapshot alone is not evidence.

Production procedure / acceptance: Read existing equity_snapshots valued_through/valuation_fresh and open position last_processed_event_at; compare daily API. Fresh recorded_at with old mark must remain stale; flat accounts may be FLAT_VERIFIED.

## VEC-P0-209 — I09 — 5m `CANDLE_CLOSED` acknowledged before downstream PAPER completion

**V1: PASS; V2: UNAVAILABLE.**

5m candle is DISPATCHED before downstream processing and COMPLETED only after PAPER acknowledgment; mixed account outcomes remain PARTIAL.

Tests: `tests/Feature/Trading/TradingPipelineStageATest.php`.

Next local procedure / acceptance: No additional local regression needed. Q4 verify no completed 5m parent precedes or contradicts its PAPER outcome.

Production procedure / acceptance: Q4: match 5m candle parent completion time/status to downstream PAPER, including mixed failures. Dispatch alone must never certify completed 5m processing.
