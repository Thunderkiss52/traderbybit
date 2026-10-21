# Run status — 2026-10-21T08:30:47Z

| Phase | Status | Started UTC | Finished UTC | Evidence |
|---|---|---|---|---|
| PRECHECK | DONE | 2026-10-21T08:30:47Z | 2026-10-21T08:30:47Z | reports/overnight/PREFLIGHT.md |
| 1A AUDIT RESIDUAL | VERIFIED | 2026-10-21T08:38:00Z | 2026-10-21T08:42:02Z | reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z |
| 2 GO PROFILING (RSI O(N^2)) | VERIFIED (prior session) | 2026-10-20T21:39:49Z | 2026-10-20T22:29:44Z | reports/overnight/GO_PROFILE_BASELINE.md |
| 1B PRIMARY ACCOUNT INVARIANT | VERIFIED (prior session) | 2026-10-20T21:39:49Z | 2026-10-20T22:29:44Z | reports/overnight/checkpoints/phase_1b_primary_invariant_20261020T220812Z |
| P-2 RISK & CAPITAL EFFICIENCY | IMPLEMENTED + VERIFIED (unit+feature+regression); real data DATA_UNAVAILABLE | 2026-10-21T08:45:00Z | 2026-10-21T09:05:00Z | reports/profit-growth/03-p2-risk-and-capital-efficiency.md, reports/profit-growth/P2_evidence.json |
| 7 FIVE GO CANDIDATES | IMPLEMENTED + VERIFIED (engine, golden parity, §7.7 controls, real-data evaluation on the local archive); 0 of 5 eligible | 2026-10-21T09:05:00Z | 2026-10-21T09:35:00Z | reports/profit-growth/04-s7-research-candidates.md, reports/profit-growth/S7_evidence.json |
| P-5 CONCENTRATION & DEPENDENCE | IMPLEMENTED + VERIFIED (unit+feature+API+real-data archive evaluation) | 2026-10-21T09:37:00Z | 2026-10-21T09:59:00Z | reports/profit-growth/05-p5-concentration-dependence.md, reports/profit-growth/P5_evidence.json |
| P-4 COST STRUCTURE / MFE-MAE | IMPLEMENTED + VERIFIED (unit+feature+API+real-data archive evaluation) | 2026-10-21T10:00:00Z | 2026-10-21T10:40:00Z | reports/profit-growth/06-p4-cost-mfe-mae.md, reports/profit-growth/P4_evidence.json |
| P-6 RESEARCH DECISION GATE | IMPLEMENTED + VERIFIED (unit+feature+API+published-evidence evaluation) | 2026-10-21T10:23:00Z | 2026-10-21T10:32:00Z | reports/profit-growth/07-p6-decision-gate.md, reports/profit-growth/P6_evidence.json |
| 8 A0-A3 AUTONOMY (PAPER-ONLY, FLAG-OFF) | IMPLEMENTED + VERIFIED (unit+feature+API+real-data evaluation) | 2026-10-21T10:34:00Z | 2026-10-21T10:42:00Z | reports/profit-growth/08-a0-a3-autonomy.md, reports/profit-growth/A0_A3_evidence.json |
| 2 GO PROFILING ITERATION 2 (exact-integer RSI) | VERIFIED (parity + golden hash + benchmark) | 2026-10-21T14:20:00Z | 2026-10-21T14:47:00Z | reports/overnight/GO_PROFILE_ITER2_REALISTIC.md, reports/overnight/runs/vector-20261021T143809Z-379762/profiles/ |
| 3 P-1 PARTIAL-CLOSE FINAL SANITY | VERIFIED (unit + service regression + offline archive evaluation) | 2026-10-21T14:59:00Z | 2026-10-21T15:09:00Z | reports/profit-growth/02-p1-slippage-attribution.md (3.1), reports/profit-growth/P1_evidence.json |
| 9 FULL REGRESSION + EVIDENCE + GIT CLOSEOUT | VERIFIED (PHP 608 / frontend 51 / Go build+vet+test+race); commit 4a04261 pushed | 2026-10-21T14:47:00Z | 2026-10-21T15:18:00Z | reports/overnight/COMMAND_LOG.md, reports/overnight/checkpoints/phase_p1_partial_close_20261021T161800Z_20261021T150816Z |

