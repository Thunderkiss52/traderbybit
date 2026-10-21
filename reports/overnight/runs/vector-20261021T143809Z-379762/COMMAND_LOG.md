# COMMAND_LOG.md — VECTOR v1.1 overnight runs

Append-only. One entry per command group with the real exit code and the raw log path.

## Run `vector-20261021T143809Z-379762` (2026-10-21T14:38:09Z → soft shutdown 2026-10-21T16:38:09Z)

| # | UTC | Command | Exit | Result / raw output |
|---|---|---|---|---|
| 1 | 14:39 | `git rev-parse HEAD && git status --short && git branch -a` | 0 | HEAD `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55`, branch `main`, dirty worktree preserved |
| 2 | 14:41 | `pg_isready -h 127.0.0.1 -p 5432 / -p 5433` | 0 | 5432 no response, 5433 accepting; `pg_hba.conf` mode 0640 root/postgres → not readable by uid 1000 |
| 3 | 14:42 | `psql -h 127.0.0.1 -p 5433 -U bybit_signals` (password from `.env`) | 2 | `fe_sendauth: no password supplied` — `.env` `DB_PASSWORD` is empty; socket peer auth → `role "thunder" does not exist` ⇒ **TEST_DB_ISOLATION_UNVERIFIED** |
| 4 | 14:47 | `XDEBUG_MODE=off vendor/bin/phpunit --no-coverage` | 0 | 600 tests, 565 passed, 35 skipped, 0 failed, 3271 assertions — `runs/vector-20261021T143809Z-379762/logs/phpunit_full_run3.txt` |
| 5 | 14:49 | `cd research-go && go build ./... && go vet ./...` | 0 | build ok, vet ok |
| 6 | 14:50 | `cd frontend && node --test tests/*.test.js` | 0 | 51/51 pass |
| 7 | 14:52 | `cd research-go && go test ./...` | 0 | all packages ok |
| 8 | 14:54 | `XDEBUG_MODE=off ./vendor/bin/pint --test` | 1 | **fail**: `scripts/p5_archive_evaluation.php` (unary_operator_spaces, not_operator_with_successor_space, blank_line_before_statement) — CI blocker of the previous session |
| 9 | 14:56 | `XDEBUG_MODE=off ./vendor/bin/pint scripts/p5_archive_evaluation.php && ./vendor/bin/pint --test` | 0 | fixed, then `{"tool":"pint","result":"passed"}` |
| 10 | 15:00 | synthetic benchmark 3 trials × 2k/5k/10k (`research-worker -benchmark`) | 0 | iteration-1 numbers, variance captured |
| 11 | 15:40 | `go run ./cmd/research-bench -data-dir …/audit_data -symbol BTCUSDT -interval 5 -trials 3 -cpuprofile … -heapprofile … -out …` | 0 | realistic baseline: backtest 76/82/78 ms, patterns 89/71/82 ms, 289 trades, -9778.82229158 — `profiles/realdata_bench_btcusdt_5m.json` |
| 12 | 15:45 | `go tool pprof -top -cum` on the iteration-1 profile | 0 | `CalculateRSI` 57.14% cum, `big.Rat.norm` 42.86%, `Decimal.Float64` 20.63% |
| 13 | 16:05 | `go test ./internal/algorithms/ ./internal/decimal/` after the integer RSI change | 0 | ok (golden `70.46413502` unchanged) |
| 14 | 16:06 | `cd research-go && go test ./... && go vet ./...` | 0 | all packages ok, vet ok |
| 15 | 16:08 | synthetic benchmark 3 trials × 2k/5k/10k after the change | 0 | 2k 20–22 ms, 5k 48–54 ms, 10k 101–119 ms, trades/PnL identical to iteration 1 |
| 16 | 16:10 | `go run ./cmd/research-bench … -out …/realdata_bench_btcusdt_5m_after.json` | 0 | backtest mean 37.0 ms, patterns mean 31.7 ms, allocations 85.8 MB → 34.5 MB, output identical |
| 17 | 16:11 | `go tool pprof -top -cum` on the iteration-2 profile | 0 | `CalculateBollingerBands` 45.45% cum, `Decimal.Float64` 30.30% (follow-up, not changed) |
| 18 | 16:12 | `go test -race ./internal/algorithms/ ./internal/decimal/ ./internal/backtest/ ./internal/candidates/ ./tests/golden/` | 0 | ok, no race detected |
| 19 | 16:14 | `go run ./cmd/research-candidates -data-dir …/audit_data -out …/S7_evidence_after_rsi_opt.json` | 0 | all 5 cohorts identical to `reports/profit-growth/S7_evidence.json` except `generated_at_utc`, the absolute manifest path and `stale_after_hours` |
| 20 | 16:16 | `gofmt -l .` in `research-go` | 0 | no output |
| 21 | 16:18 | `go test ./internal/decimal/ -run Scaled -v`, `go test ./internal/algorithms/ -run RSI -v` | 0 | 4 new/affected tests pass |

