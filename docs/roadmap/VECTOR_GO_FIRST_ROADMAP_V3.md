# VECTOR Go-First Architecture Roadmap V3: Efficiency-Driven Migration

**Status:** Canonical Approved Technical Roadmap (Architecture & Planning Specification)  
**Governing Standard:** [`AGENTS.md`](file:///home/thunder/work/traderbybit/AGENTS.md) and [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md)  
**Source Baseline SHA:** `a4b82ea04a3307cbbc29e966bcc0b250e0339caf` (Phase 0 Local Acceptance Closure)  
**Historical Scope Baseline:** `VECTOR-SCOPE-20261027-v1` (Frozen, 97 Requirements, 229 Obligations)  
**Target Proposal Baseline:** `VECTOR-SCOPE-20261028-v3`  

---

## Executive Summary & Owner Directives

The owner and architecture board have codified the primary architectural trajectory for the VECTOR research and virtual paper-trading platform:

> **"Golang will become the primary language for VECTOR's core computational, quantitative research, strategy evaluation, market data, risk management, PAPER trading, and financial business logic.**  
> **Laravel will remain a thin control plane responsible for API endpoints, authentication, task orchestration, configuration management, and read-side delivery of statistics.**  
> **Vue 3 + Quasar remains the frontend.**  
> **We optimize VECTOR for measurable efficiency, maintainability, reliability, security, and functional progress — NOT for code deletion, language migration percentages, or rewriting the same project repeatedly."**

### Core Engineering Principles
1. **Efficiency Over Migration for Its Own Sake:** Migration must be justified by measurable improvements in throughput, latency, memory predictability, computational determinism, or defect elimination. Code reduction percentages (e.g. arbitrary "40% Laravel code reduction") are explicitly removed as goals.
2. **Reuse Before Re-Implementation:** Existing mature Go components (vectorized backtesting engine, walk-forward optimizer, Monte Carlo simulator, candidate pipeline, leased PostgreSQL worker) must be reused and extended rather than rewritten.
3. **Single Authoritative Writer:** To guarantee absolute financial integrity across the 26 independent PAPER accounts (13 Bybit + 13 Binance), at any given millisecond exactly **ONE** engine is the authoritative writer for any given financial or ledger entity. Dual-writing is strictly prohibited.
4. **Single Source of Strategy Calculations:** After migration, Go becomes the sole authoritative implementation of all 13 PRIMARY strategy families, serving research, backtesting, candidate evolution, and forward PAPER execution identically.
5. **Strict Safety Invariants:** Virtual PAPER mode only; zero real exchange orders; zero trade credentials; preserved 5% entry-block and 10% halt risk thresholds; preserved 26-account census; zero historical financial alteration; ECONOM resources untouched.

---

## 1. Repository Inspection & Workload Classification

An exhaustive inspection of `/home/thunder/work/traderbybit` classifies every major system workload into its current implementation state:

| Workload Area | Current Language & Location | Operational Status | Migration Strategy |
|:---|:---|:---|:---|
| **Deterministic Backtesting** | Go: `research-go/internal/backtest/` | **Already implemented and actively used in Go** via leased `research-worker`. | **REUSE & EXTEND** (Extend to support all 13 families). |
| **Walk-Forward Optimization (WFO)** | Go: `research-go/internal/walkforward/` | **Already implemented and actively used in Go** via `WFOEvaluator`. | **REUSE** (Tested, causal splitting intact). |
| **Monte Carlo Simulation** | Go: `research-go/internal/simulation/` | **Already implemented and actively used in Go** (10k+ path block bootstrap). | **REUSE** (High throughput, zero allocations). |
| **Leased Worker Orchestration** | Go: `research-go/internal/jobrunner/` | **Already implemented and actively used in Go** (PostgreSQL `SKIP LOCKED`). | **REUSE** (Durable lease heartbeats). |
| **Pattern & Breakout Mining** | Go: `research-go/internal/patterns/` | **Already implemented in Go**, standalone capability. | **REUSE**. |
| **Decision Replay & Attribution** | Go: `research-go/internal/replay/` | **Already implemented in Go**, counterfactual replay runner. | **REUSE & EXTEND**. |
| **Candidate Strategies (5 Families)** | Go: `research-go/internal/candidates/` | **Implemented in Go but not used by production execution path** (Research only). | **EXTEND** (Align interfaces with Strategy SDK). |
| **PRIMARY Strategies (8 Families)** | PHP: `app/Strategy/Research/` | **Implemented only in PHP** (Used in production forward scanner). | **CONSOLIDATE** into Go Strategy SDK. |
| **Technical Indicators** | PHP (`app/Indicators/`) & Go (`algorithms/`) | **Hybrid / Duplicated across PHP and Go**. | **CONSOLIDATE** into shared Go package. |
| **Candle Ingestion & WS Streaming** | PHP: `BinanceCandleIngestion`, Artisan WS | **Implemented only in PHP**. Ingestion bottleneck (Binance P95 52.161 ms > 50 ms). | **MIGRATE** to Go Market Data Engine. |
| **Portfolio Risk Engine** | PHP: `app/PaperTrading/Application/RiskEngine.php` | **Implemented only in PHP**. Evaluated via queue jobs on 15m intervals. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). |
| **Position Lifecycle State Machine** | PHP: `PositionLifecycleService.php` | **Implemented only in PHP**. Evaluated sequentially per account. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). |
| **Double-Entry Financial Accounting** | PHP: `LedgerRecord.php`, `DailyPerformanceService.php` | **Implemented only in PHP**. Authoritative system of virtual accounting. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). |
| **Multi-Exchange Adapters** | PHP: `app/Exchanges/` | **Implemented only in PHP**. | **CONSOLIDATE** into unified Go exchange adapters. |
| **REST API, Auth & Admin UI** | PHP: Laravel 11/13 + Vue 3 / Quasar | **Implemented in PHP**. Operating effectively as Web presentation layer. | **RETAIN IN PHP** (Thin Control Plane). |

