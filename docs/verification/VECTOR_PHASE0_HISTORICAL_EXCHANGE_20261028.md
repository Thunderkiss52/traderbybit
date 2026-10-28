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

After commit 009041a, wider `tests/Feature/MarketData tests/Feature/Trading` regression passed: exit 0, 55 tests, 370 assertions, 22.892 seconds. Strict scorecard validation passed with provisional percentages still zero due to the existing evidence freshness state. No production gates were admitted.

Continuation: complete positive valid-history parity and exchange-qualified candidate evaluation, then renew requirement-specific Phase 0 evidence. Production conflict diagnosis requires the structured conflict record (symbol, interval, UTC open time, stored/incoming decimals), source response at that identity, and host clock/scheduler logs. Do not repair stored observations or replay historical PAPER execution. Phase 1 remains blocked.

## Warm-history parity and candidate exchange continuation

From b8d9591: HistoricalStrategyParityTest now seeds separate closed 5m/15m/30m/1h histories and market context for both exchanges and both symbols. It verifies valid/warm input, distinct exchange prices, peer input, all 13 identities, agreement with registered strategy logic, deterministic repeated evaluation and no persisted signals or positions. Exit 0: 2 tests, 361 assertions, 149.067 seconds. These are deterministic synthetic inputs, not historical exchange performance or independent mathematical reference vectors.

Candidate generation accepts validated exchange identity (default Bybit); candidate metadata carries it into the historical worker. A foreign-exchange run is rejected before linkage. Binance forward promotion is explicitly blocked because the existing account provisioner is default-exchange only. No owner approval flag changes. CandidateExchangeTest, CandidateLabValidationLifecycleTest and PromotedCandidateExecutionTest passed on isolated PostgreSQL 14: exit 0, 10 tests, 89 assertions. Correct-exchange candidate linkage uses a mocked backtest boundary; actual historical execution is covered separately by the backtest tests. Full Pint passed.

## Direct candidate evidence cross-exchange rejection and walk-forward continuation

PromotionPolicyService candidate evaluation now enforces strict exchange equality when validating evidence from BacktestRunRecord and ResearchJobRecord: a candidate configured for one exchange (e.g. Binance) explicitly rejects linked evidence originating from another exchange (e.g. Bybit) with status REJECTED and failure reason `exchange_mismatch`.

ChronologicalSplitter::walkForward now supports minute/day units in addition to months, allowing end-to-end historical walk-forward execution and validation on bounded datasets. Binance walk-forward simulation verifies isolated dataset inspection, explicit Binance execution costs, and persistence of the exchange and walk_forward run type.

Verified on isolated PostgreSQL 14 at 127.0.0.1:25439/bybit_test:
- `tests/Feature/Backtest tests/Unit/Backtest tests/Feature/Research/CandidateExchangeTest.php tests/Feature/Research/CandidateLabValidationLifecycleTest.php`
- Exit 0: 36 tests, 776 assertions, 168.853 seconds. Full Pint exit 0; git diff check exit 0.
- `tests/Feature/Trading tests/Feature/MarketData tests/Feature/PaperTrading tests/Feature/Exchanges tests/Feature/Strategy tests/Unit/Strategy`: Exit 0, 278 tests, 4,265 assertions.
- `tests/Feature/Research tests/Unit/Research tests/Feature/Api tests/Feature/AuditExportTest.php`: Exit 0, 421 tests, 3,534 assertions.
- `tests/Unit`: Exit 0, 404 tests, 1,978 assertions.
- `tests/Integration`: Exit 0, 39 tests (1 passed, 38 skipped due to pgsql isolated mode), 12 assertions. Go `research-worker` recompiled and passed with isolated PostgreSQL credentials.
- `frontend` test suite: Exit 0, 64 tests, 0 failures. `npm run build` (`vue-tsc --noEmit && vite build`): Exit 0.
- `research-go` test suite: Exit 0, all tests passed. `go vet ./...`: Exit 0.
- No production financial records or database schemas were mutated. Phase 0 remains blocked by external production acceptance (45 V2 operational criteria awaiting operator execution of `scripts/diagnostics/phase0_production_acceptance.sql` on VPS) and the Binance immutable observation conflict.
