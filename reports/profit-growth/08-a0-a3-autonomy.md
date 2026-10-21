# A0–A3 — Autonomous paper-research packages (local, fail-closed)

**Packages:** A0 (candidate registry), A1 (challenger controller), A2 (paper-only promotion), A3 (allocation research)
**Normative source:** `VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md` §8; `VECTOR_AUTONOMOUS_PROFIT_RESEARCH_MASTER_SPEC_V1_EN.md` §4.8 and §5.1
**Mode:** research only — no live orders, no trade keys, no paper-ledger mutation, no deployment, no risk-budget write
**Status:** IMPLEMENTED + VERIFIED (unit/feature/API) with a real-data evaluation over the published section 7 results
**Evidence file:** `reports/profit-growth/A0_A3_evidence.json` (SHA-256 `0533bf44eb3aa35e5b25f6726364f95fc796c52474d4fe366d7a83fb3f7e7e7c`)

---

## 1. What was implemented

| Area | File | Purpose |
|---|---|---|
| A0 hypothesis | `app/Research/Domain/Autonomy/CandidateHypothesis.php` | Immutable registration: population, null hypothesis, metric, splits, family, thresholds, data-sufficiency statement |
| A0 registry | `app/Research/Domain/Autonomy/CandidateRegistry.php` | The five research cohorts mirrored from `research-go/internal/candidates/registry.go` with the full registration fields |
| A0/A2 sufficiency | `app/Research/Domain/Autonomy/DataSufficiencyPolicy.php` | Shared fail-closed evaluation of the precommitted sample and coverage gates |
| A1 state machine | `app/Research/Domain/Autonomy/ChallengerExperiment.php` | `PROPOSED → DATA_CHECK → REGISTERED → EXPERIMENTING → EVALUATED → {…}` with refused transitions |
| A2 promotion | `app/Research/Domain/Autonomy/PaperPromotionPolicy.php` | Gate-6 evaluation, fail-closed, `applied` is always `false` |
| A3 proposals | `app/Research/Domain/Autonomy/AllocationProposal.php` | Research-only, correlation-aware risk-capital proposals that never increase the total risk |
| Service | `app/Research/Application/AutonomyResearchService.php` | Orchestrates A0–A3; writes only to research-owned tables |
| Config | `config/research.php` → `autonomy` | Gate thresholds and the two feature flags, **OFF by default** |
| Storage | `database/migrations/2026_10_21_105500_create_research_autonomy_tables.php` | `research_candidate_hypotheses`, `research_challenger_experiments`, `research_promotion_decisions` (append-only) |
| Records | `app/Research/Domain/Research{CandidateHypothesis,ChallengerExperiment,PromotionDecision}Record.php` | Append-only read models |
| API | `routes/api.php` | `GET research/autonomy/registry`, `GET research/autonomy/experiments`, `POST research/autonomy/experiments`, `POST research/autonomy/promotions` (writes are research-token protected) |
| Offline evaluator | `scripts/a0_a3_archive_evaluation.php` | Evaluates the published section 7 candidate results against the precommitted gates (no DB, no ledger) |

## 2. Design decisions (and why)

1. **The registry mirrors the Go cohorts and adds the registration contract.** The five cohorts keep their ids, families, versions and precommitted rules; the PHP registry adds the fields A0 requires (problem statement, evidence population, expected mechanism, current weakness, primary metric, null hypothesis, expected effect sign, affected population, time-known cutoff rule, train/OOS/forward splits, familywise count, data-sufficiency statement, precommitted thresholds, risk budget, versioned rules, negative outcomes to watch, instruments). A natural-language hypothesis is never presented as an accepted strategy: every entry is a `RESEARCH_COHORT`.
2. **A registration is hashed before any result is looked at.** `CandidateHypothesis::registrationHash()` is a content hash of the whole registration; `ChallengerExperiment::canTransition()` refuses `REGISTERED` without a preregistration hash, without a completed data check, and after the out-of-sample result was seen (`PREREGISTRATION_AFTER_OOS_INSPECTION`).
3. **The state machine cannot be skipped.** `PROPOSED → REGISTERED` is an illegal transition; the data check needs a candidate that is in the registry; the promotion state additionally requires a positive A2 verdict.
4. **Failed and invalidated attempts stay in the record.** The state list contains `INSUFFICIENT_DATA`, `EVIDENCE_NEGATIVE`, `INVALIDATED` and `REJECTED`, and the storage is append-only, so an attempt is never deleted or rewritten.
5. **The promotion policy reuses the repository thresholds and never weakens them.** The gates (`config('research.autonomy.gates')`) are the Gate-6 style requirements: 20 valid UTC days, ESS ≥ 20, train ≥ 300, OOS ≥ 100, shadow ≥ 100 observations over ≥ 14 days and ≥ 50 trades, a positive net-improvement lower confidence bound and no drawdown or concentration degradation.
6. **The feature flags are OFF by default.** `research.autonomy.paper_promotion_enabled` and `research.autonomy.challenger_execution_enabled` default to `false` and are only switchable through the environment. With the flag off the verdict is `BLOCKED_FLAG_OFF`.
7. **A verdict is never applied.** `PaperPromotionPolicy` returns `research_only = true` and `applied = false` in every branch; the stored decision record has `applied = false` by construction. Nothing in A0–A3 can change a strategy, a risk budget or an account.
8. **The missing shadow history is reported, never simulated.** The absence of a prospective shadow cohort is `SHADOW_HISTORY_ABSENT`, not a zero observation count and not a manufactured history.
9. **A3 never increases risk.** The proposal scales the allocation to `min(budget, current total)`, respects the per-strategy cap, leaves the remainder unallocated and never merges a ledger. `total_risk_increased` is always `false`.
10. **The eight primary accounts are never touched.** The service only writes to the three research tables; the feature test asserts that the paper account, position and execution counts and the strategy configuration are unchanged.

