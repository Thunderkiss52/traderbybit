# Docker, CI/CD and endpoint verification (stage 6)

Date: 2026-10-22
Branch: `main`
Scope: final runtime images, isolated Compose stack, GitHub Actions review, route inventory, endpoint contracts and frontend/API integration. Research/paper only.

## 1. Runtime and images

| Artifact | Verified |
|---|---|
| `traderbybit-php85-app:local` | `PHP 8.5.12`, `Laravel Framework 13.38.0`, built from current source |
| `traderbybit-php85-web:local` | nginx 1.30.6, Alpine 3.24.3, config valid (stage 4) |
| `traderbybit-php85-research-worker:local` | Go 1.27 build, Alpine 3.24.3, `--standalone` benchmark exits 0 |

Defect fixed: `docker/go/Dockerfile` used `golang:1.21-alpine` and `alpine:3.19`. Trivy reported 29 HIGH/CRITICAL (1 CRITICAL + 26 HIGH Go stdlib, 2 HIGH musl). Bumped to `golang:1.27-alpine` / `alpine:3.24`; rebuilt image now reports **0 HIGH/CRITICAL** and `--standalone` runs successfully. Evidence: `evidence/trivy-go-baseline.json`, `stage6-go-image.log`.

## 2. Isolated Docker Compose smoke test

Deployment-shaped stack from the repository `compose.yaml`, project `traderbybit-php85-smoke`, unique host port `127.0.0.1:18098`, all services on the project network with their own volumes: `postgres`, `redis`, `app` (PHP-FPM), `web` (nginx), `queue`, `scheduler`, `audit-worker`. All health checks passed (`stage6-compose-up.log`).

Migrations: full schema applied to the fresh isolated PostgreSQL database (`stage6-migrate.log`).

Live HTTP checks through nginx + PHP-FPM + PostgreSQL + Redis (`stage6-http-smoke.log`):

| Check | Result |
|---|---|
| `GET /up` (liveness, unauthenticated) | 200 |
| `GET /api/v1/dashboard` without basic auth | 401 |
| `GET /api/v1/dashboard` authenticated | 200, `schema_version=1.0.0`, `research_mode=true`, `data.market=[]` |
| `POST /api/v1/backtests` invalid payload | 422 JSON contract; response carries `X-Request-ID` and `X-Content-Type-Options: nosniff` |
| `GET /api/v1/quote-integrity/status` (feature flag off) | 503 `FEATURE_DISABLED` structured error |
| CORS preflight from foreign origin | 204 with no `Access-Control-Allow-Origin` |
| `POST /api/v1/audit/exports/quick` | 202 `QUEUED` |
| Audit export after worker | `READY`, 416,802-byte ZIP |
| `GET audit/exports/{id}/download` signed URL | 200, ZIP with 35 CSV/JSON files incl. `signals.csv`, `virtual_positions.csv`, `risk_decisions.csv`, `summary.json` |
| Same download URL unsigned | 403 |

Queue/scheduler: the `queue` worker executed scheduler-dispatched jobs (`CheckMarketDataFreshnessJob`, `GenerateSignalJob`, lease reconciliation) with no errors; the `audit-worker` executed `GenerateAuditExportJob` (570 ms); the scheduler ran 22 registered tasks. `signals:generate` operated on the empty market database (no data, no orders). Stack torn down with `compose down -v`; no containers, volumes or networks from the smoke project remain (`stage6-compose-down.log`).

Not started by design: `market-ws` (would open the public Bybit WebSocket) and `backtest-worker` (covered by unit/integration suites). No real exchange orders are possible anywhere in the stack.

## 3. GitHub Actions review (not triggered)

Single workflow `.github/workflows/deploy.yml`: YAML parses; trigger is `workflow_dispatch` only; jobs `backend-tests` (PHP 8.5.12, sqlite), `frontend-tests` (Node 22), `publish-images` (app+web to GHCR), `deploy` (SSH with explicit secrets and environment `production`). No workflow was dispatched or modified.

CI-equivalent simulation (no PostgreSQL, no network, phpunit.xml environment only): **643 tests, 3,513 assertions, 0 failures, 35 skipped, exit 0** (`stage6-ci-simulation.log`). This matches what the CI test job will run.

Findings (not changed here; pipeline is operator-owned):
- There is no push/pull-request CI gate; tests only run as part of a manually dispatched deployment.
- The CI test job has no PostgreSQL service, so all 34 PostgreSQL integration tests and the Go worker test skip there. Recommended: add a `postgres:17-alpine` service plus `TEST_DB_*` env and a migrate step, or a separate non-deploying `ci.yml`.
- `publish-images` publishes only `app` and `web`; the Go `research-worker` image is not published by the workflow (Compose builds it from `docker/go/Dockerfile`). VPS-side handling was not verifiable from this environment.

## 4. Route inventory and API verification

Generated from the running application (`php artisan route:list --json`, `stage6-routes.json`):

- 118 routes total; **114 API** routes.
- Methods: 86 GET (+ implicit HEAD), 31 POST, 1 PUT.
- 12 routes require `research.token`, 1 requires a signed URL, 9 are gated by `quote_integrity.enabled`.
- Authentication: basic auth at nginx for everything except `/up`; errors are structured JSON with request IDs; 500 responses do not leak exception text (existing test).
- Frontend/API contract: 39 unique API calls in `frontend/src`; 38 match backend routes exactly. The single static mismatch is the dynamic `POST /research/experiments/{id}/{name}` action path, which resolves to the registered `start-shadow`, `stop-shadow`, `cancel` and `approve-forward` actions — not a defect.
- CSV exports: the audit export archive (server-side CSV generation) was verified live, including `signals.csv` headers. The table-page CSV export is generated client-side in `frontend/src/pages/TablePage.vue` and has no automated test (gap; no browser available in this environment).

## 5. Skips and notice (recorded)

- 35 skips in the CI-equivalent run: 34 PostgreSQL integration tests (no database configured) and the opt-in `BybitPublicApiIntegrationTest` (public data only, `RUN_BYBIT_INTEGRATION=1`).
- With the isolated database present, 34 of those run; only the opt-in public API test remains skipped (`RUN_DB=bybit_test`: 35 tests, 1 skipped).
- One pre-existing PHPUnit notice in `FundingHistorySynchronizerTest` (mock with no expectations) — unchanged, unrelated to the migration.

## 6. Residual risks

- `docker compose up` for `market-ws` / `backtest-worker` / `research-worker` was not exercised end-to-end; their images build and the Go worker passes its own tests.
- Node 22 (CI) versus Node 24 (local) for frontend tests: both are supported by the pinned toolchain, but the CI job itself was not executed.
- The deployment job and VPS script (`/usr/local/sbin/bybit-signals-deploy`) were not executed or inspected; no production access was made.
