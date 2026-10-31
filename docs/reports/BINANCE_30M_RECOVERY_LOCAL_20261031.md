# Binance 30m recovery: local verification, 2026-10-31

## Scope and provenance

Branch: `vector/binance-30m-recovery`; starting HEAD: `f3ba05f` on `23748cd`.
This is local source and isolated infrastructure evidence. No production backfill,
database mutation, deployment, push, merge, account unblocking, or real order was
performed. Production acceptance and the live Go worker execution remain separate.
The V3.4 Go migration registry remains unchanged: this PHP recovery does not
change the ownership or maturity of MIG-03, which is still `APPROVED`.

The confirmed production incident was 31 missing closed 30m bars per symbol
(BTCUSDT and ETHUSDT) and competing Binance strategy observations. The local
tests use source-shaped, synthetic Binance API responses; they do not claim that
the production interval has been repaired.

## Independent review of `f3ba05f`

The original recovery job omitted 30m; the strategy history job used the Bybit
sync route; historical Binance ingestion had no bounded range. Observation
contention was treated as a generic failure and the two job paths used different
middleware lock keys. The implementation in `f3ba05f` addressed these at the
source level but introduced or retained the following defects:

- The backfill used an undefined endpoint variable, omitted a Carbon import,
  wrote columns absent from its new audit table, used a random unique key that
  could not identify a resumed range, and could report `COMPLETED` with gaps.
- Historical requests used an inclusive end timestamp and did not reject
  out-of-range returned rows. The command's default “dry-run” still mutated
  history, and `--days` was read as a positional argument.
- The new Binance gap job was scheduled by default, bypassing the intended
  operator gate. PAPER processing could also dispatch it indirectly. Errors and
  incomplete ranges could be hidden from queue failure handling.
- The observation job had an invalid exception reference and could swallow
  contention. Lock expiry was shorter than the maximum worker execution time.
  A 15m candle could become `COMPLETED` before downstream PAPER processing.

## Corrections and file ownership

| Files | Purpose |
| --- | --- |
| `app/Exchanges/Binance/BinanceCandleBackfillService.php`, `BinanceCandleIngestion.php` | Aligned half-open UTC windows, at most 500 candles/request, exact persisted gap scan, bounded retry, closed-bar clamp, immutable conflict quarantine, and strict response bounds. |
| `app/MarketData/Jobs/EnsureStrategyHistoryJob.php`, `RecoverCandleGapsJob.php` | Explicit Binance API route including 30m; Bybit route preserved; opt-in historical maintenance even through indirect PAPER dispatch, and visible partial/failure semantics. |
| `app/Strategy/Application/StrategyObservationService.php`, `app/Strategy/Exceptions/StrategyObservationActiveException.php`, `app/Strategy/Jobs/ObserveStrategiesJob.php` | Observation slot lock lifetime exceeds worker timeout; bounded contention handling and correct exception scope. |
| `app/Trading/Jobs/ProcessClosedCandleEventJob.php`, `ProcessPaperPipelineJob.php` | Preserve PENDING on observation contention, terminalize 15m only after PAPER acknowledgment, prevent status downgrade, and bound paper middleware locks. |
| `config/exchanges.php`, `routes/console.php` | Automatic Binance historical recovery defaults off; manual command is dry-run by default and returns nonzero on incomplete recovery. |
| Deleted `BinanceCandleBackfillEventRecord.php` and `2026_10_30_000000_create_binance_candle_backfill_events_table.php` | Remove a broken duplicate recovery journal. Immutable `market_candles` are the durable progress checkpoint; existing conflict tables/journal preserve contradictory observations. |
| `tests/Feature/Exchanges/BinanceGapRecoveryTest.php`, `BinanceBackfillCommandSafetyTest.php`, `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php` | Gap routing, command safety, and fail-closed API behavior. |
| `tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php`, `BinanceObservationContentionTest.php` | Pipeline status and contention regressions. |
| `tests/Integration/BinanceCandleBackfillIntegrationTest.php`, `BinanceObservationRedisLockIntegrationTest.php`, `PaperPipelineConcurrentWorkersIntegrationTest.php` | PostgreSQL recovery/financial isolation, two-process Redis SIGKILL/expiry recovery, and two-process financial idempotency. |
| `tests/Fixtures/hold_observation_redis_lock.php`, `run_paper_pipeline_at_barrier.php` | Independent worker processes for actual lock and transaction contention. |

## Isolated environment and evidence

The host provides PHP 8.4.24 with PostgreSQL and PHP 8.5.9 without it;
Composer's platform gate requires PHP >=8.5.12. No vendor or production `.env`
file was changed. The task-owned `vector-binance-repair-php-test:20261031-psql`
image runs PHP 8.5.12 with PostgreSQL and Redis extensions. Task-owned
PostgreSQL 17.11 container `vector-binance-repair-pg-20261031` uses database
`bybit_test`, user `postgres`, container port 5432, host loopback port 25442.
Task-owned Redis container `vector-binance-repair-redis-20261031` shares that
network namespace. SQL identity and Laravel's resolved connection were checked
before migrations; only this disposable database was migrated. ECONOM and other
worktrees/resources were not touched.

