# Phase 0 historical exchange propagation

Local synthetic verification only. Starting HEAD: b3c7107d723b081405420e3550e9e700f3cbb212.

BacktestRunService now accepts an exchange (Bybit remains the default), scopes dataset inspection, propagates exchange through optimizer, split/walk-forward simulation, stress runs and regime reports, and persists Binance selection in strategy configuration/fingerprint. Binance costs must be explicitly configured; no Bybit cost fallback or execution approval changes. Existing historical records are untouched.

Verified on task-owned PostgreSQL 14 at 127.0.0.1:25439/bybit_test, PHP CLI, synthetic fixtures:

```
env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=25439 DB_DATABASE=bybit_test DB_USERNAME=thunder DB_PASSWORD= php -dxdebug.mode=off artisan test tests/Feature/Backtest tests/Unit/Backtest
```

Exit 0: 19 tests, 382 assertions. Full `vendor/bin/pint --test` exit 0. `git diff --check` exit 0.

New assertions cover Binance split-run completion, optimization/stress market read scoping, distinct Bybit/Binance run IDs, deterministic repeated Binance IDs, explicit Binance costs, fail-closed missing costs and zero PAPER fixture writes. All 13 identities are checked on both exchanges for deterministic missing-history SKIP. This does not prove profitable signals, valid-history parity, funding settlement coverage, production PostgreSQL 17 behavior, or production recovery. Walk-forward propagation is implemented but lacks a new exchange-specific positive test.

Phase 0 remains blocked by production acceptance and unresolved immutable candle conflicts. No production data was accessed or mutated. No frozen obligation is admitted from these synthetic checks.

## CLI and queue continuation

After commit 194eb29, both backtest commands accept `--exchange=bybit|binance`; default and legacy serialized jobs select Bybit. The queued job forwards selection to the service and qualifies its overlap lock by exchange. Unknown exchanges fail before dispatch. Entry permissions are not changed by historical research.

The same isolated PostgreSQL environment ran:

```
php -dxdebug.mode=off artisan test tests/Feature/Backtest tests/Unit/Backtest tests/Feature/Exchanges tests/Unit/Exchanges
```

Exit 0: 112 tests, 1,298 assertions, 80.349 seconds. Full Pint exit 0; diff check exit 0. New tests cover both CLI modes/exchanges, the worker service call, legacy payload defaults and invalid dispatch rejection. Production worker consumption and PostgreSQL 17 remain unverified.

## Strategy and PAPER regression

The subsequent isolated run of `tests/Unit/Strategy tests/Feature/Strategy tests/Feature/PaperTrading` completed with exit 0: 162 tests, 3,060 assertions, 110.573 seconds. No production evidence or frozen acceptance credit is inferred.

## Binance recovery failure propagation

Confirmed source defect: RecoverCandleGapsJob caught ingestion exceptions around both intervals, skipped the remaining interval after a failure, and returned successfully. Queue retry/failure handling could therefore not observe the failure. This is independent of the still-unexplained production source revision/conflict.

The corrected job attempts its existing bounded 5m/15m recovery scopes independently, records failed scopes, and throws after attempting the batch. It adds explicit retry backoff. Successful scopes remain committed; retries verify immutable identities. No signals, risk decisions or PAPER entries are dispatched by recovery. This is not a complete historical backfill procedure for all HTF intervals.

Negative control: temporarily restoring the original job while retaining the two new regression tests produced two failures, exit 1. Fixed-source verification with BinanceGapRecoveryTest, BinanceBatchCandleIngestionTest, BinanceImmutableMarketIngestionTest and ExchangeIngestionAndValuationStageCTest passed: exit 0, 22 tests, 243 assertions. Full Pint exit 0. Tests verify malformed data failure, continued processing of other scopes, successful retry without duplicate/changed rows, and immutable conflicts still failing on repeated attempts. Actual production queue retry and terminal failed-job persistence remain unverified.

## Scheduled HTF failure isolation

The combined HTF callback previously aborted its sequential interval loop on the first exception. Each existing HTF interval now has its own callback and overlap lock; its cron and request limit remain unchanged. This contains a failed observation without accepting or overwriting it. Existing 5m scheduling remains separate.

`BinanceHtfScheduleTest` and `BinanceResearchCycleTest` passed on isolated PostgreSQL: exit 0, 9 tests, 223 assertions, 18.962 seconds. Full Pint exit 0. The new test executes all ten registered candle callbacks, injects a BTCUSDT 15m response failure, verifies nine other successful scopes, distinct mutexes, repeated ingestion without duplicates, and zero signals or positions from market ingestion. This does not prove the production scheduler, cache locks, source finality or host clock is healthy. The historical Binance immutable high mismatch still needs the diagnostic identity and both exact values from the affected production run.
