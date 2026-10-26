# VECTOR V2.6 — Stage C: Isolated Queues & Event-Driven Trading Pipeline

## Executive Summary

- **Task**: VECTOR V2.6 Stage C Implementation & Verification
- **Status**: PASSED / COMPLETE
- **Date**: 2026-10-26
- **Target Commit**: Local commit on `main` following Stage B (`4daed86`)
- **Safety Boundaries**: Strictly PAPER-trading virtual environment. No real orders, no exchange keys with trade/withdraw privileges. All 13 Bybit and 13 Binance accounts preserved. No modifications to ECONOM resources.

---

## 1. Objectives & Architectural Context

Stage C replaces the latency-producing, independently scheduled cron polling path with a causally ordered, event-driven forward PAPER-trading pipeline for both Bybit and Binance.

### Key Deliverables Completed:
1. **Queue Isolation & Dedicated Workers**:
   - `trading-bybit` (`queue-bybit` service): dedicated to Bybit time-sensitive signals and paper execution.
   - `trading-binance` (`queue-binance` service): dedicated to Binance time-sensitive signals and paper execution.
   - `market-ingest` (`queue` service): serves Binance REST ingestion and general default background jobs.
   - Preserves research and audit queues in their existing isolated processes.
   - Resource bounded: `cpus: 0.75`, `mem_limit: 384m` for trading queues, fitting within VPS 4 vCPU / 8 GiB constraints.
2. **Durable Transactional Outbox / Event Tracking**:
   - Table `trading_pipeline_events` tracks every step: `CANDLE_CLOSED`, `SIGNALS_EVALUATED`, `PAPER_PROCESSED`.
   - Deterministic event keys: `{exchange}:{symbol}:{timeframe}:{candleCloseAtIso}:{stage}`.
   - Status tracking: `PENDING`, `DISPATCHED`, `COMPLETED`, `FAILED`, `STALE`.
3. **Causally Ordered Event Pipeline**:
   - Closed candle event (15m decision candle) triggers `ProcessClosedCandleEventJob`.
   - Context is verified and signals/observations are generated transactionally across all 13 strategies.
   - Upon transaction commit, `ProcessPaperPipelineJob` is queued on the dedicated exchange queue.
   - 5m execution bar events drive position lifecycle updates (SL/TP, trailing, pending entry fills) without redundant signal generation.
4. **Recovery & Anti-Starvation Guarantees**:
   - `TradingPipelineRecoveryService` and `trading:pipeline-recovery` command running every minute.
   - Automatically detects stuck or missed events older than 60s and retries or marks stale.
   - Backward-compatible rollback switch: `TRADING_PIPELINE_MODE=EVENT_DRIVEN` vs `SCHEDULED`.
   - Scheduled cron jobs check `isDecisionAlreadyServiced()` to cleanly skip redundant execution when event-driven pipeline is operating normally.
5. **Temporal & Financial Safety Invariants**:
   - Enforces 300-second freshness window: stale events are marked `STALE` and reject order execution.
   - Forward-fill protection: virtual entries are strictly evaluated using closed bars subsequent to signal timestamp.
   - Financial ledger fencing: `WithoutOverlapping` queue middleware and transactional locks prevent concurrent writes to the same paper account.

---

## 2. Modified and Created Files

### Configuration & Infrastructure
- `config/trading.php`: Queue names, pipeline mode (`EVENT_DRIVEN`), supported symbols, freshness window (300s).
- `compose.yaml`: Configured `queue-bybit` (`--queue=trading-bybit`), `queue-binance` (`--queue=trading-binance`), and `queue` (`--queue=market-ingest,default`) with explicit CPU/memory limits.
- `database/migrations/2026_10_26_120000_create_trading_pipeline_events_table.php`: PostgreSQL migration creating `trading_pipeline_events`.

### Application Layer (`app/Trading/`)
- `app/Trading/Infrastructure/Persistence/TradingPipelineEventRecord.php`: Eloquent model for pipeline events.
- `app/Trading/Application/TradingPipelineEventService.php`: Event recording, deduplication, freshness validation, and dispatch service.
- `app/Trading/Application/TradingPipelineRecoveryService.php`: Crash recovery scanner, retry handler, and duplicate run deduplication check.
- `app/Trading/Jobs/ProcessClosedCandleEventJob.php`: Queue job handling candle closures, multi-timeframe validation, market context verification, and 13-strategy evaluation.
- `app/Trading/Jobs/ProcessPaperPipelineJob.php`: Queue job handling paper signal processing and position lifecycle on dedicated trading queues.

### Market Data & Exchange Adapters
- `app/MarketData/Application/ClosedCandleIngestor.php`: Hooked Bybit WebSocket candle closure to `TradingPipelineEventService`.
- `app/Exchanges/Binance/BinanceWebSocketMessageParser.php`: WebSocket message parser for Binance USD-M klines.
- `app/Exchanges/Binance/BinanceClosedCandleIngestor.php`: Dedicated WebSocket closed-candle ingestor for Binance.
- `app/Exchanges/Binance/BinanceCandleIngestion.php`: Preserved pure Stage B batch-insertion contract (2 queries, zero pollution).

### Console & Scheduled Jobs
- `app/Strategy/Jobs/GenerateSignalJob.php`: Guarded with `TradingPipelineRecoveryService::isDecisionAlreadyServiced()`.
- `app/PaperTrading/Jobs/ProcessPaperTradingJob.php`: Guarded with `TradingPipelineRecoveryService::isPaperAlreadyServiced()`.
- `routes/console.php`: Added `trading:pipeline-recovery` command, scheduled recovery every minute, added serviced guards to Binance scheduled tasks.