## 3. Test evidence (all actually executed)

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Autonomy                    → 17 tests, 17 passed, 229 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/AutonomyResearchTest.php → 11 tests, 11 passed, 41 assertions
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage                                                   → see §3.1
./vendor/bin/pint app/Research app/Http/Controllers/Api/V1/ProfitabilityResearchController.php tests/Feature/Research tests/Unit/Research/Autonomy database/migrations/2026_10_21_105500_create_research_autonomy_tables.php routes/api.php → clean
```

### 3.1 Full regression after A0–A3

`XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` → 585 tests, 550 passed, 35 skipped, 0 failed (557 after P-6, +28 A0–A3 tests).

Covered behaviours (unit + feature): the registry is deterministic, immutable and complete; every candidate declares the required registration fields; the lead-lag cohort is registered but not executable; a missing observation fails the sufficiency check; the sufficiency check passes only when every requirement is met; an experiment cannot skip the data check, cannot be registered without a preregistration hash, cannot be registered after the OOS was seen and needs a registered candidate; the promotion state needs a positive verdict; the promotion is blocked while the flag is off; a missing shadow history yields `NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT`; a negative net-improvement LCB, a drawdown degradation and a concentration degradation each block the promotion; only a complete positive evidence set is eligible; the allocation proposal never increases the total risk and respects the cap; the registry endpoint exposes the candidates and the flags; the hypothesis registration is idempotent and append-only; a valid experiment transition is recorded; a recorded promotion decision is never applied; the paper ledger, the strategy configuration and the account set are unchanged; the write endpoints require the research token; the experiment endpoint records and refuses transitions.

## 4. Real-data evaluation (published section 7 results, read-only)

```
php scripts/a0_a3_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/A0_A3_evidence.json
→ eligible_candidates=0 of 5
  funding_open_interest_squeeze   -> NOT_ELIGIBLE (SHADOW_HISTORY_ABSENT)
  liquidity_sweep_reversal        -> NOT_ELIGIBLE (SHADOW_HISTORY_ABSENT)
  volatility_compression_breakout -> NOT_ELIGIBLE (SHADOW_HISTORY_ABSENT)
  btc_eth_lead_lag                -> NOT_ELIGIBLE (SHADOW_HISTORY_ABSENT)
  multi_timeframe_trend_following -> NOT_ELIGIBLE (SHADOW_HISTORY_ABSENT)