---

## 2. Core Target Architecture

The target architecture establishes a clean, decoupled boundary between orchestration and execution:

```
┌────────────────────────────────────────────────────────────────────────┐
│               LARAVEL THIN CONTROL PLANE & WEB UI                     │
│  - Authentication & Authorization (Sanctum, Roles, Session Management) │
│  - REST API Routing & Request Validation (`/api/v1/...`)               │
│  - Operator Commands, Governance Gate Approvals & Audits               │
│  - Research Experiment Dispatch & Progress Presentation                │
│  - Read-Side Delivery of Statistics, Ledgers, Reports & CSV Exports    │
│  - Quasar 2 / Vue 3 Operational Dashboards (Strict Observability)      │
└───────────────────────────────┬────────────────────────────────────────┘
                                │ Redis Streams (Async Events & Signals)
                                │ PostgreSQL 17 (Durable System of Record)
┌───────────────────────────────▼────────────────────────────────────────┐
│                        GOLANG VECTOR CORE                              │
│                                                                        │
│  ┌───────────────────────┐  ┌───────────────────────────────────────┐  │
│  │   Market Data Engine  │  │         Strategy Runtime SDK          │  │
│  │  - Public WS Streams  │  │  - All 13 PRIMARY Families in Go      │  │
│  │  - Normalized Candles │  │  - Unified Feature Calculations       │  │
│  │  - Ring Buffer Memory │  │  - Bit-Exact Golden Parity Fixtures   │  │
│  │  - Sub-15ms Ingestion │  │  - Real-Time Signal Generation        │  │
│  └───────────┬───────────┘  └───────────────────┬───────────────────┘  │
│              │                                  │                      │
│  ┌───────────▼──────────────────────────────────▼───────────────────┐  │
│  │               PAPER Trading & Risk Engine Core                   │  │
│  │  - Account Isolation (13 Bybit + 13 Binance Accounts)           │  │
│  │  - Position Lifecycle (Pending -> Open -> TP1/TP2 -> Closed)     │  │
│  │  - Certified Funding Rates (8h/4h) & Taker Slippage Settlement   │  │
│  │  - Authoritative Double-Entry Financial Accounting (12-Decimals) │  │
│  │  - Database-Enforced Single-Writer Fencing Protocol              │  │
│  └───────────────────────────────────┬──────────────────────────────┘  │
│                                      │                                 │
│  ┌───────────────────────────────────▼──────────────────────────────┐  │
│  │              Research & Deterministic Evolution                  │  │
│  │  - Leased Job Runner (`research-worker`, `FOR UPDATE SKIP LOCKED`)│  │
│  │  - Vectorized Backtesting, Walk-Forward, Monte Carlo Bootstrapping│  │
│  │  - Reproducible Parameter Space Exploration & Mutation Trees     │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Resource and Service Topography
- **No Service Proliferation:** Rather than deploying dozens of containerized microservices, Go execution is organized into **two bounded processes**:
  1. `vector-core`: Single-process, multi-goroutine daemon handling Market Data, Strategy Evaluation, Risk, and PAPER lifecycle.
  2. `research-worker`: Existing scalable batch compute worker executing heavy asynchronous research jobs (`BACKTEST`, `WFO`, `MONTE_CARLO`, `EVOLVE`).
- **Storage Roles:**
  - **PostgreSQL 17:** Authoritative persistence for paper balances, positions, executions, ledger history, immutable audit events, and research job manifests.
  - **Redis 7.4:** Fast caching of market snapshots, in-memory tick buffering, and ephemeral inter-process event streams (signals, alerts). Redis is **never** the authoritative store for financial balances.

---

## 3. Migration Value Gate (MVG) Specification

No component is migrated simply because it is written in PHP. Every proposed workload must satisfy the **Migration Value Gate (MVG)**:

### 3.1 The 10-Point Evaluation Template
1. **Current Implementation & Ownership:** File paths, classes, and current runtime ownership.
2. **Identified Problem or Bottleneck:** Empirical latency, CPU/memory overhead, synchronization defects, or developer maintenance friction.
3. **Technical Rationale for Go:** Why Go’s type system, concurrency model, or memory layout solves the specific issue.
4. **Baseline Empirical Measurement:** Exact recorded metrics (P50/P95 latency, allocation count, failure rate).
5. **Migration Complexity & Cost:** Engineering effort, dependency impact, and lines of critical path touched.
6. **Integrity & Correctness Risks:** Financial risk, race conditions, decimal truncation, or accounting divergence.
7. **Expected Measurable Improvement:** Specific verifiable target (e.g. latency, throughput, memory bounds).
8. **Shadow Parity & Rollback Strategy:** Dual-running methodology, verification period, and rollback mechanics.
9. **Acceptance Criteria:** Hard measurable thresholds required before authorizing production cutover.
10. **Formal Gate Decision:** `REUSE`, `EXTEND`, `MIGRATE`, `CONSOLIDATE`, `RETAIN`, or `DEFER`.

### 3.2 Formal Migration Value Gate Decisions

| Component Area | Current Implementation | Empirical Baseline | Bottleneck / Rationale | Decision | Target Milestone |
|:---|:---|:---|:---|:---:|:---:|
| **Binance Candle Ingestion** | PHP: `BinanceCandleIngestion` | P95 = 52.161 ms (> 50 ms SLA) | PHP PDO parameter binding & string escaping overhead. Go direct binary unnest eliminates serialization lag. | **MIGRATE** | Phase 2 |
| **Bybit WebSocket Listener** | PHP: `market-ws` CLI | Single-threaded ReactPHP | Socket reconnect fragility, process memory drift. Go goroutines offer robust lock-free channel fanout. | **MIGRATE** | Phase 2 |
| **Deterministic Backtesting** | Go: `research-go/internal/backtest/` | Millions of bars/sec in Go | High throughput, zero garbage collection pauses. | **REUSE** | Phase 4 |
| **WFO & Monte Carlo** | Go: `research-go/internal/` | 10k paths in < 2s | Efficient parallel matrix operations in Go standard library. | **REUSE** | Phase 4 |
| **Technical Indicators** | PHP `app/Indicators` & Go `algorithms` | Duplicated math | Maintaining duplicate math risks subtle rounding drift. Consolidate into single Go library. | **CONSOLIDATE** | Phase 1 & 3 |
| **13 PRIMARY Strategy Families** | PHP `app/Strategy/Research` | 13 PHP, 5 Go candidates | Dual maintenance. Strategies must evaluate identically in backtesting and forward paper trading. | **CONSOLIDATE** | Phase 3 |
| **Candidate Search & Evolution** | PHP `StrategyEvolutionService` | High memory in PHP | Parameter mutation loops separated from Go backtesting engine create IPC overhead. | **MIGRATE** | Phase 4 |
| **RiskEngine & Position Sizing** | PHP `RiskEngine.php` | Scheduled 15m polling | Polling delays fill acknowledgment. Go in-memory evaluation responds in milliseconds. | **MIGRATE** | Phase 5 |
| **Position Lifecycle & Fills** | PHP `PositionLifecycleService` | Sequential account loops | Sequential PHP transactions on 26 accounts cause queue latency spikes during volatile bars. | **MIGRATE** | Phase 5 |
| **Double-Entry Paper Ledger** | PHP `LedgerRecord.php` | Synchronous SQL | Must reside alongside Position Lifecycle to avoid distributed multi-phase commit overhead. | **MIGRATE** | Phase 5 |
| **Candidate Lab Governance** | PHP `PromotionPolicyService` | Low-frequency admin action | Approval requires human operator intervention. Laravel excels at authenticated admin workflows. | **RETAIN** | Control Plane |
| **REST API & Auth** | PHP Laravel 11/13 + Sanctum | P95 < 20 ms for API | Thin presentation layer, robust ecosystem for authentication, validation, and JSON schemas. | **RETAIN** | Control Plane |
| **Vue 3 Operational Dashboard** | TypeScript / Vue 3 / Quasar | Browser client | Clean, responsive UI with zero trading credentials. Fully decoupled from backend language. | **RETAIN** | Frontend |
| **High-Frequency Orderbook L2/L3**| None | Not required | VECTOR operates on 5m/15m/1h closed candles. Sub-millisecond tick streaming adds unnecessary complexity. | **DEFER** | Future Scope |

---

## 4. Objective Efficiency Metrics (Removing Arbitrary Code Targets)

The arbitrary objective to "reduce Laravel code by 40%" is **revoked and struck from all specifications**. Success is evaluated strictly against empirical operational and engineering metrics:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   OBJECTIVE EFFICIENCY CRITERIA                        │
├─────────────────────────────────────┬──────────────────────────────────┤
│ Metric                              │ Measurement Target               │
├─────────────────────────────────────┼──────────────────────────────────┤
│ 1. Duplicate Strategy Implementations│ Exactly 1 canonical Go source    │
│ 2. Binance 500-Candle Ingestion P95 │ PROPOSED < 25.0 ms (current 52ms)│
│ 3. Strategy Evaluation Latency (13) │ PROPOSED < 10.0 ms across all 13 │
│ 4. Paper Lifecycle Processing (26)  │ PROPOSED < 20.0 ms end-to-end    │
│ 5. Memory Footprint Stability       │ Bounded Go daemon RSS < 256 MiB  │
│ 6. Strategy Authoring Time          │ Add strategy in 1 file + schema  │
│ 7. Financial Accounting Parity      │ 0.000000000000 discrepancy       │
│ 8. Deterministic Replay Parity      │ 100% bit-exact decision identity │
└─────────────────────────────────────┴──────────────────────────────────┘
```

