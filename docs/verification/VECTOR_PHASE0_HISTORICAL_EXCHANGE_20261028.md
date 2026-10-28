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
