# NEXT_SESSION_PROMPT.md — resume after the Go profiling iteration 2 and the scoped commit (VECTOR v1.1)

Copy/paste this entire prompt into a fresh local OpenCode/Codex session from `/home/thunder/work/traderbybit`.

```text
CONTEXT
- Repo: /home/thunder/work/traderbybit, branch main, remote git@github.com:Thunderkiss52/traderbybit.git
- HEAD is now 4a04261 ("feat(research): add VECTOR profitability research packages and Go candidate engine"),
  pushed to origin/main (f2e3ace..4a04261). The production deploy workflow is workflow_dispatch only,
  so the push did not deploy anything.
- The worktree is still intentionally dirty: the QIM (quote integrity) feature, the three VECTOR spec
  files, the launcher scripts, reports/overnight/, bybit_test and research-go/backtest.test were NOT
  committed. docs/QUOTE_INTEGRITY_SPEC_FINAL.md is still staged, exactly as it was found.
- Deadlines of the previous run: soft shutdown 2026-10-21T16:38:09Z, hard 2026-10-21T17:38:09Z.
- Constraints: no live orders/trade keys, paper-only, no production deploy/migrations, no ECONOM,
  no destructive git/docker, preserve the eight primary paper accounts/ledgers, P-3 replay prohibited,
  autonomy feature flags OFF by default.

WHAT WAS DONE IN THIS RUN
- Go engine iteration 2: the exact-rational RSI hot path was replaced by exact integer arithmetic in the
  internal scale 24 (Decimal.ScaledInt / FromScaledInt + roundHalfEvenDiv / roundHalfAwayDiv).
  Parity: golden fixture 70.46413502, the locked backtest golden hash, trade counts and net PnL are
  unchanged (2k 136/-6146.60626498, 5k 342/-9128.86034728, 10k 682/-9831.61489905, realistic BTCUSDT 5m
  289/-9778.82229158); the five section 7 candidate cohorts are unchanged.
- New research-go/cmd/research-bench measures the engine on the local realistic archive with wall/CPU
  time, peak RSS, allocations and CPU/heap pprof profiles.
- Performance: synthetic 2k 52-53ms -> 20-22ms, 5k 131-140ms -> 48-54ms, 10k 241-275ms -> 101-119ms;
  realistic backtest mean 78.7ms -> 37.0ms, patterns 80.7ms -> 31.7ms, allocated bytes 85.8MB -> 34.5MB.
- Fixed the CI blocking pint defect of the previous session in scripts/p5_archive_evaluation.php.
- The committed tree was verified in isolation (extracted from git archive + copied vendor):
  PHP 557 tests / 524 passed / 33 skipped / 0 failed; Go build+vet+test all ok; frontend 46/46 and
  `npm run build` ok.
- Evidence: reports/overnight/GO_PROFILE_ITER2_REALISTIC.md, reports/overnight/COMMAND_LOG.md,
  reports/overnight/{RUN_STATUS,CHANGES,BLOCKERS}.md, checkpoints under reports/overnight/checkpoints/.

REMAINING / NEXT (in this order)
1. Go engine iteration 3 (optional, measured hotspot): `CalculateBollingerBands` -> `Decimal.Float64` is
   now 45.45% / 30.30% of the realistic CPU profile. Acceptance rule: the replacement must reproduce the
   current float64 bit pattern for every value of the research archive AND keep the golden hash and the
   RSI parity oracle green. Do not change the decimal rounding semantics without proving bit equality.
2. PostgreSQL verification gate (still open, `TEST_DB_ISOLATION_UNVERIFIED`): the operator has to provide
   the local test role/password for the cluster on 5433 (pg_hba.conf is not readable by uid 1000). Then
   run the migrations and the integration suite once against that isolated instance. The production
   database was never contacted.
3. Prospective shadow history: A2 stays NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT until an isolated paper
   challenger has >= 14 days and >= 50 trades of forward observations. Do not fabricate it, do not lower
   Gate 6, keep the feature flags OFF.
4. P-1 partial close sanity: CLOSED in this run (see CHANGES.md). The allocation now lives in
   app/Research/Domain/SlippageAttribution/SlippageAllocation.php, the still-open positions no longer
   contribute a realized entry drag, the full-fill figure is NOT_VERIFIED_SCOPE_MISMATCH and the
   72.6789 USDT headline was withdrawn. The P-1 changes (service delegation, domain class, offline
   evaluator scripts/p1_archive_evaluation.php, unit tests, UI status surface, regenerated
   reports/profit-growth/P1_evidence.json) are NOT committed yet: a new commit requires a fresh
   owner authorisation.
5. The QIM feature and the three VECTOR spec files remain uncommitted by design; commit them only in a
   dedicated, separately reviewed change set (the QIM routes and the four split files
   routes/api.php, bootstrap/app.php, frontend/src/router/index.ts, frontend/src/layouts/MainLayout.vue
   belong together).
6. Keep reports/overnight/{RUN_STATUS,CHANGES,BLOCKERS,COMMAND_LOG}.md and the checkpoints up to date.

RULES
- Research-only, fail-closed. Never apply promotions, never merge ledgers, never write risk budgets.
- Append-only for research_decision_records, research_challenger_experiments, research_candidate_hypotheses,
  research_promotion_decisions, research_promotion_controls.
- Do not fabricate data. Missing evidence -> NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT / COLLECT_MORE_DATA.
- Never modify the eight primary paper accounts or strategy activation.
- No production/deploy/git push/docker destructive operations without an explicit new owner authorisation.
```