---

## 5. The Nine-Phase Implementation Roadmap

### Phase 0 — Existing System Acceptance & Baseline Remediation
- **Objective:** Complete production acceptance, reconcile operational obligations, and establish verified baselines without changing frozen SLAs.
- **1. Existing Components to Reuse:** Full test suite (939 tests, 8873 assertions), Playwright browser suite (`scripts/verify-browser-phase0.py`), SQL diagnostics (`scripts/diagnostics/phase0_production_acceptance.sql`), operator checklist (`docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md`).
- **2. PHP Components to Migrate:** None. Phase 0 is strict stabilization of the existing baseline.
- **3. Go Packages to Implement:** Performance benchmarking utilities (`research-bench`) to profile PostgreSQL batch ingestion.
- **4. Interfaces & Data Contracts:** Canonical envelope (`data`, `error`, `meta`), QIM health endpoints, dual kill-switch state machine.
- **5. Database & Event Ownership:** PostgreSQL 17 system of record; Laravel owns all authoritative writes.
- **6. Dependency Ordering:** Gate 0 closure is the prerequisite for Phase 1.
- **7. Exact Acceptance Criteria:** Operator executes checklist on VPS; `VEC-P0-003-V1` (census) and `VEC-P0-008-V1` (gate) closed; Binance ingestion P95 threshold of `< 50.0 ms` strictly evaluated without relaxing threshold.
- **8. Test Requirements:** 939 PHP tests passing, 64 frontend tests passing, 9 browser tests passing, Pint compliant.
- **9. Performance & Resource Budgets:** PHP-FPM <= 512 MiB, PostgreSQL <= 1 GiB RSS, Redis <= 256 MiB.
- **10. Shadow-Mode & Rollback Strategy:** Immediate rollback to commit `ca2aaea` if VPS diagnostic fails.
- **11. Safety Constraints:** Strictly virtual PAPER mode across 26 accounts; zero live orders; ECONOM untouched.
- **12. Local Commit Boundaries:** Evidence admission and scorecard update commits.
- **13. Scorecard Requirement Mappings:** `VEC-P0-001` through `VEC-P0-209` (45 requirements, 99 obligations).

