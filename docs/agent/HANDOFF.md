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

Current stage: **3 compatibility**. Stages 4 security, 5 business regression, 6 Docker/CI/endpoints and 7 three final verification passes remain incomplete. No release readiness certification.

## Changes and evidence already available

- `docker/php/Dockerfile`: pinned PHP stages, Redis 6.3.0, bcmath, built-in OPcache, test-only Go/Python/Bash tools. `libpq-dev` avoids PostgreSQL server build dependencies.
- `scripts/verify-php-runtime.php`, entrypoint: fail closed on incorrect PHP/extensions. `.github/workflows/deploy.yml` PHP setup updated; further CI work remains.
- `.dockerignore` excludes reports; `docker/README.md` describes target runtime.
- `composer.json` requires `~8.5.12`, platform 8.5.12, framework `^13.38`, Predis `^3.7`; lock updated under target PHP, no ignored platform requirements.
- Official PHP advisories GHSA-w8hw-w9fx-j79w / GHSA-6wmf-4rv5-p3rp identify 8.5.12 as patched for CVE-2026-109618/109619. Source links are in audit. Host PHP 8.4.24 remains unchanged and is rejected by the gate; use containers.
- Evidence: ignored `reports/php85-upgrade/`. Baseline PHP 8.4: **317 unit / 1,556 assertions**, **274 feature / 1,873 assertions**, all passed. Frontend **54 passed**, typecheck/build passed on Node 24.19.0.
- Target PHP 8.5.12: **317 unit / 1,556 assertions passed**. Composer strict validation, install, platform check, audit and Artisan version all exit 0 (`stage2-checks.log`); no advisories/abandoned packages. Initial network audit timed out, successful retry recorded.

## Failures and pending fixes

- `stage2-tests.log`: **590 passed, 1 failed**, 3,429 assertions. Benchmark fails executing `research-go/bin/research-worker`: existing tracked host binary cannot run on Alpine. Go is now installed in test image. Compile a static binary in the isolated snapshot; do not overwrite/commit the user's host binary. Snapshot synchronizer currently recopies it: exclude `research-go/bin/` first. Set writable `GOCACHE`/`GOPATH` for container UID.
- `initial-integration.log`: 35 tests, 3 failures, 2 skipped, 257 assertions. Ledger tests require `DB_DATABASE=bybit_test` but runner supplies `:memory:`. Run Integration separately with `env DB_CONNECTION=pgsql DB_DATABASE=bybit_test`; Go integration then uses isolated PostgreSQL. Live Bybit remains opt-in/skipped.
- Compatibility issue found but not yet fixed: `ResearchController::marketRows()` caches Eloquent collections under `market:candles:v4`; `buildAnalysisPayload()` caches models/collections/Carbon under `market:analysis:v5`. `cache.serializable_classes=false` must remain. Add a real serializing-cache repeated-request regression, store scalar arrays, hydrate candle attributes after cache retrieval, bump only affected keys. Convert analysis nested values to portable JSON-compatible arrays. Preserve response contracts and timestamp precision.
- Security review still required: basic auth at Nginx protects general API routes; many mutations intentionally lack a research token. Preserve clients; review browser request-origin/CSRF protection and caught exception leakage. JSON sessions and cache allowlist restrictions already exist; retain names/prefixes.
- No image vulnerability scanner installed. GitHub API query for Trivy latest timed out; scanner verification remains pending.

## Local resources and commands

All resources below were created by this task; do not inspect/mutate other projects.

- Buildx builder: `traderbybit-php85-20261022` (docker-container), container `buildx_buildkit_traderbybit-php85-202610220`; cache isolated from shared builders.
- Images: `traderbybit-php85-test:local` (PHP 8.5.12 + Go); `traderbybit-php85-app:local` (stage-1 image, still Laravel 13.36.0; rebuild after source/dependency changes).
- Network `traderbybit-php85-testnet`; containers `traderbybit-php85-postgres` (tmpfs DB `bybit_test`) and `traderbybit-php85-redis`. Both run in background, no host ports. These are disposable test data, never production.
- PostgreSQL migrations applied to this new DB; app test role `bybit_signals` created before migration for QIM permission tests. Test credentials are confined to ignored runner, not production secrets.
- `reports/php85-upgrade/run.py` syncs source into `reports/php85-upgrade/workspace`, excludes local `.env` and user specs, and runs a disposable PHP test container as host UID. Vendor dependencies installed there. No Docker socket mounted in tests.
- Shell prefix: `/home/thunder/.headroom/bin/rtk proxy` (RTK absent from PATH). Example: `rtk proxy python3 reports/php85-upgrade/run.py php artisan --version` (expand RTK path).
- Build: `rtk proxy docker buildx build --builder traderbybit-php85-20261022 --load --target app -f docker/php/Dockerfile -t traderbybit-php85-app:local .`
- Frontend: prefix PATH with `/home/thunder/.nvm/versions/node/v24.19.0/bin`; `npm test --prefix frontend`, `npm run build --prefix frontend` (includes typecheck).
- All previous build commands finished; no known build/test process is still active. Reconfirm narrowly before starting duplicate work.

## Exact next actions

1. Fix isolated runner binary sync and compile Go with `CGO_ENABLED=0`; rerun only failed benchmark test.
2. Write cache regression test using file/Redis cache, demonstrate failure, implement portable candle/analysis payloads, then run targeted tests and Pint. Commit stage 3 as `fix: resolve PHP 8.5 and Laravel 13 compatibility issues`; update this handoff with evidence/hash.
3. Stage 4: disable upload progress, review bounded limits/origin/errors/auth/uploads, add security tests, scan images, write `docs/security/php-85-laravel-13-security-verification.md`; commit `security: harden PHP runtime and Laravel application`.
4. Stage 5: full PHP/Go/frontend business tests with separate isolated PostgreSQL integration run; preserve existing eight primary accounts/strategy configuration. Commit `test: restore and verify application regression coverage`.
5. Stage 6: extend nondeploying CI gates, build app/web/Go, isolated FPM/Nginx/Redis/queue/scheduler smoke tests. Inventory 118 registered routes (114 API); existing `CompleteApiEndpointAuditTest` only tests 41 GETs despite its name. Record every endpoint with actual status/auth/error and explicit untested behavior. Commit `ci: validate PHP 8.5 Laravel 13 containers and API workflows`.
6. Stage 7: three recorded full passes (compatibility/regression/final); exact counts/skips, PHP lint/Pint, Composer, frontend, containers, smoke/security. Write final report with commits, remaining risks, rollback, no push/deployment. Commit `test: complete three-pass PHP 8.5 Laravel 13 verification`. Do not certify incomplete gates.

## Safety / user-owned state

No push, deployment, workflow dispatch, production access, production migrations, real exchange orders, live trading endpoints or trade keys. Never touch ECONOM/econom-group or shared/unknown Docker resources/cache. No prune/global cleanup, database/history edits, volume deletion, reset/stash or unrelated commits. Never read/expose production secrets. Keep eight primary accounts and historical records intact. User's untracked `VECTOR_UNIFIED_13_STRATEGY_RESEARCH_DASHBOARD_AUDIT_EXPORTS_AND_TECH_DEBT_SPEC_V2_3_EN.md` must remain untouched. `/docs` and `/reports` are ignored; force-add only explicit task documentation/evidence paths. Never stage the whole repository.
