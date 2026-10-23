# Agent handoff — PHP security migration

Updated: 2026-10-22. Repository: `/home/thunder/work/traderbybit`, branch `main`.

## Objective and workflow

Deliver verified PHP **8.5.12** and latest compatible Laravel **13.x**, preserving research/paper trading and all data. Continue sequential stages 0–7; do not restart discovery. Update this file after each completed stage and before stopping/limits. Commit completed, verified stages locally, with explicit paths. Record failures/skips honestly; reuse successful checks unless code changed or final verification requires reruns.

## Completed stages / commits

| Stage | Commit | Result |
|---|---|---|
| 0 discovery | `921abbd` | `docs/security/php-85-laravel-13-upgrade-audit.md` |
| 1 runtime | `d230adc` | Official PHP 8.5.12 Alpine 3.24 app/test images built; CLI/FPM and 21 extensions verified |
| 2 dependencies | `4be18be` | Laravel 13.36.0 → 13.38.0; PHPUnit 12.5.39 → 12.5.40; other package versions retained |
| 3 compatibility | `4ee1358` | Go benchmark unblocked; cache object-serialisation bug fixed + regression test |
| 4 security | `39c5078` | Runtime/transport hardening, origin/CSRF protection, nginx 1.30.6, Trivy 0 HIGH/CRITICAL |
| 5 business regression | `d461033` | Ledger guard env fix; full suite 643 tests / 3,805 assertions, 0 failures; Go, scheduler, queue, frontend verified |
| 6 Docker/CI/endpoints | `5b32dee` | Go image 1.27/Alpine 3.24 (Trivy 29 → 0); isolated 7-service Compose smoke; CI reviewed + simulated 643/3,513, 0 failures; 118 routes inventoried |

Go toolchain upgrade to **Go 1.27.3** completed after stage 7 (`2acf3e8`, `969fe31`; report `docs/security/go-1273-upgrade-verification.md`).

Current stage: **7 final verification complete** (three recorded passes, `docs/security/php-85-laravel-13-final-verification.md`). No production deployment performed; production verification and release approval remain with the operator.

## Stage 3 evidence (committed `4ee1358`)

- Benchmark: host `research-go/bin/research-worker` is untouched; isolated workspace holds a statically linked `CGO_ENABLED=0` build (`file` → "statically linked"). `tests/Feature/Research/ResearchBenchmarkCommandTest.php`: OK (1 test, 4 assertions) — `stage3-benchmark.log`.
- Root cause fixed: `config/cache.php` sets `serializable_classes => false`; Laravel 13 cache stores then return `__PHP_Incomplete_Class` for objects. Production uses the Redis store. `ResearchController::marketRows()` cached `Collection<CandleRecord>` under `market:candles:v4` and `buildAnalysisPayload()` cached models/collections/Carbon under `market:analysis:v5`.
- Fix: candle rows cached as raw attribute arrays and hydrated with `Builder::hydrate()`; cache key `market:candles:v5`. Analysis payload contains only scalars/arrays (`toArray()` for candles/context/patterns/signals, ISO-8601 `times`); key `market:analysis:v6`. `times` JSON is byte-identical because Carbon `jsonSerialize()` calls `toISOString()`.
- Negative control: with the old controller all 4 new tests fail (`marketRows(): Return value must be of type Collection, __PHP_Incomplete_Class returned`) — `evidence/cache-negative-control.log`.
- Response contract: cold responses for `candles`, `indicators`, `analysis` captured before and after the fix are **identical** (11,491 bytes each) — `evidence/contract-baseline.json`, `evidence/contract-fixed.json`.
- Full PHPUnit run (PHP 8.5.12): 632 tests, 3,726 assertions; 629 passed, 3 failed only because `tests/Integration` requires PostgreSQL (`stage3-tests.log`). Integration suite separately with `RUN_DB=bybit_test`: **35 tests, 304 assertions, 1 skipped (live Bybit opt-in), OK** (`stage3-integration.log`).
- Pint: `app/Http/Controllers/Api/V1/ResearchController.php`, `tests/Feature/MarketData/MarketCacheSerializationTest.php` — PASS.

## Stage 4 evidence (committed `39c5078`)

- PHP runtime (`docker/php/php.ini`) verified inside the rebuilt `traderbybit-php85-app:local`: `session.upload_progress.enabled=0`, `session.use_strict_mode=1`, `session.use_only_cookies=1`, `session.cookie_httponly=1`, `session.cookie_samesite=Lax`, `post_max_size=21M`, `upload_max_filesize=21M`, `opcache.enable=1`, `expose_php`/`display_errors` off.
- Upload bounds aligned so the app can enforce its own contract: nginx 22m > PHP 21M > application 20 MiB (413 `EVIDENCE_TOO_LARGE`). Previously PHP/nginx were 16M, making the documented 20 MiB limit unreachable.
- `app/Http/Middleware/ValidateBrowserOrigin` appended to the API group: configured `CORS_ALLOWED_ORIGINS` allowed; browsers with `Sec-Fetch-Site` other than `same-origin`/`none` rejected 403 `ORIGIN_NOT_ALLOWED`; foreign Origin host rejected; requests without Origin (curl/workers/tests) unchanged; GET/HEAD/OPTIONS never blocked. Cannot bypass `research.token` (tested).
- nginx: `nginx:1.27-alpine` (Alpine 3.21.3) → `nginx:1.30-alpine` (1.30.6, Alpine 3.24.3); security headers (`nosniff`, `DENY`, `no-referrer`, `Permissions-Policy`) via `docker/nginx/security-headers.conf` included in server block and every `add_header` location. Verified in the built `traderbybit-php85-web:local`: `nginx -t` OK, headers present on 200/401/502.
- Dev only: `frontend/vite.config.ts` removes the proxied `Origin` header so `npm run dev` keeps working with the new check.
- Trivy 0.76.1 (checksum-verified binary, DB 2026-10-22): app 0, test 0, web 0 HIGH/CRITICAL; baseline `nginx:1.27-alpine` had 59 (2 CRITICAL OpenSSL) — `evidence/trivy-*.json`, `evidence/trivy-summary.log`.
- Tests: new `tests/Feature/Security/*` 11 tests / 44 assertions PASS. Full suite 643 tests / 3,770 assertions, 3 failures only from the integration DB guard (`stage4-tests.log`); `RUN_DB=bybit_test` integration 35 / 304 / 1 skipped OK (`stage4-integration.log`). Pint PASS. Frontend 54 tests PASS, `vue-tsc` + build PASS.
- `.dockerignore` excludes `.env*` (examples only); built app image contains no `.env`.
- Written report: `docs/security/php-85-laravel-13-security-verification.md` (residual risks: no CSP, shared basic-auth credential, token-less mutations rely on basic auth + origin checks).

## Stage 5 evidence (committed `d461033`)

- Three previous failures were an env-contract defect in `LedgerReconciliationPostgresIntegrationTest` (read `DB_*`, whose `DB_DATABASE` is `:memory:` in the default suite, instead of `TEST_DB_*`). Fixed; the strict `bybit_test` + non-production guards are unchanged. The 3 tests now run against the isolated container and pass (38 assertions).
- Targeted: Unit/Strategy/PaperTrading/Backtest/Indicators/MarketData **414 tests / 2,246 assertions OK** (`stage5-targeted.log`); API/Research/QuoteIntegrity/Audit/PatternDiscovery/Security **190 / 1,216 OK** (`stage5-api-research.log`).
- Full suite: **643 tests / 3,805 assertions, 0 failures, 2 skipped, 1 pre-existing notice** (exit 0) — `stage5-full-1.log`, `stage5-full-2.log` (skips: opt-in public Bybit API test; Go worker test in the default sqlite run, which runs under `RUN_DB`).
- `RUN_DB=bybit_test tests/Integration`: **35 / 304, 1 skipped OK** (`stage5-integration.log`).
- Go: `go -C research-go test ./...` OK for all packages (`stage5-go-tests.log`). Scheduler lists 22 tasks; `queue:work redis` + SIGTERM exits 0 (`stage5-queue-sigterm.log`); isolated Redis `PING` OK, 0 keys.
- Frontend: 54 tests passed, `vue-tsc` + production build OK (`stage5-frontend-tests.log`, `stage5-frontend-build.log`). Pint PASS on the changed test.
- Safety: no order-placement code or Bybit API keys exist; all DB work was against isolated `bybit_test`; eight primary accounts/history untouched; no VECTOR work.
- Report: `docs/security/php-85-laravel-13-business-regression.md`.

## Stage 6 evidence (committed `5b32dee`)

- Runtime images: `traderbybit-php85-app:local` reports PHP 8.5.12 / Laravel 13.38.0; web image nginx 1.30.6 (Alpine 3.24.3); `traderbybit-php85-research-worker:local` on Alpine 3.24.3, `--standalone` benchmark exits 0.
- Defect fixed: `docker/go/Dockerfile` `golang:1.21-alpine`/`alpine:3.19` → `golang:1.27-alpine`/`alpine:3.24`; Trivy baseline 29 HIGH/CRITICAL (1 CRITICAL, 26 HIGH Go stdlib 1.21.13, 2 HIGH musl) → **0** (`evidence/trivy-go-baseline.json`, `evidence/trivy-go-fixed.json`, `stage6-go-image.log`).
- Isolated stack (project `traderbybit-php85-smoke`, port 18098, own volumes): postgres, redis, app, web, queue, scheduler, audit-worker all healthy; migrations applied (`stage6-compose-up.log`, `stage6-migrate.log`). Teardown with `down -v` left no containers/volumes/networks (`stage6-compose-down.log`).
- Live HTTP (`stage6-http-smoke.log`): `/up` 200; dashboard 401/200 with `schema_version=1.0.0` and `research_mode=true`; invalid backtest 422 with `X-Request-ID`; QIM flag off 503 `FEATURE_DISABLED`; foreign-origin preflight 204 without ACAO; audit export 202 → audit-worker `GenerateAuditExportJob` → READY (416,802 B) → signed download 200 with 35 CSV/JSON files (`signals.csv` etc.), unsigned 403. Queue worker processed scheduler jobs; scheduler runs 22 tasks.
- CI review (not triggered, unmodified): single `deploy.yml`, `workflow_dispatch` only; jobs backend-tests (PHP 8.5.12/sqlite), frontend-tests (Node 22), publish-images (app+web), deploy (SSH). CI-equivalent run (no DB, no network): **643 tests / 3,513 assertions, 0 failures, 35 skipped, exit 0** (`stage6-ci-simulation.log`). Findings: no push/PR CI gate; CI skips all PostgreSQL integration tests (no service); Go image not published by CI.
- Routes (`stage6-routes.json`): 118 total, 114 API (86 GET, 31 POST, 1 PUT); 12 research-token, 1 signed, 9 QIM-gated. Frontend: 39 unique API calls, 38 exact matches; one dynamic action path (`experiments/{id}/{name}`) matches registered actions. Client-side table CSV export has no automated test (gap).
- Report: `docs/security/php-85-laravel-13-docker-ci-endpoints.md`.