---

### Phase 1 — Go Core Foundation & Versioned Contracts
- **Objective:** Build shared Go foundational packages, high-precision financial arithmetic, and versioned IPC protocols.
- **1. Existing Components to Reuse:** `research-go/internal/decimal/`, `research-go/internal/contracts/job.go`, Redis 7.4.
- **2. PHP Components to Migrate:** Duplicate DTO definitions, status enum constants, serialization helpers.
- **3. Go Packages to Implement:**
  - `pkg/types`: Universal domain primitives (`Exchange`, `Symbol`, `Timeframe`, `Side`, `OrderState`, `AccountID`).
  - `pkg/decimal`: 12-decimal-place scaled fixed-point math (`ScaledDecimal`) guaranteeing bit-exact arithmetic with PostgreSQL `NUMERIC(24,12)`.
  - `pkg/protocol`: Versioned message envelopes (`v3.0.0`) with schema validation and cryptographic digests.
  - `pkg/fencing`: Monotonic epoch-based fencing token generator.
- **4. Interfaces & Data Contracts:** Versioned Redis Stream message envelope: `{"v": "3.0.0", "epoch": 1042, "type": "EVENT_NAME", "payload": {...}}`.
- **5. Database & Event Ownership:** Redis stream namespace reservation (`vector:stream:*`); zero PostgreSQL schema changes.
- **6. Dependency Ordering:** Requires Phase 0 sign-off; prerequisite for all subsequent Go components.
- **7. Exact Acceptance Criteria:** `ScaledDecimal` passes 1,000,000 randomized arithmetic operations against PostgreSQL `NUMERIC` without 1 ULP error; IPC serialization benchmark PROPOSED < 5 microseconds per envelope.
- **8. Test Requirements:** Property-based testing (`testing/quick`), fuzzing of JSON/protocol decoders, Go race detector (`go test -race`).
- **9. Performance & Resource Budgets:** Static binary size < 25 MB; zero heap allocations on hot-path decimal math; worker memory ceiling 64 MiB.
- **10. Shadow-Mode & Rollback Strategy:** Dual-parsing of contracts in PHP and Go unit tests; git revert rollback.
- **11. Safety Constraints:** Strict compilation boundary; no reflection in hot paths; compile-time interface verification (`var _ Interface = (*Implementation)(nil)`).
- **12. Local Commit Boundaries:** Foundational packages and property test suite.
- **13. Scorecard Requirement Mappings:** Foundation for `VEC-P1-001` through `VEC-P1-008`.

---

### Phase 2 — Go Market Data Engine
- **Objective:** Eliminate PHP ingestion bottlenecks with a high-performance Go market data ingestion engine for Bybit and Binance.
- **1. Existing Components to Reuse:** Bybit v5 and Binance USDT-M public REST/WS endpoints, rate-limit policies.
- **2. PHP Components to Migrate:** `BinanceCandleIngestion.php`, `RunMarketWebSocketCommand.php`, historical candle synchronizers.
- **3. Go Packages to Implement:**
  - `internal/marketdata/bybit`: Resilient Bybit v5 public WebSocket client with auto-reconnect and heartbeat.
  - `internal/marketdata/binance`: Binance public WebSocket client with sequence number checking.
  - `internal/marketdata/ringbuffer`: Concurrent, lock-free circular candle buffer holding 500 closed bars per symbol/timeframe.
  - `internal/marketdata/writer`: Direct PostgreSQL bulk insertion using optimized binary `COPY` / unnest queries.
- **4. Interfaces & Data Contracts:** `MarketDataStreamer` interface emitting immutable `CandleClosedEvent` structs.
- **5. Database & Event Ownership:** Go becomes authoritative writer for `market_candles`, `market_funding_rates`, `market_open_interests`. Laravel cron ingestion retired.
- **6. Dependency Ordering:** Requires Phase 1; prerequisite for Phase 3 strategy runtime.
- **7. Exact Acceptance Criteria:** 500-candle bulk ingestion PROPOSED P95 < 20.0 ms (resolving the failing 52 ms PHP baseline); zero dropped candles over 72 continuous hours; automatic reconnect with bounded backoff on network interruption.
- **8. Test Requirements:** Mock WebSocket servers emitting malformed payloads, out-of-order candles, and burst ticks.
- **9. Performance & Resource Budgets:** Peak RSS < 128 MiB; CPU load < 0.2 cores under active tick streams.
- **10. Shadow-Mode & Rollback Strategy:** Parallel ingestion to shadow database table; hash comparison against PHP; one-switch cutover via configuration.
- **11. Safety Constraints:** Public endpoints only; zero trading credentials; exchange rate limits enforced via token buckets.
- **12. Local Commit Boundaries:** Market data engine packages, CLI daemon, and integration tests.
- **13. Scorecard Requirement Mappings:** Resolves `VEC-P0-009-V1` permanently; feeds live market stream to `VEC-P1-001` through `VEC-P1-008`.

