# P-6 — Research decision gate

**Package:** P-6 (PROFIT-1) · **Normative source:** `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` §14 (with §11/§18 references)
**Mode:** research only — no live orders, no trade keys, no paper-ledger mutation, no deployment, no strategy change
**Status:** IMPLEMENTED + VERIFIED (unit/feature/API) with a real-data evaluation over the published evidence
**Evidence file:** `reports/profit-growth/P6_evidence.json` (SHA-256 `7937435fa9414ad17123d82a44fa69976454b27cb63ee8cb59f664a538eb9a78`)

---

## 1. What was implemented

| Area | File | Purpose |
|---|---|---|
| Decision states | `app/Research/Domain/Decision/DecisionState.php` | The eight §14.2 states and the allowed append-only transitions |
| Input package | `app/Research/Domain/Decision/DecisionInputPackage.php` | One measurement package a conclusion may depend on, with explicit `NULL` semantics |
| Decision gate | `app/Research/Domain/Decision/DecisionGate.php` | Pure, deterministic, fail-closed evaluation of the evidence |
| Outcome | `app/Research/Domain/Decision/DecisionOutcome.php` | State, reasons, missing inputs, gate results, transition legality |
| Hypothesis priority | `app/Research/Domain/Decision/HypothesisPriority.php` | §14.4 recovery-per-100-trades display and ranking, without a composite score |
| Application service | `app/Research/Application/DecisionEvidenceService.php` | Replaces the thin WIP stub: assembles the inputs, appends one decision record, read model |
| Storage | `database/migrations/2026_10_21_104500_create_research_decision_records_table.php` | `research_decision_records` (append-only research output) |
| Record | `app/Research/Domain/ResearchDecisionRecord.php` | Read model; update and delete are rejected in the model |
| API | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php`, `routes/api.php` | `GET research/profitability/decision-gate`, `POST research/profitability/generations/p6` (research-token protected) |
| Offline evaluator | `scripts/p6_archive_evaluation.php` | Runs the same pure gate over the published P-1/P-2/P-4/P-5 evidence (no DB, no ledger) |

## 2. Design decisions (and why)

1. **The gate is a pure function.** `DecisionGate::evaluate()` reads nothing and writes nothing; the same class is used by the Laravel service and by the offline evaluator, so the stored and the offline result cannot diverge.
2. **Fail-closed ordering.** The gates run in a fixed order — claim type, P-3, required inputs, scope, generation status, reconciliation, staleness, sample sufficiency, inferential readiness, preregistration, evidence bundle, registered test outcome, owner authorisation. The first blocking gate decides the state, so the outcome is deterministic and explainable.
3. **`NULL` never means "good".** A missing reconciliation, a missing sample declaration or a missing `as_of` blocks the conclusion instead of being treated as a pass. This is why the current real-data outcome is a fail-closed state rather than an approval.
4. **Stale, partial, failed, scope-mixed and unreconciled inputs are rejected for the conclusions that depend on them** (§14.1). A partial input generation asks for more data (`COLLECT_MORE_DATA`); a failed or unreconciled one is `INSUFFICIENT_EVIDENCE`.
5. **The sample gate is per package role.** P-2 must report `sample_sufficiency = SUFFICIENT` for a profitability or improvement claim; the inferential packages P-4/P-5 must meet their *own declared* sample and coverage requirements. P-1 is an attribution/accounting package and is deliberately **not** given an ESS requirement (§14.1).
6. **P-3 is refused.** Any P-3 input, and any claim type that concerns risk-rejected opportunities, is blocked with `P3_NOT_AUTHORIZED` — the P-3 replay is prohibited in this project.
7. **`REJECTED` and `EVIDENCE_NEGATIVE` are only reachable through an explicitly supplied registered-test outcome.** Missing evidence can never be relabelled as a rejection (§14.3.7).
8. **Owner authorisation is explicit and narrow.** `OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT` requires a previous `ELIGIBLE_FOR_OWNER_REVIEW` state plus an explicit actor and authorisation hash. The state still does not implement an experiment and does not touch the active strategy path.
9. **Append-only transitions.** A decision transition is inserted; the model rejects updates and deletes, and the record always carries the previous state, the actor/source, the reason, the input generation ids, the preregistration hash and the evidence-bundle hash (§14.2).
10. **The hypothesis table never invents a recovery estimate.** `historical_counterfactual_recovery_usdt_per_100_trades` is `100 * MEAN(delta_net_pnl_i)` over matched eligible lifecycles and is marked `HYPOTHETICAL`; a forward estimate is only reported for a frozen hypothesis with a sufficient OOS sample, otherwise `NOT_ESTIMATED` / `INSUFFICIENT_DATA`. Rows are ranked by the conservative lower confidence bound, untested ideas come last, and the dimensions (drawdown, cost assumptions, ESS, sample sufficiency, concentration) are kept separate — there is no composite score (§14.4).
11. **No strategy change, no account merge, no risk increase, no promotion.** The service writes only to `research_generations` and `research_decision_records`; the decision evidence records `strategy_paths_modified = false` and `orders_placed = 0`.

## 3. Test evidence (all actually executed)

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Decision                          → 30 tests, 30 passed, 69 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P6DecisionGateTest.php       →  7 tests,  7 passed
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P6DecisionGateApiTest.php     →  4 tests,  4 passed
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage                                                     → see §3.1
./vendor/bin/pint app/Research app/Http/Controllers/Api/V1/ProfitabilityResearchController.php tests/Feature/Research tests/Unit/Research/Decision database/migrations/2026_10_21_104500_create_research_decision_records_table.php routes/api.php → clean
```