## Last update: 2026-10-21T15:18:00Z

Final verification snapshot of this run:
- `XDEBUG_MODE=off php artisan test --no-ansi` -> **608 tests, 573 passed, 35 skipped, 0 failed, 3311 assertions** (exit 0)
- `XDEBUG_MODE=off php artisan test --no-ansi tests/Feature/Research` -> 136 tests, 136 passed, 853 assertions (exit 0) - targeted endpoint smoke checks for G1/P-1/P-2/P-5/P-4/P-6, auth, pagination and source correctness
- `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAllocationTest` -> 8 tests, 8 passed, 40 assertions (exit 0)
- `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAttributionServiceTest` -> 4 tests, 4 passed, 24 assertions (exit 0)
- `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAttributionPostgresIntegrationTest` -> 2 tests, **2 skipped** (no reachable PostgreSQL test instance) -> `TEST_DB_ISOLATION_UNVERIFIED`
- `./vendor/bin/pint --test` -> `{"tool":"pint","result":"passed"}` (exit 0)
- `cd frontend && node --test tests/*.test.js` -> 51/51 pass (exit 0); `npm run build` -> exit 0 (7.30s)
- `cd research-go && go build ./... && go vet ./... && go test ./... && go test -race ./internal/algorithms ./internal/decimal ./internal/backtest ./internal/candidates` -> exit 0, all packages ok
- P-1 partial-close final sanity: corrected realized-scope allocation, 72.6789 USDT headline withdrawn, evidence regenerated
- Git: commit `4a04261` pushed to `origin/main` (`f2e3ace..4a04261`, exit 0); the P-1 partial-close changes of this run are **not committed** (a further commit needs a new owner authorisation)
- No live orders, no trade keys, no production deploy/migration, no VPS action, no ECONOM resource touched, no paper-ledger mutation, no primary account modified

## Last update: 2026-10-21T10:42:00Z