---

### Phase 3 — Go Strategy SDK & Strategy Runtime
- **Objective:** Implement all 13 PRIMARY strategy families in Go with bit-exact parity against PHP golden fixtures.
- **1. Existing Components to Reuse:** 5 Go candidate families in `research-go/internal/candidates/`, parameters in `config/strategy.php`.
- **2. PHP Components to Migrate:** Remaining 8 PHP strategy families (`BaselineStrategy`, `TrendPullbackStrategy`, `BollingerMeanReversionStrategy`, `DonchianBreakoutStrategy`, `RegimeSwitchingStrategy`, `MomentumStrategy`, `SupportResistanceBounceStrategy`, `SupportResistanceBreakoutStrategy`).
- **3. Go Packages to Implement:**
  - `pkg/strategy`: Universal Go Strategy SDK (`StrategyDescriptor`, `StrategyInstance`, `Evaluate(input)`).
  - `internal/strategy/primary`: All 13 PRIMARY strategy families implemented natively in Go.
  - `internal/strategy/runtime`: Concurrent strategy evaluation engine triggered instantly upon candle close events.
- **4. Interfaces & Data Contracts:** `StrategyDecision` struct matching canonical JSON schema (BUY, SELL, SKIP with deterministic reason codes, price targets, ATR stop-loss).
- **5. Database & Event Ownership:** Go publishes `SignalGeneratedEvent` to Redis Stream; Laravel reads signals for UI/API display.
- **6. Dependency Ordering:** Requires Phase 2 candle stream; prerequisite for Phase 4 evolution and Phase 5 paper execution.
- **7. Exact Acceptance Criteria:** All 13 PRIMARY families implemented in Go; 100% bit-exact signal decision equivalence across 10,000 historical golden candle fixtures compared against PHP golden output; per-candle evaluation PROPOSED P95 < 5 ms across all 13 families.
- **8. Test Requirements:** Differential testing comparing Go output against PHP output byte-for-byte; edge-case boundary testing.
- **9. Performance & Resource Budgets:** All 13 strategies evaluated concurrently in PROPOSED < 10 ms; memory allocation < 1 MiB per evaluation.
- **10. Shadow-Mode & Rollback Strategy:** Go runs in shadow mode emitting signals to shadow stream; diffs logged; Laravel forward scanner continues driving paper orders until Phase 5.
- **11. Safety Constraints:** Pure function evaluation; zero I/O or network calls inside strategy logic; deterministic RNG seeded by candle hash.
- **12. Local Commit Boundaries:** Go strategy implementations committed family by family with corresponding golden fixtures.
- **13. Scorecard Requirement Mappings:** Maps to `VEC-P1-001` through `VEC-P1-008` (Strategy SDK and Registry).

---

### Phase 4 — Go Research, Backtesting & Evolution Engine
- **Objective:** Consolidate parameter optimization, walk-forward analysis, and candidate search into the unified Go compute core.
- **1. Existing Components to Reuse:** `research-go/internal/backtest/`, `research-go/internal/walkforward/`, `research-go/internal/simulation/`.
- **2. PHP Components to Migrate:** `ProcessAutonomousLearningJob`, `EvaluateStrategyCandidateJob`, `StrategyEvolutionService`.
- **3. Go Packages to Implement:**
  - `internal/research/evolution`: Deterministic parameter exploration (grid sweeps, mutation trees) with reproducible RNG.
  - `internal/research/manifest`: Cryptographic dataset and experiment manifest generator.
  - `internal/research/pipeline`: End-to-end candidate lifecycle orchestrator (Search -> Backtest -> WFO -> Monte Carlo -> Candidate Lab artifact).
- **4. Interfaces & Data Contracts:** Job contract (`JobType`, `DatasetManifest`, `ParameterSpace`, `ResultManifest`); SHA-256 output hashing.
- **5. Database & Event Ownership:** Go worker claims `research_jobs` via PostgreSQL `FOR UPDATE SKIP LOCKED`; writes immutable result manifests.
- **6. Dependency Ordering:** Requires Phase 3 strategy runtime; prerequisite for Candidate Lab promotion.
- **7. Exact Acceptance Criteria:** 1 year of 15m candles backtested across 13 strategies in PROPOSED < 1.5 seconds; bit-exact identical result hash across repeated runs; zero future label leakage verified by permutation tests.
- **8. Test Requirements:** Leakage mutation tests, identical-input replay verification, worker crash/restart lease reclamation tests.
- **9. Performance & Resource Budgets:** Worker memory ceiling <= 512 MiB; CPU bounded to 1.0 core via Compose limits.
- **10. Shadow-Mode & Rollback Strategy:** Jobs executed asynchronously; failed jobs do not affect paper trading; leases expire cleanly.
- **11. Safety Constraints:** No autonomous promotion to PRIMARY accounts; promotion requires human operator gate sign-off.
- **12. Local Commit Boundaries:** Research packages, worker CLI, and unit test suites.
- **13. Scorecard Requirement Mappings:** Maps to `VEC-P3-001` through `VEC-P3-011` (Evolution) and `VEC-P4-001` through `VEC-P4-010` (Governance).

---

