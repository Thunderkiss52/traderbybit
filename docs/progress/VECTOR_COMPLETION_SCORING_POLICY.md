# VECTOR completion scoring policy

Version 1. Initial scope `VECTOR-SCOPE-20261027-v1` is **PROPOSED** pending owner ratification of weights. Scores are **PROVISIONAL**, not audited readiness. JSON was chosen over YAML because Python's standard library supports JSON deterministically without dependencies; the repository already uses standalone Python engineering scripts. The tracker never boots Laravel, connects to a database, dispatches jobs or participates in candle processing.

## Commands and source of truth

```sh
python3 scripts/vector_progress.py
python3 scripts/vector_progress.py --format=json
python3 scripts/vector_progress.py --format=markdown --phase=0
python3 scripts/vector_progress.py --generate
python3 scripts/vector_progress.py --strict --base-ref HEAD
python3 scripts/vector_progress.py --compare docs/progress/history/initial.json
python3 scripts/vector_progress.py --format=json --output=/tmp/vector-progress.json
python3 -m unittest discover -s scripts/tests -p test_vector_progress.py -v
```

Prefix shell commands with the repository's RTK wrapper when working interactively. JSON registry `vector-requirements.json` owns requirement criteria, assessments and evidence. `baselines/` freezes scope, meaning, dependencies and weights. `current.json`, the scorecard and traceability are generated projections. `history/` contains immutable recorded snapshots; generation never overwrites history. `--output` and each generated file use temporary-file/fsync/atomic rename. A crash between generated files is detected by `--strict`; regenerate the projections.

Every invocation calculates scores from actual registry records, current source fingerprints, Git ancestry and the UTC assessment date; no manually entered percentages are consumed. A checked-in scorecard is an explicitly dated snapshot, not a live operational dashboard. CLI source HEAD is current; assessment HEAD identifies the reviewed baseline. `--phase` filters detail while overall scores and next actions remain global. `--as-of=YYYY-MM-DD` supports reproducible historical review; it does not convert current source into an old checkout or override evidence dependencies.

## Three independent denominators

For runtime requirement r, implementation fraction I_r is the count of atomic criteria marked IMPLEMENTED with current source evidence divided by all its implementation criteria. UNKNOWN and PLANNED receive zero. Existence of tests, documentation or a class alone is not proof of a full runtime feature: source assessments describe exactly the limited behavior credited. Non-runtime assurance and Phase 6 documentation are excluded from A entirely.

Verification V_r is sum of weights of current PASS obligations divided by all obligation weights. Latest appended attempt controls an obligation: failure, skipped, unavailable or stale evidence receives zero. Test existence is only a test reference. A test suite's total pass count is not allocated to all requirements. Operational obligations have weight 2 within a requirement; other obligations have weight 1 in the initial proposal.

Product P_r is 1 only for a demonstrated and explicitly accepted requirement outcome with all its implementation criteria and applicable verification obligations current, valid product evidence covering every acceptance criterion, and accepted prerequisite outcomes. Otherwise it is 0. Partial capability acceptance comes from independently accepted requirement outcomes, not subjective confidence. Each requirement belongs to exactly one primary phase capability. Its requirement weight determines its share of that capability's accepted fraction. An entire capability is counted accepted only when all constituent outcomes are accepted. Documentation-only Phase 6 can eventually receive verification/product credit through explicit document review; it never contributes runtime implementation or real trading credit.

For each metric m and phase p: S_mp = sum(w_r × fraction_mr) / sum(w_r) over eligible requirements in that phase. Global score = 100 × sum(W_mp × S_mp) / sum(W_mp). This is equivalent to the requested weighted requirement formula using effective weight W_mp × w_r / sum_phase(w_r). Calculations use exact rational fractions until four-decimal display rounding. The three metrics never multiply one another's global percentages.

| Phase | Implementation W | Verification W | Product W | Rationale |
|---|---:|---:|---:|---|
| 0 | 25 | 30 | 25 | Account, financial, risk and operational safety gate |
| 1 | 15 | 12 | 15 | Strategy developer workflow and immutable identity |
| 2 | 20 | 18 | 20 | Trustworthy financial visibility and audit |
| 3 | 15 | 15 | 15 | Bounded deterministic research |
| 4 | 15 | 15 | 15 | Statistically justified PAPER admission and rollback |
| 5 | 10 | 8 | 8 | Portable public-data adapters |
| 6 | 0 | 2 | 2 | Future boundary documentation only |

Weights are engineering/product judgments, not measured effort or probability of success. Within phases, financial/risk/funding/rollback items generally receive 3 and other runtime capabilities 2. Phase 0 critical runtime/acceptance items receive 3; individual F/I closure obligations receive 1. Fixed phase budgets prevent many small frontend or remediation rows from inflating their phase's total global influence. Runtime fixes covered by F/I closure are credited only in their owning runtime criteria; the F/I rows are non-runtime assurance obligations. They add explicit verification/acceptance obligations, not duplicate implementation.

Initial weighted A is 42.2882%. Phase 0 contributes 19.6429 percentage points, Phase 1 2.8125, Phase 2 8.1818, Phase 3 4.7727, Phase 4 5.1136 and Phase 5 1.7647 (rounded contributions). Giving each runtime phase equal weight would instead produce approximately 36.9645%; this sensitivity shows why weights must remain visible and stable. Neither number is an audited universal measure of project completion.

## Evidence contract and invalidation

Source evidence contains reviewer, full assessment Git SHA, date, environment, limitations, source path/anchor and dependency fingerprint. Runtime credit cannot use Markdown or tests as implementation evidence. The initial review is a focused reuse assessment, not a repeat of the entire audit; uninspected F/I closure remains UNKNOWN. Criterion wording and source anchors must be reviewed together; automatic hashing cannot prove semantic correctness or reviewer honesty.