### 3.1 Full regression after P-6

`XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` → 557 tests, 522 passed, 35 skipped, 0 failed, 2917 assertions (516 after P-4, +41 P-6 tests).

Covered behaviours (unit + feature): unsupported claim type refused; missing required package refused with the missing list; scope-mixed inputs refused; partial input asks for more data; failed input is insufficient evidence; unreconciled attribution input refused; attribution package without a declared reconciliation refused; stale input asks for more data; insufficient and undeclared P-2 sample ask for more data; inferential package without a declared sample requirement asks for more data; missing preregistration and missing evidence bundle refused; all gates passed produce `ELIGIBLE_FOR_OWNER_REVIEW` only; the attribution package is not given an ESS requirement; a registered test outcome produces `REJECTED` / `EVIDENCE_NEGATIVE`; an unknown test outcome is refused; P-3 input and a risk-rejected-opportunity claim are refused; owner authorisation is invalid without a previous eligible state and accepted on top of one; an illegal transition is reported instead of being applied; the decision record is appended and can neither be updated nor deleted; the service changes no strategy, creates no order and mutates no paper row; an insufficient P-2 sample blocks the owner-review state; the generation is idempotent and a conflicting payload is rejected; the read model returns the latest decision without writing; the read endpoint reports "no decision" before the first run; the write endpoint requires the research token; the write endpoint is idempotent and answers 409 on a payload conflict.

## 4. Real-data evaluation (published evidence, read-only)

```
php scripts/p6_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/P6_evidence.json
→ PROFITABILITY_CLAIM=COLLECT_MORE_DATA (INPUT_GENERATIONS_ACCEPTED)
  IMPROVEMENT_CLAIM=COLLECT_MORE_DATA (INPUT_GENERATIONS_ACCEPTED)
  CONCENTRATION_RISK_CLAIM=COLLECT_MORE_DATA (INPUT_GENERATIONS_ACCEPTED)
  COST_STRUCTURE_CLAIM=COLLECT_MORE_DATA (INPUT_GENERATIONS_ACCEPTED)
  RISK_REJECTED_OPPORTUNITY_CLAIM=INSUFFICIENT_EVIDENCE (P3_NOT_USED)
```

The evaluator derives the input packages from the published evidence files (SHA-256 recorded for each) and runs the same pure gate:

| Package | Evidence | Derived status | Sample sufficiency | Reconciliation |
|---|---|---|---|---|
| P-1 | `P1_evidence.json` | `SUCCEEDED` (reproduction published) | not applicable (attribution package) | **not declared** in the published evidence |
| P-2 | `P2_evidence.json` | `PARTIAL` (`status = NOT_VERIFIED`) | `NOT_VERIFIED` | not declared |
| P-4 | `P4_evidence.json` | `SUCCEEDED` | **not declared** | `true` (every cost-attribution group reconciles) |
| P-5 | `P5_evidence.json` | `SUCCEEDED` | **not declared** | not declared |

Consequences, all fail-closed and none of them an approval:

- No claim reaches `ELIGIBLE_FOR_OWNER_REVIEW`. The first blocking gate is `INPUT_GENERATIONS_ACCEPTED` (P-2 is not an accepted generation), followed by the undeclared sample sufficiency of the inferential packages and the undeclared reconciliation of P-1.
- The risk-rejected-opportunity claim is refused outright (`P3_NOT_AUTHORIZED`).
- The hypothesis priority table contains one row without a point estimate: no registered hypothesis with a matched baseline/counterfactual population exists, so no recovery number may be shown (§14.4).
- The evidence records `strategy_paths_modified = false`, `orders_placed = 0`, `paper_ledger_mutations = 0`.

