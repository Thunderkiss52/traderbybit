# VECTOR — Incident Remediation Report: PostgreSQL Migration Locking & Safe Candle Backfill

## Executive Summary

During the production deployment of commit `816926e`, `php artisan migrate --force --no-interaction` remained blocked for approximately 25 minutes. The root cause was identified as an unindexed, whole-table cross-join `UPDATE signals s SET candle_id = mc.id FROM market_candles mc ...` executed inside the same migration transaction that acquired an `AccessExclusiveLock` on `signals` in `2026_11_08_230000_add_candle_id_to_signals_table.php`.

This report documents the architectural remediation implemented and verified across the database schema, background processing, and deployment execution pipeline.

---

## 1. Incident Baseline & Root Cause Analysis

### A. The Lock Chain
1. **Migration Transaction Start**: Laravel started a transaction for `2026_11_08_230000_add_candle_id_to_signals_table.php`.
2. **Catalog & Table Lock**: `Schema::table('signals', ...)` added `candle_id`, index, and foreign key constraint, acquiring `AccessExclusiveLock` and `ShareRowExclusiveLock` on `signals`.
3. **Catastrophic Mass UPDATE**: Inside the uncommitted transaction, `DB::statement("UPDATE signals s SET candle_id = mc.id FROM market_candles mc WHERE ...")` evaluated a 5-way OR condition with dynamic timestamp arithmetic (`s.candle_close_time - interval '...'`), forcing an exhaustive join scan across historical signals and candles.
4. **Queue Worker Blockage**: Active workers executing `StrategyObservationService::observe()` called `SignalRecord::query()->insertOrIgnore(...)`, which blocked on PostgreSQL behind the `AccessExclusiveLock` on `signals`.
5. **Cascading Redis Lock Contention**: While waiting on PostgreSQL, the blocked worker retained its Redis observation slot lock (TTL 660s). Competing scheduled tasks or retry workers for that slot attempted to acquire the Redis lock and threw:
   ```text
   StrategyObservationActiveException: Strategy observation is already running for bybit:linear:BTCUSDT
   ```
6. **Container Healthcheck Failure**: Docker's healthcheck for `app` (testing port 9000 PHP-FPM) failed repeatedly because the container was running the CLI migration process in the foreground. Docker marked the temporary container as `unhealthy`.
7. **Operator Intervention**: The blocking PostgreSQL backend PID `190237` was terminated via `pg_cancel_backend`. The transaction rolled back, leaving `signals.candle_id` uncreated on production. The old release was preserved.

---

## 2. Remediated Architecture