### Phase 5 — Go RiskEngine, PAPER Engine & Financial Accounting (Shadow Validation)
- **Objective:** Implement the complete portfolio risk engine, order lifecycle state machine, and double-entry accounting in Go; run in shadow mode while PHP remains authoritative.
- **1. Existing Components to Reuse:** PostgreSQL financial schemas (`paper_positions`, `paper_executions`, `paper_account_ledgers`), risk parameters in `config/paper.php`.
- **2. PHP Components to Migrate:** `PositionLifecycleService.php`, `RiskEngine.php`, `PaperProcessingService.php`, `DailyPerformanceService.php`.
- **3. Go Packages to Implement:**
  - `internal/paper/risk`: Portfolio risk evaluation, max drawdown enforcement, account isolation, leverage checks.
  - `internal/paper/lifecycle`: Order state machine (Pending -> Open -> TP1/TP2 -> Closed), trailing stops, timeout liquidation.
  - `internal/paper/accounting`: Double-entry transaction ledger engine (`Equity = Balance + Unrealized PnL`).
  - `internal/paper/settlement`: Certified 8h/4h funding rate fee calculations, taker commission deductions, slippage modeling.
- **4. Interfaces & Data Contracts:** `RiskEvaluator`, `OrderExecutor`, `LedgerService`; transactional event signatures.
- **5. Database & Event Ownership:** **Strict Shadow Mode.** PHP remains the sole authoritative writer to `paper_accounts`, `paper_positions`, `paper_executions`, `paper_account_ledgers`. Go writes shadow records to isolated staging tables (`shadow_paper_*`) and emits reconciliation reports.
- **6. Dependency Ordering:** Requires Phase 3 strategy signals and Phase 2 real-time candle marks; prerequisite for Phase 7 cutover.
- **7. Exact Acceptance Criteria:** 26 independent accounts processed in PROPOSED < 20 ms per candle; zero ledger discrepancies across 100,000 synthetic lifecycle events; exact double-entry arithmetic balance identity holds at every millisecond; shadow execution runs for 14 continuous days without financial divergence from PHP.
- **8. Test Requirements:** PostgreSQL concurrency race tests (`FOR UPDATE` row locking), double-funding negative controls, partial exit precision tests.
- **9. Performance & Resource Budgets:** Position processing latency PROPOSED P95 < 25 ms; memory footprint < 128 MiB; zero lock contention on concurrent accounts.
- **10. Shadow-Mode & Rollback Strategy:** Continuous comparison of Go shadow balance vs PHP live balance; discrepancy > 0.0001 USDT raises immediate alert. Rollback is trivial since PHP remains the live writer.
- **11. Safety Constraints:** Zero real trading keys; 26 accounts strictly isolated; preserved 5% entry-block and 10% halt risk thresholds (no 15% threshold).
- **12. Local Commit Boundaries:** Risk engine, position lifecycle engine, accounting ledger engine, and migration scripts.
- **13. Scorecard Requirement Mappings:** Direct mapping to core Phase 0 financial requirements and Phase 4 operational controls.

---

### Phase 6 — Unified Analytics & Multi-Exchange SDK
- **Objective:** Implement high-throughput analytics calculations and standardized exchange adapters in Go, surfacing them cleanly via Laravel API.
- **1. Existing Components to Reuse:** `MetricRegistry.php`, `ExchangeComparisonService.php`, frontend analytics views.
- **2. PHP Components to Migrate:** Performance statistics calculations, Sharpe/Sortino/Calmar math, correlation matrices, slippage attribution.
- **3. Go Packages to Implement:**
  - `pkg/exchange`: Unified exchange adapter interface (`Capabilities()`, `FetchKlines()`, `StreamTicks()`, `FundingSchedule()`).
  - `internal/analytics`: Streaming financial metrics calculation (real-time drawdowns, win-rate by cohort, R-multiple distributions).
  - `internal/attribution`: Mark-to-market and slippage decomposition engine.
- **4. Interfaces & Data Contracts:** Normalized multi-exchange DTOs; analytics query API contracts.
- **5. Database & Event Ownership:** Go maintains read-optimized analytical materialized caches in Redis; PostgreSQL remains system of record.
- **6. Dependency Ordering:** Requires Phase 5 financial accounting; prerequisite for Phase 7 cutover.
- **7. Exact Acceptance Criteria:** Unified metric calculation for 26 accounts over 90 days generated in PROPOSED < 150 ms (vs 1200 ms in PHP); multi-exchange adapter contract harness verifies both Bybit and Binance with 100% specification conformance.
- **8. Test Requirements:** Multi-exchange contract conformance test suite; extreme market data gap/delisting simulations.
- **9. Performance & Resource Budgets:** Analytical query response PROPOSED P95 < 50 ms; Redis cache size < 64 MiB.
- **10. Shadow-Mode & Rollback Strategy:** Frontend toggles between PHP and Go analytics via feature flag; zero state mutations.
- **11. Safety Constraints:** Public data only; unsupported exchange features return explicit `UNSUPPORTED` enum rather than fabricating defaults.
- **12. Local Commit Boundaries:** Exchange adapter packages, analytics engine, and API integration.
- **13. Scorecard Requirement Mappings:** Maps to `VEC-P2-001` through `VEC-P2-010` (Analytics) and `VEC-P5-001` through `VEC-P5-008` (Multi-exchange).

---

### Phase 7 — Controlled Cutover & PHP Business-Logic Retirement
- **Objective:** Transfer authoritative trading and financial write ownership to Go under database-enforced fencing; retire duplicate PHP business logic.
- **1. Existing Components to Reuse:** Laravel routing, auth middleware, Vue 3 frontend UI, Docker Compose deployment framework.
- **2. PHP Components to Migrate / Retire:** Decommission and retire duplicate PHP business logic:
  - `app/Trading/Application/TradingPipelineEventService.php`
  - `app/PaperTrading/Application/PositionLifecycleService.php`
  - `app/PaperTrading/Application/RiskEngine.php`
  - `app/Strategy/Research/` (all 13 PHP strategy implementations)
  - `app/Exchanges/Binance/BinanceCandleIngestion.php`
