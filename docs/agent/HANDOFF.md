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

Current stage: **4 security**. Stages 5 business regression, 6 Docker/CI/endpoints and 7 three final verification passes remain incomplete. No release readiness certification.

## Stage 3 evidence (committed `4ee1358`)

- Benchmark: host `research-go/bin/research-worker` is untouched; isolated workspace holds a statically linked `CGO_ENABLED=0` build (`file` → "statically linked"). `tests/Feature/Research/ResearchBenchmarkCommandTest.php`: OK (1 test, 4 assertions) — `stage3-benchmark.log`.
- Root cause fixed: `config/cache.php` sets `serializable_classes => false`; Laravel 13 cache stores then return `__PHP_Incomplete_Class` for objects. Production uses the Redis store. `ResearchController::marketRows()` cached `Collection<CandleRecord>` under `market:candles:v4` and `buildAnalysisPayload()` cached models/collections/Carbon under `market:analysis:v5`.
- Fix: candle rows cached as raw attribute arrays and hydrated with `Builder::hydrate()`; cache key `market:candles:v5`. Analysis payload contains only scalars/arrays (`toArray()` for candles/context/patterns/signals, ISO-8601 `times`); key `market:analysis:v6`. `times` JSON is byte-identical because Carbon `jsonSerialize()` calls `toISOString()`.
- Negative control: with the old controller all 4 new tests fail (`marketRows(): Return value must be of type Collection, __PHP_Incomplete_Class returned`) — `evidence/cache-negative-control.log`.
- Response contract: cold responses for `candles`, `indicators`, `analysis` captured before and after the fix are **identical** (11,491 bytes each) — `evidence/contract-baseline.json`, `evidence/contract-fixed.json`.
- Full PHPUnit run (PHP 8.5.12): 632 tests, 3,726 assertions; 629 passed, 3 failed only because `tests/Integration` requires PostgreSQL (`stage3-tests.log`). Integration suite separately with `RUN_DB=bybit_test`: **35 tests, 304 assertions, 1 skipped (live Bybit opt-in), OK** (`stage3-integration.log`).
- Pint: `app/Http/Controllers/Api/V1/ResearchController.php`, `tests/Feature/MarketData/MarketCacheSerializationTest.php` — PASS.

## Earlier evidence still valid

- `docker/php/Dockerfile`: pinned PHP stages, Redis 6.3.0, bcmath, built-in OPcache, test-only Go/Python/Bash tools. `scripts/verify-php-runtime.php`, entrypoint fail closed on incorrect PHP/extensions.
- `composer.json` requires `~8.5.12`, platform 8.5.12, framework `^13.38`, Predis `^3.7`; lock updated under target PHP, no ignored platform requirements. Composer strict validation/audit/platform check exit 0 (`stage2-checks.log`).
- Baseline PHP 8.4: 317 unit / 1,556 assertions, 274 feature / 1,873 assertions passed. Frontend 54 passed, typecheck/build passed on Node 24.19.0.
- Official PHP advisories GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp identify 8.5.12 as patched (CVE-2026-109618/109619). Host PHP 8.4.24 remains unchanged and is rejected by the gate; use containers.

## Remaining work / known issues

- Stage 4 security (next): disable upload progress, review bounded limits/origin/errors/auth/uploads, add security tests, scan images, write `docs/security/php-85-laravel-13-security-verification.md`. Basic auth at Nginx protects general API routes; many mutations intentionally lack a research token — preserve clients. Review browser request-origin/CSRF protection and caught exception leakage. Keep `cache.serializable_classes=false`, JSON sessions and cache names/prefixes.
- No image vulnerability scanner installed. GitHub API query for Trivy latest timed out; scanner verification remains pending (record as unverified if unavailable).
- Stage 5 full regression must include the isolated PostgreSQL integration run (use `RUN_DB=bybit_test`); preserve the eight primary accounts and strategy configuration.
- Stage 6: inventory 118 registered routes (114 API); existing `CompleteApiEndpointAuditTest` only tests 41 GETs despite its name. Extend non-deploying CI gates, build app/web/Go, isolated FPM/Nginx/Redis/queue/scheduler smoke tests.
- Stage 7: three recorded full passes (compatibility/regression/final); exact counts/skips, PHP lint/Pint, Composer, frontend, containers, smoke/security. Write final report with commits, remaining risks, rollback. Do not certify incomplete gates.

## Local resources and commands

All resources below were created by this task; do not inspect/mutate other projects. `econom-group-*`, `bybit-signals-test-*`, `toolma-bot` and the shared builders belong to someone else — leave untouched.

- Buildx builder: `traderbybit-php85-20261022` (docker-container), container `buildx_buildkit_traderbybit-php85-202610220`.
- Images: `traderbybit-php85-test:local` (PHP 8.5.12 + Go); `traderbybit-php85-app:local` (stage-1 image, still Laravel 13.36.0; rebuild after source/dependency changes).
- Network `traderbybit-php85-testnet`; containers `traderbybit-php85-postgres` (tmpfs DB `bybit_test`) and `traderbybit-php85-redis`. No host ports, no aliases — use the full container names (`traderbybit-php85-postgres`, `traderbybit-php85-redis`); `run.py` was corrected accordingly.
- PostgreSQL migrations applied to this DB; test role `bybit_signals` created for QIM permission tests. Test credentials confined to ignored runner.
- `reports/php85-upgrade/run.py` syncs source into `reports/php85-upgrade/workspace`, excludes `.env`, `docs/`, `reports/`, `VECTOR_*` and `research-go/bin/`, and runs a disposable PHP test container as host UID. `RUN_DB=bybit_test` adds pgsql env overrides; `CONTRACT_LABEL` is forwarded for contract dumps. Vendor installed in the workspace; static Go binary persists there. No Docker socket mounted.
- Shell prefix: `/home/thunder/.headroom/bin/rtk proxy` (RTK absent from PATH; plain commands also fine).
- Test: `python3 reports/php85-upgrade/run.py vendor/bin/phpunit <path>`; full suite `vendor/bin/phpunit`; Postgres `RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Integration`.
- Build: `docker buildx build --builder traderbybit-php85-20261022 --load --target app -f docker/php/Dockerfile -t traderbybit-php85-app:local .`
- Frontend: prefix PATH with `/home/thunder/.nvm/versions/node/v24.19.0/bin`; `npm test --prefix frontend`, `npm run build --prefix frontend`.
- Evidence lives in ignored `reports/php85-upgrade/` (including `evidence/`). No build/test process is active; reconfirm narrowly before duplicating work.

## Safety / user-owned state

No push, deployment, workflow dispatch, production access, production migrations, real exchange orders, live trading endpoints or trade keys. Never touch ECONOM/econom-group or shared/unknown Docker resources/cache. No prune/global cleanup, database/history edits, volume deletion, reset/stash or unrelated commits. Never read/expose production secrets. Keep eight primary accounts and historical records intact. User's untracked `VECTOR_UNIFIED_13_STRATEGY_RESEARCH_DASHBOARD_AUDIT_EXPORTS_AND_TECH_DEBT_SPEC_V2_3_EN.md` must remain untouched. Never stage the whole repository.