### A. Staged, Non-Blocking Schema Migration
The migration [`database/migrations/2026_11_08_230000_add_candle_id_to_signals_table.php`](file:///home/thunder/work/traderbybit/database/migrations/2026_11_08_230000_add_candle_id_to_signals_table.php) was refactored:
- **Transaction Decoupling**: Declared `public $withinTransaction = false;` to allow PostgreSQL `CONCURRENTLY` operations and staged lock isolation.
- **Stage A (Add Column)**: Adds `candle_id bigint NULL` without default. In PostgreSQL 11+, this is a metadata catalog update completing in < 5ms with `SET lock_timeout = '5s'`.
- **Stage B (Concurrent Index)**: Checks `pg_index.indisvalid`. If an invalid index exists (e.g. from an interrupted build), drops it with `DROP INDEX CONCURRENTLY IF EXISTS`. Creates index using `CREATE INDEX CONCURRENTLY IF NOT EXISTS signals_candle_id_index ON signals (candle_id)`, taking `ShareUpdateExclusiveLock` without blocking active writers.
- **Stage C (Foreign Key NOT VALID)**: Adds constraint using `FOREIGN KEY (candle_id) REFERENCES market_candles(id) ON DELETE SET NULL NOT VALID`, skipping full table scans during constraint establishment.
- **Stage D (Separate Validation)**: Validates constraint with `ALTER TABLE signals VALIDATE CONSTRAINT signals_candle_id_foreign` with `SET lock_timeout = '5s'` and `SET statement_timeout = '30s'` under `ShareUpdateExclusiveLock`.
- **Zero Mass UPDATE**: The blocking `UPDATE` query was completely removed.

### B. Resumable Artisan Backfill Command
Implemented [`app/Strategy/Console/BackfillCandleIdsCommand.php`](file:///home/thunder/work/traderbybit/app/Strategy/Console/BackfillCandleIdsCommand.php) (`php artisan signals:backfill-candle-ids`):
- **Safety Defaults**: Defaults to `DRY-RUN` preview mode. Modifications require explicit `--apply`.
- **Dual Lock Mutual Exclusion**:
  - Connection-scoped PostgreSQL advisory lock (`SELECT pg_try_advisory_lock(...)`). Automatically freed if connection drops.
  - Distributed Redis lock (`Cache::lock(...)`) with safety headroom.
- **Durable Checkpointing & Unmatched Tracking**:
  - Created [`database/migrations/2026_11_09_193000_create_signal_candle_backfill_tables.php`](file:///home/thunder/work/traderbybit/database/migrations/2026_11_09_193000_create_signal_candle_backfill_tables.php) provisioning `signal_candle_backfill_checkpoints` and `signal_candle_backfill_items`.
  - Unmatched signals are recorded with status `NO_CANDLE` and `retry_after = now() + 1 hour`. Subsequent executions do not waste time rescanning them unless `--retry-all` is passed.
  - Ambiguous matches (>1 matching candle) are recorded as `AMBIGUOUS` and left unlinked.
  - Unclosed candles (`is_closed = false`) are recorded as `UNCLOSED_CANDLE` with `retry_after = now() + 15 minutes`.
- **Bulk Set-Based Resolution**:
  - Batches calculate open times and fetch candles in single set-based queries, eliminating N+1 overhead.
- **Bounded Transactions**:
  - Updates only records `WHERE candle_id IS NULL`, with `SET LOCAL lock_timeout = '2s'` and `SET LOCAL statement_timeout = '5s'`.

### C. Deployment Pipeline Hardening
Updated [`docker/scripts/common.sh`](file:///home/thunder/work/traderbybit/docker/scripts/common.sh), [`docker/scripts/update.sh`](file:///home/thunder/work/traderbybit/docker/scripts/update.sh), and [`docker/scripts/deploy.sh`](file:///home/thunder/work/traderbybit/docker/scripts/deploy.sh):
- Configured `MIGRATION_TIMEOUT_SECONDS="${MIGRATION_TIMEOUT_SECONDS:-300}"`.
- Implemented `run_safe_database_migration`: Runs migration in background PID loop with elapsed second tracking. If timeout is reached, issues `SIGTERM` followed by `SIGKILL`.
- Implemented `report_postgres_lock_diagnostics`: Queries `pg_stat_activity` and `pg_locks` for active transactions and locks on application tables on any failure.
- Fail-closed: Migration failure immediately aborts promotion, preserves healthy running containers, and prevents release advancement.

---

## 3. Verification Evidence Matrix

| Test Suite / Tool | Command / Scope | Assertions / Result | Status |
| :--- | :--- | :--- | :--- |
| **PostgreSQL Migration & Backfill Integration** | `phpunit tests/Integration/PostgresMigrationAndCandleBackfillIntegrationTest.php` | 11 tests, 44 assertions (idempotency, dry-run, apply, ambiguity rejection, unclosed rejection, retry timing, cross-exchange isolation, checkpoint resumption, lock contention, timeline API) | **PASS** (1.50s) |
| **Deployment Recovery & Migration Failure** | `python3 -m unittest scripts/tests/test_deployment_update.py -v` | 13 tests (migration failure handling, forward migration rollback block, recovery review, backup failure, clean release rollback) | **PASS** (85.2s) |
| **TwentySixPaperAccounts** | `phpunit tests/Feature/PaperTrading/TwentySixPaperAccountsVerificationTest.php` | 6 tests, 322 assertions | **PASS** (5.73s) |
| **Forward Pipeline Causality** | `phpunit tests/Feature/Trading/TradingPipelineForwardCausalityTest.php` | 6 tests, 342 assertions | **PASS** (9.03s) |
| **Binance Contract Smoke** | `php scripts/tests/binance_contract_smoke.php` | 50 standalone assertions | **PASS** |
| **Gate 6 Standalone** | `php scripts/verify-gate6-standalone.php` | 68 checks | **PASS** |
| **A2 Standalone** | `php scripts/verify-a2-standalone.php` | 24 assertions | **PASS** |
| **CI / Progress Policy Suite** | `python3 -m unittest scripts/tests/test_assess_phase0_evidence.py ... -v` | 64 tests | **PASS** (1.96s) |
| **Laravel Pint** | `./vendor/bin/pint --test` | Clean PSR-12 | **PASS** |
| **Git Diff Whitespace** | `git diff --check` | Clean | **PASS** |

---

## 4. Verification Categorization

### VERIFIED LOCALLY & IN ISOLATED POSTGRESQL
- Idempotent execution of staged schema DDL (`2026_11_08_230000_add_candle_id_to_signals_table.php`).
- `CREATE INDEX CONCURRENTLY` and constraint validation under active reader/writer access.
- Non-mutating `signals:backfill-candle-ids --dry-run` operation.
- Bounded batch application with `--apply`, checkpoint progression, and durable `signal_candle_backfill_items` status recording.
- Rejection of unclosed, ambiguous, or cross-exchange candles.
- Lock contention rejection between competing backfill workers.
- Docker deployment script failure propagation and schema review gating (`test_deployment_update.py`).

### NOT VERIFIED ON PRODUCTION
- VPS `/usr/local/sbin/bybit-signals-deploy` execution on production VPS.
- Historical backfill against production PostgreSQL dataset.

### REQUIRES OWNER APPROVAL
- Triggering the next deployment via GitHub Actions release workflow.
- Operator execution of `php artisan signals:backfill-candle-ids --dry-run` followed by `php artisan signals:backfill-candle-ids --apply` on production VPS after deployment.
