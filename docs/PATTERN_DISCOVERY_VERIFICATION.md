# Pattern Discovery verification (local, 2026-10-15)

## Scope

Daily Paper Performance was present in the supplied project (service, API, dashboard component and feature tests). Pattern Discovery was added as a separate, read-only research module. It **does not** create virtual or real orders, change RiskEngine rules, or promote patterns automatically.

The pattern miner evaluates BUY and SELL counterfactual outcomes after closed 5-minute bars, using conservative SL precedence on ambiguous OHLC events, modeled fees/slippage, explicit availability timestamps, fixed historical train/validation/test boundaries and a multiple-testing-aware threshold. It is an initial research implementation, **not** proof of profitable performance.

The new API supports bounded research jobs, paginated runs/patterns, pattern detail and latest research-only matches. The `research` queue was added to the dedicated Docker backtest/research worker.

## Three test iterations

1. **Unit/causality:** 7 standalone PHP checks passed (feature extraction, future-data rejection, sequence matching, bounded itemsets and chronology). An additional 8 standalone outcome/context checks passed (ATR barriers, conservative fills, fees, missing candles, point-in-time context). Source includes PHPUnit tests, but the PHPUnit runner cannot start in this environment.
2. **API/frontend/integration checks:** 33/33 `node:test` checks passed; a point-in-time parity fix was applied to the latest pattern matcher. Laravel integration tests require PHP extensions and a configured database, unavailable in this environment.
3. **Regression/build:** 250 PHP files passed syntax lint; Vue TypeScript check (`vue-tsc --noEmit`) passed; Vite production build passed. The charts chunk remains above Vite's advisory 500-kB threshold.

## Not verified

- Full PHPUnit (the local PHP CLI is missing `dom`, `mbstring`, and `xmlwriter`; package repositories were unreachable).
- PostgreSQL migrations, worker execution, job idempotency/concurrency and authenticated HTTP route smoke tests.
- Historical experiments on real Bybit data, walk-forward parameter retraining, and profitability/OOS outcomes.
- Live browser memory usage and production deployment.

## Production checklist (do not deploy without approval)

1. Restore/install PHP dependencies in the project's official Docker environment.
2. Run `php artisan migrate --force` on a **test database first** and execute PHPUnit, especially `PatternDiscoveryTest`, `PatternResearchApiTest`, and `DailyPerformanceTest`.
3. Verify `docker compose config`, start the research queue on staging, and inspect a bounded historical run and `/api/v1/research/patterns` HTTP responses.
4. Verify `/research/patterns` in a real browser, including pagination and permissions.
5. Use GitHub Actions only for production deployment after explicit approval.

No market performance numbers have been invented, and no live trading or deployment has been performed.
