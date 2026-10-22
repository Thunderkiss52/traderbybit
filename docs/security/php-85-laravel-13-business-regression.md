# Business regression verification (stage 5)

Date: 2026-10-22
Branch: `main`
Scope: full business regression for the PHP 8.5.12 + Laravel 13.38.0 migration. Research and paper trading only.

## 1. Integration guard failures — root cause and fix

Before this stage the full suite reported 3 failures, all from `tests/Integration/LedgerReconciliationPostgresIntegrationTest`:

```
SAFETY ABORT: PostgreSQL test must run strictly against isolated 'bybit_test' database (current: :memory:).
```

Root cause: this suite was the only one reading `DB_*` instead of the shared `TEST_DB_*` contract when falling back to the isolated PostgreSQL container. The test runner supplies `DB_HOST=traderbybit-php85-postgres`, `DB_PORT=5432`, `DB_DATABASE=:memory:` and `TEST_DB_*=bybit_test`. The suite therefore configured the fallback connection with database `:memory:` and the strict guard, correctly, aborted.

Fix (`39c5078`..this stage): the fallback now reads `TEST_DB_PORT`, `TEST_DB_HOST`, `TEST_DB_DATABASE`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`, matching the other eight integration suites.

The guards were not weakened: the suite still hard-fails unless the active connection is PostgreSQL, the database name is exactly `bybit_test`, and the environment is not production. No production host, database or credential is used or configurable through this change.

Result: the three tests now execute against the isolated PostgreSQL container and pass (3 tests, 38 assertions) even in the default full-suite run, and also in the explicit `RUN_DB=bybit_test` run.

## 2. Actual verification results

All runs used `reports/php85-upgrade/run.py` (disposable PHP 8.5.12 container, isolated network, no Docker socket, no `.env`).

| Area | Command | Result |
|---|---|---|
| Unit + Strategy + PaperTrading + Backtest + Indicators + MarketData | `tests/Unit tests/Feature/Strategy tests/Feature/PaperTrading tests/Feature/Backtest tests/Feature/Indicators tests/Feature/MarketData` | **OK, 414 tests / 2,246 assertions** (`stage5-targeted.log`) |
| API + Research + QuoteIntegrity + Audit + PatternDiscovery + Security | `tests/Feature/Api tests/Feature/Research tests/Feature/QuoteIntegrity tests/Feature/Audit tests/Feature/PatternDiscovery tests/Feature/Security` | **OK, 190 tests / 1,216 assertions** (`stage5-api-research.log`) |
| Full suite | `vendor/bin/phpunit` | **OK, 643 tests / 3,805 assertions, 0 failures, 2 skipped, 1 pre-existing notice** (`stage5-full-1.log`, `stage5-full-2.log`) |
| PostgreSQL integration (explicit) | `RUN_DB=bybit_test vendor/bin/phpunit tests/Integration` | **OK, 35 tests / 304 assertions, 1 skipped** (`stage5-integration.log`) |
| Ledger reconciliation (default run) | `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php` | **OK, 3 tests / 38 assertions** |
| Go research worker | `go -C research-go test ./...` | **OK** for `algorithms`, `auditdata`, `backtest`, `candidates`, `decimal`, `jobrunner`, `patterns`, `replay`, `simulation`, `walkforward`, `tests/golden` (`stage5-go-tests.log`) |
| Scheduler | `php artisan schedule:list` | **22 scheduled entries** registered (market data sync/health, strategy observation, signal generation, paper processing, equity checkpoints, forward research, ledger reconciliation, autonomous learning, lease recovery, audit purge) |
| Queue worker + SIGTERM | `queue:work redis` then `SIGTERM` | Worker started against isolated Redis, `Worker STOPPED Interrupted`, exit 0 (`stage5-queue-sigterm.log`) |
| Isolated Redis state | phpredis `PING`/`DBSIZE` | `PONG`, 0 keys — no leftover queue/cache state |
| Frontend unit tests | `npm test --prefix frontend` | **54 passed, 0 failed** (`stage5-frontend-tests.log`) |
| Frontend typecheck + production build | `npm run build --prefix frontend` | **OK** (`vue-tsc --noEmit` + `vite build`, `stage5-frontend-build.log`) |
| Pint | `vendor/bin/pint --test tests/Integration/LedgerReconciliationPostgresIntegrationTest.php` | **PASS** |

Business invariants exercised by the passing suites: eight primary paper accounts and ledger reconciliation (`LedgerReconciliationPostgresIntegrationTest`), financial accounting/reconciliation and funding completeness, risk funnel and slot integrity, strategy observation slots, counterfactual idempotency, slippage attribution, ML authority/proper scoring, backtest/walk-forward determinism with no look-ahead, QIM quote-integrity flows, audit exports, and the research API contracts.

## 3. Safety verification

- No exchange order path exists: no `order/create`, `placeOrder` or similar code, and no Bybit API key configuration in `app/`, `config/` or `routes/`. Only public market data is consumed.
- No production access: all database work used the isolated `traderbybit-php85-postgres` container (`bybit_test`); the production database, accounts and volumes were never contacted.
- The eight primary accounts, historical executions and signal history were not modified; the integration suites create and remove only their own fixtures.
- No VECTOR v2.3 / v5.3 features were implemented; only the regression fix and evidence were added.

## 4. Skipped, noticed, blocked

- `BybitPublicApiIntegrationTest::test_public_linear_market_endpoints_match_the_documented_contract` — skipped by design (`RUN_BYBIT_INTEGRATION=1` required; public endpoints only, never orders).
- `GoResearchWorkerIntegrationTest` — skipped in the default sqlite full-suite run because it requires PostgreSQL; it **runs and passes** in the `RUN_DB=bybit_test` integration run (included in the 35 tests).
- One pre-existing PHPUnit notice: `FundingHistorySynchronizerTest` uses a mock without expectations (`PHPUnit Notices: 1`). Unrelated to this migration; left unchanged to keep the diff minimal.
- Nothing else blocked.

## 5. Residual risks

- The integration suites assume the isolated database is already migrated and reachable by container name; a fresh environment must run migrations before integration tests.
- The default full-suite run now executes PostgreSQL integration tests when the isolated container is reachable and skips the Go worker test; CI (stage 6) must run the explicit `RUN_DB=bybit_test` integration job for complete coverage.
- The public-API integration test remains opt-in and was not exercised here; it makes read-only public requests by design.