- **3. Go Packages to Implement:** Unified Go Daemon (`cmd/vector-core`) orchestrating Ingestion, Strategies, Risk, and PAPER Lifecycle as a lean, single-process, multi-goroutine binary.
- **4. Interfaces & Data Contracts:** Laravel acts as a reverse proxy/client to the Go daemon via lightweight local Unix Domain Socket or HTTP/REST on localhost.
- **5. Database & Event Ownership:** Go becomes the **sole authoritative writer** to trading and financial tables; database-enforced fencing token prevents any PHP writes. Laravel PostgreSQL user permissions restricted to `SELECT` on financial ledgers.
- **6. Dependency Ordering:** Requires successful completion and 14-day shadow validation of Phases 1 through 6.
- **7. Exact Acceptance Criteria:** Go daemon running continuously for 14 days with zero crashes, zero data-loss events, and zero ledger discrepancies; end-to-end candle-to-ledger latency PROPOSED P95 < 30 ms; duplicate computational implementations fully eliminated.
- **8. Test Requirements:** Chaos testing (PostgreSQL restart, Redis crash, network drop); continuous integrity reconciler verifying ledger identities.
- **9. Performance & Resource Budgets:** Total VPS footprint: Go Daemon < 256 MiB, PHP-FPM < 128 MiB (lean API mode), CPU load decreased significantly.
- **10. Shadow-Mode & Rollback Strategy:** Account-by-account transactional handover under monotonic fencing tokens; rollback script re-enables PHP writer with explicit audit log entry if an anomaly occurs. Never rollback by restoring a stale database backup.
- **11. Safety Constraints:** Permanent read-only database locks for Laravel on `paper_account_ledgers`; zero real trading access.
- **12. Local Commit Boundaries:** Step-by-step deprecation commits with explicit test verification at each boundary.
- **13. Scorecard Requirement Mappings:** Transitions all remaining Phase 1–5 requirements to `GO_AUTH` (Go Authoritative).

---

### Phase 8 — Future External Execution Boundary (Documentation Only)
- **Objective:** Formally specify the future out-of-process execution gateway protocol, security boundaries, and authorization controls without implementing live trading.
- **1. Existing Components to Reuse:** `docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md`.
- **2. PHP Components to Migrate:** None (Specification only).
- **3. Go Packages to Implement:** None (Strictly documentation).
- **4. Interfaces & Data Contracts:** Formal specification of out-of-process execution gateway protocol, mutual TLS, HMAC authentication, intent verification, order reconciliation.
- **5. Database & Event Ownership:** Zero runtime implementation or database modifications.
- **6. Dependency Ordering:** Follows Phase 7; strictly gated behind explicit owner authorization.
- **7. Exact Acceptance Criteria:** Complete, peer-reviewed architectural specification defining responsibilities, cryptographic nonces, execution kill-switches, and zero-trust boundaries; explicit notice that live execution is not implemented in VECTOR.
- **8. Test Requirements:** Document review, schema validation for example JSON message payloads.
- **9. Performance & Resource Budgets:** Zero runtime resource impact.
- **10. Shadow-Mode & Rollback Strategy:** N/A (Documentation only).
- **11. Safety Constraints:** Real money trading strictly prohibited; no exchange API trading keys; no automated order placement endpoints.
- **12. Local Commit Boundaries:** Single documentation commit.
- **13. Scorecard Requirement Mappings:** Maps to `VEC-P6-001` through `VEC-P6-005`.

---

## 6. Preserved Risk, Financial, and Safety Invariants

The platform maintains 13 PRIMARY strategy families across Bybit and Binance, totaling **26 independent virtual PAPER accounts**:

### 6.1 Preserved Risk Controls
- **Entry-Block Threshold:** Strictly preserved at **5% drawdown** from high-water mark.
- **System Halt Threshold:** Strictly preserved at **10% drawdown** from high-water mark.
- **Forbidden Modifiers:** No arbitrary new thresholds (e.g. 15% circuit breaker) are permitted. Existing risk parameters in `config/paper.php` remain authoritative.
- **Exposure Limits:** Maximum simultaneous open positions, leverage limits, and instrument concentration limits remain enforced.
- **Dual Kill-Switches:** Both the Capability Flag and the Emergency Kill-Switch must evaluate to true for any strategy or paper action.

### 6.2 Preserved Financial Identities
At every millisecond and for every account:
$$\text{Equity} = \text{Balance} + \text{Unrealized PnL}$$
$$\text{Balance}_{t} = \text{Balance}_{0} + \sum \text{Realized PnL} - \sum \text{Commissions} \pm \sum \text{Funding Settlements}$$
- No historical financial record may ever be rewritten, mutated, or deleted.
- Fills at candle close must model certified taker commissions and realistic slippage.
- All 26 accounts maintain isolated positions, executions, balances, and drawdowns without cross-contamination.

---

## 7. Database-Enforced Financial Fencing & Single-Writer Protocol

