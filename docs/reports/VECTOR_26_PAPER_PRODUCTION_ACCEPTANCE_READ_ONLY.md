# VECTOR production acceptance: 26 PAPER portfolios

## 1. Purpose

Read-only acceptance diagnostic for the deployed VECTOR application covering all 13 Bybit and 13 Binance PRIMARY PAPER accounts, both market-data pipelines, strategy evaluation, PAPER financial integrity, and the Go Research Worker.

## 2. Safety properties

- Runs inside `BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY`.
- `SET LOCAL statement_timeout = '120s'`, `SET LOCAL lock_timeout = '5s'`.
- Zero `INSERT / UPDATE / DELETE / DROP / CREATE / ALTER / TRUNCATE / GRANT / REVOKE` statements.
- One `COMMIT` at the end. No writes occur even on error.
- Verified against an isolated disposable PostgreSQL 17 instance (`bybit_acceptance`, `127.0.0.1:25433`) with the full migration set applied via normal `php artisan migrate`.

## 3. How to run on the VPS (operator only)

```bash
docker exec -i bybit-signals-postgres-1 \
  psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -v ON_ERROR_STOP=1 -P pager=off \
  -f /path/to/VECTOR_26_PAPER_PRODUCTION_ACCEPTANCE_READ_ONLY.sql
```

To force read-only even if the file is edited:

```bash
docker exec -i -e PGOPTIONS='-c default_transaction_read_only=on' \
  bybit-signals-postgres-1 \
  psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -v ON_ERROR_STOP=1 -P pager=off \
  -f /path/to/VECTOR_26_PAPER_PRODUCTION_ACCEPTANCE_READ_ONLY.sql
```

## 4. Deployment image verification (operator commands)

```bash
docker compose ps --format '{{.Service}} {{.Image}} {{.Status}}'
docker inspect -f '{{.Config.Image}}' bybit-signals-app-1
docker inspect -f '{{.Config.Image}}' bybit-signals-web-1
docker inspect -f '{{.Config.Image}}' bybit-signals-research-worker-1
docker inspect -f '{{.Config.Image}}' bybit-signals-queue-1
docker inspect -f '{{.Config.Image}}' bybit-signals-scheduler-1
docker logs --since 30m bybit-signals-queue-bybit-1 | tail -40
docker logs --since 30m bybit-signals-queue-binance-1 | tail -40
docker logs --since 30m bybit-signals-research-worker-1 | tail -40
```

## 5. Sections produced

- 00 Read-only snapshot (transaction_read_only, isolation, snapshot id, backend pid)
- 01 Account census for all 26 PRIMARY accounts (balances, drawdown, position/execution/snapshot/journal timestamps)
- 02 Cohort integrity: 13 per exchange, no duplicates, no exchange-mixed positions or executions
- 03 M9 financial reconciliation: realized/equity/unrealized differences, closed nonzero marks
- 04 Market data freshness for 5 intervals x 2 symbols x 2 exchanges, missing intervals, conflict quarantine
- 05 Strategy evaluation per stream: 24h observation/signal/paper-state counts and rejection reasons
- 06 Pipeline events and stalled pending events
- 07 Executions, open positions, journal anomalies
- 08 Risk decisions and blocked accounts
- 09 Binance capital provenance (no duplicate starting capital)
- 10 Go Research Worker registration, active jobs, completed jobs
- 11 Summary row

## 6. Coverage of all 26 streams

Every PRIMARY account row is enumerated from `paper_accounts WHERE is_primary = true`, so coverage is driven by the cohort itself, not by a hardcoded strategy list. Section 05 adds a UNION ALL fallback row for any PRIMARY account with no 24h observation, so a silent stream is still reported with zero counts instead of being omitted.

## 7. Local validation

- New isolated PostgreSQL 17 container: `vector-acceptance-20261030-pg`, `127.0.0.1:25433`, database `bybit_acceptance`.
- Normal migrations applied: `php artisan migrate --force --no-interaction` with DB env scoped to the isolated instance. 62 migrations, all DONE, exit 0.
- SQL executed with `-v ON_ERROR_STOP=1`: exit 0, all 11 sections returned, `transaction_read_only = on`, `transaction_isolation = repeatable read`.
- DML scan confirms no mutating statements.
- `git diff --check`: exit 0.

## 8. Known limitations

- The isolated database contains no production market data, signals, positions, executions, pipeline events, research jobs or workers, so all counts are zero there. The SQL is validated for schema, types, column names, reserved-word handling, read-only semantics and coverage. Production values must be read by the operator.
- Binance PRIMARY accounts are created by `binance:initialize-paper`, not by a migration, so the isolated schema shows 0 Binance accounts. This is expected and does not indicate a defect.
- The four unresolved Binance candle conflicts dated 2026-10-27 are reported as zero in the isolated database because it has no conflicts. Section 04C/04D will show real quarantined rows in production.