The reproducible PHP test command is:

```bash
/home/thunder/.headroom/bin/rtk proxy docker run --rm --network container:vector-binance-repair-pg-20261031 --user 1000:1000 \
  -v /home/thunder/work/traderbybit-repair:/var/www/html \
  -v /tmp/vector-binance-repair-evidence-20261031:/evidence -w /var/www/html \
  -e APP_ENV=testing -e APP_URL=http://127.0.0.1 \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=5432 \
  -e DB_DATABASE=bybit_test -e DB_USERNAME=postgres -e DB_PASSWORD=testpass \
  -e TEST_DB_HOST=127.0.0.1 -e TEST_DB_PORT=5432 \
  -e TEST_DB_DATABASE=bybit_test -e TEST_DB_USERNAME=postgres -e TEST_DB_PASSWORD=testpass \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=6379 \
  -e GOCACHE=/tmp/go-build -e GOMODCACHE=/tmp/go-mod -e GOPATH=/tmp/go \
  --entrypoint php vector-binance-repair-php-test:20261031-psql \
  vendor/bin/phpunit --no-progress --log-junit /evidence/phpunit-full-verified.xml
```

Targeted recovery, pipeline, and wider regression runs passed (8/41, 9/53, 12/66,
92/662 tests/assertions respectively). The two-process paper test passed with
1 test and 13 assertions. The full suite and final gates are recorded below.

## Verified invariants and limits

- A PostgreSQL test seeded 17/48 bars for each symbol and filled exactly the
  31 missing identities without changing any of the 26 account rows, balances,
  positions, virtual executions, risk decisions, or trading journals. It
  recorded zero candle pipeline events from the historical backfill.
- Repeat ingestion preserved existing candle rows; a conflicting replay kept
  the original OHLCV, recorded conflict evidence, and returned a quarantined
  status. Partial and failed responses remained incomplete and could resume
  from committed rows. Open bars were excluded.
- A killed external Redis lock holder left a PENDING observation event; after
  TTL expiry the recovery service redispatched it within the 300-second
  freshness boundary. A separate two-process PostgreSQL race produced one
  position, one execution, and one `POSITION_OPENED` journal event for the
  fixture account; unrelated existing accounts were byte-for-byte unchanged.
- Setup continuity/freshness remains fail-closed. Backfilled rows are never
  dispatched as live `CANDLE_CLOSED` events. PAPER execution remains separately
  gated; neither account states nor risk limits were relaxed.

No live Binance API call, production recovery, live market observation, or
post-release operator validation was performed. The Go worker was built and its
isolated integration test ran, but production worker execution is unverified.
An observation worker killed while holding its 660-second lock can outlive the
300-second event freshness window. The event then becomes visibly `STALE` and
cannot execute a late PAPER trade; a missed forward evaluation needs operator
diagnosis. The lock cannot remain owned indefinitely.

## Release boundary

Owner review is required before integration. A production operator must verify
the release diff, deploy only through `.github/workflows/deploy.yml` after
explicit authorization, inspect the production conflict journal and candle
identity/continuity, then decide whether to run the documented manual
`binance:backfill-candles --apply` data-only command. Recheck the 26-account
financial reconciliation and pipeline statuses before accepting the incident
as resolved. No recovery command is automatically enabled by this commit.

## Final gate results

| Gate | Result | Exit |
| --- | --- | ---: |
| `php -l` on 21 changed PHP files | 21 pass | 0 |
| `vendor/bin/pint --test` | 730 files pass | 0 |
| Binance gap/Stage C targeted PHPUnit | 7 tests, 31 assertions | 0 |
| Two-process paper PostgreSQL targeted PHPUnit | 1 test, 13 assertions | 0 |
| Full `vendor/bin/phpunit --no-progress --log-junit /evidence/phpunit-full-final-verified.xml` | 1,096 tests, 10,777 assertions, 1 opt-in live Bybit API skip, 0 failures | 0 |
| Standalone Go worker PostgreSQL integration | 2 tests, 40 assertions | 0 |
| `npm test` in `frontend` | 64 pass, 0 skipped | 0 |
| `npm run build` in `frontend` | `vue-tsc --noEmit` and Vite build pass | 0 |
| `git diff --check` | clean | 0 |

The first full run found test-environment and stale-expectation issues, which
were corrected; the final full suite above is the authoritative result. The
only remaining skip needs the separately gated live Bybit API environment.
Final JUnit SHA-256:
`90c92723b599d9f1e4cc630c2cb4d1b0ea266e2074b26df3ca670a4fd5fa691b`.
The artifact is in the task-owned temporary evidence directory, outside Git.