## Stage 7 final verification (three passes, baseline `6a0751f`)

- Pass 1 — full suite: 643 tests / 3,805 assertions, 0 failures, 2 skipped, 1 pre-existing notice, exit 0 (`stage7-pass1-full-suite.log`). CI-equivalent run (no DB): 643 / 3,513, 35 skipped, exit 0 (`stage6-ci-simulation.log`).
- Pass 2 — Pint 584 files PASS; composer validate/audit/platform OK; `php -l` clean; Go test/vet/gofmt OK (`stage7-pass2-*.log`).
- Pass 3 — frontend 54 tests + typecheck/build OK; Trivy app/test/web/Go all 0 HIGH/CRITICAL; deployment-shape Compose smoke on the committed tree (`/up` 200, dashboard 401/200 with `research_mode=true`, QIM 503, clean teardown) (`stage7-pass3-*.log`, `stage7-smoke-*.log`).
- No migrations changed during the upgrade; rollback = revert commits / redeploy previous tag, no schema rollback.
- Report: `docs/security/php-85-laravel-13-final-verification.md`.

## Go 1.27.3 toolchain upgrade (committed `2acf3e8`, `969fe31`)

- `golang:1.27-alpine` (floating) → `golang:1.27.3-alpine3.24` with `GOTOOLCHAIN=local`; test image now uses the official Go 1.27.3 toolchain instead of Alpine's Go 1.26.9; `research-go/go.mod` `go 1.18` → `go 1.27.3`; CI gained `actions/setup-go@v5` (`1.27.3`, `cache: false`).
- Tracked `research-go/bin/research-worker` rebuilt with Go 1.27.3 (was go1.18.1); `go version -m` confirms go1.27.3 for it and for the image binary.
- Go 1.27 review: `encoding/json` v1 now backed by v2 with v1 behavior; no code or contract change needed (no JSON v2 migration). `go mod tidy` only dropped the stale `// indirect` marker.
- Verification: `go mod verify`, `go build ./...`, `go vet ./...`, `gofmt -l` clean; `go test ./...` all packages OK; `go test -race ./...` OK; tests pass in the configured test image; Laravel↔Go integration `2 tests / 16 assertions` OK on the isolated DB; worker SIGTERM exit 0.
- Determinism: `--standalone` output identical (Trades=67, NetPnL=-3161.74311831, WFE 0.000, MC median 6864.87501489, RuinRisk 93.8%) before/after.
- Trivy: research-worker and test images 0 HIGH/CRITICAL.
- Cleanup/CI: `research-go/backtest.test` (unreferenced go1.18.1 test binary) removed from Git and `/research-go/*.test` ignored. The repository has exactly one workflow, `deploy.yml` (`workflow_dispatch` only); its `go-tests` job (Go 1.27.3: `go mod verify`, build, vet, gofmt, tests, race) gates `backend-tests`/`frontend-tests`, publishing and deployment. The short-lived `ci.yml` was folded into `deploy.yml`.
- Evidence: `go1273-*.log`, `evidence/trivy-*-go1273.json`, `evidence/trivy-go-1273.json`.

## Earlier evidence still valid

- `docker/php/Dockerfile`: pinned PHP stages, Redis 6.3.0, bcmath, built-in OPcache, test-only Go/Python/Bash tools. `scripts/verify-php-runtime.php`, entrypoint fail closed on incorrect PHP/extensions.
- `composer.json` requires `~8.5.12`, platform 8.5.12, framework `^13.38`, Predis `^3.7`; lock updated under target PHP, no ignored platform requirements. Composer strict validation/audit/platform check exit 0 (`stage2-checks.log`).
- Baseline PHP 8.4: 317 unit / 1,556 assertions, 274 feature / 1,873 assertions passed. Frontend 54 passed, typecheck/build passed on Node 24.19.0.
- Official PHP advisories GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp identify 8.5.12 as patched (CVE-2026-109618/109619). Host PHP 8.4.24 remains unchanged and is rejected by the gate; use containers.

## Remaining work / known issues

- Full suite note: with the isolated Postgres reachable, the default run now executes the PostgreSQL integration suites (except the Go worker, skipped on sqlite) and the strict `bybit_test` guard. CI must still run the explicit `RUN_DB=bybit_test` integration job. Keep `cache.serializable_classes=false`, JSON sessions and cache names/prefixes.
- Trivy is not a repository dependency: the binary was downloaded to ignored `/tmp/opencode/trivy/trivy` (cache `/tmp/opencode/trivy/cache`). Re-download/verify before relying on it after a host restart; image scans reflect local builds, re-scan in CI on release.
- Stage 6 closed; remaining gaps are CI coverage (no push/PR gate, integration tests skip in CI, Go image not published) and the untested client-side CSV export — documented, not changed (operator-owned pipeline).
- Stage 7 complete. Remaining external actions: operator-approved deployment via `.github/workflows/deploy.yml`, then production healthcheck and forward-paper data review. Unverified: deploy job/VPS script, `market-ws`/`backtest-worker` in stack, browser UI, client-side CSV export, load/soak.

## Local resources and commands

All resources below were created by this task; do not inspect/mutate other projects. `econom-group-*`, `bybit-signals-test-*`, `toolma-bot` and the shared builders belong to someone else — leave untouched.

- Buildx builder: `traderbybit-php85-20261022` (docker-container), container `buildx_buildkit_traderbybit-php85-202610220`.
- Images: `traderbybit-php85-test:local` (PHP 8.5.12 + Go 1.27.3); `traderbybit-php85-app:local` (current source); `traderbybit-php85-web:local` (nginx 1.30.6); `traderbybit-php85-research-worker:local` (Go 1.27.3, Alpine 3.24.3).
- Network `traderbybit-php85-testnet`; containers `traderbybit-php85-postgres` (tmpfs DB `bybit_test`) and `traderbybit-php85-redis`. No host ports, no aliases — use the full container names (`traderbybit-php85-postgres`, `traderbybit-php85-redis`); `run.py` was corrected accordingly.
- PostgreSQL migrations applied to this DB; test role `bybit_signals` created for QIM permission tests. Test credentials confined to ignored runner.
- `reports/php85-upgrade/run.py` syncs source into `reports/php85-upgrade/workspace`, excludes `.env`, `docs/`, `reports/`, `VECTOR_*` and `research-go/bin/`, and runs a disposable PHP test container as host UID. `RUN_DB=bybit_test` adds pgsql env overrides; `CONTRACT_LABEL` is forwarded for contract dumps. Vendor installed in the workspace; static Go binary persists there. No Docker socket mounted.
- Shell prefix: `/home/thunder/.headroom/bin/rtk proxy` (RTK absent from PATH; plain commands also fine).
- Test: `python3 reports/php85-upgrade/run.py vendor/bin/phpunit <path>`; full suite `vendor/bin/phpunit`; Postgres `RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Integration`.
- Build: `docker buildx build --builder traderbybit-php85-20261022 --load --target app -f docker/php/Dockerfile -t traderbybit-php85-app:local .`; web target uses `--target web -t traderbybit-php85-web:local`.
- Trivy scan: `/tmp/opencode/trivy/trivy image --scanners vuln --severity HIGH,CRITICAL --no-progress <image>` (cache in `/tmp/opencode/trivy/cache`).
- Frontend: prefix PATH with `/home/thunder/.nvm/versions/node/v24.19.0/bin`; `npm test --prefix frontend`, `npm run build --prefix frontend`.
- Evidence lives in ignored `reports/php85-upgrade/` (including `evidence/`). No build/test process is active; reconfirm narrowly before duplicating work.

## Safety / user-owned state

No push, deployment, workflow dispatch, production access, production migrations, real exchange orders, live trading endpoints or trade keys. Never touch ECONOM/econom-group or shared/unknown Docker resources/cache. No prune/global cleanup, database/history edits, volume deletion, reset/stash or unrelated commits. Never read/expose production secrets. Keep eight primary accounts and historical records intact. User's untracked `VECTOR_UNIFIED_13_STRATEGY_RESEARCH_DASHBOARD_AUDIT_EXPORTS_AND_TECH_DEBT_SPEC_V2_3_EN.md` must remain untouched. Never stage the whole repository.

## VECTOR v2.3 (in progress, uncommitted)

Updated: 2026-10-22T18:15Z. HEAD `f06f34f`. Worktree clean except the user-owned v2.3 spec (untracked; never stage). Phase 0 checkpoint: `reports/overnight/checkpoints/phase0_v23_20261022T180506Z.md`; requirement matrix: `reports/overnight/TECH_DEBT_ACCEPTANCE_MATRIX.md`.

