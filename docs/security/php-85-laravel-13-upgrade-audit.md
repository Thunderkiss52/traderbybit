# PHP 8.5.12 / Laravel 13 upgrade audit

Discovery date: 2026-10-22. Baseline commit: `e99c55010012759fb2355481df3701f0446ef253` on `main`.

## Observed baseline

- Installed and locked Laravel: **13.36.0** (`php artisan --version`, `composer show --locked --direct`). Preserve major version 13.
- Host CLI: **8.4.24**, OPcache 8.4.24, Xdebug 3.5.3; Composer **2.8.4**. Host packages are outside this repository and will not be modified. Use the isolated target container for application commands.
- `docker/php/Dockerfile`: both extension and runtime stages use floating `php:8.4-fpm-alpine`. Vendor installation inherits that runtime; the Composer image only supplies its executable. Redis PECL installation is unpinned. Built extensions: pdo_pgsql, intl, zip, pcntl, opcache, redis.
- `compose.yaml`: app/FPM, queue, scheduler, market-ws, audit-worker and backtest-worker share the same application image. PostgreSQL 17, Redis 7.4, Nginx 1.27 and a separate Go research worker are configured. No independent PHP CLI image.
- `.github/workflows/deploy.yml` selects PHP 8.4. Existing gates: Composer install, PHPUnit, Pint, frontend tests/build; image publishing and deployment are workflow-dispatch only. No workflow will be dispatched.
- Composer requires PHP `^8.3`, Laravel `^13.17`, Tinker `^3.0`, PHPUnit `^12.5.12`; no platform pin. Installed PHPUnit 12.5.39, Pint 1.32.1, Tinker 3.0.2, Predis 3.7.0 (`*` constraint). No Pest or Boost installed. Symfony 8 components require newer PHP than the root minimum.
- Local PHP modules include PostgreSQL/MySQL/SQLite PDO, mbstring, intl, bcmath, pcntl, zip, sockets, gd, XML, sodium, curl, OPcache; phpredis is absent on the host. Actual container extensions require build verification.
- Frontend: Vue/Quasar in `frontend`, Node >=22, `npm test`; build includes `vue-tsc --noEmit`. Host default Node is 20.20.0, Docker/CI already use Node 22.
- Entry points: `public/index.php`, `artisan`, `bootstrap/app.php`, `routes/{api,web,console}.php`; `/up` is Nginx/FPM liveness, API routes include application health. Route inventory will be generated from Laravel.
- PHPUnit uses in-memory SQLite by default. PostgreSQL tests accept `TEST_DB_*`; never use their default port without a new isolated instance. Public Bybit smoke test is opt-in. Go integration needs PostgreSQL and a compiler.
- Existing security defaults: JSON sessions, cache unserialization classes disabled, debug disabled in Compose, bounded PHP memory/body/time limits, unprivileged FPM workers and CLI. Nginx basic authentication fronts the UI/API; mutation token protections require review.
- Worktree had one untracked user file: `VECTOR_UNIFIED_13_STRATEGY_RESEARCH_DASHBOARD_AUDIT_EXPORTS_AND_TECH_DEBT_SPEC_V2_3_EN.md`. It is excluded from this task's commits.

## Verified upstream evidence

- [PHP 8 changelog](https://www.php.net/ChangeLog-8.php#8.5.12) lists the engine fixes in 8.5.12.
- PHP advisories: [OOM corruption](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [stale global state](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). A framework upgrade alone cannot remediate these engine bugs.
- Official Docker manifest inspection succeeded for `php:8.5.12-fpm-alpine3.24`, index digest `sha256:f09d9172dd28b8696aef729ca2c2081a132c1f8d581218c99497d552c82a92df`, including linux/amd64 and source provenance to docker-library/php.
- Reviewed [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade), [PHP 8.5 incompatibilities](https://www.php.net/manual/en/migration85.incompatible.php), and [deprecations](https://www.php.net/manual/en/migration85.deprecated.php).

## Implementation map and risks

1. Pin both PHP image stages and CI to 8.5.12; verify CLI/FPM and extension loading inside built images; add an executable runtime gate. PHP 8.5 embeds OPcache, so review the old extension build step.
2. Resolve the latest compatible Laravel 13 patch under target PHP; tighten PHP/Predis constraints, set Composer platform, validate lock/install/audit. Do not broadly replace packages.
3. Exercise PHP warnings, numeric/date behavior, cache object payloads, request forgery middleware, queues, scheduling, route contracts and upserts. Keep existing prefixes/session serialization and historical strategy rules intact.
4. Disable unused upload progress, retain finite limits and security controls; review upload/auth/log boundaries with regression tests.
5. Run existing financial, strategy/account, research, data ingestion, API and frontend suites against fixtures and isolated databases. No real exchange trading, historical rewriting or synthetic forward-performance claims.
6. Build/start isolated project images, test PostgreSQL/Redis/FPM/queue/scheduler and route behavior; extend CI without changing deployment authorization or credentials.
7. Record three full verification passes, exact counts/skips/failures, image identities, endpoint evidence and local commits. Baseline logs and subsequent evidence live under `reports/php85-upgrade`.

No production runtime was inspected; production versions, data and performance remain unverified. Image pinning alone is not acceptance: built binaries and application behavior must pass verification. Existing user data, eight-account safeguards and current strategy inventory must be preserved.