Conclusions that cannot be made with the current evidence: no profitability or improvement ranking (the 24-hour archive cannot support one), no cost or fee cut may be justified from it, and no hypothesis may be promoted.

## 5. Acceptance checklist (§14)

| Requirement | Status | Evidence |
|---|---|---|
| Consumes only accepted generations of P-1/P-2/P-4/P-5 | Met | `DecisionEvidenceService::buildInputPackages()` selects by package code, scope and status; the gate refuses a non-accepted generation |
| Rejects stale, partial, scope-mixed and unreconciled inputs | Met | Gates `INPUT_GENERATIONS_ACCEPTED`, `INPUTS_NOT_STALE`, `SCOPE_CONSISTENT`, `INPUTS_RECONCILED` with unit tests |
| Fail-closed sample gate (P-2 `SUFFICIENT` + P-4/P-5 declared requirements) | Met | Gates `P2_SAMPLE_SUFFICIENCY` and `INFERENTIAL_PACKAGES_READY`; the real-data evaluation shows the fail-closed outcome |
| P-1 is an attribution package without an ESS requirement | Met | `ATTRIBUTION_ONLY_PACKAGES`; unit test `test_attribution_package_is_not_imbued_with_an_ess_requirement` |
| P-3 only when separately authorized and accepted | Met | Gate `P3_NOT_USED` refuses every P-3 input and the risk-rejected-opportunity claim |
| Append-only state transitions with the required fields | Met | `research_decision_records`; the model rejects updates/deletes; the record carries actor, reason, previous/new state, input generation ids, preregistration hash, evidence-bundle hash |
| The eight decision states with the specified meanings | Met | `DecisionState`; each state's meaning is asserted by the unit tests |
| Preregistration and evidence bundle are mandatory | Met | Gates `PREREGISTRATION_PRESENT`, `EVIDENCE_BUNDLE_PRESENT` |
| Inconclusive evidence is not relabelled as rejected | Met | `REJECTED`/`EVIDENCE_NEGATIVE` require an explicit registered test outcome; unit test `test_unknown_test_outcome_is_refused` |
| No strategy change, account merge, risk increase or promotion | Met | Feature test `test_the_service_never_changes_a_strategy_and_never_creates_an_order`; the decision evidence records `strategy_paths_modified = false` |
| Hypothesis priority table with sample size, generation ids, assumption version and `HYPOTHETICAL` status | Met | `HypothesisPriority`; unit tests for the scaling, the `NOT_ESTIMATED` path and the ranking |
| No invented composite score | Met | The row keeps the dimensions separate; unit test `test_the_dimensions_are_kept_separate_and_no_composite_score_is_produced` |

## 6. Not verified / limitations

- **The eligible path is verified by unit and feature tests with synthetic inputs, not with real data.** No real accepted generation set exists in this environment, so `ELIGIBLE_FOR_OWNER_REVIEW` was never reached against real evidence. This is a property of the available data, not a defect of the gate.
- **PostgreSQL is still unusable** (`5432` unreachable, `5433` auth fails) ⇒ `TEST_DB_ISOLATION_UNVERIFIED`; the P-6 verification ran on SQLite `:memory:`. The `research_decision_records` schema, its JSON columns and its indexes are unverified on PG.
- The append-only guarantee is enforced in the application layer (the model rejects updates/deletes). A database-level trigger would additionally protect the table against direct SQL, but it is not portable to SQLite and was therefore not added.
- P-1 and P-5 do not publish a reconciliation flag, and P-4/P-5 do not publish a sample-sufficiency declaration. The gate therefore refuses their conclusions until the owning packages publish those fields; adding them would change already-published packages and their hashed evidence, so it was not done inside P-6.
- The offline evaluation consumes *published evidence files*, not database rows; it therefore describes what the evidence supports, not the live database state.
- No inferential test, no bootstrap interval and no forward paper cohort were executed: no registered hypothesis with a matched baseline/counterfactual population exists.
- No live/paper trading path was exercised, no order was created, and no deployment was performed.

## 7. Reproduction

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Decision
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P6DecisionGateTest.php
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P6DecisionGateApiTest.php
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
php scripts/p6_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/P6_evidence.json
sha256sum reports/profit-growth/P6_evidence.json
```

## 8. Next package

A0–A3 autonomy (SPEC §8) as a local implementation only, and the final full regression / evidence bundle. The decision gate stays closed until an accepted generation set, a published sample-sufficiency declaration and a registered hypothesis with a matched population exist.