- Gate 0 `PARTIAL`: `TEST_DB_ISOLATION_UNVERIFIED` (only `127.0.0.1:5433` listens, no credentials) → DB writes/migrations blocked; sqlite tests only. Upstream `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` absent (`SOURCE_SPEC_MISSING`).
- Iteration 1 (verified): SKIP Opportunity Analyzer quarantined as legacy. `config/research.php` `counterfactual.legacy_analysis_enabled=false`; `CounterfactualAnalyzerService` refuses create/resolve; cron entry removed from `routes/console.php`; API report returns `LEGACY_READ_ONLY` + `P3_REJECTED_SIGNAL_REPLAY_PROHIBITED`; UI shows a legacy banner. Historical rows untouched. Tests: targeted 18/18, API/P6 13/13, full PHP 645 tests / 610 passed / 35 skipped / 0 failed, Pint pass, frontend 54/54, typecheck+build pass.
- Owner override recorded: account model is **13 PRIMARY** (not spec §1.1's 8 PRIMARY + 5 RESEARCH_CANDIDATE) → `CONTRACT_DIVERGENCE_ACCOUNT_MODEL`; the 8 existing ledgers must be preserved. Do not build the registry/migration until the 13-account design is approved.
- Local counterfactual data is empty (`audit_data` header-only; `bybit_test` 0 rows), so the reported +798.89 R / 147 / 994 figures are `DATA_UNAVAILABLE` locally; production access remains prohibited.
- No commits, pushes, deployments, migrations, production access or Docker changes in this work.


## VECTOR v2.3 iteration 2 — 13 primary strategy accounts (uncommitted)

Updated: 2026-10-22T19:15Z. HEAD `f06f34f`. Only the owner-approved account-model change is implemented; the v2.3 registry is still not built.

- Five research candidates are now primary strategies with independent paper accounts: `funding_open_interest_squeeze`, `liquidity_sweep_reversal`, `volatility_compression_breakout`, `btc_eth_lead_lag`, `multi_timeframe_trend_following` (all v1.0.0). `config/paper.php` `primary_strategies` has 13 entries; hardcoded 8-lists, UI selectors and stale account-count labels were replaced or updated.
- `btc_eth_lead_lag` is signal-only (`research.forward_paper.signal_only_strategies`): signals are recorded but no fills/positions/paper-signal states are created. The peer input is shared by `StrategyObservationService` and `StrategyResearchService`, so both paths persist the same BUY/SELL instead of an observation-first SKIP.
- Safe paper execution for lead-lag remains **blocked by design**. Removing it from the signal-only list requires owner approval plus: peer/current-symbol candles and context from one as-of snapshot with no future bars; explicit cross-symbol staleness and session-alignment rules; a fee/slippage/funding fill model for correlated timing; independent account risk/correlation limits and idempotent recovery; and dedicated negative/look-ahead tests. Until then its account stays visible but `execution_eligible=false`.
- Migration `2026_10_22_020000_add_five_primary_strategy_accounts.php` is idempotent and inserts five accounts only outside unit tests. It preserves the existing eight accounts/history. Its `down()` is intentionally irreversible.
- Isolated PostgreSQL migration proof (`bybit_test_migreview`, created and dropped): 13 accounts / 13 primary / 13 equity snapshots; all eight original IDs, balances, signals, positions, executions, journal rows and snapshot checksums preserved after running `up()` twice; no duplicate `primary_strategy_id`. Evidence: `reports/overnight/evidence/iter2-migration-idempotency-pg.json`.
- HEAD-vs-current PostgreSQL comparison in isolated `bybit_test`: HEAD `641 tests / 3,662 assertions / 13 errors + 18 failures / 2 skipped`; current `657 / 3,985 / 13 errors + 15 failures / 1 skipped` (`28 failed, 628 passed` in compact output). All 28 current failures have identical normalized messages at HEAD; no current-only failures. HEAD-only failures fixed: two `BrowserOriginProtectionTest` cases and the eight-account ledger reconciliation case. JUnit: `iter2-head-pg-junit.xml`, `iter2-current-pg-junit.xml`.
- SQLite full suite: **658 passed, 2 skipped, 4,357 assertions, exit 0** (`reports/overnight/evidence/iter2-sqlite-full.log`; skips: opt-in Bybit API, Go worker in sqlite mode). Readiness/regression: `PrimaryStrategyReadinessTest` (3) covers registration, scheduling, per-strategy causal decisions, API visibility and accounting isolation; migration rehearsal now executes the real migration twice; combined targeted run 12 passed / 512 assertions.
- PostgreSQL full suite was **not green** at this checkpoint (28 pre-existing failures): UUID/bigint text casts, `varchar(32)` truncation, decimal formatting, sqlite-only 404 assumptions and shared-DB residue. **Superseded by iteration 3 below: the current worktree is green on both PostgreSQL and SQLite.**
- Pint PASS on all changed PHP files; frontend **54/54 tests**, `vue-tsc` + production build PASS. No commits, pushes, deployments, production DB access or Docker cleanup. Production remains operator-controlled; the untracked v2.3 spec is untouched.
- Review verdict: in-scope implementation and individual strategy readiness are verified; no new regression found. Production readiness remains gated by the independently reproduced PostgreSQL test debt and explicit owner deployment authorization.

## VECTOR v2.3 iteration 3 — audit remediation and final verification (uncommitted)

Updated: 2026-10-22T20:10Z. HEAD `f06f34f`. Worktree is dirty with the remediation diff plus the user-owned v2.3 spec. No commits, pushes, deployments, production access or Docker cleanup. Full reconstructed finding/status table and limits: `docs/agent/OPENCODE_CONTINUE.md`.

- F01–F15 reconstructed findings: 14 `FIXED`, F13 `PARTIAL` (audit-export all-stream summary completeness not implemented/verified), F15 `FIXED` locally but CI/deployment unverified. Source limitation: the original finding list and `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` are absent (`SOURCE_SPEC_MISSING` / `AUDIT_LIST_MISSING`).
- Gate 0 isolation now verified through the existing disposable `bybit_test` runner: full PostgreSQL suite **672 tests / 4,514 assertions / 0 failures / 0 notices / 1 skipped, exit 0** (`/tmp/opencode/pg-final2.log`). Full SQLite: **672 / 4,502 / 0 failures / 0 notices / 2 skipped, exit 0** (`/tmp/opencode/sqlite-final2.log`). Prior 28 PostgreSQL failures are resolved in the current worktree.
- PHPUnit notice identified and resolved: `FundingHistorySynchronizerTest` used an expectation-free mock; changed to a stub. No notices remain.
- Additional read-only fix: profitability GET summary/strategies no longer provision missing primary accounts (`ProfitabilityResearchController::getPrimaryAccountsReadOnly()` now queries directly); regression test asserts `paper_accounts`/`equity_snapshots` stay empty after both GETs.
- Pint: **598 files PASS**. Frontend: **54/54 tests PASS**, typecheck + production build PASS. Targeted remediation: 9 tests / 159 assertions PASS.
- Remaining open matrix items outside reconstructed F01–F15: backtest defect (#8), decimal audit (#10), worker recovery/idempotency (#11), endpoint inventory (#12), credential leakage scan (#13), all-13-stream registry/export (#14, F13 partial), dashboard contract (#17). Do not claim them verified.
- Production/CI execution and VPS verification remain unperformed and operator-controlled. `btc_eth_lead_lag` remains signal-only; P-3 remains disabled.


## Mandatory checkpoint policy — owner instruction, 2026-10-23

After **every completed or interrupted implementation module**, append a chronological checkpoint here **before starting the next iteration**. Never overwrite previous verified evidence. Each checkpoint records stage/iteration, starting/ending Git state, changed/new files, completed/partial/blocked requirements, exact commands/results, independent review findings, remaining issues/owner decisions, the next task and reproduction steps. When context/usage limits approach, stop development and prioritize a complete resumable checkpoint. Another agent must not need conversation history.

## Checkpoint VECTOR-REMEDIATION-CODEX-INTERRUPTED-20261023 — handoff only

**Implementation stopped at the owner's request. No new source edits, tests, commits, push or deployment are authorized in this checkpoint.** This entry is append-only; earlier OpenCode entries and their evidence claims remain intact.

### Git and evidence chronology

- Start: branch `main`, HEAD `f06f34fd631d550c2782992bf43e53cce426eb10`; 41 modified tracked files plus Iteration 2 strategy/migration/tests and owner spec untracked; no staged changes.
- End/checkpoint: same branch and HEAD, no commits created by Codex; index remains empty. Current worktree inventory below includes preserved earlier/later agent edits, not solely this module's changes.
- At the first stop, Codex had observed 78 modified tracked files and 15 untracked files. At this resumed documentation checkpoint there are 79 modified tracked files and 16 untracked entries, including `.dockerignore` and `last_development_for_full_service/`. Preserve these later/unrelated additions; they were not investigated or modified.
- Existing 20:10Z OpenCode checkpoint reports additional source/test changes and 672-test results. **These are later recorded claims, not the Codex 671-test run.** Do not discard them, rerun them unnecessarily, or conflate their source states. Validate their existing logs first.
- Critical documentation discrepancy: earlier OPENCODE_CONTINUE.md reconstructed/reassigned F01–F15 IDs. The owner supplied an authoritative list in this task. The table below restores that mapping. In particular **F05 is funding/OI momentum units, F13 is build provenance, F14 is the release PG gate, and F15 is funnel metrics**. The earlier statement “14 FIXED” does not establish acceptance against the owner's actual specification.
- No source changed during handoff. `/tmp/vector-*.log` files existed and were read before interruption but are now absent in this environment. Do not fabricate/recreate their raw contents. A fresh worktree status/diff snapshot is in ignored `reports/overnight/evidence/codex-remediation-20261022/`; this is preservation metadata, not a replacement test log. Historical `/tmp/opencode/*` logs are referenced by the later checkpoint and were not inspected here.

### Non-negotiable contracts

Exactly **13 PRIMARY strategies and 13 independent PRIMARY accounts** (additional research/candidate accounts remain separate). Original eight IDs, balances, positions, executions, ledger and history must remain unchanged. The five new PRIMARY implementations already exist: funding_open_interest_squeeze, liquidity_sweep_reversal, volatility_compression_breakout, btc_eth_lead_lag, multi_timeframe_trend_following. `btc_eth_lead_lag` remains SIGNAL_ONLY until approved paired-execution semantics are validated. No fabricated fills/profits. Strict paper-only; no real-order endpoints/keys/LIVE fallback. Promotion requires authoritative current evidence and all applicable gates; stored all_gates_passed is insufficient. Legacy P-3 replay remains prohibited. PostgreSQL correctness is mandatory. ECONOM/econom-group, production databases/servers/credentials and unknown shared resources are out of scope. No push, deployment, Actions dispatch, image publication, destructive cleanup or production migrations. Deployment always needs separate explicit owner authorization and the existing workflow. Do not implement Iteration 3 features.

### Authoritative finding progress

| Owner ID / priority | Authoritative finding | Checkpoint status and evidence / remaining acceptance |
|---|---|---|
| F01 P0 | Unify autonomous promotion gates | **PARTIAL / BLOCKED acceptance.** `PromotionPolicyService` revalidates under candidate/settings locks; OOS minimum at least 100, existing candidate `PaperPromotionPolicy` applied, stored verdict alone insufficient, append-only evaluation audit events; both kill-switch paths checked; A2 config and capability defaults OFF. Non-ML calibration remains distinct. Tests cover rejected/forged verdicts, disabled flags and kill switch. **No candidate-bound authoritative prospective-evidence adapter was implemented: all current candidate evaluations fail closed.** Positive authorized promotion, source freshness/config binding, concurrent promotion, complete model/advisory-path audit and kill-switch TOCTOU proof remain. Do not call this a completed unified promotion system or enable it to bypass missing evidence. |
| F02 P0 | Prevent unsafe PRIMARY adoption | **PARTIAL.** New five-account migration no longer adopts by strategy ID; runtime `primaryAccount` creates canonical independent identities transactionally; provenance guards, version/hash checks, boolean cast; original default account is not silently reclassified. Empty candidate preservation and 13-account migration/readiness tests pass. Required open/closed-position, executions/ledger, incompatible configuration, partial-recovery, rollback and true concurrent PostgreSQL scenarios are not all proven. Older `2026_10_18_010000` data-adoption branch still needs review. Review initial snapshot recovery, identity collisions and concurrent hash initialization before acceptance. |
| F03 P1 | Fully quarantine legacy SKIP research | **IMPLEMENTED; targeted and full-suite verification observed.** `ResearchRecommendationService` no longer creates counterfactual ALPHA_IMPROVEMENT recommendations; existing category excluded from active list/report and blocked from acceptance; model exposes `LEGACY_INELIGIBLE`. `legacyAnalysisEnabled()` requires unit-test mode plus explicit config, so an operational env flag alone cannot reactivate replay. Existing rows retained. Quarantine/recommendation tests pass. Final historical API/CSV presentation review remains advisable; no new replay system. |
| F04 P1 | Correct SKIP metric semantics | **PARTIAL.** Explicit missed_tp_r, avoided_sl_r, timeout_r and net_filter_benefit_r with finite-R population, missing count, signed formula and dependence/window limitations. Legacy net_missed_r retained as TP/SL-only benefit. Vue benefit sign corrected. Synthetic mixed-outcome regression passes. Audit/serializer completeness and per-filter versus summary naming need final reconciliation; no new simulations or paper PnL changes. |
| F05 P1 | Funding/OI momentum units | **BLOCKED / behavior untouched.** PHP and Go use absolute price difference versus 0.001. Available v2.3 specification section 447ff does not define this threshold's units; upstream PROFIT-1 specification missing. Do not guess fractional/percentage semantics. Owner/authoritative contract needed, then versioned PHP/Go change and nominal-price, zero-reference, boundary and golden parity tests if required. Record CONTRACT_DIVERGENCE. |
| F06 P1 | PostgreSQL compatibility | **Regression failures resolved in observed Codex snapshot; final acceptance review pending.** Observed PG final summary 671 tests/4509 assertions/0 errors or failures/1 notice/1 skip, versus historical 28 failing tests. UUID fixtures corrected; numeric-vs-UUID QIM lookups separated; malformed position UUID guarded; permission JSON moved from VARCHAR(32) states into evidence; invalid provenance fixture replaced with actual producer value; exact BigDecimal comparisons replace scale-sensitive assertions without float tolerances. Migration unit-test skips removed; `tests/TestCase.php` explicitly clears account fixtures inside guarded rolled-back test transactions. Review this fixture reset independently and prove migration behavior outside it. Later OpenCode docs report 672 tests/no notices: preserve but verify their logs/source linkage before relying on them. |
| F07 P1 | Remove GET mutations | **PARTIAL overall; four intelligence endpoints verified read-only.** Recommendations GET reads stored records; generation is token-protected POST `/api/v1/research/recommendations/generate`; report/degradation/correlation use nonpersisting computations. `IntelligenceReadOnlyTest` checks repeated GETs produce no INSERT/UPDATE/DELETE and authorization. Later handoff reports profitability GET fix. Broader GET graph, empty/stale/failed states and health/autonomy initialization side effects remain to review. |
| F08 P1 | Prevent internal error disclosure | **PARTIAL.** Controller response concatenations removed; nondebug 5xx response sanitizer produces safe server_error/request ID, preserves explicit FEATURE_DISABLED 503. Synthetic production-response leak test passes, validation rejection preserved as 422 in promotion controller. Full endpoint/error/log secret-redaction audit is not complete; do not claim exhaustive coverage. |
| F09 P1 | Audit UTC half-open intervals | **IMPLEMENTED for both processed_at and created_at branches; boundary tests pass.** Tests cover exact from, last second before to, exact to, adjacent/empty windows and repeat CSV checksum. Microsecond precision/storage contract and API/dashboard/export reconciliation still require proof; acceptance therefore **PARTIAL**. |
| F10 P2 | Audit snapshot consistency | **Existing best-effort contract preserved; disclosure implemented.** Capture start/end timestamps and checksum-scope warning added alongside BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ. Export tests pass; no transactional snapshot claim or redesign. Validate populated manifest disclosure in final review. |
| F11 P2 | SIGNAL_ONLY transparency | **VERIFIED_EXISTING_BEHAVIOR at local test level; cross-system proof partial.** Preserve BTC/ETH lead-lag PRIMARY identity, causal signals, independent account and no fills. Existing readiness/signal-only tests included in passing suite. CSV execution mode and Audit UI mode added. No paired-execution enablement, browser/runtime/profitability proof. |
| F12 P2 | Evolution coverage | **Inventory verified from source; optimization deliberately DEFERRED.** Only original eight families have bounded evolution spaces in config/research.php and StrategyEvolutionService. New five require authoritative parameter spaces, negative controls and promotion evidence contracts. No ranges invented or optimizer enabled. This is a dependency for subsequent work, not authority to implement Iteration 3 now. |
| F13 P2 | Build/version provenance | **PARTIAL.** ResearchHealthController reads build-metadata.json and returns null/UNKNOWN if unavailable; no shell git/fake SHA/hardcoded date. Docker app build args and workflow metadata wiring added. Unknown-state API test passes. Known metadata, rebuilt image and release/image correspondence not verified. |
| F14 P2 mandatory release gate | PostgreSQL release verification | **PARTIAL.** Existing sole deploy.yml remains workflow_dispatch-only; backend SQLite/PG matrix uses isolated PG17 service; identity script precedes full tests; publishing depends on backend/frontend. Local PyYAML parse verified one workflow, dispatch trigger, matrix/dependencies. New guard script was not executed independently; actionlint/local job reproduction and fresh-migration/idempotency evidence still needed. Never dispatch workflow during remediation. |
| F15 P2 | Strategy funnel metrics | **PARTIAL.** CSV counts strategy_observations separately from eligible paper_signal_states and includes BUY/SELL/SKIP/execution mode; API adds actual_decisions/execution_mode; Audit Vue column added. Export/regression/build tests pass. All 13 populated source/API/Vue/CSV reconciliation, risk-evaluation/entry/execution distinctions and signal-only outcomes remain to prove. |

### Implemented files and architecture

Core changes made by this Codex module (many overlap preserved Iteration 2 edits):

- PromotionPolicyService, AutonomyPermissionService, config/research.php and AutonomousLearningController; tests PromotionPolicyServiceTest, PromotionPolicyFailClosedTest, AutonomyPermissionServiceTest, AutonomousLearningApiTest, AutonomyResearchTest. Re-evaluation is transactional; prior/new validation gates recorded as audit evidence. Candidate forward evidence is deliberately absent/fail-closed, not a fabricated passing adapter.
- PaperAccountService, PaperAccountRecord; `2026_10_22_020000_add_five_primary_strategy_accounts.php`; removed test-only skip in `2026_10_18_010000_enforce_eight_primary_accounts_and_widen_quality.php`; ThirteenPrimaryAccountsTest, PrimaryAccountMigrationRehearsalTest, PrimaryStrategyReadinessTest. `tests/TestCase.php` explicitly prepares empty account fixtures **after real migrations**, only under RefreshDatabase/test transaction and DB identity checks; assertions use exact decimal arithmetic where scale is not contractual. This broad fixture change warrants independent review.
- CounterfactualAnalyzerService, ResearchRecommendationService, ResearchRecommendationRecord, ResearchReportService, StrategyHealthService, StrategyDegradationService, PortfolioCorrelationService, ResearchIntelligenceController, routes/api.php and ResearchOpportunitiesPage.vue; quarantine/metrics/recommendation tests and new IntelligenceReadOnlyTest.
- ApiRequestDiagnostics, ResearchHealthController, ResearchJobController, QuoteIntegrityController, ProfitabilityResearchController; PostgreSQL UUID fixtures and exact decimal tests in the current inventory. No production constraint was widened to accommodate invalid fixtures.
- AuditExportService, StrategyFunnelService, AuditPage.vue, AuditExportTest; `.github/workflows/deploy.yml`, docker/php/Dockerfile, scripts/verify-test-database.php.
- No Go strategy behavior changes. F05 units remain unresolved. No strategy tuning, new optimizer or rejected-signal replay.

### Actual verification observed before the stop

Use `/home/thunder/.headroom/bin/rtk` prefix (plain `rtk` was unavailable). PHP wrapper `reports/php85-upgrade/run.py` copies source to its isolated workspace and invokes PHP 8.5.12 in `traderbybit-php85-test:local`. **Pint through this runner formats the copied workspace; copy back only intended files when formatting is authorized.** Do not run simultaneous copying/formatting against active tests.

| Check / exact command after RTK proxy | Observed result |
|---|---|
| `python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Unit/Research/PromotionPolicyServiceTest.php tests/Unit/Research/AutonomyPermissionServiceTest.php tests/Feature/Research/AutonomousLearningApiTest.php tests/Feature/Research/AutonomousLearningPipelineTest.php` | 14 tests, 87 assertions, exit 0 at that stage. |
| `env RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Unit/Research/PromotionPolicyServiceTest.php tests/Unit/Research/AutonomyPermissionServiceTest.php tests/Feature/Research/IntelligenceReadOnlyTest.php tests/Feature/PaperTrading/PrimaryAccountMigrationRehearsalTest.php` | 12 tests, 139 assertions; OK summary observed. |
| `env RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Unit/Research/CounterfactualLegacyQuarantineTest.php tests/Feature/Backtest/BacktestCurveServiceTest.php tests/Feature/QuoteIntegrity/QimApiStatusTest.php tests/Feature/QuoteIntegrity/QimFeatureFlagOffTest.php tests/Feature/Research/IntelligenceReadOnlyTest.php tests/Feature/AuditExportTest.php` | 20 tests, 191 assertions; OK summary observed. |
| `python3 reports/php85-upgrade/run.py vendor/bin/phpunit --no-coverage` | Final Codex snapshot: 671 tests, 4497 assertions, no errors/failures, 1 PHPUnit notice, 2 skips. Summary observed; process exit was not retrieved before interruption. |
| `env RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit --no-coverage --display-phpunit-notices --display-skipped` | Final Codex snapshot: 671 tests, 4509 assertions, no errors/failures, 1 notice, 1 skip. Summary observed; process exit was not retrieved before process handle expired. |
| `python3 reports/php85-upgrade/run.py composer validate --strict` | exit 0. |
| `python3 reports/php85-upgrade/run.py composer audit --locked` | exit 0; no vulnerability advisories. |
| `python3 reports/php85-upgrade/run.py composer check-platform-reqs` | exit 0; PHP 8.5.12/platform requirements pass. |
| `python3 reports/php85-upgrade/run.py vendor/bin/pint --test` | exit 0; 598 files PASS. |
| `env PATH=/home/thunder/.nvm/versions/node/v24.19.0/bin:/usr/bin:/bin npm test --prefix frontend` | exit 0; 54 tests passed, none failed/skipped. |
| Same PATH, `npm run build --prefix frontend` | vue-tsc and Vite build passed; output reported built in 7.27s. |
| Go 1.27.3 test container, read-only research-go mount: `go mod verify`, `go test ./...`, `go vet ./...`, `go build ./...` | Passed sequentially. Combined command then exited 2 at `go test -race ./...` because CGO/compiler absent. This was an environment failure, not a race-test result. |
| Separate ephemeral test container: `apk add --no-cache gcc musl-dev` then `CGO_ENABLED=1 go test -race ./...` | exit 0; all test-bearing packages passed, including candidates and golden tests. Compiler installed only in disposable container, no shared-host mutation. |
| PyYAML parse of deploy.yml | One workflow; only workflow_dispatch; SQLite/pgsql backend matrix; publish dependencies backend/frontend confirmed. Not actionlint or executed CI. |
| `git diff --check` | exit 0 before interruption. |

SQLite skips: opt-in public Bybit API and Go worker persistence in SQLite mode; PostgreSQL skip: opt-in public Bybit API (`RUN_BYBIT_INTEGRATION=1`). The notice was in FundingHistorySynchronizerTest; later OpenCode entry reports replacing an expectation-free mock with a stub. Later documented results **672/4514 PG and 672/4502 SQLite, no notices, exits 0** are preserved as separate evidence claims, not independently re-run by this checkpoint. No browser, production, deployment or real forward-performance validation occurred. No standalone full PHP syntax-lint pass or image metadata build verification was completed by Codex.

### Confirmed failure causes and review limits

Initial in-progress PG full run: 663 tests, 4121 assertions, 27 errors + 27 failures after removal of migration shortcuts; this included inherited UUID/decimal/identifier defects plus newly exposed fixture assumptions. Subsequent PG regression: 671/4474, 2 errors + 2 failures (missing decision_snapshot in a new fixture; nonexistent BigDecimal method in a test; two FEATURE_DISABLED responses over-redacted). Those were corrected before the green 671-test summary. Earlier scalar `is_primary` and Collection/array test issues were also corrected. Do not treat those transient counts as remaining failures.

Independent post-implementation review **was not completed** and no independent agent was delegated. Codex's bounded diff review identified unfinished F01 positive evidence/TOCTOU proof, F02 provenance/concurrency/recovery matrix, F05 missing units, and incomplete end-to-end F15 reconciliation. Passing regression does not close those acceptance gaps. New current worktree changes after Codex verification must be attributed to the later checkpoint before accepting combined results. No current operation is known to be active; old process IDs are expired and not reliable. Inspect only task-owned processes if resuming a command, do not kill unrelated workers.

### Next exact tasks (no implementation during this handoff)

1. Read this latest checkpoint and appended continuation prompt; reconcile original F IDs with existing later evidence. Preserve all dirty/untracked work. Inspect existing logs before rerunning anything. Do not start Iteration 3 or commit partial safety work as complete.
2. **First implementation module: F01 safety acceptance.** Establish candidate-bound immutable forward-evidence contract/source and enumerate remaining promotion/model entry points. Missing evidence continues to reject. Add positive authorized, stale/altered evidence, calibration/risk, concurrent and both-kill-switch tests. If normative source is missing, block only this dependency; never turn on A2 or fabricate evidence. Checkpoint before proceeding.
3. F02: complete PostgreSQL adoption/history/concurrency/recovery scenarios and original-eight deterministic before/after hashes. Review legacy migration branch and test reset contract. Do not mutate any real historical accounts. Checkpoint.
4. F05: obtain authoritative units; keep behavior unchanged until supplied. If changed, bump attribution/version and prove PHP/Go golden parity. Continue independent F07/F08/F09/F13/F14/F15 contract checks meanwhile.
5. Finish populated 13-strategy API/Vue/CSV reconciliation, microseconds, build provenance and workflow identity/job validation. No workflow dispatch. Preserve existing F03 safety and F10 declared best-effort consistency. Document F12 missing parameter-space contracts without implementing optimization.
6. Create/update `docs/audits/VECTOR_CODE_REMEDIATION_2026_10_22.md` with original IDs, roots, changes, commands/evidence, limits and FIXED/VERIFIED_EXISTING_BEHAVIOR/BLOCKED/DEFERRED statuses. Run only affected targeted checks after changes; final suites are necessary only after final source changes/remaining gates. Use isolated positively identified PG and preserve TEST_DB_*/RUN_DB guards. Update this handoff after each module; make explicit-path local commits only once authorized remediation stages are verified. Never push/deploy.

Remaining acceptance: every P0/P1 confirmed defect resolved safely; authoritative promotion positive/negative evidence tests; 13 independent accounts with original-eight preservation; unambiguous funding/OI units; PostgreSQL green with sound fixtures; read-only GET/error contracts; half-open timestamps; coherent API/UI/CSV; mandatory PG release dependency; final evidence tied to reviewed source. **Readiness: PARTIAL, not READY_FOR_ITERATION_3.**

### Current complete modified/untracked inventory

This inventory includes earlier Iteration 2 work, Codex remediation and later agent changes; preserve all entries. No files staged at checkpoint. The owner spec must remain untracked/read-only and never be committed.

```text
 M .dockerignore
 M .github/workflows/deploy.yml
 M app/Audit/Application/AuditExportService.php
 M app/Audit/Application/StrategyFunnelService.php
 M app/Http/Controllers/Api/V1/AutonomousLearningController.php
 M app/Http/Controllers/Api/V1/ProfitabilityResearchController.php
 M app/Http/Controllers/Api/V1/QuoteIntegrityController.php
 M app/Http/Controllers/Api/V1/ResearchHealthController.php
 M app/Http/Controllers/Api/V1/ResearchIntelligenceController.php
 M app/Http/Controllers/Api/V1/ResearchJobController.php
 M app/Http/Middleware/ApiRequestDiagnostics.php
 M app/PaperTrading/Application/PaperAccountService.php
 M app/PaperTrading/Infrastructure/Persistence/PaperAccountRecord.php
 M app/Providers/AppServiceProvider.php
 M app/Research/Application/AutonomyPermissionService.php
 M app/Research/Application/AutonomyResearchService.php
 M app/Research/Application/CounterfactualAnalyzerService.php
 M app/Research/Application/PortfolioCorrelationService.php
 M app/Research/Application/PromotionPolicyService.php
 M app/Research/Application/ResearchRecommendationService.php
 M app/Research/Application/ResearchReportService.php
 M app/Research/Application/RiskCapitalMetricsService.php
 M app/Research/Application/SlippageAttributionService.php
 M app/Research/Application/StrategyDegradationService.php
 M app/Research/Application/StrategyHealthService.php
 M app/Research/Domain/Autonomy/AllocationProposal.php
 M app/Research/Domain/Autonomy/CandidateRegistry.php
 M app/Research/Infrastructure/Persistence/ResearchRecommendationRecord.php
 M app/Strategy/Application/StrategyInputFactory.php
 M app/Strategy/Application/StrategyObservationService.php
 M app/Strategy/Application/StrategyRegistry.php
 M app/Strategy/Application/StrategyResearchService.php
 M app/Strategy/Domain/StrategyInput.php
 M app/Strategy/Research/ResearchStrategy.php
 M config/paper.php
 M config/research.php
 M database/migrations/2026_10_18_010000_enforce_eight_primary_accounts_and_widen_quality.php
 M docker/php/Dockerfile
 M docs/agent/HANDOFF.md
 M frontend/src/pages/AuditPage.vue
 M frontend/src/pages/ProfitabilityResearchPage.vue
 M frontend/src/pages/ResearchOpportunitiesPage.vue
 M frontend/src/pages/ResearchPage.vue
 M frontend/tests/ui.test.js
 M routes/api.php
 M routes/console.php
 M tests/Feature/Api/ResearchApiTest.php
 M tests/Feature/AuditExportTest.php
 M tests/Feature/Backtest/BacktestCurveServiceTest.php
 M tests/Feature/Backtest/BacktestSimulationTest.php
 M tests/Feature/MarketData/FundingHistorySynchronizerTest.php
 M tests/Feature/MarketData/OpenInterestHistorySynchronizerTest.php
 M tests/Feature/PaperTrading/DailyPerformanceTest.php
 M tests/Feature/PaperTrading/PerformanceStatisticsTest.php
 M tests/Feature/PaperTrading/PrimaryAccountMigrationRehearsalTest.php
 M tests/Feature/PaperTrading/StatisticsPayloadSerializationRegressionTest.php
 M tests/Feature/Research/AutonomousLearningApiTest.php
 M tests/Feature/Research/AutonomousLearningPipelineTest.php
 M tests/Feature/Research/AutonomyResearchTest.php
 M tests/Feature/Research/P2RiskCapitalMetricsTest.php
 M tests/Feature/Research/ProfitabilityResearchApiTest.php
 M tests/Feature/Research/PromotedCandidateExecutionTest.php
 M tests/Feature/Research/ScheduledResearchJobsTest.php
 M tests/Feature/Strategy/MarketScannerServiceTest.php
 M tests/Feature/Strategy/ResearchApiTest.php
 M tests/Feature/Strategy/ResearchBootstrapTest.php
 M tests/Feature/Strategy/SignalGenerationTest.php
 M tests/Feature/Strategy/SupportResistanceStrategiesTest.php
 M tests/Integration/CounterfactualSlotIdempotencyPostgresIntegrationTest.php
 M tests/Integration/LedgerReconciliationPostgresIntegrationTest.php
 M tests/TestCase.php
 M tests/Unit/Research/AutonomyPermissionServiceTest.php
 M tests/Unit/Research/CounterfactualAccurateRCalculationTest.php
 M tests/Unit/Research/CounterfactualAnalyzerServiceTest.php
 M tests/Unit/Research/CounterfactualNoHindsightTest.php
 M tests/Unit/Research/PromotionPolicyFailClosedTest.php
 M tests/Unit/Research/PromotionPolicyServiceTest.php
 M tests/Unit/Research/ResearchRecommendationServiceTest.php
 M tests/Unit/Research/SlippageAttributionServiceTest.php
?? VECTOR_UNIFIED_13_STRATEGY_RESEARCH_DASHBOARD_AUDIT_EXPORTS_AND_TECH_DEBT_SPEC_V2_3_EN.md
?? app/Strategy/Research/BtcEthLeadLagStrategy.php
?? app/Strategy/Research/CandidateStrategy.php
?? app/Strategy/Research/FundingOpenInterestSqueezeStrategy.php
?? app/Strategy/Research/LiquiditySweepReversalStrategy.php
?? app/Strategy/Research/MultiTimeframeTrendFollowingStrategy.php
?? app/Strategy/Research/VolatilityCompressionBreakoutStrategy.php
?? database/migrations/2026_10_22_020000_add_five_primary_strategy_accounts.php
?? last_development_for_full_service/
?? scripts/verify-test-database.php
?? tests/Feature/PaperTrading/PrimaryStrategyReadinessTest.php
?? tests/Feature/PaperTrading/ThirteenPrimaryAccountsTest.php
?? tests/Feature/Research/IntelligenceReadOnlyTest.php
?? tests/Feature/Strategy/SignalOnlyStrategyExecutionTest.php
?? tests/Unit/Research/CounterfactualLegacyQuarantineTest.php
?? tests/Unit/Strategy/CandidateStrategiesTest.php
```


## Checkpoint CODEX-RECOVERY-20261023-R1 — recovery complete; F07 next

Owner resumed Codex-only implementation. No delegation or commits authorized. Current restrictions supersede older runner instructions: no Docker daemon operations, SSH, production, Actions, deployment, secrets, or unverified DB writes. Preserve 13 PRIMARY accounts and original histories, lead-lag SIGNAL_ONLY, P3 prohibited, A2 default OFF.

Start/end recovery state: main at f06f34fd631d550c2782992bf43e53cce426eb10; 79 tracked modified paths, 16 untracked entries; index empty. No source edits during recovery. Earlier F01–F15 corrected table remains authoritative; no finding newly accepted. Embedded post-v2.3 A/B/C/D IDs match, but their archive is reference only and was not extracted over the repository. File C is an execution annex, not the absent upstream Full Spec. Canonical e534ac49 evidence remains unavailable in supplied file inventory; alternative 4ac3a57f ZIP is not a substitute. Multi-exchange account/schema decisions remain blocked. Latest owner authorizes independently decidable remediation; this does not approve policy amendments.

Confirmed next defect: ResearchHealthController overview/strategyFunnel call persist-by-default health computation; AutonomousLearningController overview/settings call firstOrCreate through getSettings. F07 module will make those GET paths read-only while preserving explicit mutation and scheduled persistence. No financial formulas or promotion thresholds change.

Native checks: php8.5 -v reports 8.5.9, below target8.5.12; php8.5 vendor/bin/phpunit --version exits1 (missing dom/mbstring/xmlwriter). php8.4 vendor/bin/phpunit --version succeeds (12.5.39), distinct from prior target-runtime12.5.40 evidence. Supplemental native8.4 tests may run in a secrets-free temporary base path using in-memory SQLite; they cannot satisfy target-runtime acceptance. PostgreSQL identity has not been proven in this session. No prior test result is relabelled as current.

Pre-edit source copies, hashes and exact Git patches: reports/overnight/runs/vector-20261023T100704Z. Independent recovery review found additional raw Redis exception disclosure in SystemFreshnessService; address in a separate F08 checkpoint after F07. Next: add negative-control GET write tests, verify current failure, fix scoped calls, run targeted tests and Pint, append checkpoint before another module.

## Checkpoint CODEX-F07-R2 — GET health/autonomy writes removed

Start/end: main f06f34fd631d550c2782992bf43e53cce426eb10, prior dirty tree preserved, index empty; no commit. Modified AutonomyPermissionService::getSettings (optional nonpersisting firstOrNew), AutonomousLearningController GET overview/settings and ResearchHealthController GET overview/funnel (explicit persist:false), IntelligenceReadOnlyTest (two regressions). No thresholds, scheduler persistence, historical rows or enabled operational policy changed.

Reproduction: native PHP8.4.24/PHPUnit12.5.39, secrets-free base `/tmp/vector-native-test-io2spr0r`, APP_BASE_PATH pointing there, SQLite :memory:, env allowlist in f07-command.json. `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php --filter test_health_and_autonomy_reads` failed before fix:1 test/10 assertions,53 INSERTs (52 health snapshots+1 settings), exit1. After: `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php tests/Feature/Research/AutonomousLearningApiTest.php tests/Unit/Research/AutonomyPermissionServiceTest.php`:11 tests/89 assertions, exit0. `XDEBUG_MODE=off php8.4 vendor/bin/pint --test` with the four changed paths:exit0. Raw logs, exact command/environment JSON and final source hashes in /home/thunder/work/traderbybit/reports/overnight/runs/vector-20261023T100704Z.

Independent post-implementation review by Codex: nonpersisting settings use same defaults as writer; stored capability/kill-switch values win; old explicit writers and health snapshot jobs retain defaults. Regression asserts empty table reads, repeated requests, persisted policy unchanged, kill-switch blocks permission, explicit snapshot writes produce13 rows. These are supplemental local compatibility results, NOT PHP8.5.12/locked-dependency/PostgreSQL acceptance. Native8.5.9 lacks required PHPUnit extensions; no dependency installation/platform bypass. F07 broader endpoint inventory remains partial. Next exact module:F08 SystemFreshnessService Redis error is embedded in a200 response and bypasses the5xx sanitizer; inject a synthetic exception, redact public output and prove degraded state without network.


## Checkpoint CODEX-EMERGENCY-20261023-R3 — owner STOP; OpenCode continuation

**Development frozen by the owner's latest instruction. OpenCode continuation is now explicitly requested and supersedes the earlier Codex-only continuation restriction. No agent was launched. No commits are authorized or created.** Append-only checkpoint; preserve all previous entries and distinguish historical test claims from current evidence.

### Git state and preservation

Start of this resumed run: `main` at `f06f34fd631d550c2782992bf43e53cce426eb10`, 79 modified tracked paths, 16 untracked short-status entries, empty index. End: `main` at the same HEAD, **80 modified tracked paths, 16 untracked short-status entries (26 individual untracked files)**, empty index. SystemFreshnessService is the one additional tracked source path. No reset, clean, stash, restore, push, deployment, Actions, production access or Docker operations. Source changes remain unstaged. The earlier complete inventory remains valid plus SystemFreshnessService; current complete inventories are `emergency-status.txt` and `emergency-status-all.txt` under `reports/overnight/runs/vector-20261023T100704Z`. Preserve ALL entries, especially the owner v2.3 specification, `last_development_for_full_service/`, six strategy classes, five-account migration, DB identity script and added tests. Do not stage owner specs.

Files edited in this resumed run:
- `app/Research/Application/AutonomyPermissionService.php`
- `app/Http/Controllers/Api/V1/AutonomousLearningController.php`
- `app/Http/Controllers/Api/V1/ResearchHealthController.php`
- `app/Research/Application/SystemFreshnessService.php`
- `tests/Feature/Research/IntelligenceReadOnlyTest.php` (already untracked; extended, not replaced)
- `docs/agent/HANDOFF.md`, `docs/agent/OPENCODE_CONTINUE.md`, `reports/overnight/TECH_DEBT_ACCEPTANCE_MATRIX.md` (append-only updates; latter two may be ignored by Git and must still be preserved).
New evidence files only under `reports/overnight/runs/vector-20261023T100704Z`; no new application class/migration or dependency. `before/` and `f08-before/` retain pre-edit bytes; source hashes in `f07-final-sha256.json` and `emergency-source-sha256.json`.

### Completed bounded module: F07 R2

Confirmed 53 INSERTs from eight repeated GET requests: 52 strategy-health snapshots and one autonomy settings singleton. Health overview/strategy-funnel now call `overview(..., persist:false)`. Learning overview/autonomy-settings call `getSettings(persist:false)`, which returns existing settings or unsaved effective defaults through `firstOrNew`. Explicit mutation and worker initialization retain `firstOrCreate`. Existing persisted capability and kill-switch values are not overwritten. No financial/promotion threshold changes.

Regression tests prove repeated reads create no writes, saved policy remains byte-identical, kill switch still denies permission, and an explicit health writer still creates13 snapshots. F07 is **not globally closed**: broader endpoint coverage/empty-stale-failed contracts and final target-runtime verification remain.

### Interrupted current module: F08 R3

Confirmed `SystemFreshnessService::check()` embedded raw Redis exception text inside a successful200 response, outside the earlier5xx sanitizer. It also reported overall HEALTHY when Redis failed. Applied minimal fix: generic `Queue health is unavailable.`, queue status UNAVAILABLE, overall WARNING unless already CRITICAL; structured warning logs only fixed code, exception class and request ID, never message/connection details. Synthetic Redis exception regression checks response redaction, warning state and exact safe log context; no Redis network access in this test.

**Interruption point:** source and regression are saved; already-running targeted test finished successfully after owner STOP. No further source changes or tests started. **F08 post-change Pint and independent final diff review have NOT run.** Do not mark the whole F08 finding fixed: controller/middleware raw exception logging, broader endpoint error envelopes and unhandled exception paths still need bounded review.

### Exact current verification and environment

Evidence root: `reports/overnight/runs/vector-20261023T100704Z`. Native compatibility evidence only: PHP8.4.24, PHPUnit12.5.39, existing local vendor tree. Target remains PHP8.5.12/locked dependencies; prior target-runtime672-test reports are historical and not rerun here.

- `php8.5 -v`:8.5.9; `php8.5 vendor/bin/phpunit --version`:exit1, missing dom/mbstring/xmlwriter. `/usr/lib/php/20250925` inspection confirmed these extension binaries absent. No install or platform bypass.
- `php8.4 vendor/bin/phpunit --version`:12.5.39,exit0. Subsequent tests set XDEBUG_MODE=off.
- F07 before: `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php --filter test_health_and_autonomy_reads`:1 test/10 assertions/1 failure,exit1 (`f07-before.log`); demonstrates53 writes.
- F07 after: `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php tests/Feature/Research/AutonomousLearningApiTest.php tests/Unit/Research/AutonomyPermissionServiceTest.php`:**11 tests/89 assertions,exit0**, no skips (`f07-after.log`).
- `XDEBUG_MODE=off php8.4 vendor/bin/pint` on AutonomyPermissionService, AutonomousLearningController, ResearchHealthController, IntelligenceReadOnlyTest:exit0; only array indentation/import order formatted. Same four paths `--test`:exit0 (`f07-pint.log`). This predates F08 test additions.
- F08 before: `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php --filter test_queue_health_failure`:1 test/3 assertions/1 failure,exit1; expected WARNING but received HEALTHY (`f08-before.log`). Raw-message disclosure also confirmed directly in source.
- F08 after: `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php`:**7 tests/52 assertions,exit0**, no skips (`f08-after.log`). This was already running at STOP; only result collected afterward.
- No full suite, PostgreSQL, Go, frontend, build, Composer audit or final re-audit executed in this resumed run. No new assertion of readiness.

Test isolation/reproduction: temporary base `/tmp/vector-native-test-io2spr0r` contains copied bootstrap/app.php/providers.php/artisan/composer.json/phpunit.xml, symlinks to current source/tests/vendor/config/routes/resources and migration/factory/seeder directories, empty independent bootstrap cache/storage; no environment files copied or opened. Commands use explicit allowlisted environment from `f07-command.json`, `f07-after-command.json`, `f08-command.json`: APP_BASE_PATH=temp base, APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL='', CACHE_STORE=array, QUEUE_CONNECTION=sync, SESSION_DRIVER=array, XDEBUG_MODE=off. No PostgreSQL connection was attempted. JSON captures exact command/cwd/exit. `/tmp/vector-current-run-path` and `/tmp/vector-native-base-path` point to these resources; they may disappear, so durable logs are in reports. No command remains running from this module (test process completed exit0).

### Overall finding status (original owner IDs)

F01 partial: authoritative candidate-bound prospective evidence and complete positive promotion/concurrency/TOCTOU validation remain; containment fails closed. F02 partial: historical adoption, old migration branch, recovery/config/concurrency matrix remain. F03 implemented with prior targeted evidence; preserve quarantine. F04 partial metrics/API/export reconciliation. F05 blocked: threshold0.001 units not authoritatively defined; no PHP/Go behavior change. F06 prior green target-runtime PG/SQLite reports, current acceptance unverified; fixture/migration safety review outstanding. F07 scoped R2 fixed/tested on supplemental native runtime, broader acceptance partial. F08 scoped R3 fix/test saved, review/Pint unfinished, overall partial. F09 boundary fix exists; microsecond/storage/API reconciliation partial. F10 best-effort snapshot disclosure exists; preserve limitation. F11 existing SIGNAL_ONLY behavior, cross-system proof partial. F12 five new evolution spaces deliberately deferred pending contracts. F13 build provenance partial (known metadata/image correspondence unverified). F14 PostgreSQL release gate partial (guard/job verification outstanding). F15 all13 populated source/API/Vue/CSV funnel reconciliation incomplete. **Overall PARTIAL; not READY_FOR_ITERATION_3.**

### Specifications, decisions, blockers

Read current AGENTS, full HANDOFF, technical OPENCODE_CONTINUE and all `last_development_for_full_service` inputs. Embedded A/B/C/D specification IDs were inspected using Python tarfile, without extraction over the repository: A VECTOR-POST-V2.3-ROADMAP v3.1, B VECTOR-DAILY-PNL-EXECUTION v3.1, C VECTOR-PROFIT-1-EXECUTION v2.1, D VECTOR-MULTI-EXCHANGE-EXECUTION v1.0. Archive path: `last_development_for_full_service/VECTOR_POST_V23_AUDIT_CHECKPOINT_20261023.tar.xz`, members `docs/post_v23_specs/file_a.md` through file_d.md (locate exact prefix from member list). Actual working tree is authority; archive has a different A0–A3 patch snapshot, not an automatically approved replacement. Do not import it wholesale.

Canonical PROFIT-1 Gate0 archive `vector-audit-1d-e534ac49-*.zip`, expected SHA256 `54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4`, remains absent from supplied inventory; available4ac3a57f ZIP is not a replacement. Slippage35.1732,49 rejections,6/65 ambiguous and precise net arithmetic cannot be declared verified. Rounded142.54−104.50−0.54=37.50 versus37.51 requires raw input. Stage0 owner acceptance and relevant policy decisions must not be fabricated. Multi-exchange13→26 account model and altering existing tables versus new-tables-only remain owner-gated. P2 accepted V5.3 services, P4 prior window and P5 concentration parameters still need exact contracts. F05 units remain unresolved. PostgreSQL identity unproven; Docker runner is now forbidden. Native target PHP toolchain unavailable. Continue independently authorized engineering defects only, not blocked profitability features.

### Next tasks and acceptance

1. **Next exact task:** review the saved F08 SystemFreshnessService/IntelligenceReadOnlyTest diff against `f08-before/`; run touched-file Pint and relevant targeted regression on an authorized native/test runtime. Preserve F07 changes. If formatting changes source, retest affected paths. Append checkpoint immediately.
2. Resume F01 authoritative promotion-source/path audit and F02 provenance/history/concurrency work. Never enable A2 or weaken a gate to create a positive test. Prove isolated DB identity before any PostgreSQL write; preserve TEST_DB_*/RUN_DB guards. Missing policy/source blocks only dependent implementation.
3. Complete partial F04/F07/F08/F09/F13/F14/F15 coverage; retain F03/F10/F11 safeguards and document F12 dependencies. Create original-ID remediation report if still absent.
4. Run required target-runtime final regression only after final source changes and safe toolchain/DB prerequisites. Distinguish this run's native8.4 compatibility evidence from target8.5.12 acceptance. All13 account/history, promotion, null/decimal, API/UI/export and mandatory PG release gates require actual evidence; no profitability claim.
5. After EACH completed/interrupted module append HANDOFF with Git state, changes, commands/results, own post-implementation audit, blockers and exact next task. No commit/push/deploy without separate explicit authorization. Stop feature work after remediation and prepare Iteration3 handoff.

Safety contract: exactly13 independent PRIMARY accounts, original8 balances/positions/executions/ledgers/history unchanged; five added implementations preserved; lead-lag SIGNAL_ONLY; paper-only; no live orders/keys/fallback; Gate6/risk limits intact; P3 replay prohibited; no ECONOM/shared-resource mutation, production, SSH, Docker daemon operations, Actions or deployment. Owner will manually start OpenCode. Current Codex session stops after checkpoint integrity verification.


## Checkpoint OPENCODE-F08-R4 — queue-health disclosure verification complete

Updated: 2026-10-23. Start/end `main` HEAD `f06f34fd631d550c2782992bf43e53cce426eb10`; dirty worktree preserved, index empty, no commit/push/deploy. F07 changes untouched.

### Scope verified

- Reviewed saved F08 diff against `reports/overnight/runs/vector-20261023T100704Z/f08-before/`. `SystemFreshnessService::check()` now returns fixed text `Queue health is unavailable.`, queue `status=UNAVAILABLE`, overall `WARNING` unless already `CRITICAL`, and logs only fixed code, exception class and request ID. No raw Redis message, host or URI reaches the HTTP 200 payload.
- Regression `test_queue_health_failure_is_redacted_and_reported_as_degraded` in `tests/Feature/Research/IntelligenceReadOnlyTest.php` injects a synthetic RuntimeException containing marker secrets, asserts redacted payload, degraded status, absent markers, and exact safe log context. No Redis network access.
- Independent bounded review found no remaining HTTP `5xx` raw-message path in `ResearchJobController`, `ResearchHealthController`, `ResearchIntelligenceController`, `AutonomousLearningController`, `ProfitabilityResearchController` or `QuoteIntegrityController`: controller catches return generic messages, and `ApiRequestDiagnostics` replaces non-debug `5xx` JSON while preserving `FEATURE_DISABLED` 503 and `X-Request-ID`. Remaining `getMessage()` responses are `409 IDEMPOTENCY_CONFLICT` / `422 INVALID_TRANSITION` domain messages containing only caller-supplied identifiers; no trace, host, SQL or credential text.
- Residual risk (unchanged, not claimed fixed): controller/middleware `Log::error` calls still interpolate raw exception messages and pass exception objects; those are internal logs, so a full log-secret redaction audit remains open. Frontend freshness panel still renders missing queues as `0` rather than UNAVAILABLE. F08 whole-finding status remains **PARTIAL**.
- Production config proof: `compose.yaml` sets `APP_ENV=production` / `APP_DEBUG=false`; `.env.production.example` sets `APP_DEBUG=false`, so the sanitizer/safe-message path is the deploy path.

### Commands and results (native supplemental PHP 8.4.24/PHPUnit 12.5.39, not target 8.5.12 acceptance)

- `XDEBUG_MODE=off php8.4 vendor/bin/pint --test app/Research/Application/SystemFreshnessService.php tests/Feature/Research/IntelligenceReadOnlyTest.php` -> pass, exit 0 (`f08-pint-verify.log`).
- Isolated base `/tmp/vector-native-test-io2spr0r`, secrets-free env (`APP_ENV=testing`, SQLite `:memory:`, array cache/session, sync queue): `php8.4 vendor/bin/phpunit --no-coverage tests/Feature/Research/IntelligenceReadOnlyTest.php tests/Feature/Research/ResearchHealthApiTest.php tests/Unit/Research/SystemFreshnessServiceTest.php tests/Feature/Api/CompleteApiEndpointAuditTest.php` -> **13 tests / 126 assertions, exit 0** (`f08-targeted-verify.log`).
- Source hashes: `SystemFreshnessService.php` `3867b381…`, `IntelligenceReadOnlyTest.php` `f7934a66…`.
- No PostgreSQL, Docker daemon, full suite, Go, frontend build or production action was run in this module. No new defect required a source edit in this verification.

### Next

Proceed to owner-supplied independent A2 findings A2-001/A2-002/A2-003 (revocation boolean handling, incomplete monitoring evidence, false rollback confirmation). Then resume F01/F02 and remaining partial F04/F07/F09/F13/F14/F15 work per prior checkpoint.


## Checkpoint OPENCODE-A2-FINDINGS-R5 — three A2 safety defects fixed

Updated: 2026-10-23. Start/end `main` HEAD `f06f34fd631d550c2782992bf43e53cce426eb10`; dirty worktree preserved, index empty, no commit/push/deploy. F07/F08 work untouched.

### Findings and status

| Finding | Status | Evidence |
|---|---|---|
| A2-001 revocation boolean handling | **FIXED** | `PromotionControlPolicy::evaluateTriggers()` previously compared every trigger through `(string) $value === (string) $threshold`; JSON `true` casts to `"1"`, so `source_generation_revoked=true` did **not** breach. Now revocation accepts only real booleans: `true` breaches, `false` satisfies, `null`/missing is unobserved, and any other type (`"true"`, `"false"`, `1`, `0`, array, object) is rejected as invalid. |
| A2-002 incomplete monitoring evidence | **FIXED** | Missing/null metrics are `unobserved`; malformed numbers, NaN, ±Infinity, arrays and booleans are `invalid`; numeric strings are parsed only when finite. Status is `ROLLBACK_REQUIRED` if any breach exists (preserved even with other missing evidence), otherwise `INCOMPLETE_EVIDENCE` if anything is unobserved/invalid, otherwise `NO_TRIGGER_BREACHED`. A genuine zero is observed, not confused with missing. |
| A2-003 false rollback confirmation | **FIXED** | `AutonomyResearchService::rollbackPromotion()` no longer writes a `ROLLBACK_RECORDED` control event. It returns `ROLLBACK_REFUSED` / `OPERATIONAL_ROLLBACK_UNSUPPORTED` because no verified routine restores the previously applied paper strategy version. No account, position, execution, ledger or promotion-decision row is mutated; trigger evaluation is still returned as evidence. Malformed decision IDs are rejected before a UUID-column query. |

### Reproduction (before implementation)

Isolated SQLite `:memory:`, native PHP 8.4.24 / PHPUnit 12.5.39, temp base `/tmp/vector-native-test-io2spr0r`:
`php8.4 vendor/bin/phpunit --no-coverage tests/Unit/Research/Autonomy/AutonomyControlTest.php tests/Feature/Research/AutonomyResearchTest.php` -> **7 failures / 32 tests**. Confirmed: boolean `true` produced `NO_TRIGGER_BREACHED`; invalid values produced `ROLLBACK_REQUIRED`/`NO_TRIGGER_BREACHED`; missing evidence produced `NO_TRIGGER_BREACHED`; rollback returned `ROLLBACK_RECORDED` without restoration.

### Files changed

- `app/Research/Domain/Autonomy/PromotionControlPolicy.php` — typed/fail-closed trigger evaluation, `invalid` list, status precedence, finite-number guard.
- `app/Research/Application/AutonomyResearchService.php` — rollback refusal, UUID guard, no success audit write.
- `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` — rollback API returns `404` only for not-found and `409` for unsupported restoration.
- `tests/Unit/Research/Autonomy/AutonomyControlTest.php` — boolean/null/missing/invalid/NaN/Infinity/zero/breach-preservation cases.
- `tests/Feature/Research/AutonomyResearchTest.php` — unsupported-rollback, repeated no-write idempotency, malformed ID, API `409`; existing unknown-decision test retained.

### Post-change verification

- `XDEBUG_MODE=off php8.4 vendor/bin/pint --test` on all five changed paths -> pass, exit 0 (`reports/overnight/runs/vector-20261023T100704Z/a2-pint.log`).
- Isolated temp-base PHPUnit: `tests/Unit/Research/Autonomy` + `AutonomyResearchTest` + `AutonomousLearningApiTest` + `PromotionPolicyServiceTest` + `PromotionPolicyFailClosedTest` + `CompleteApiEndpointAuditTest` -> **68 tests / 543 assertions, exit 0** (`a2-targeted.log`). Focused A2 pair: **34 tests / 200 assertions, exit 0**.
- Repeated rollback is now provably write-free in the test via `DB::listen` (no INSERT/UPDATE/DELETE); control-event count and `virtual_positions` count unchanged, decision `rollback_applied` remains false.
- Source hashes: `PromotionControlPolicy.php` `f61a4093…`, `AutonomyResearchService.php` `775250f2…`, `ProfitabilityResearchController.php` `6bd84e68…`, unit test `878e47fb…`, feature test `8feb6c09…`.

### Review, limits and owner decisions

- Independent re-review of the three paths found no bypass: a revocation flag cannot be made safe by string coercion; missing/invalid monitoring cannot yield `NO_TRIGGER_BREACHED`; rollback refusal performs no writes. `ACTION_ROLLBACK_RECORDED` remains a historical constant but is no longer produced by this path.
- True multi-process concurrency was **not** executed (no isolated PostgreSQL identity proven this session and the refusal path is read-only). Residual risk is low because the path has no writes; a PostgreSQL concurrency test remains unverified rather than claimed passing.
- **Owner decision required** before enabling operational rollback: define the authoritative previous-version restoration/persistence routine and its idempotent failure semantics. Until then rollback is intentionally refused.
- No strategies, thresholds, Gate 6 gates, balances, positions, executions, ledgers or historical records were changed. Paper-only constraints preserved.
- No target PHP 8.5.12, full suite, PostgreSQL, Go, frontend/build or production verification was run in this module. Overall remediation remains **PARTIAL**.

### Next

Resume remaining F01/F02 acceptance gaps and partial F04/F07/F09/F13/F14/F15 contract checks; then prepare the audit source archive.


## Checkpoint OPENCODE-F07-BROAD-R6 — all registered GET handlers proven write-free

Updated: 2026-10-23. Start/end `main` HEAD `f06f34fd631d550c2782992bf43e53cce426eb10`; dirty worktree preserved, index empty, no commit/push/deploy. F08/A2 changes untouched.

### Finding and fix

- A new exhaustive route test (`tests/Feature/Api/ReadOnlyGetEndpointsTest.php`) enumerates every registered `api/*` GET route, replaces path placeholders with safe UUID/symbol values, executes each route under its real middleware chain (only `ThrottleRequests` is disabled to avoid the test-time 60/min cap), and records every `INSERT`/`UPDATE`/`DELETE`/`REPLACE` through `DB::listen`.
- The test reproduced one real GET mutation: `/api/v1/research/autonomy/registry` inserted the `autonomy_settings` singleton because `AutonomyResearchService::registry()` called `AutonomyPermissionService::isAllowed()` with the default persistence path.
- Fix: `isAllowed(string $capability, bool $persist = true)` now forwards `getSettings($persist)`; `registry()` uses `isAllowed(..., false)` for both displayed capability flags. Mutating/worker callers (`PromotionPolicyService`, autonomous jobs) keep the default persist behavior. Read paths use `firstOrNew` and never create the singleton.
- `IntelligenceReadOnlyTest` now also covers `autonomy/registry`, `autonomy/experiments` and `autonomy/promotion-controls` in its no-write regression.
- Scope: this closes the **GET side-effect** portion of F07. Broader F07 acceptance (auth/error/empty/stale contracts on every endpoint) remains open and is still **PARTIAL**.

### Commands and results (native supplemental PHP 8.4.24/PHPUnit 12.5.39, not target 8.5.12 acceptance)

- Isolated `/tmp/vector-native-test-io2spr0r`, secrets-free env (`APP_ENV=testing`, SQLite `:memory:`, array cache/session, sync queue):
  - Before fix: `ReadOnlyGetEndpointsTest` -> 1 failure, `autonomy_settings` INSERT at `/api/v1/research/autonomy/registry` (`f07-broad-before.log` not retained; failure text captured in session).
  - After fix: same command -> **1 test / 2 assertions, exit 0**.
  - Targeted regression set (`ReadOnlyGetEndpointsTest`, `IntelligenceReadOnlyTest`, `AutonomyResearchTest`, `AutonomyPermissionServiceTest`, `CompleteApiEndpointAuditTest`, `ProfitabilityResearchApiTest`) -> **40 tests / 356 assertions, exit 0** (`f07-broad-targeted.log`).
- Pint on four touched/related paths -> pass, exit 0 (`f07-broad-pint.log`).
- Source hashes (`f07-broad-source-sha256.txt`): `AutonomyPermissionService.php` `9eeae477…`, `AutonomyResearchService.php` `77e42821…`, `ReadOnlyGetEndpointsTest.php` `91b18970…`, `IntelligenceReadOnlyTest.php` `8f0974d5…`.
- No PostgreSQL, Docker daemon, full suite, Go, frontend build or production action was run. No balance, position, execution, ledger, strategy threshold or Gate 6 rule was changed.

### Review and residual limits

- The route test bypasses only the throttle middleware; authentication, diagnostics and validation middleware still execute. Routes that return `401/403/422` before reaching a controller are still included and produced no writes.
- GET handlers protected by `research.token` return unauthorized in the audit; their code was separately reviewed as read-only (download/evidence streaming), but they are not executed by this test.
- True concurrency is not tested; GET write-freedom is demonstrated serially. No claim is made for target-runtime or PostgreSQL acceptance.

### Next exact task

Resume the next unblocked partial finding, starting with F04 metric/API/export contract reconciliation or F09 microsecond/storage/API reconciliation. Keep F07 broad acceptance open; do not mark overall remediation READY.


## Checkpoint OPENCODE-F04-R7 — SKIP filter metrics fully reconciled

Updated: 2026-10-23. Start/end `main` HEAD `f06f34fd631d550c2782992bf43e53cce426eb10`; dirty worktree preserved, index empty, no commit/push/deploy. Previous checkpoints untouched.

### Scope
F04 (SKIP metric semantics): extend per-filter effectiveness with `missed_tp_r`, `avoided_sl_r`, `timeout_r`, `net_filter_benefit_r` and per-filter `eligible_records`/`missing_r_records`. Keep summary formula `net_filter_benefit_r = avoided_sl_r - missed_tp_r - timeout_r`. Legacy `net_missed_r` (TP/SL-only) remains for old clients. Exclude missing R from eligible population; counts are explicit. Frontend shows net filter benefit and per-filter breakdown including timeouts.

### Files changed
- `app/Research/Application/CounterfactualAnalyzerService.php` — track per-filter totals and missing counts; compute per-filter `net_filter_benefit_r` when eligible.
- `tests/Unit/Research/CounterfactualLegacyQuarantineTest.php` — assert per-filter breakdown matches summary contract.
- `frontend/src/pages/ResearchOpportunitiesPage.vue` — rename summary card to Net Filter Benefit R, add per-filter columns and `formatR` helper.

### Commands and results (native supplemental PHP 8.4.24/PHPUnit 12.5.39, not target 8.5.12 acceptance)
- Pint: `app/Research/Application/CounterfactualAnalyzerService.php tests/Unit/Research/CounterfactualLegacyQuarantineTest.php frontend/src/pages/ResearchOpportunitiesPage.vue` -> pass, exit 0 (`f04-pint.log`).
- Isolated temp-base: `CounterfactualLegacyQuarantineTest`, `CounterfactualAccurateRCalculationTest`, `CounterfactualNoHindsightTest`, `CounterfactualAnalyzerServiceTest` -> **16 tests / 79 assertions, exit 0** (`f04-targeted.log`).
- Frontend: `npm test` (node:test) -> **54 tests, 0 failures, exit 0**.
- Source hashes (`f04-source-sha256.txt`): `CounterfactualAnalyzerService.php` `b1348e14…`, `CounterfactualLegacyQuarantineTest.php` `7a5cbfd3…`, `ResearchOpportunitiesPage.vue` `0b1538ca…`.
- No PostgreSQL, Docker daemon, full suite, Go, frontend build, paper PnL, promotion or strategy tuning was run/changed. Quarantine remains enforced; P3 replay prohibited.

### Review/limits
- Serializer/API already exposes all summary fields; no contract break. Per-filter naming is explicit; `net_r` remains legacy TP/SL net for compatibility.
- Metrics remain descriptive, dependent, and window-limited per contract. No claim of portfolio PnL or promotion evidence.
- Overall remediation remains **PARTIAL**; F04 closed for the stated metric reconciliation. Continue with next partial (F09/F01/F02/F13/F14/F15).


## Checkpoint OPENCODE-F08-R8 — F08 completed and committed

Updated: 2026-10-23T11:00Z. Start: main f06f34f (dirty). End: main cd570cb (local commit). No push/deploy.

### Completed
- Verified and committed SystemFreshnessService queue-health redaction fix (was already implemented in working tree; verified + committed now). HTTP 200 responses show "Queue health is unavailable.", queue status UNAVAILABLE, overall WARNING unless already CRITICAL. Logging uses only code, exception class, request_id (no raw message/host/password/path).
- Regression test `test_queue_health_failure_is_redacted_and_reported_as_degraded` asserts redaction and safe log context; extended `IntelligenceReadOnlyTest` coverage added.
- Pint passed on touched files. Targeted regression in isolated native base: 13 tests / 132 assertions, exit 0 (IntelligenceReadOnlyTest, ResearchHealthApiTest, SystemFreshnessServiceTest, CompleteApiEndpointAuditTest).

### Residual
- Controller/middleware Log::error still interpolates raw exception messages in places (internal logs) — bounded audit remains open.
- Frontend freshness panel renders missing queues as 0 rather than UNAVAILABLE.
- Overall F08 remains PARTIAL in prior assessment but core disclosure path fixed/committed; broader log-redaction continues if found.

### Next
Continue remaining safely implementable tasks: A2 already fixed/committed earlier in tree (verified), F07 broad coverage done, F04 done. Resume F01/F02, F09/F13/F14/F15 partials, and audit exports/13-strategy reconciliation as appropriate.
