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

Current stage: **6 Docker/CI/endpoints**. Stage 7 three final verification passes remain incomplete. No release readiness certification.

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

## Earlier evidence still valid

- `docker/php/Dockerfile`: pinned PHP stages, Redis 6.3.0, bcmath, built-in OPcache, test-only Go/Python/Bash tools. `scripts/verify-php-runtime.php`, entrypoint fail closed on incorrect PHP/extensions.
- `composer.json` requires `~8.5.12`, platform 8.5.12, framework `^13.38`, Predis `^3.7`; lock updated under target PHP, no ignored platform requirements. Composer strict validation/audit/platform check exit 0 (`stage2-checks.log`).
- Baseline PHP 8.4: 317 unit / 1,556 assertions, 274 feature / 1,873 assertions passed. Frontend 54 passed, typecheck/build passed on Node 24.19.0.
- Official PHP advisories GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp identify 8.5.12 as patched (CVE-2026-109618/109619). Host PHP 8.4.24 remains unchanged and is rejected by the gate; use containers.

## Remaining work / known issues

- Full suite note: with the isolated Postgres reachable, the default run now executes the PostgreSQL integration suites (except the Go worker, skipped on sqlite) and the strict `bybit_test` guard. CI must still run the explicit `RUN_DB=bybit_test` integration job. Keep `cache.serializable_classes=false`, JSON sessions and cache names/prefixes.
- Trivy is not a repository dependency: the binary was downloaded to ignored `/tmp/opencode/trivy/trivy` (cache `/tmp/opencode/trivy/cache`). Re-download/verify before relying on it after a host restart; image scans reflect local builds, re-scan in CI on release.
- Stage 6 (next): inventory 118 registered routes (114 API); existing `CompleteApiEndpointAuditTest` only tests 41 GETs despite its name. Extend non-deploying CI gates, build app/web/Go, isolated FPM/Nginx/Redis/queue/scheduler smoke tests.
- Stage 7: three recorded full passes (compatibility/regression/final); exact counts/skips, PHP lint/Pint, Composer, frontend, containers, smoke/security. Write final report with commits, remaining risks, rollback. Do not certify incomplete gates.

## Local resources and commands

All resources below were created by this task; do not inspect/mutate other projects. `econom-group-*`, `bybit-signals-test-*`, `toolma-bot` and the shared builders belong to someone else — leave untouched.

- Buildx builder: `traderbybit-php85-20261022` (docker-container), container `buildx_buildkit_traderbybit-php85-202610220`.
- Images: `traderbybit-php85-test:local` (PHP 8.5.12 + Go); `traderbybit-php85-app:local` (rebuilt from current source at stage 4); `traderbybit-php85-web:local` (nginx 1.30.6).
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
