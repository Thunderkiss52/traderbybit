# Gate 1 — Runtime and Reliability Report

## 1. Process Topology & Execution Baseline
- **Laravel 11 App / API**: Configured with strict paper trading and research mode (`paper_only: true`). Verified via PHPUnit (302 tests, 300 passed, 2 skipped, 0 failed, 1749 assertions).
- **Queue Workers & Scheduler**: Evaluates 15-minute closed bars via `MarketScannerService` coordinating 16 evaluations per cycle (8 strategies × 2 symbols: BTCUSDT, ETHUSDT).
- **Go Jobrunner**: Located in `research-go/`, running 13 packages for high-performance backtesting, pattern mining, and simulation.
- **Frontend SPA**: Vue 3 + Quasar with TypeScript and Vite in `frontend/` (41 Node tests pass, Vite build passes with zero errors).

## 2. Isolated Test Infrastructure (PostgreSQL 17 & Redis 7.4)
- **Isolated Containers**:
  - `bybit-test-pg`: PostgreSQL 17 on `127.0.0.1:25432` (`POSTGRES_DB=bybit_test`, `POSTGRES_USER=postgres`, `POSTGRES_PASSWORD=testpass`).
  - `bybit-test-redis`: Redis 7.4 on `127.0.0.1:26379`.
- **Startup Commands**:
  ```bash
  docker run -d --name bybit-test-pg -p 25432:5432 -e POSTGRES_DB=bybit_test -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=testpass postgres:17-alpine
  docker run -d --name bybit-test-redis -p 26379:6379 redis:7.4-alpine
  ```
- **Database Migrations on Isolated Infrastructure**:
  ```bash
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=25432 DB_DATABASE=bybit_test DB_USERNAME=postgres DB_PASSWORD=testpass php artisan migrate --force
  ```
- **Go Worker & PostgreSQL Integration**:
  - Binary built: `cd research-go && go build -o ../bin/research-worker ./cmd/research-worker`
  - Integration verified via `tests/Integration/GoResearchWorkerIntegrationTest.php`: Worker claims jobs in `research_jobs` using `FOR UPDATE SKIP LOCKED`, executes compute, and transitions status to `SUCCEEDED` with telemetry and `result_manifest_sha256`.

## 3. API 500, Redis & Dashboard Latency Resolution
- **Issue 1: Redis Deserialization**: `__PHP_Incomplete_Class_Name` on cold dashboard queries. Resolved by returning bounded plain Eloquent/array representations instead of raw serialized objects.
- **Issue 2: `/research/patterns/latest-matches`**: Threw 500 when `symbol` query parameter was omitted. Parameter was made optional with graceful handling and verified in `PatternResearchApiTest::test_latest_matches_handles_missing_symbol_parameter_safely`.
- **Issue 3: Dashboard Aggregation & Latency Reconciled**:
  - Early prototype implementations introduced an artificial `limit(200)` / `limit(100)` on the 24-hour SKIP reasons query, capping aggregate counts.
  - **Resolution**: All row-truncating limits were removed from `ResearchController::strategyObservations`. Full 24-hour aggregation is performed using indexed time-range filters (`decision_time >= now() - 24h`) and column projection (`get(['reasons'])`), ensuring 100% accurate SKIP aggregates.
  - **Measured Performance (`storage/latency_benchmark.json`)**:
    - Measured on populated database: 8 accounts, 120 signals, 25 positions, 351 observations, 1250 candles, 100 contexts.
    - Cold response: 250.86 ms (SLA threshold < 1500 ms).
    - Warm response (N=50): P50 = 0.01 ms, P95 = 0.01 ms (SLA threshold < 200 ms).

## 4. Market Ingestion & Causality Invariants
- Bybit v5 linear market candles ingested strictly on closed bars (`is_closed = true`).
- Timestamps stored in UTC with timezone-aware representations.
- Gap detection and out-of-order market event handling prevents retroactive fills.