### Test Suites & Benchmarks
- `tests/Unit/Trading/TradingQueueIsolationTest.php`: Verifies compose worker command lines, memory limits, and queue mapping.
- `tests/Feature/Trading/TradingPipelineEventContractTest.php`: Tests migration, model schema, idempotency, freshness guards, and dispatch.
- `tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php`: Full end-to-end integration test (closed candle -> 13 strategies -> paper processing) for Bybit and Binance.
- `tests/Feature/Trading/TradingPipelineRecoveryAndRollbackTest.php`: Tests crash recovery, retry limits, stale timeouts, and scheduled fallback.
- `tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php`: Concurrency fencing, duplicate prevention, retroactive fill guards, open position continuity under blocked new entries, ledger reconciliation.
- `scripts/benchmark-trading-pipeline.php`: Standalone benchmark measuring end-to-end eligibility and entry latency across 20 measured runs with 3 warmups.

---

## 3. Latency Benchmark Results

Acceptance Target: End-to-end pipeline latency strictly under **15.0 seconds** (15,000 ms).

Results measured on PostgreSQL 14.24 (`bybit_test`) over 20 measured runs after 3 warmups evaluating all 13 primary strategies and paper order execution:

| Exchange | Metric | P50 | P95 | P99 | Min | Max | Avg | Target Passed |
|:---|:---|---:|---:|---:|---:|---:|---:|:---:|
| **Bybit** | Signal Eligibility Latency | 1,302.217 ms | 1,374.956 ms | 1,423.777 ms | 1,249.768 ms | 1,423.777 ms | 1,311.849 ms | **TRUE** (< 15 s) |
| **Bybit** | Virtual Entry Latency | 1,358.319 ms | 1,451.917 ms | 1,505.766 ms | 1,303.690 ms | 1,505.766 ms | 1,375.467 ms | **TRUE** (< 15 s) |
| **Binance** | Signal Eligibility Latency | 1,380.286 ms | 1,441.490 ms | 1,481.136 ms | 1,339.311 ms | 1,481.136 ms | 1,392.295 ms | **TRUE** (< 15 s) |
| **Binance** | Virtual Entry Latency | 1,426.455 ms | 1,485.368 ms | 1,528.573 ms | 1,383.041 ms | 1,528.573 ms | 1,437.363 ms | **TRUE** (< 15 s) |

Both Bybit and Binance achieve P95 virtual entry latency under **1.5 seconds**, outperforming the 15-second acceptance threshold by an order of magnitude (~10x margin).

Artifact: `reports/vector-v26-stage-c/benchmark-pipeline.json`

---

## 4. Verification Gates Summary

| Verification Gate | Command | Exit Code | Result | Evidence |
|:---|:---|:---:|:---:|:---|
| **Trading Unit Tests** | `php vendor/bin/phpunit tests/Unit/Trading/` | 0 | 3 passed, 25 assertions | All worker and queue mappings verified |
| **Trading Feature Tests** | `php vendor/bin/phpunit tests/Feature/Trading/` | 0 | 17 passed, 78 assertions | Contract, chain, recovery, safety verified |
| **Binance Ingestion Invariant** | `php vendor/bin/phpunit tests/Feature/Exchanges/BinanceBatchCandleIngestionTest.php` | 0 | 14 passed, 198 assertions | 500-candle 2-query budget and zero write pollution preserved |
| **Full PHPUnit Suite** | `php vendor/bin/phpunit --no-coverage --display-skipped` | 0 | 846 passed, 6,668 assertions (1 skipped) | Clean pass across entire backend test suite |
| **Code Style (Pint)** | `php -d xdebug.mode=off vendor/bin/pint --test` | 0 | 669 files checked, 0 style issues | Clean pass |
| **PHP Syntax Lint** | `git ls-files '*.php' \| xargs -n 1 php -l` | 0 | 0 syntax errors | Clean pass across all PHP files |
| **Frontend Unit Tests** | `npm test --prefix frontend` | 0 | 55 passed, 0 failed | Node test runner verified |
| **Frontend Build** | `npm run build --prefix frontend` | 0 | Vue/Vite build successful | Dist artifacts emitted cleanly in 8.33s |
| **Go Target Tests & Vet** | `cd /source-go && go test ./... && go vet ./...` | 0 | All packages ok, vet clean | Run in test container |
| **Go Host Race Tests** | `cd research-go && go test -count=1 -race ./...` | 0 | All packages ok, 0 race conditions | Host race detector clean |

---

## 5. Remaining Risks and Production-Dependent Evidence

1. **Live WebSocket Stream Connectivity**:
   - The event-driven pipeline was verified in tests using deterministic WebSocket kline messages and mock transports.
   - Long-lived WebSocket connection stability (handling Bybit/Binance ping/pong heartbeats and reconnections under live network packet loss) is handled by existing WS daemons, but live network jitter remains an operational factor.
   - The fallback recovery scanner running every minute ensures that any missed WebSocket candle closure is caught within 60 seconds.
2. **Production Container Deployment**:
   - Container CPU/memory boundaries (`compose.yaml`) were verified syntactically and logically.
   - Actual production deployment and live multi-container orchestration is operator-controlled per Project Safety Boundaries (no manual deployment or unauthorized docker daemon modification).
3. **Stage Boundary**:
   - Stage C completes queue isolation and event-driven pipeline implementation.
   - Stage D (strategy parameter decoupling and dynamic configs) and subsequent stages are left untouched as planned.
