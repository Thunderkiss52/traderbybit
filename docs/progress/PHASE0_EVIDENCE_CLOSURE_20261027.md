# Phase 0 evidence closure — 2026-10-27

Existing-suite evidence review completed; Phase 0 product acceptance **not achieved**. Additional local coverage remains explicitly incomplete below. No Phase 1 implementation authorized or started.

Starting HEAD: `c8d51156b2c72b03f009d10e6a634ff80cff77ff`, branch `main`, clean worktree. The evidence commit is the local commit titled `test(vector): establish phase zero requirement-specific verification evidence`; its exact ending SHA is reported with the final handoff and can be resolved with `git log -1 --format=%H -- docs/progress/PHASE0_EVIDENCE_CLOSURE_20261027.md`. Evidence identifies the tested starting SHA; the evidence-only commit does not change application dependencies.

## Measurement

97 requirements / 229 obligations unchanged. Phase 0 has 45 requirements / 99 obligations, including all F01–F27 and I01–I09. Frozen scope, criteria, weights, dependencies and original history are unchanged. The proposed scope weights remain **PROVISIONAL**, not silently ratified.

| Metric | Before | After | Change |
|---|---:|---:|---:|
| Implementation | 42.2882% | 42.2882% | 0 |
| Verification | 0.0000% | 4.1270% | +4.1270 points |
| Product | 0.0000% | 0.0000% | 0 |

| Phase | Implementation before → after | Verification before → after | Product before → after |
|---|---:|---:|---:|
| 0 | 78.5714 → 78.5714 | 0 → 13.7566 | 0 → 0 |
| 1 | 18.7500 → 18.7500 | 0 → 0 | 0 → 0 |
| 2 | 40.9091 → 40.9091 | 0 → 0 | 0 → 0 |
| 3 | 31.8182 → 31.8182 | 0 → 0 | 0 → 0 |
| 4 | 34.0909 → 34.0909 | 0 → 0 | 0 → 0 |
| 5 | 17.6471 → 17.6471 | 0 → 0 | 0 → 0 |
| 6 | N/A → N/A | 0 → 0 | 0 → 0 |

Newly satisfied Phase 0 obligations (all **V1 only**): `VEC-P0-001`, `005`, `006`, `007`, `104`, `106`, `112`, `113`, `127`, and `201`–`209`. Full IDs and independent proof arguments are in the [matrix](evidence/phase0-20261027/MATRIX.md) and [machine-readable case mapping](evidence/phase0-20261027/matrix.json).

18 PASS, 1 FAIL (`VEC-P0-009-V1`), 80 UNAVAILABLE in Phase 0. The 81 unsatisfied obligations comprise 26 other local obligations, one failed local performance obligation, 45 production obligations and nine browser obligations. Globally: 18 PASS, 1 FAIL, 210 UNAVAILABLE. No requirement has every obligation verified; no product capability is accepted.

The increase is exactly the weighted contribution of the 18 applicable local obligations: four weight-3 requirements and fourteen weight-1 findings, each with one of three obligation-weight units satisfied, inside the fixed Phase 0 budget. No suite total, commit, source label or historical report was converted into product credit. The new failed attempt awards zero and removes no previous credit because there was none.

## Baseline review and metadata corrections

Startup tracker tests: 25 passed. Strict registry/prior-ref validation passed at the starting SHA. [Baseline review](evidence/phase0-20261027/baseline-review.json) records every retained IMPLEMENTED atomic claim, actual source references/hashes, frozen scope hash and equality between tested workspace and runtime source watch sets.

No confirmed unsupported implementation credit was found within the existing narrow atomic descriptions. For example, a declared interface, source-level resource limit or stored-decision label is credited only as that limited behavior; none certifies the future complete capability. Operational demonstration atoms remain uncredited. Some frozen Phase 0 criteria mix implementation and verification language; this semantic limitation is disclosed, not repaired by changing the denominator.

Corrected metadata only:

- P0-001 now references actual account provisioning services as well as the strategy list.
- Missing/broad Phase 0 test references now include the reviewed specific suites, including MultiAccountSelectionTest and CandidateLabValidationLifecycleTest.
- P0-125 now references EvaluateStrategyCandidateJob, which owns durable run linkage. Scheduling/rejection tests alone do not establish linkage.
- Each Phase 0 blocker states precisely whether local V1 passed or remains incomplete; all production/browser/acceptance gates remain visible.

No application, test suite, risk configuration, schema or scoring-calculator code changed. Original baseline/snapshot files remain byte-identical. New progress is appended in [history](history/phase0-evidence-20261027.json); [current scorecard](VECTOR_PROJECT_COMPLETION_SCORECARD.md) and traceability are regenerated.

## Actual executions

Exact commands, UTC start/end timestamps, exit codes and log hashes: [commands.jsonl](evidence/phase0-20261027/commands.jsonl). Retained runner: [runner.py](evidence/phase0-20261027/runner.py). Commands use a separate copied workspace, never the developer `.env`. The runner refuses to initialize over an existing workspace; for a future rerun choose new task-owned names/output directory and record the differences.

PHP 8.5.12 test image; newly created PostgreSQL 17.11 `bybit_test`; task-owned Redis 7.4; UTC; no published ports; network namespace has no external network. Image digests and resource labels are retained. Host: Linux/WSL2 x86_64, Node 24.19.0, Go 1.24.6. Go binary was built from the tested source into the isolated workspace; the tracked worker binary was not replaced. This is local environment evidence, not deployed image parity.

| Check | Actual result / artifact |
|---|---|
| Focused lifecycle/funding/bootstrap/Candidate Lab | 33 passed, 1,238 assertions, no skips; [JUnit](evidence/phase0-20261027/focused.xml) |
| Full PHP Unit/Feature/Integration on PostgreSQL | 902 passed, 1 skipped of 903; 8,106 assertions, zero errors/failures; [JUnit](evidence/phase0-20261027/full.xml) |
| PostgreSQL concurrency/idempotency | Four actual Binance child-process insertion race cases passed, along with unique-constraint negatives, observation migrations and PHP→Go persisted-result integration; exact cases in P0-007 evidence |
| Frontend | 63 passed, no skips; source/contract tests, **not browser rendering proof** |
| Vue/TypeScript | `npm --prefix frontend run build` passed (`vue-tsc --noEmit` then Vite) |
| Go | `go test -race -count=1 ./...` passed in 11 tested packages; six packages have no test files; vet passed; gofmt listed no files |
| Laravel Pint | Passed, 683 files |
| PHP syntax | All files under app/config/database/routes/tests passed; full per-file log retained |
| Scorecard | 25 startup tests passed; final tests, strict prior-ref validation and artifact verification retained alongside final diff checks |
| Prepared SQL | Two draft query errors (JSON grouping, pipeline column name) corrected; final SELECT-only script executed successfully against isolated migrated schema, mostly empty fixtures; **not production acceptance** |

The only skipped backend case is `BybitPublicApiIntegrationTest::test_public_linear_market_endpoints_match_the_documented_contract`, requiring `RUN_BYBIT_INTEGRATION=1`. Public API conformance was not verified. No skipped PostgreSQL test is counted as passed. Historical golden fixtures remain labelled test datasets; they are not today's 26-account forward-paper performance.

Some test names say “concurrent” but simulate a completed first worker followed by a second worker in one process. Those establish duplicate handling, not a true simultaneous PAPER-worker race. Only actual multiprocess cases are labelled concurrency evidence. No new AI/LLM or trading integration was added; pre-existing suites were exercised in isolation.

## Performance limitation

Existing ingestion benchmark: 30 measured repetitions after five warmups per case, exact synthetic decimals, faked HTTP and real PostgreSQL commits. Two runs retained; first overlapped PHP lint. Repeat without that task load:

| Rows / scenario | P50 ms | P95 ms | SQL queries |
|---|---:|---:|---:|
| 100 new | 14.675 | 20.584 | 2 |
| 100 duplicate | 5.826 | 8.903 | 1 |
| 100 mixed | 11.060 | 19.278 | 2 |
| 500 new | 58.375 | **74.860** | 2 |
| 500 duplicate | 18.194 | 21.360 | 1 |
| 500 mixed | 38.812 | 44.923 | 2 |

500-new-row P95 fails the roadmap `<50ms` gate (first run: 65.641ms). Benchmark process exit 0 means execution/correctness checks succeeded; it does **not** mean the latency gate passed. [Performance assessment](evidence/phase0-20261027/performance-assessment.json) records FAIL independently. The script's `prior_baseline_ms=51.11` is a historical constant, not a measurement from this task. Host variance, full process RSS and PAPER/research mixed-load headroom are not established.

Reproduction: use the retained isolated runner with `php scripts/benchmark-binance-ingestion.php --runs=30 --warmup=5 --revision=<tested-SHA>`. Minimal next engineering step: profile PHP transformation versus INSERT timings on this fixture, then propose a separate measured fix if warranted. No runtime root cause or minimal code patch is claimed from latency alone. No functional regression failed, and no unrelated remediation was mixed into this commit.

## Ordered remaining work

Every ID has an exact local procedure, missing assertions, production procedure, environment and acceptance condition in the [matrix](evidence/phase0-20261027/MATRIX.md). Runtime code changes are **not yet established** by missing test coverage. Test-only extensions are explicitly listed; production actions require operator authorization. Highest-priority packets:

| Priority / IDs | Specific next action and acceptance | Environment / owner / code |
|---|---|---|
| 1 Financial integrity: P0-003, F04/F06/F11/F12, I03/I07/I08 | Q1/Q3 plus certified schedules, execution/funding/journal records and valuation timestamps. Recalculate quantities held at each settlement, fees, signed funding, balances and drawdowns. Identify all five historical late cases by ID. Missing marks/rates stay unknown; no adjustments. Complete direct partial-position Statistics test. | Operator production SELECT-only + local QA; no repair authorized; test changes needed for F11 |
| 2 History preservation: P0-001/005, F09/F26/F27 | Q1/Q3 census/history packet and effective Binance configuration. Preserve ACTIVE and BLOCKED statuses and every historical position. Test all 26 selectors; establish rejected-audit immutability/real concurrent retry semantics locally. | Operator read-only + isolated PostgreSQL/browser QA; no activation, capital reset or code fix assumed |
| 3 Deployed baseline: P0-007/008, every V2 | Obtain current release/image/schema/config evidence and actor-attributed packets for every finding; do not reuse old report verdicts. Independent acceptance maps every acceptance ID. | Production operator; no deployment or workflow trigger authorized |
| 4 PAPER workflows: P0-002/004, I01/I04/I06/I09 | Complete all-failed and targeted recovery matrix plus missing RiskEngine boundary assertions. Q4 and existing logs must prove terminal acknowledgments, partial failures and no replay of successful peers. Keep 5%/10% limits. | Isolated PostgreSQL QA then operator observation; tests required, code only if a reproduced defect warrants separate scope |
| 5 Research readiness: P0-006, F13–F21/F25 | Q5 real Binance forecast populations; retain 30-observation gate. Execute candidate job locally through owned run linkage and verify no promotion. Complete exchange/cutoff/fallback negatives listed per finding. | Isolated PostgreSQL/browser QA then operator read-only; test additions, no new features |
| 6 Performance/UI evidence: P0-009, F01–F03/F05/F07/F08/F10/F22–F24 | Profile failed ingestion gate; measure bounded local mixed load and obtain existing effective production limits. Complete successful-path no-write, export schema/cohort, envelope/error and pagination assertions, then nine browser V3 demonstrations. | Local QA/performance and authorized operator telemetry; scoped performance fix may be needed, not attempted here |