Verification snapshot after A0-A3:
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Autonomy tests/Feature/Research/AutonomyResearchTest.php` -> 43 tests, 43 passed, 354 assertions
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 600 tests, 565 passed, 35 skipped, 0 failed, 3271 assertions (+15 tests / +355 assertions since P-6)
- `./vendor/bin/pint` on the touched A0-A3 paths -> clean
- `php scripts/a0_a3_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/A0_A3_evidence.json` -> exit 0; SHA-256 `856075b6e20c01bae9d9ef4be6c8fad3fe17789249fc39ca3b256169eaa7897a`; eligible 0 of 5 (`NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT`)
- Go: `go build ./...` ok, `go test ./...` ok; frontend: `node --test tests/*.test.js` 51/51, `npm run build` ok
- A0: candidate registry (5 cohorts, mirrored from `research-go/internal/candidates/registry.go`), immutable registration hash; `btc_eth_lead_lag` registered but not executable
- A1: challenger state machine (preregistration hash required, cannot skip data check, cannot register after OOS inspection), append-only experiment transitions
- A2: paper-only promotion Gate-6, feature flags OFF by default, kill switch + precommitted rollback triggers (4) + immutable approval content, `applied=false` always, `BLOCKED_FLAG_OFF` / `BLOCKED_KILL_SWITCH` / `NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT`
- A3: correlation-aware allocation proposals, cluster-capping, portfolio-volatility estimate, `total_risk_increased=false`, `ledgers_merged=false`, `applied=false`
- No live orders, no trade keys, no strategy/risk-budget change, no paper-ledger mutation, no primary account touched, no deployment

## Last update before that: 2026-10-21T10:33:00Z
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 557 tests, 522 passed, 35 skipped, 0 failed, 2917 assertions
  (516 after P-4; +41 P-6 tests: 30 unit, 11 feature/API).
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Decision tests/Feature/Research/P6DecisionGateTest.php tests/Feature/Research/P6DecisionGateApiTest.php` -> 41 tests, 41 passed, 112 assertions.
- `./vendor/bin/pint --test` on the touched P-6 paths -> clean.
- `php scripts/p6_archive_evaluation.php --evidence-dir=reports/profit-growth --out=reports/profit-growth/P6_evidence.json` -> exit 0.
- Real-data result (published evidence, read-only): PROFITABILITY/IMPROVEMENT/CONCENTRATION_RISK/COST_STRUCTURE
  claims -> COLLECT_MORE_DATA (first blocking gate INPUT_GENERATIONS_ACCEPTED: P-2 is not an accepted
  generation); RISK_REJECTED_OPPORTUNITY_CLAIM -> INSUFFICIENT_EVIDENCE (P3_NOT_AUTHORIZED). No claim reaches
  ELIGIBLE_FOR_OWNER_REVIEW: P-1 and P-5 publish no reconciliation flag and P-4/P-5 publish no sample-sufficiency
  declaration, so the gate fails closed. No hypothesis has a matched baseline/counterfactual population, so no
  recovery estimate is shown. `strategy_paths_modified=false`, `orders_placed=0`, `paper_ledger_mutations=0`.
- No production access, no live orders, no P-3 replay, no ECONOM, no destructive git, no docker, no
  primary paper account touched. P-6 writes only to research_generations and research_decision_records.

## Last update before that: 2026-10-21T10:45:00Z

Verification snapshot after P-4:
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 516 tests, 481 passed, 35 skipped, 0 failed, 2803 assertions
  (was 488 after P-5; +28 new P-4 tests).
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/CostMfe` -> 20 tests, 20 passed, 103 assertions.
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeTest.php` -> 5 tests, 5 passed, 33 assertions.
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P4CostMfeMaeApiTest.php` -> 3 tests, 3 passed, 18 assertions.
- `./vendor/bin/pint` on the touched P-4 paths -> fixed, then clean.
- `cd research-go && go build ./... && go vet ./... && go test ./...` -> all packages ok (cached).
- `php scripts/p4_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P4_evidence.json` -> exit 0;
  archive SHA-256 matches the manifest for paper_accounts, virtual_positions, virtual_executions,
  position_funding_events and market_candles.
- Real-data result (local research export, read-only, 38 positions): MFE/MAE computed for 38/38 positions
  (median MFE 0.513573561064 R, median MAE 0.720161997701 R, 0 ambiguous paths); pre-entry expected MFE
  34/38 COMPUTED and 4/38 INSUFFICIENT_DATA (no matured prior observation); cost attribution over 6 strategies /
  2 symbols / 6 accounts / 2 fill roles with `Gross - Fees + Funding = Net` reconciling in every group;
  3 gross-positive/net-negative positions; 6 ambiguous executions; 2 positions with a missing entry execution
  in the bounded export, reported explicitly together with the 4.385208466320 fee delta they explain.
- No production access, no live orders, no P-3 replay, no ECONOM, no destructive git, no docker, no
  primary paper account touched. The paper ledger is read-only for P-4; writes go to research-owned tables only.

## Last update before that: 2026-10-21T10:00:00Z

Verification snapshot after P-5:
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 488 tests, 453 passed, 35 skipped, 0 failed
  (was 462/427/35 after P-2; +26 new P-5 tests).
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Unit/Research/Concentration` -> 14 tests, 14 passed, 56 assertions.
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P5DependenceConcentrationTest.php` -> 9 tests, 9 passed.
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage tests/Feature/Research/P5ConcentrationApiTest.php` -> 3 tests, 3 passed, 16 assertions.
- `./vendor/bin/pint --test ...` on the touched P-5 paths -> clean.
- `php scripts/p5_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P5_evidence.json` -> exit 0;
  archive SHA-256 of `paper_accounts.csv`, `virtual_positions.csv`, `equity_snapshots.csv` match the archive manifest.
- Real-data result (local research export, read-only): 38 positions, 6 of 8 primary accounts with
  positions, 0 excluded intervals; combined `SAME_SIDE` 11 clusters / 38 members, total net PnL
  -260.362805849568 (share ratios NULL with reason `TOTAL_NET_PNL_NOT_POSITIVE`); `OPPOSITE_SIDE`
  24 clusters / 16 bipartite edges; cross-symbol gross peak 39817.600096300000 (never netted);
  equity alignment `NOT_COMPARABLE` (observed snapshot gaps up to 11940 s exceed the fixed 3600 s
  carry-forward age, nothing interpolated or zero-filled).
- No production access, no live orders, no P-3 replay, no ECONOM, no destructive git, no docker, no
  primary paper account touched. The paper ledger is read-only for P-5; writes go to research-owned
  tables only.

## Last update before that: 2026-10-21T09:36:00Z

Verification snapshot after section 7:
- `cd research-go && go build ./...` -> exit 0.
- `cd research-go && go vet ./...` -> exit 0, no findings.
- `cd research-go && gofmt -l .` -> no output.
- `cd research-go && go test ./...` -> exit 0, all packages ok.
- `cd research-go && go test -race ./internal/candidates/ ./internal/backtest/ ./internal/auditdata/` -> exit 0, no race.
- `cd research-go && go run ./cmd/research-candidates -data-dir ../audit_data -out ../reports/profit-growth/S7_evidence.json` -> exit 0.
- Real-data result on the local archive (11 days of 5m/60m candles, 3 funding events, 95 OI snapshots): 0 of 5 cohorts eligible; `liquidity_sweep_reversal` test net PnL -2777.69 (PF 0.221), `volatility_compression_breakout` -430.10 (9 trades), `multi_timeframe_trend_following` -649.95 (12 trades), `funding_open_interest_squeeze` 0 signals, `btc_eth_lead_lag` `EXECUTION_NOT_VALIDATED`.
- PHP side unchanged in this phase; the last full PHP regression (after P-2) was 462 tests, 427 passed, 35 skipped, 0 failed.
- No production access, no live orders, no P-3 replay, no ECONOM, no destructive git, no docker, no primary paper account touched.

## Next planned phase
A0-A3 autonomy (SPEC §8) as a local implementation only, then the final full regression and evidence bundle.
Every phase stays evidence gated: no promotion, no account change, no threshold fitting after held-out
inspection.

---

# Run status — 2026-10-21T14:38:09Z (RUN_ID vector-20261021T143809Z-379762)

| Phase | Status | Started UTC | Finished UTC | Evidence |
|---|---|---|---|---|
| PRECHECK | DONE | 2026-10-21T14:38:09Z | 2026-10-21T14:45:00Z | reports/overnight/runs/vector-20261021T143809Z-379762/PREFLIGHT.md |
| 1A/1B/1C | VERIFIED (prior session, re-verified by full suite) | 2026-10-20T21:39:49Z | 2026-10-21T08:42:02Z | reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z, reports/overnight/checkpoints/phase_1b_primary_invariant_20261020T220812Z |
| 2 GO PROFILE ITER 2 | VERIFIED (integer RSI, realistic dataset, 3 trials, pprof) | 2026-10-21T15:40:00Z | 2026-10-21T16:20:00Z | reports/overnight/GO_PROFILE_ITER2_REALISTIC.md |
| P-2 / P-5 / P-4 / P-6 / A0-A3 | IMPLEMENTED + VERIFIED (prior session, full suite re-run green) | 2026-10-21T08:45:00Z | 2026-10-21T10:42:00Z | reports/profit-growth/03..08 + {P2,P5,P4,P6,A0_A3}_evidence.json |
| 7 FIVE GO CANDIDATES | IMPLEMENTED + VERIFIED (outputs re-verified after the Go change) | 2026-10-21T09:05:00Z | 2026-10-21T16:14:00Z | reports/profit-growth/04-s7-research-candidates.md, reports/profit-growth/S7_evidence.json |
| FINAL REGRESSION | VERIFIED (PHP 600 tests / 0 failed, Go all packages + race, frontend 51/51, pint clean) | 2026-10-21T14:47:00Z | 2026-10-21T16:25:00Z | reports/overnight/COMMAND_LOG.md |

## Last update: 2026-10-21T16:30:00Z

Verification snapshot of this run:
- `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` -> 600 tests, 565 passed, 35 skipped, 0 failed, 3271 assertions (exit 0).
- `./vendor/bin/pint --test` -> `{"tool":"pint","result":"passed"}` after fixing `scripts/p5_archive_evaluation.php` (the previous session left a CI-blocking formatting defect).
- `cd research-go && go build ./... && go vet ./... && go test ./...` -> all ok; `go test -race` on algorithms/decimal/backtest/candidates/golden -> ok.
- `cd frontend && node --test tests/*.test.js` -> 51/51 pass.
- Go engine iteration 2 (integer RSI, exact parity): synthetic 2k 52-53ms -> 20-22ms, 5k 131-140ms -> 48-54ms, 10k 241-275ms -> 101-119ms; realistic BTCUSDT 5m (3168 closed candles) backtest mean 78.7ms -> 37.0ms, patterns mean 80.7ms -> 31.7ms, allocated bytes 85.8MB -> 34.5MB. Trades and net PnL identical in every case; golden fixture `70.46413502` and the locked backtest golden hash unchanged.
- Section 7 candidate evaluation re-run after the Go change: all five cohorts identical (statuses and signal counts).
- New: `research-go/cmd/research-bench` (realistic dataset benchmark with CPU/heap pprof), `internal/algorithms/rsi_parity_test.go` (oracle parity test), `internal/decimal/scaled_test.go`.
- PostgreSQL remains unusable (`5432` unreachable, `5433` auth fails, `pg_hba.conf` unreadable) => `TEST_DB_ISOLATION_UNVERIFIED` stays open; every PHP migration and DB assertion of this run stayed on the SQLite test driver.
- No production access, no live orders, no deployment, no docker, no ECONOM, no destructive git, no primary paper account touched, no P-3 replay, no promotion, all autonomy flags OFF.

## Git closeout of this run (2026-10-21T14:58:00Z)

- Commit `4a04261` "feat(research): add VECTOR profitability research packages and Go candidate engine":
  152 files, +34,990 / -223 lines. Scope: the VECTOR P-1/P-2/P-4/P-5/P-6 and A0-A3 research services,
  the eight research migrations, the read-only research API, the Profitability Research UI page and its
  tests, the five Go research candidate cohorts with the look-ahead controls, the Go engine optimisation
  iteration 2, the backtest engine fix, the evaluation scripts and the `reports/profit-growth/` evidence.
- Deliberately NOT committed: the whole QIM (quote integrity) feature, the three VECTOR specification
  files, the launcher scripts, `reports/overnight/` (run logs, checkpoints, snapshots), `bybit_test`,
  `research-go/backtest.test`, `.env`. `docs/QUOTE_INTEGRITY_SPEC_FINAL.md` stays staged exactly as found.
- `routes/api.php`, `bootstrap/app.php`, `frontend/src/router/index.ts` and `frontend/src/layouts/MainLayout.vue`
  contained both contract and QIM hunks. The committed version contains the research/contract hunks only;
  the QIM hunks are still in the worktree and were restored byte identical after the commit
  (SHA-256 of the restored files matches the pre-commit backups).
- The committed tree was verified in isolation (`git archive HEAD` + the committed files + a copy of
  `vendor`): PHP 557 tests / 524 passed / 33 skipped / 0 failed, Go build + vet + test ok, frontend 46/46
  and `npm run build` ok.
- Push: `git push origin main` -> `f2e3ace..4a04261 main -> main`, exit 0. The only workflow in the
  repository (`.github/workflows/deploy.yml`) triggers on `workflow_dispatch` only, so the push did not
  deploy, did not publish an image and did not mutate any production resource.

## Checkpoints of this run (2026-10-21)

| Checkpoint | UTC | HEAD | Archive | SHA-256 (snapshot) | Integrity |
|---|---|---|---|---|---|
| `reports/overnight/checkpoints/phase_go_iter2_20261021T163500Z_20261021T145123Z` | 2026-10-21T14:51:23Z | f2e3ace | snapshot.tar.gz (18,365,634 B) | `23e1f3a2fad2d89c9fd945b9a4602281e0e51edf0f25f5119d212bf5887d1b2a` | archive_ok=1 |
| `reports/overnight/checkpoints/phase_final_commit_20261021T150000Z_20261021T145840Z` | 2026-10-21T14:58:40Z | 4a04261 | snapshot.tar.gz (18,365,729 B) | `0b443c31788be77f19436a518d6b074399bd7fa20c75da7a79cb23f74330dbc6` | archive_ok=1 |

The launcher snapshot of this run is
`reports/overnight/runs/vector-20261021T143809Z-379762/worktree-snapshot-vector-20261021T143809Z-379762.tar.gz`
(SHA-256 `15bb76816dfba85d42f68d489cb4a79bff0cc3fdf2bc58ef7592e2a259141876`, 22,372,594 B).

## P-1 partial-close final sanity (2026-10-21T15:09:00Z)

- Contract item "P-1 partial-close final sanity -> P-2" (VECTOR v1.1 §3) is now closed with a
  corrected implementation, unit tests, a read-only archive evaluator and regenerated evidence.
- Corrected behaviour: the realized counterfactual is
  `realized_scope_net_pnl + realized_allocated_slippage_drag`; a partially closed position allocates
  its entry drag proportionally to the closed volume; a still open position contributes no realized
  entry drag; the full-fill figure stays separate and is classified `NOT_VERIFIED_SCOPE_MISMATCH`.
  The previously published `72.6789` USDT headline is withdrawn.
- Archive recomputation (`audit_data/`, as-of 2026-10-18T07:45:00Z, 8 primary accounts, manifest
  checksums verified): 38 positions / 76 fills; full-fill drag 60.86131918 USDT (2.0000 bps);
  realized-scope net PnL -260.362805849568 USDT; realized allocated drag 55.81139068 USDT; open
  unallocated drag 5.0499285 USDT; hypothetical realized-scope net PnL -204.551415169568 USDT; raw
  full-fill counterfactual -213.386012583893 USDT (`NOT_VERIFIED_SCOPE_MISMATCH`).
- The archive contains no partially closed position, so the proportional rule is proven by the unit
  test and by the synthetic contract fixture (0.021 entry / 0.010 realized / 0.011 open), not by
  archive data. This limitation is disclosed in the evidence file and in the P-1 report.
- Verification: full PHPUnit suite 608 tests / 573 passed / 35 skipped / 0 failed / 3311 assertions
  (exit 0); `SlippageAllocationTest` 8/8; `SlippageAttributionServiceTest` 4/4; frontend 51/51;
  `npm run build` exit 0; pint clean; PostgreSQL integration 2 skipped (no reachable test instance).
- Final checkpoint of the run: `reports/overnight/checkpoints/phase_p1_partial_close_20261021T161800Z_20261021T150816Z`
  (HEAD `4a04261`, snapshot SHA-256 `54cd906d8ba3a372fd2648fda22ff0d52c7e5c308ea1cd5da4f8109e3e413d71`,
  18,375,400 B, diff SHA-256 `6fa13349a4eaee07494ccfba2690ff29294504134ef1f0253e1161e17794a493`,
  `archive_ok=1`). The P-1 partial-close changes of this run are captured in the snapshot and the
  diff, but are intentionally not committed: a further commit requires a new owner authorisation.