To guarantee that PHP and Go can **never simultaneously write** to authoritative financial records, a database-level fencing protocol is established:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        POSTGRESQL 17 FENCING                           │
│                                                                        │
│  TABLE: paper_accounts                                                 │
│  ┌────────────┬──────────────────────┬──────────────┬───────────────┐  │
│  │ account_id │ authoritative_writer │ fence_epoch  │ updated_at    │  │
│  ├────────────┼──────────────────────┼──────────────┼───────────────┤  │
│  │ 1..13 (BY) │ 'PHP_LEGACY'         │ 1000         │ 2026-10-28    │  │
│  │ 14..26(BN) │ 'PHP_LEGACY'         │ 1000         │ 2026-10-28    │  │
│  └────────────┴──────────────────────┴──────────────┴───────────────┘  │
│                                                                        │
│  TRIGGER: enforce_account_writer_fencing()                             │
│  - Rejects any UPDATE/INSERT if writer does not match                  │
│  - Rejects any transaction if fence_epoch < current fence_epoch        │
│  - Fails closed immediately on conflict                                │
└────────────────────────────────────────────────────────────────────────┘
```

### Handover Mechanics (Phase 7):
1. **Account Freeze:** Fencing token increments; account transitions to `HANDOVER_PENDING`. Open orders serviced, new entries blocked.
2. **Reconciliation Audit:** Balance, positions, and unrealized PnL verified against double-entry formula.
3. **Writer Transition:** Single transaction updates `authoritative_writer = 'GO_CORE'`, increments `fence_epoch`.
4. **Go Verification:** Go daemon claims account, verifies balance continuity, resumes order servicing.
5. **Rollback Safety:** If an anomaly is detected, operator command re-increments epoch and returns authority to `PHP_LEGACY`. History is never restored from a stale database snapshot; delta adjustment transactions preserve audit trail integrity.

---

## 8. Single Strategy Calculation Source & Parity Validation

Before Go becomes authoritative for strategy execution, every family must achieve **bit-exact parity** against versioned PHP golden fixtures:

### Parity Protocol
1. **Fixture Extraction:** 10,000 closed candles extracted for each of the 13 families across BTCUSDT and ETHUSDT (1h, 30m, 15m, 5m).
2. **PHP Baseline Execution:** Run PHP strategy scanner, recording complete decision vector:
   - `decision`: BUY, SELL, or SKIP
   - `reason_code`: Exact string identifier of rule triggered
   - `price_plan`: Entry zone, ATR stop-loss, TP1, TP2
   - `indicator_snapshot`: EMA, RSI, ATR, Bollinger values
3. **Go Differential Runner:** Execute Go strategy on identical candle stream.
4. **Comparison Gate:**
   - Decisions must match 100.000%.
   - Reason codes must match 100.000%.
   - Price levels must match within $1 \times 10^{-8}$ decimal precision.
5. **Resolution:** Any divergence must be documented, root-caused, and resolved before execution authority transitions.

---

## 9. Scorecard V3 Transition & Go Migration Registry

To maintain audit integrity, the frozen baseline is strictly protected:

1. **Frozen Baseline Preserved:** `VECTOR-SCOPE-20261027-v1` remains immutable in `docs/progress/baselines/VECTOR-SCOPE-20261027-v1.json`.
2. **Proposed Baseline V3:** Proposed file `docs/progress/baselines/VECTOR-SCOPE-20261028-v3-proposal.json` maps the 97 requirements to the 9-phase sequence.
3. **Go Migration Registry (`docs/progress/VECTOR_GO_MIGRATION_REGISTRY_V3.md`):** Tracks the operational migration of each component independently across four states:
   - `PHP_AUTH`: PHP is the authoritative implementation.
   - `GO_SHADOW`: Go implementation exists and runs in shadow verification mode.
   - `GO_AUTH`: Go is the sole authoritative writer/calculator.
   - `PHP_RETIRED`: Duplicate PHP implementation cleanly decommissioned.
4. **Independent Metrics:**
   - **Functional Implementation %:** What the platform can do (features, tests).
   - **Verification %:** What has been proven by executed evidence.
   - **Product Acceptance %:** Operator-verified production readiness.
   - **Go Migration %:** Fraction of computational/trading workload running authoritatively in Go.

---

## 10. Component Ownership & Migration Summary Matrix

| Subsystem | Specific Component | Phase 0–4 State | Phase 5–6 State | Target Phase 7 State | Authoritative Writer |
|:---|:---|:---:|:---:|:---:|:---:|
| **Market Data** | Public Candle REST / WS | PHP | Go (Authoritative) | Go | **Go Engine** |
| **Market Data** | Candle Ring Buffer | PHP / Redis | Go In-Memory | Go | **Go Engine** |
| **Market Data** | Funding Rates & Open Interest | PHP | Go | Go | **Go Engine** |
| **Indicators** | Technical Indicators (EMA, RSI...) | PHP & Go | Go Library | Go Library | Pure Library |
| **Strategy** | 13 PRIMARY Strategy Families | PHP (5 in Go) | Go (Shadow Parity) | Go (Authoritative) | **Go Engine** |
| **Strategy** | Real-Time Signal Generation | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Research** | Backtesting Engine | Go | Go | Go | **Go Worker** |
| **Research** | Walk-Forward & Monte Carlo | Go | Go | Go | **Go Worker** |
| **Research** | Candidate Search & Evolution | PHP | Go | Go | **Go Worker** |
| **Paper Trading**| Risk Engine & Limits | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Position Lifecycle (Orders/Fills) | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Double-Entry Financial Ledger | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Funding & Fee Settlement | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Adapters** | Bybit / Binance Public Adapters | PHP | Go | Go | **Go Engine** |
| **Analytics** | Statistics, Drawdowns, Sharpe | PHP | Go Engine | Go Engine | **Go Engine** |
| **Control Plane**| Candidate Lab Approval Gates | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Operator Auth & Roles | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| REST API Endpoints | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Report & CSV Read Delivery | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **UI** | Vue 3 / Quasar Dashboard | Frontend SPA | Frontend SPA | Frontend SPA | Read-Side UI |