`Fnn` maps to `VEC-P0-1nn`; `Inn` maps to `VEC-P0-2nn`. These groups prioritize work; they do not collapse distinct obligations.

## Operator procedure boundary

Prepared [operator.sql](evidence/phase0-20261027/operator.sql) uses an explicit REPEATABLE READ, READ ONLY transaction, SELECT statements and transaction-local timeouts, ending in ROLLBACK. Only built-in read expressions are invoked. PostgreSQL takes ordinary read locks; statement/lock timeouts bound waiting. Use a SELECT-only role, an operator-selected database and at most a seven-day UTC window. Limit sentinels mean incomplete output and require smaller partitions; do not silently truncate acceptance populations.

Schema execution was checked locally after draft fixes. The artifact is a diagnostic collection aid, not an automatic financial reconciler. Funding rows alone cannot discover absent market events; certified schedules must enumerate expected boundaries separately. Q1 is current account state, not reconstructed as-of state. Q5 is a timestamp-qualified population inventory, not proof that every strategy should trade; inspect actual cohort fallback and effective limits. The older pipeline SQL's pooled `GATING_PASSED_ACTIVE` and hardcoded expected-slot denominators are not accepted as readiness evidence.

No production connection was opened. No operational Artisan command, repair, forced funding reconciliation, initializer, allocation, activation, restart or deployment was run. Production API reads/browser navigation listed in the matrix require review of the deployed controller/middleware side effects before operator execution; otherwise use existing captured responses. Rejected promotion, missing-funding and failed-worker production cases must be observed from existing evidence, never induced. No unrelated shared-VPS resource was inspected.

## Exact Phase 1 prerequisites

1. Satisfy all 99 frozen Phase 0 obligations with current requirement-specific evidence: retain the 18 passes, resolve the failed performance obligation, fill 26 local gaps, collect all 45 authorized operational packets and nine browser demonstrations.
2. Resolve contradictions or actual defects through separately scoped remediation and rerun affected evidence; do not waive production, histories, risk thresholds or minimum statistical samples. Source changes invalidate watched evidence until reverified.
3. Supply every Phase 0 acceptance criterion with demonstrated outcome, responsible `accepted_by` actor and explicit authorization reference. Verify dependency acceptance and freshness (local 90 days, operational seven days, product 30 days).
4. Obtain a Phase 1 assignment naming the first vertical increment after Phase 0 acceptance. The tracker currently lists **all 45 Phase 0 requirements** as preventing Phase 1. A local commit, passing suite or this evidence report is not that approval.

Product remains 0% because no production/browser outcome has been demonstrated and explicitly accepted under the policy. Baseline frozen history is preserved; no push or deployment occurred.

## Session recovery and finalization

Continuation started at the same HEAD with 65 staged files, no unstaged changes and no untracked files. The previous session had already executed the suites, removed its isolated PostgreSQL/Redis containers, admitted evidence and generated the immutable snapshot. These executions are inherited evidence, not tests newly executed by the continuation.

Recovery revalidated artifact integrity and current dependency hashes, reran tracker checks, restored the omitted progress-history index entry and corrected the Go package count from 12 to 11 using the retained log. Final commands and outcomes are retained separately in `evidence/phase0-20261027/continuation-checks.json`; original logs and snapshots remain unchanged. No application or test source changed. The 26 incomplete local obligations are coverage/acceptance gaps, not all production-access blockers; their test extensions remain work to do. No additional obligation is credited by finalization.

The continuation tracker run passed all 25 tests, artifact verification and strict prior-ref validation passed, and host Pint passed. Unfiltered `git diff --check HEAD` returned 2 solely for trailing whitespace/blank endings in raw PostgreSQL/Pint logs. These hashed execution artifacts are preserved verbatim; the separate authored-file whitespace check excludes only evidence `*.log` files. No failing runtime result was suppressed.