Execution evidence additionally contains requirement and obligation IDs, evidence type, PASS/FAIL/SKIPPED/UNAVAILABLE result, environment, fixture/dataset, synthetic flag, reviewer, artifact path/SHA-256 and dependency fingerprint. Production evidence requires authorization reference and cannot be synthetic. Accepted product evidence also records all demonstrated acceptance IDs, accepted_by and authorization reference. This is explicit acceptance by a responsible reviewer/operator, not an implementation commit or an automatically generated label. Evidence artifacts must be retained in a version-controlled, appropriately redacted local path; secrets and unredacted production dumps must not be added.

Current evidence requires: valid metadata; matching environment/type/ownership; assessment SHA an ancestor of current HEAD; unchanged watched dependency content; matching artifact digest; and age within the obligation's declared maximum. Initial lifetimes: 7 days for operational evidence, 90 days for local/integration/document review, and 30 days for product acceptance. These are conservative proposed policies, not external guarantees. Product acceptance may expire and scores decrease. Future-dated evidence is stale. Evidence retries append; a later failed result invalidates earlier passes.

Watch sets intentionally include shared app/config/schema/routes/dependencies/frontend/Go and test trees for runtime assessments. This over-invalidates some unrelated changes rather than silently certifying stale behavior. Refining watch sets is an explicit reviewed scope revision because missing dependency edges can fabricate freshness. Source references and missing artifacts fail structural validation; edited dependencies/expired evidence reduce scores. The unchanged registry cannot retain a higher score after dependency changes.

Historical reports are trace references only in this initial baseline. Available reports list test totals and local completion claims, but no claim is imported without mapping current dependency hashes, execution artifact, environment, fixture, date and independent reviewer. Zero admitted verification evidence is therefore a conservative lower bound, not a finding that old tests failed. Tracker tests validate this tool; they do not satisfy trading-platform obligations or prove PAPER profitability.

## States and transitions

Keep criterion states, obligation attempts and product acceptance separate. `current_state` is the recorded workflow state; generated effective state reflects current evidence and can regress automatically after expiry or code changes. State labels never award points themselves.

Allowed recorded advances: NOT_STARTED → IN_PROGRESS or IMPLEMENTED_UNVERIFIED; IN_PROGRESS → IMPLEMENTED_UNVERIFIED; IMPLEMENTED_UNVERIFIED → VERIFIED_LOCAL / VERIFIED_INTEGRATION / VERIFIED_OPERATIONAL; VERIFIED_LOCAL → VERIFIED_INTEGRATION / VERIFIED_OPERATIONAL / ACCEPTED; VERIFIED_INTEGRATION → VERIFIED_OPERATIONAL / ACCEPTED; VERIFIED_OPERATIONAL → ACCEPTED. Stages may enter BLOCKED/FAILED when evidence warrants it. BLOCKED may return to IN_PROGRESS/IMPLEMENTED_UNVERIFIED; FAILED returns to IN_PROGRESS/BLOCKED. Verified/accepted records may regress to IN_PROGRESS, BLOCKED or FAILED; accepted may return to IMPLEMENTED_UNVERIFIED. Each recorded transition requires `state_change.from` and a reason when compared with the prior Git baseline.

ACCEPTED requires all required evidence, not a direct jump from implementation. VERIFIED states cannot exist without required passing evidence records. Stale previously valid evidence downgrades effective scores/state rather than retaining acceptance. NOT_APPLICABLE is deliberately rejected in the active denominator: removing an obligation requires an authorized scope revision; production evidence cannot be silently waived. Initial population is a baseline assessment, not a claim that each historical transition was observed.

## Scope, history and CI

Registry version controls format; scope baseline ID controls denominator and requirement meaning. Freeze IDs, phase/capability ownership, requirement weights, criteria, obligations, environment/lifetime, dependencies and watch paths. Scope changes require a new baseline file/ID, change log and explicit approval record. Never edit historical baseline/snapshot files. Rename or remove requirements only through a documented mapping in the new change log; retain the old baseline. Compare same-scope snapshots for delivery deltas; different scopes return SCOPE_CHANGED and no delivery delta. First baseline returns NO_COMPARABLE_SNAPSHOT, never fabricated progress from informal percentages.

The separate `progress.yml` workflow tests the tool and checks registry/report consistency on pull requests. Full Git history allows ancestry and prior-baseline checks. It uses no credentials, databases, services or deployment jobs. `--strict` validates structure, reference existence, scope equality, denominator presence, unique IDs/atomic ownership, acyclic dependencies, evidence provenance, deterministic projections and freshness relative to the stored snapshot. It succeeds with explicitly missing evidence and a zero score; it does not claim platform readiness. `--base-ref` additionally enforces immutable history, append-only attempts, scope revision approval and recorded transitions. Without a trusted prior Git ref, strict mode cannot authenticate historical immutability.

## Required future development workflow

Before a stage, record current scores, HEAD and relevant requirement IDs; do not implement an already-present behavior again merely because its verification is missing. Run applicable tests in permitted environments. Attach actual outcomes, including skips/failures, source evidence and dependency hashes. Update separate implementation/verification/product fields and state transition reason. Regenerate scorecard and traceability; run focused tests plus strict prior-ref checks. Record a new immutable history snapshot and explain delivery versus scope differences, blockers and before/after scores. Commit the implementation and progress metadata locally together. Product acceptance needs explicit outcome evidence and cannot be inferred from that commit.

Do not execute Phase 1 until all Phase 0 acceptance prerequisites pass. Production diagnostics require their own authorization; reporting itself requires none. No live integration, LLM, Binance activation, financial mutation, account reset, per-candle work, push, deployment or unrelated resource access is introduced by this tracker.