| 22 | 14:52 | `git add <scoped list>` + `git commit -F msg -- <scoped list>` | 0 | commit `4a04261`, 152 files, +34990/-223 |
| 23 | 14:53 | extraction verification: `git archive HEAD` + copy of the committed files + `cp -r vendor` into `/tmp/opencode/verify_commit`, `vendor/bin/phpunit --no-coverage` | 1 → 0 | first run 557 tests / **1 failed** (`DeploymentStateValidationAndRecoveryTest::test_competing_independent_process_blocked_by_lock`, caused by the excluded `docker/scripts/common.sh`); after adding that file to the scope: **557 tests, 524 passed, 33 skipped, 0 failed**, exit 0 |
| 24 | 14:55 | `cd /tmp/opencode/verify_commit/research-go && go build ./... && go vet ./... && go test ./...` | 0 | all packages ok |
| 25 | 14:56 | `cd /tmp/opencode/verify_commit/frontend && node --test tests/*.test.js` | 0 | 46/46 pass (the 5 QIM frontend tests are not part of the commit) |
| 26 | 14:57 | `cd /tmp/opencode/verify_commit/frontend && npm run build` | 0 | production build ok, `ProfitabilityResearchPage` chunk emitted |
| 27 | 14:58 | `git fetch origin && git rev-list --left-right --count origin/main...main` | 0 | 0 behind, 1 ahead |
| 28 | 14:58 | `git push origin main` | 0 | `f2e3ace..4a04261 main -> main`; the only workflow (`.github/workflows/deploy.yml`) is `workflow_dispatch` only, so no deployment, no image publish and no production mutation was triggered |
| 29 | 14:58 | restore of the four split files from `/tmp/opencode/full/` | 0 | the QIM content of `routes/api.php`, `bootstrap/app.php`, `frontend/src/router/index.ts` and `frontend/src/layouts/MainLayout.vue` is back in the worktree; `docs/QUOTE_INTEGRITY_SPEC_FINAL.md` is still staged |

| 30 | 15:01 | `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAllocationTest` | 0 | 8 tests, 8 passed, 40 assertions |
| 31 | 15:01 | `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAttributionServiceTest` | 0 | 4 tests, 4 passed, 24 assertions (no regression) |
| 32 | 15:04 | `php scripts/p1_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P1_evidence.json` | 0 | 38 positions / 76 fills, status `COMPUTED`, manifest checksums verified, sha256 `da678ce0a20e09512c29f50a2b20944efcd8cd8bb743609221b4999a417fd9b0` |
| 33 | 15:05 | `XDEBUG_MODE=off php artisan test --no-ansi --filter=SlippageAttributionPostgresIntegrationTest` | 0 | 2 tests, **2 skipped** (no reachable PostgreSQL test instance) -> `TEST_DB_ISOLATION_UNVERIFIED` |
| 34 | 15:06 | `cd frontend && node --test tests/*.test.js` | 0 | 51/51 pass |
| 35 | 15:07 | `./vendor/bin/pint --test` -> `./vendor/bin/pint` | 0 -> 0 | 3 files fixed (style of the new P-1 allocation code), re-check clean |
| 36 | 15:07 | `XDEBUG_MODE=off php artisan test --no-ansi` (full suite) | 0 | 608 tests, 573 passed, 35 skipped, 0 failed, 3311 assertions |
| 37 | 15:08 | `cd frontend && npm run build` (after the TS5076 `??`/`||` fix) | 0 | type check clean, production build in 7.30s |
| 38 | 15:13 | `XDEBUG_MODE=off php artisan test --no-ansi tests/Feature/Research` (targeted endpoint smoke checks: G1/P-1/P-2/P-5/P-4/P-6, auth, pagination, source correctness) | 0 | 136 tests, 136 passed, 853 assertions |
| 39 | 15:16 | `cd research-go && go build ./... && go vet ./... && go test ./... && go test -race ./internal/algorithms ./internal/decimal ./internal/backtest ./internal/candidates` | 0 | all packages ok (final regression) |
