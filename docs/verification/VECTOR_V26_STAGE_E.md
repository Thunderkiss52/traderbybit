# VECTOR V2.6 — Stage E: Daily Performance Aggregation

## Executive Summary

- **Task**: Remove the daily-performance N+1 query pattern while preserving exact
  forward-paper financial and timezone semantics.
- **Status**: IMPLEMENTED and tested locally; HTTP latency target under 20-way
  concurrency is **not verified** on the available development server.
- **Date**: 2026-10-26
- **Branch**: `vector/v26-stage-d`
- **Base commit**: `dc5851d` (Stage D)
- **Environment**: isolated PostgreSQL `bybit_test` on `127.0.0.1:25432`.
- **Safety**: PAPER/research only. No production database, Redis, deployment, or real
  orders were touched.

The service previously issued one `period()` query set for each returned day plus
five summary periods. A ten-day response required **137 SQL queries**. It now uses two
PostgreSQL set-based queries plus account/day-discovery queries: **5 SQL queries** for
the same response.

---

## 1. Root Cause

`DailyPerformanceService::report()` called `period()` independently for:

- `today`, `yesterday`, `last_7_days`, `last_30_days`, `all_time`;
- every requested day (default 10).

Each `period()` performed separate snapshot, position-count, execution-sum, and
funding-sum queries. The result was a deterministic N+1 pattern that grew with page
size. The existing 15-second HTTP cache only hid the cost on cache hits.

---

## 2. Implementation

Files:

- `app/PaperTrading/Application/DailyPerformanceService.php`
- `scripts/benchmark-daily-performance.php`
- `tests/Feature/PaperTrading/DailyPerformanceTest.php`

### Period definitions

All summary and day windows are converted to UTC once. A day window remains
`[local 00:00, next local 00:00 - 1 microsecond]`, capped at the current time for
today. The opening snapshot boundary remains `start - 1 microsecond`; the closing
boundary remains the period end.

### PostgreSQL query plan

For PostgreSQL, `report()` now executes:

1. account lookup;
2. total snapshot-day count;
3. current page of local snapshot dates;
4. one metrics query for every period using a `VALUES` CTE and correlated aggregates;
5. one snapshot query for every close/open boundary using `LEFT JOIN LATERAL`.

The metrics query counts opened, closed, and winning positions, averages `r_multiple`,
and sums execution commission, gross PnL, and position funding. Windows, symbol
filters, and account filters are applied in SQL. No historical rows are modified.

The snapshot query selects the last equity snapshot at or before each boundary with
`ORDER BY recorded_at DESC, id DESC LIMIT 1`, preserving the previous tie-breaking
behavior.

### Non-PostgreSQL fallback

The original per-period logic remains as a fallback for non-PostgreSQL drivers. Both
paths share one formatting function, so contract fields and financial arithmetic are
identical.

### Testability

The service clock now uses `CarbonImmutable::now($zone)` so Laravel test time travel
also controls freshness and "today" windows. Production behavior is unchanged.

No migration was required. Existing composite indexes cover the filtered aggregates,
including `equity_account_recorded_index`, `positions_account_closed_index`, and the
account/symbol/time position indexes.

---

## 3. Correctness Evidence

`tests/Feature/PaperTrading/DailyPerformanceTest.php` now includes a deterministic
fixture with:

- prior-day and current-day equity snapshots;
- three trades crossing day boundaries, including one open position;
- entry and exit commissions;
- positive and negative funding events on separate symbols;
- a symbol-filtered response (`BTCUSDT`) alongside the all-account response.

Assertions cover:

- exact `net_pnl`, `realized_pnl`, commissions, funding, opened/closed counts,
  win rate, average R, and completeness;
- day-window inclusion/exclusion;
- symbol-filtered realized PnL and the explicit
  `symbol_mark_to_market_history_unavailable` limitation;
- a query budget of at most 8 queries, with the actual result reported as 5.

The existing pagination, timezone, large-history, and invalid-timezone tests continue
to pass.

---

## 4. Performance Evidence

Command:

```bash
APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=25432 DB_DATABASE=bybit_test \
DB_USERNAME=postgres DB_PASSWORD=testpass XDEBUG_MODE=off \
php scripts/benchmark-daily-performance.php --runs=20 --warmup=3
```

Fixture: one account, 1,000 equity snapshots, ten returned days.

| Version | SQL queries | P50 | P95 | Max |
|:---|---:|---:|---:|---:|
| Stage D baseline (`dc5851d`) | 137 | 173.723 ms | 194.263 ms | 208.034 ms |
| Stage E optimized | **5** | **8.645 ms** | **10.757 ms** | 10.780 ms |

This is a **18.1x P95 reduction** in the same local environment and fixture. It is a
service-call benchmark, not a production SLA and not a profitability result.

### HTTP concurrency limitation

The available environment has no PHP-FPM or Octane; only the PHP development server is
installed. A cached `/api/v1/paper/daily-performance` benchmark with 16 CLI workers
and 20 concurrent requests produced P95 **182 ms** (40 requests, 0 failures). The
application work per request was 6.856 ms across concurrent requests, but development
server/bootstrap overhead dominates.

Therefore the requested **API P95 under 60 ms at 20-way concurrency is not verified**
in this environment. Production verification requires the deployed PHP-FPM/Octane
runtime and must not be inferred from this development-server result.

A separate 20-process cold service benchmark produced P95 155.3 ms; that measurement
includes 20 independent Laravel bootstraps and cold database connections, so it is not
an HTTP latency result.

---

## 5. Verification Results

Targeted suite:

```bash
php artisan test --no-ansi --filter=DailyPerformance
```

Result: **5 passed**, 49 assertions.

Full PHPUnit suite:

```bash
php artisan test --no-ansi
```

Result: **852 passed, 1 skipped**, 6,722 assertions (853 tests). The skipped test is
pre-existing.

Pint:

```bash
vendor/bin/pint --test
```

Result: passed.

PHP syntax lint (`php -l`): **673 files, 0 errors**.

Frontend and Go gates are recorded in the final Stage F verification.

---

## 6. Remaining Risks / Limitations

1. **HTTP SLA not verified** — See Section 4. The service optimization is verified;
   the end-to-end HTTP target on production infrastructure is not.
2. **PostgreSQL-specific path** — The optimized query is exercised on PostgreSQL. The
   fallback remains covered by existing behavior but is not the production path.
3. **Timezone edge cases** — Existing tests cover a non-UTC timezone. DST-transition
   dates were not separately benchmarked; window construction still uses local
   midnight and UTC conversion.
4. **Data volume** — The benchmark uses 1,000 snapshots and no production-sized trade
   history. Trade/funding aggregate performance at production volume remains unverified.
5. **No deployment** — No code, database, Redis, or container state was changed outside
   the isolated worktree and test database.

---

## 7. Files Changed

- `app/PaperTrading/Application/DailyPerformanceService.php`
- `tests/Feature/PaperTrading/DailyPerformanceTest.php`
- `scripts/benchmark-daily-performance.php`
- `docs/verification/VECTOR_V26_STAGE_E.md` (this document)