```

| Observation | Result |
|---|---|
| Registered candidates | 5 of 5 (registry hash recorded in the evidence) |
| Eligible candidates | **0 of 5** |
| Blocking reason for every candidate | `SHADOW_HISTORY_ABSENT` — no prospective forward-only shadow cohort exists in this environment |
| Additional failures | the archive is shorter than the 20 valid-UTC-day requirement and the cohort ESS cannot be established |
| Effect on the eight primary accounts | none — `primary_paper_accounts_touched = false` |
| Effect on the strategy and the risk budgets | none — `strategy_paths_modified = false`, `risk_budget_written = false`, `ledgers_merged = false`, `orders_placed = 0` |

This is the correct outcome of an unattended build: zero qualifying candidates is a valid result, and the packages must not invent a 14-day challenger history to change it.

## 5. Acceptance checklist (overnight spec §8)

| Requirement | Status | Evidence |
|---|---|---|
| A0: structured, immutable candidate registry with the required fields | Met | `CandidateHypothesis`, `CandidateRegistry`; unit test `test_every_candidate_declares_the_required_registration_fields` |
| A0: candidate generation cannot modify operational strategies | Met | The registry is data only; `CandidateHypothesis` has no strategy reference; the feature test asserts the strategy config is unchanged |
| A1: deterministic, frozen challenger versions without changing the baseline accounts | Met | `ChallengerExperiment` transitions carry the preregistration hash and the rules hash; the paper ledger counts are asserted unchanged |
| A1: isolated research cohorts | Met | Every transition carries an `cohort_id` that is never a primary account id |
| A1: no rejected-signal (P-3) replay | Met | No P-3 path exists in the module; the candidate registry contains only the five research cohorts |
| A1: every attempt, invalidation and failed test is tracked | Met | Append-only `research_challenger_experiments` with `INSUFFICIENT_DATA`, `EVIDENCE_NEGATIVE`, `INVALIDATED`, `REJECTED` states |
| A1: no retrospective threshold fitting on OOS outcomes | Met | `PREREGISTRATION_AFTER_OOS_INSPECTION` refuses the registration |
| A2: Gate 6 reused without weakening any threshold | Met | `config('research.autonomy.gates')` is the single source; the policy fails closed on every missing observation |
| A2: feature flag OFF by default, fail closed | Met | `research.autonomy.paper_promotion_enabled` defaults to `false`; `BLOCKED_FLAG_OFF` verdict; unit + feature tests |
| A2: immutable approvals with source ids, rollback triggers and kill switch | Partially met | The decision record is immutable and carries the source ids, the verdict and the failed gates. The rollback trigger and the kill switch are **not** implemented in this change set — they are listed as the next A2 increment in `NEXT_SESSION_PROMPT.md`. |
| A2: `NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT` when the prospective shadow history is unavailable | Met | The real-data evaluation returns it for all five candidates |
| A3: research-only allocation simulations, no ledger merge, no risk increase | Met | `AllocationProposal`; `total_risk_increased = false`, `ledgers_merged = false`, `applied = false` |
| A3: correlation-aware budgeting proposals | Partially met | The proposal is volatility-inverse weighted and accepts the correlation matrix, but the correlation is reported as context and is **not** yet used to compute the covariance-aware scaling. Listed as the next A3 increment. |

## 6. Not verified / limitations

- **The A2 rollback triggers and the kill switch are not implemented.** The decision record is immutable and the verdict is fail-closed, but there is no controller that can revert a (never applied) promotion. This is the first item of the next A2 increment.
- **The A3 proposal is not covariance-scaled.** The correlation matrix is carried through and reported, but the weights are inverse-volatility only. A covariance-aware scaling is the next A3 increment.
- **No challenger was ever executed.** The A1 controller records state transitions; it does not run a strategy in an isolated paper cohort. The overnight contract allows an *optional* local challenger simulation, and none was run in this change set.
- **The shadow history does not exist and was not fabricated.** The A2 verdict is therefore `NOT_ELIGIBLE` for every candidate; the eligible path is covered by unit and feature tests with synthetic evidence only.
- **PostgreSQL is still unusable** (`5432` unreachable, `5433` auth fails) ⇒ `TEST_DB_ISOLATION_UNVERIFIED`; the verification ran on SQLite `:memory:`. The three new research tables are unverified on PG.
- The offline evaluation consumes the published section 7 evidence, not the live database; it describes what the published evidence supports.
- No real trading path exists, was exercised or was added; no order was created; no deployment was performed.

## 7. Reproduction

```
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Autonomy
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/AutonomyResearchTest.php
XDEBUG_MODE=off vendor/bin/phpunit --no-coverage
php scripts/a0_a3_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/A0_A3_evidence.json
sha256sum reports/profit-growth/A0_A3_evidence.json
```

## 8. Next increment

1. **A2** — the rollback triggers and the kill switch (research-only, flag-gated), plus the immutable approval record that names the source generations, the rule SHA, the safety verdict, the old/new paper-only version and the monitoring thresholds.
2. **A3** — covariance-aware scaling of the allocation proposal using the measured BTC/ETH correlation, still research-only and never increasing the total risk.
3. **A1** — an optional isolated local challenger simulation that never touches the eight primary accounts.
4. The final full regression across PHP, Vue and Go, and the consolidated evidence bundle.
