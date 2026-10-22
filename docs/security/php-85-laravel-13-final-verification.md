# Final verification: PHP 8.5.12 / Laravel 13.38.0

Date: 2026-10-22. Branch: `main`. Verification baseline commit `6a0751f`. No production deployment, no workflow dispatch, no push.

## Scope

Three final passes over the committed tree: full backend suite, backend/Go quality gates, and frontend + container + deployment-shape smoke. Repository `vendor/` is present and the isolated test infrastructure (containers `traderbybit-php85-postgres`, `traderbybit-php85-redis`, network `traderbybit-php85-testnet`) is in use. Production databases, the eight primary paper accounts and exchange access were never touched.

## Upgrade commits

| Commit | Change |
|---|---|
| `921abbd` | Upgrade audit / requirements |
| `d230adc` | PHP 8.5.12 images and CI PHP version/extensions |
| `4be18be` | Laravel 13.38.0, PHPUnit 12.5.40, dependency lock |
| `5fb26f7` | Agent handoff workflow |
| `4ee1358` | PHP 8.5/Laravel 13 compatibility (cache serialisation, Go benchmark) |
| `dce7339` | Stage 3 handoff |
| `39c5078` | Runtime/transport security hardening (session, upload limits, origin checks, nginx 1.30.6) |
| `75a2352` | Stage 4 handoff |
| `d461033` | Ledger reconciliation test env-contract fix |
| `e7aabae` | Stage 5 handoff |
| `5b32dee` | Go worker images (Go 1.27 / Alpine 3.24) |
| `6a0751f` | Stage 6 handoff |

No `database/migrations` file changed in the upgrade: the schema is unchanged, so application rollback does not require a database rollback.

## Pass 1 — full backend suite

`vendor/bin/phpunit` in the PHP 8.5.12 test container against the isolated PostgreSQL/Redis: **643 tests, 3,805 assertions, 0 failures, 0 errors, 2 skipped, 1 notice, exit 0** (`stage7-pass1-full-suite.log`).

- Skips: opt-in live Bybit public API test (`RUN_BYBIT_INTEGRATION=1`); Go worker persistence test in the sqlite default run (covered by the `RUN_DB=bybit_test` integration run: 35 tests / 304 assertions / 1 skipped, stage 5).
- Notice: pre-existing `FundingHistorySynchronizerTest` mock without expectations; unrelated to the upgrade.
- CI-equivalent run (no PostgreSQL, no network): 643 tests / 3,513 assertions, 0 failures, 35 skipped, exit 0 (`stage6-ci-simulation.log`).

## Pass 2 — backend and Go quality gates

- Pint: **584 files, PASS** (`stage7-pass2-php-quality.log`). (An earlier FAIL came from a stale probe file in the ignored workspace copy, not from the repository; removed and re-run.)
- `composer validate --strict`: valid. `composer audit`: no advisories. `composer check-platform-reqs` under PHP 8.5.12: all satisfied.
- `php -l` over `app`, `config`, `database`, `routes`, `bootstrap`, `tests`: no syntax errors.
- Go (Go 1.26.9 in the test image): `go test ./...` all packages OK; `go vet ./...` exit 0; `gofmt -l` empty (`stage7-pass2-go.log`).

## Pass 3 — frontend, images, deployment-shape smoke

- Frontend: **54/54 tests**, `vue-tsc --noEmit` + `vite build` OK (`stage7-pass3-frontend.log`). `engines.node >=22` matches the CI Node 22 job.
- Images: app `PHP 8.5.12` / `Laravel Framework 13.38.0`; web nginx 1.30.6 (Alpine 3.24.3); research-worker Alpine 3.24.3 with a working `--standalone` benchmark.
- Vulnerabilities (Trivy 0.76.1, DB 2026-10-22): app 0, test 0, web 0, research-worker 0 HIGH/CRITICAL.
- Deployment-shape smoke on the committed tree (project `traderbybit-php85-smoke`, port 18098, own volumes): `docker compose config` valid; 7 services up and healthy; migrations applied; `/up` 200; dashboard 401 without auth and 200 with `schema_version=1.0.0`, `research_mode=true`; QIM flag off 503; `down -v` left 0 containers/volumes/networks (`stage7-smoke-*.log`).
- Endpoint pipeline verified live in stage 6: audit export 202 → worker → READY → signed download 200 (35 CSV/JSON files), unsigned 403; 118 routes (114 API); 38/39 frontend API paths match statically, the remaining path is a dynamic action dispatcher.

## Rollback

1. `git revert` the upgrade commits (or deploy the previous release tag); no migration rollback is required because no migration changed.
2. Re-deploy through `.github/workflows/deploy.yml` only; previous GHCR images remain addressable by their commit SHA tags.
3. Redis keyspace: cache/session names and prefixes were preserved during the upgrade; the research-token cache namespace is unchanged. Clear only `market:*` keys if stale payloads from the old schema are suspected.
4. Database: no destructive action needed. Do not revert production data; signals, executions and account records are append-only and untouched.

## Residual risks / not verified

- Deployment job and VPS deploy script were not executed; production verification is pending operator authorization. CI has no push/PR gate, skips all PostgreSQL integration tests, and does not publish the Go image.
- `market-ws` ingestion and `backtest-worker` were not exercised in the smoke stack (public WebSocket not opened); their images build and their logic is covered by unit/integration tests.
- No production-like load/soak test; no browser-based UI test; the client-side CSV export in `TablePage.vue` has no automated test.
- Security posture unchanged from stage 4: no CSP, single shared basic-auth credential, token-less mutating endpoints rely on basic auth plus origin enforcement.
- PHP 8.5 is a recent minor; third-party runtime behaviour is validated only by the suites above and the smoke stack.
