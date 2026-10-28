# VECTOR Go-First Architecture Roadmap V3.3: Research Data Import & Dataset Manager

**Document Version:** V3.3 (Authoritative Engineering Specification)
**Status:** Canonical Approved Technical Roadmap & Architecture Plan
**Governing Standards:** [`AGENTS.md`](file:///home/thunder/work/traderbybit/AGENTS.md) and [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md)
**Source Baseline SHA:** `7113f2257d00f73ee01dd392c0d8b4e72782b6b6` (Roadmap V3.2 Baseline)
**Historical Scope Baseline:** `VECTOR-SCOPE-20261027-v1` (Frozen, 97 Requirements, 229 Obligations)
**Target Proposal Baseline:** `VECTOR-SCOPE-20261028-v3.3`
**Execution Mode:** STRICTLY VIRTUAL PAPER TRADING ONLY. Zero real exchange trading; zero private trade credentials; zero order placement endpoints.

---

## Executive Summary & Owner Directives

The owner and architecture board have codified the primary architectural trajectory for the VECTOR research and virtual paper-trading platform:

> **"Golang will become the primary language for VECTOR's core computational, quantitative research, strategy evaluation, market data, risk management, PAPER trading, and financial business logic.**
> **Laravel will remain a thin control plane responsible for API endpoints, authentication, task orchestration, configuration management, and read-side delivery of statistics.**
> **Vue 3 + Quasar remains the frontend dashboard and observability layer.**
> **We optimize VECTOR for measurable efficiency, maintainability, reliability, security, and functional progress — NOT for code deletion, language migration percentages, or rewriting the same project repeatedly.**
> **VECTOR must evolve from a fixed collection of 13 trading strategies on two exchanges into an extensible, configuration-driven quantitative research platform.**
> **To enable rapid, low-cost extension without requiring custom programming or an embedded LLM, VECTOR will support declarative exchange onboarding via validated CSV templates and declarative strategy authoring via validated rule configurations and UI builders.**
> **When an exchange API or strategy logic exceeds declarative capabilities, the platform cleanly falls back to native Go SDK implementations.**
> **To accelerate quantitative research without waiting months for exchange observation accumulation, VECTOR will support secure offline historical dataset ingestion (CSV, JSON, Parquet, ZIP) alongside continuously collected exchange market data.**
> **A researcher must be able to select: STRATEGY + EXCHANGE + DATASET + INSTRUMENT + TIME WINDOW, and obtain reproducible, properly qualified research results.**
> **Imported historical data must NEVER silently modify live market history, exchange observations, or authoritative PAPER accounting.**
> **Offline historical research on an exchange must NOT require an active Exchange API connection or paper trading accounts.**
> **Historical OHLCV alone does not prove execution quality or trading costs; unknown costs must be explicitly disclosed, and no simulated or imported data may replace prospective forward-trading evidence in promotion decisions.**
> **VECTOR does NOT require an LLM, AI agent, or external token consumption for trading, evaluation, or normal operations."**

### Core Engineering Principles
1. **Declarative First, SDK Fallback:** Common REST/WebSocket cryptocurrency perpetual exchanges and indicator-based strategies should be onboardable via declarative schemas without generating code. Unusually complex or non-standard protocols fall back to native versioned Go SDK implementations.
2. **Fundamental Data Isolation:** Imported historical datasets, continuous exchange observations, external research evidence, synthetic fixtures, backtests, out-of-sample evaluations, shadow runs, and prospective PAPER observations maintain absolute provenance and storage separation. Imported data cannot retroactively become genuine live observations or forward-paper evidence.
3. **Exchange Identity Without Exchange API Connection:** Offline research source identity (e.g. OKX historical research) can be registered, explored, and backtested without requiring live exchange API connectivity, credentials, or paper account creation.
4. **Financial Accuracy & Cost Modeling:** Historical OHLCV alone does not establish actual trading costs or execution quality. Where cost data is absent, fees or funding are never fabricated; simulation assumptions are explicitly tagged and segregated from verified PAPER facts.
5. **Zero Mandatory LLM / No AI Trade Decisions:** The platform operates with 100% determinism. No LLM credentials, embedded agents, prompt injections, or external token costs exist in the trading, market data, evaluation, or accounting execution paths. External AI tools (e.g. Codex) may be used offline by developers to generate code or configurations, but the runtime remains strictly deterministic.
6. **Dual Certification — Correctness vs Profitability:** Technical correctness (flawless execution, zero lookahead, deterministic calculations, accounting reconciliation) is strictly separated from economic profitability (net R-multiples, slippage drag, fee decay, drawdown, and statistical stability). An operationally sound strategy may be unprofitable; a backtest-profitable strategy may be statistically fragile. Neither condition is ever obscured.
7. **Single Authoritative Writer:** To guarantee absolute financial integrity across all independent PAPER accounts, at any given millisecond exactly **ONE** engine is the authoritative writer for any given financial or ledger entity. Dual-writing is strictly prohibited. Fencing tokens and monotonic epochs enforce this invariant in PostgreSQL.
8. **Efficiency Over Migration for Its Own Sake:** Workload migration must be justified by empirical performance gains (throughput, latency, memory bounds, mathematical determinism). Arbitrary code deletion goals (e.g. "remove 40% of PHP code") are permanently revoked.
9. **Strict Safety Invariants:** Virtual PAPER mode only; zero real exchange orders; zero trade credentials; preserved 5% entry-block and 10% halt risk thresholds; preserved independent account isolation; zero historical financial record mutation; ECONOM resources strictly untouched.

---

## Roadmap V3.3 Change Log & Superseded Requirements

### What Changed in Roadmap V3.3 (from V3.2)
1. **Research Data Import & Dataset Manager (Section 9):**
   - Added a dedicated architectural specification for offline historical data ingestion, dataset versioning, and provenance tracking.
   - Formalized three distinct data categories: **(A) Exchange-Collected Market Data**, **(B) Imported Historical Market Data**, and **(C) Imported Research Evidence**.
   - Established the **Dataset Manifest Canonical Schema** (`dataset_id`, `dataset_version`, `source_exchange_id`, `canonical_content_checksum`, `provenance_status`, etc.) guaranteeing cryptographic immutability and provenance.
   - Defined the **Historical OHLCV CSV Format** with clear distinction from exchange configuration and strategy rule CSV formats.
2. **Data Quality & Validation Engine (Phase 2 & Section 9.5):**
   - Specified a deterministic Go validation pipeline enforcing UTC alignment, decimal precision, OHLC relationship constraints ($low \le open, close \le high$, $volume \ge 0$), gap detection, and conflict quarantine.
   - Established fail-closed policies: `REJECT`, `QUARANTINE`, `WARN`, and `ACCEPT_WITH_LIMITATIONS`. Fabricating candles to fill gaps is strictly prohibited.
3. **Secure File Processing & Streaming Architecture (Section 9.6):**
   - Specified strict security boundaries for untrusted file uploads: 500 MB upload limit, 2 GB archive ceiling, streaming parsing with bounded memory (RSS < 256 MiB), ZIP bomb prevention (10:1 ratio limit), path traversal protection, and safe temporary directory handling. Zero exchange trading credentials.
4. **Exchange Identity Without Exchange API Connection (Section 9.7):**
   - Decoupled offline research source identities (e.g. OKX historical data) from live exchange API connectivity, WebSocket listeners, and PAPER account provisioning.
5. **Historical Research Integration & Leakage Protection (Phase 4 & Section 9.8):**
   - Implemented the research selection tuple: `STRATEGY + EXCHANGE + DATASET + INSTRUMENT + TIME WINDOW`.
   - Enforced chronological evaluation, point-in-time feature availability, indicator warm-up handling, and strict chronological isolation between training and out-of-sample periods.
6. **Live vs Imported Data Comparison & Dedicated UI (Section 9.10 & 9.11):**
   - Designed comparative analytical views combining datasets without compromising source isolation.
   - Added UI specifications for `Research -> Data Manager`, `Research -> New Backtest`, and `Research -> Dataset Comparison`.
7. **Migration Registry Updates:**
   - Added work packages `MIG-24` through `MIG-29` to `docs/progress/VECTOR_GO_MIGRATION_REGISTRY_V3.md`.
   - Defined 25 comprehensive, measurable acceptance criteria for dataset ingestion and management.

### What Changed in Roadmap V3.2 (from V3.1)
1. **Declarative Exchange Onboarding (Phase 6 & Section 7):**
   - Added a work package for the Declarative Exchange Builder / CSV Configuration Import.
   - Defined the canonical Exchange Configuration Schema and SSRF prevention boundaries.
   - Defined the 10-state Exchange Lifecycle and fail-closed error handling (`REQUIRES_CUSTOM_ADAPTER`).
2. **Declarative Strategy Onboarding (Phase 3 & Section 6):**
   - Introduced three strategy creation modes: Mode A (Native Go), Mode B (Declarative Config), Mode C (Visual Builder UI).
   - Defined canonical Declarative Strategy Schema, rule grammar, and fallback (`REQUIRES_CUSTOM_STRATEGY`).
3. **No Mandatory LLM Architectural Invariant:**
   - Explicitly codified that VECTOR core does not require OpenAI/Claude API keys, local LLMs, or AI agent decision-making.

### What Changed in Roadmap V3.1 (from V3.0)
1. **Dynamic Extensibility Model:** Replaced hardcoded assumptions of "13 strategies and 2 exchanges" with versioned Strategy SDK and Exchange SDK architectures ($N$ strategies $\times$ $M$ exchanges = $N \times M$ accounts).
2. **PROFIT-1 Research & Quantitative Intelligence:** Added independent slippage attribution, risk-adjusted performance (ESS, uncertainty bounds), cost structure decomposition, portfolio concentration analysis, and quarantined counterfactual rejected-signal replay.
3. **Autonomous Research Loop:** Formalized a 16-step autonomous experimentation loop with human owner approval for PRIMARY trading changes.
4. **Phase 0 Status Realignment:** Synchronized Phase 0 with commit `8e04eba` (42 local V1 PASS, 9 browser V3 PASS, 45 production V2 pending operator execution).

### Obsolete Requirements Explicitly Superseded
- **Superseded:** Waiting months for exchange observation accumulation before conducting research. **Authoritative Rule:** Secure offline historical dataset ingestion allows immediate backtesting and walk-forward research while live ingestion accumulates prospective data.
- **Superseded:** Merging or backfilling imported data into live production market tables. **Authoritative Rule:** Imported datasets and live market streams are strictly isolated in separate storage partitions.
- **Superseded:** Mandatory live exchange API connection to perform historical research on that exchange. **Authoritative Rule:** Offline research source identity supports full backtesting without active API adapters or accounts.
- **Superseded:** Treating missing trading costs as zero in historical backtests. **Authoritative Rule:** Unknown costs must be explicitly disclosed; assumed costs must be segregated from observed facts.
- **Superseded:** The obsolete 8 PRIMARY + 5 RESEARCH_CANDIDATE split. **Authoritative Rule:** All 13 strategy families are PRIMARY production strategies on Bybit and Binance.
- **Superseded:** Premature phase transition while Phase 0 is incomplete. **Authoritative Rule:** Phase 0 remains strictly BLOCKED until operator production checklist evidence is recorded; Phase 1 remains NOT STARTED.

---

## 1. Repository Inspection & Workload Classification

An exhaustive inspection of `/home/thunder/work/traderbybit` classifies every major system workload into its current implementation state:

| Workload Area | Current Implementation & Location | Operational Status | Migration Strategy | Target Phase |
|:---|:---|:---|:---|:---:|
| **Deterministic Backtesting** | Go: `research-go/internal/backtest/` | **Active in Go** via leased `research-worker`. High throughput. | **REUSE & EXTEND** (Support all 13 families + declarative strategies + imported datasets). | Phase 4 |
| **Walk-Forward Optimization (WFO)** | Go: `research-go/internal/walkforward/` | **Active in Go** via `WFOEvaluator`. Causal splits intact. | **REUSE** (Tested, causal splitting intact). | Phase 4 |
| **Monte Carlo Simulation** | Go: `research-go/internal/simulation/` | **Active in Go** (10k+ path block bootstrap). Zero allocations. | **REUSE** (High throughput, zero allocations). | Phase 4 |
| **Leased Worker Orchestration** | Go: `research-go/internal/jobrunner/` | **Active in Go** (`FOR UPDATE SKIP LOCKED`). Heartbeats active. | **REUSE** (Durable lease heartbeats). | Phase 4 |
| **Pattern & Breakout Mining** | Go: `research-go/internal/patterns/` | **Implemented in Go**, standalone capability. | **REUSE & INTEGRATE**. | Phase 4 |
| **Decision Replay & Attribution** | Go: `research-go/internal/replay/` | **Implemented in Go**, counterfactual replay runner. | **REUSE & EXTEND**. | Phase 4 |
| **Historical Dataset Ingestion & Parsing**| Planned: Go `internal/dataset/importer` | **New in V3.3**. High-throughput streaming CSV/Parquet importer. | **IMPLEMENT** in Go Core / Research. | Phase 2 |
| **Dataset Storage & Manifests** | Planned: Go `internal/dataset/storage` | **New in V3.3**. Immutability checksums, metadata, PostgreSQL. | **IMPLEMENT** in Go Core / Postgres. | Phase 2 |
| **Data Quality & Validation Engine** | Planned: Go `internal/dataset/validation` | **New in V3.3**. Deterministic validation, gaps, anomalies. | **IMPLEMENT** in Go Core. | Phase 2 |
| **Research Dataset Loader & Backtest** | Planned: Go `internal/research/loader` | **New in V3.3**. Chronological loading, warm-up, leakage checks. | **IMPLEMENT** in Go Research. | Phase 4 |
| **Dataset Manager UI & Reporting** | Planned: Vue 3 / Quasar + Laravel API | **New in V3.3**. Upload UI, quality reports, manifest browser. | **IMPLEMENT** in Laravel / Vue. | Phase 2 |
| **Cross-Dataset Research Comparison** | Planned: Go `internal/research/comparison` | **New in V3.3**. Analytical views without data contamination. | **IMPLEMENT** in Go Research. | Phase 4/6 |
| **Candidate Strategies (5 Families)**| Go: `research-go/internal/candidates/` | **Implemented in Go**, candidate contract tests passing. | **STANDARDIZE** into Go Strategy SDK. | Phase 3 |
| **PRIMARY Strategies (13 Families)**| PHP: `app/Strategy/Research/` | **Implemented in PHP**. Scoped by exchange in Backtest/PAPER. | **MIGRATE & CONSOLIDATE** into Go Strategy SDK. | Phase 3 |
| **Declarative Strategy Engine** | Planned: Go `pkg/strategy/declarative` | **New in V3.2**. Evaluates JSON/CSV rule definitions. | **IMPLEMENT** in Go Strategy SDK. | Phase 3 |
| **Technical Indicators** | PHP (`app/Indicators/`) & Go (`algorithms/`) | **Duplicated math across PHP and Go**. | **CONSOLIDATE** into shared Go package. | Phase 1 & 3 |
| **Candle Ingestion & WS Streaming** | PHP: `BinanceCandleIngestion`, Artisan WS | **Implemented in PHP**. Bottleneck (P95 50.49ms on 500 rows). | **MIGRATE** to Go Market Data Engine. | Phase 2 |
| **Generic Config-Driven Adapter** | Planned: Go `pkg/exchange/generic` | **New in V3.2**. Parses CSV exchange templates. | **IMPLEMENT** in Go Multi-Exchange SDK. | Phase 6 |
| **Portfolio Risk Engine** | PHP: `app/PaperTrading/Application/RiskEngine.php` | **Implemented in PHP**. Polling queue execution. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Position Lifecycle State Machine**| PHP: `PositionLifecycleService.php` | **Implemented in PHP**. Sequential account loops. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Double-Entry Financial Accounting**| PHP: `LedgerRecord.php`, `DailyPerformanceService.php` | **Implemented in PHP**. System of record in PostgreSQL. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Multi-Exchange Adapters** | PHP: `app/Exchanges/` | **Implemented in PHP** (Bybit & Binance). | **CONSOLIDATE** into unified Go Exchange SDK. | Phase 6 |
| **REST API, Auth & Control Plane** | PHP: Laravel 11/13 + Sanctum | **Implemented in PHP**. Operating effectively as Web API. | **RETAIN IN PHP** (Thin Control Plane). | Control Plane |
| **Vue 3 Operational Dashboard** | TypeScript / Vue 3 / Quasar | **Browser SPA**. Clean observability, zero trading keys. | **RETAIN** (Decoupled Frontend). | Frontend |

---

## 2. Core Target Architecture & Extensibility Vision

### 2.1 Decoupled Architecture & Process Model

VECTOR enforces a clean separation of concerns between control plane orchestration and high-performance quantitative execution:

```
┌────────────────────────────────────────────────────────────────────────┐
│               LARAVEL THIN CONTROL PLANE & WEB UI                     │
│  - Authentication, Roles, Session Management (Sanctum)                │
│  - REST API Routing & Request Validation (`/api/v1/...`)               │
│  - Declarative Builders UI: Exchange CSV Upload & Visual Strategy UI  │
│  - Research Data Manager UI: Dataset Upload, Validation, Manifests     │
│  - Operator Governance: Promotion Approvals, Kill-Switch Controls      │
│  - Asynchronous Research Job Submission & Experiment Visualization     │
│  - Read-Side Delivery: Ledgers, Statistics, CSV & ZIP Audit Archives   │
│  - Quasar 2 / Vue 3 Operational Dashboards (Strict Observability)      │
└───────────────────────────────┬────────────────────────────────────────┘
                                │ Redis Streams (Async Events & Signals)
                                │ PostgreSQL 17 (Durable System of Record)
┌───────────────────────────────▼────────────────────────────────────────┐
│                        GOLANG VECTOR CORE                              │
│                                                                        │
│  ┌───────────────────────┐  ┌───────────────────────────────────────┐  │
│  │   Market Data Engine  │  │         Strategy Runtime SDK          │  │
│  │  - Native WS Adapters │  │  - Native Go Strategies (Mode A)      │  │
│  │  - Config-Driven (CSV)│  │  - Declarative Rules Engine (Mode B/C)│  │
│  │  - Normalized Candles │  │  - Extensible Strategy Registry       │  │
│  │  - Server-Time Sync   │  │  - Bit-Exact Parity Golden Fixtures   │  │
│  │  - Sub-15ms Ingestion │  │  - Concurrent Real-Time Evaluation    │  │
│  └───────────┬───────────┘  └───────────────────┬───────────────────┘  │
│              │                                  │                      │
│  ┌───────────▼──────────────────────────────────▼───────────────────┐  │
│  │               PAPER Trading & Risk Engine Core                   │  │
│  │  - Multi-Account Topology ($N$ Strategies $\times$ $M$ Exchanges) │  │
│  │  - Deterministic Position State Machine (Pending->Open->TP->Closed)│
│  │  - Certified Funding Rates (8h/4h) & Taker Slippage Settlement   │  │
│  │  - Double-Entry Financial Accounting (Exact 12-Decimal Math)     │  │
│  │  - Database-Enforced Single-Writer Fencing Protocol              │  │
│  │  - STRICTLY ISOLATED from Offline Imported Datasets              │  │
│  └───────────────────────────────────┬──────────────────────────────┘  │
│                                      │                                 │
│  ┌───────────────────────────────────▼──────────────────────────────┐  │
│  │              Research & Deterministic Evolution                  │  │
│  │  - Leased Compute Runner (`research-worker`, `FOR UPDATE SKIP`)  │  │
│  │  - Dataset Manager: Ingestion, Validation, Immutability Hashes   │  │
│  │  - Dual Data Sources: Live Stream AND Imported Offline Datasets  │  │
│  │  - Vectorized Backtesting, Walk-Forward, Monte Carlo Simulation  │  │
│  │  - Profitability Intelligence: Slippage, Costs, Concentration    │  │
│  │  - Multi-Dataset Cross-Comparison & Analytical Benchmarking      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Resource, Service, and Storage Topography
- **Zero Service Proliferation:** Go execution is organized into **two bounded processes**:
  1. `vector-core`: Single-process, multi-goroutine daemon handling Market Data Ingestion (native + generic config adapters), Strategy Evaluation (native + declarative engines), Risk Management, and PAPER Lifecycle.
  2. `research-worker`: Scalable batch compute worker executing heavy asynchronous research workloads (`BACKTEST`, `WFO`, `MONTE_CARLO`, `EVOLVE`, `MINE_PATTERNS`, `DATASET_IMPORT_VALIDATE`).
- **Persistence Roles:**
  - **PostgreSQL 17:** Single source of truth for accounts, positions, executions, double-entry journals, audit events, exchange configurations, declarative rule sets, dataset manifests, normalized historical dataset partitions, and research results.
  - **Redis 7.4:** In-memory tick buffers, fast snapshot cache, and ephemeral IPC streams. **Never** the authoritative store for balances.

### 2.3 Forward-Compatible Scalability Model ($N$ Strategies $\times$ $M$ Exchanges)
The platform scales dynamically through two parallel paths:
- **Declarative Path:** New standard perpetual exchanges and indicator-based strategies are onboarded via CSV/JSON configurations without writing or compiling code.
- **Native SDK Path:** Unusually complex exchanges or algorithmic strategies are implemented via Go SDK interfaces.
- **Offline Historical Exploration:** Datasets for any exchange (even without an active API adapter) can be ingested and researched immediately.
- **Account Isolation:** Each account is strictly partitioned by `(exchange_id, strategy_id)`. Cross-account margin sharing, cross-exchange position leakage, and shared balance pools are architecturally prevented.

---

## 3. Migration Value Gate (MVG) Specification

No component is migrated simply because it is written in PHP. Every proposed workload must satisfy the **Migration Value Gate (MVG)**:

### 3.1 Formal Migration Value Gate Decisions

| Component Area | Current Implementation | Empirical Baseline | Bottleneck / Rationale | Decision | Target Milestone |
|:---|:---|:---|:---|:---:|:---:|
| **Binance Candle Ingestion** | PHP: `BinanceCandleIngestion` | P95 = 50.49 ms on 500 rows | PHP PDO binding & serialization overhead. Go binary unnest eliminates lag. | **MIGRATE** | Phase 2 |
| **Bybit WebSocket Listener** | PHP: `market-ws` CLI | Single-threaded ReactPHP | Socket reconnect fragility, memory drift. Go goroutines offer robust lock-free channel fanout. | **MIGRATE** | Phase 2 |
| **Deterministic Backtesting** | Go: `research-go/internal/backtest/` | Millions of bars/sec in Go | High throughput, zero GC pauses. | **REUSE** | Phase 4 |
| **WFO & Monte Carlo** | Go: `research-go/internal/` | 10k paths in < 2s | Parallel matrix operations in Go standard library. | **REUSE** | Phase 4 |
| **Technical Indicators** | PHP `app/Indicators` & Go `algorithms` | Duplicated math | Rounding drift risk. Consolidate into single Go library. | **CONSOLIDATE** | Phase 1 & 3 |
| **13 PRIMARY Strategies** | PHP `app/Strategy/Research` | 13 PHP, 5 Go candidates | Dual maintenance. Strategies must evaluate identically in research and paper trading. | **CONSOLIDATE** | Phase 3 |
| **Strategy Extensibility SDK** | PHP `StrategyRegistry.php` | Hardcoded switch dispatches | Adding a strategy requires modifying core files. Go descriptor model enables 1-file additions. | **MIGRATE** | Phase 3 |
| **Declarative Strategy Engine**| New in V3.2 | N/A (New capability) | Allows non-programmers to define strategies via UI/config without compiling code. | **IMPLEMENT** | Phase 3 |
| **Historical Dataset Schema & Contracts**| New in V3.3 (`MIG-24`)| N/A (New capability) | Standardizes immutable dataset manifests, schema validation, and provenance. | **IMPLEMENT** | Phase 1/2 |
| **Secure Historical Import & Validation**| New in V3.3 (`MIG-25`)| N/A (New capability) | High-speed Go streaming parser for CSV/Parquet with gap & anomaly detection. | **IMPLEMENT** | Phase 2 |
| **Dataset Storage & Versioning**| New in V3.3 (`MIG-26`)| N/A (New capability) | Isolated PostgreSQL tables with immutable content hashing and zero live contamination. | **IMPLEMENT** | Phase 2 |
| **Research Dataset Selection & Loader** | New in V3.3 (`MIG-27`)| N/A (New capability) | Chronological data loading, indicator warm-up, and leakage protection for research. | **IMPLEMENT** | Phase 4 |
| **Dataset Manager UI & Reporting** | New in V3.3 (`MIG-28`)| N/A (New capability) | Quasar/Vue UI for dataset upload, field mapping, quality summary, and version browsing. | **IMPLEMENT** | Phase 2 |
| **Multi-Dataset Research Comparison** | New in V3.3 (`MIG-29`)| N/A (New capability) | Side-by-side analytical evaluation across datasets, exchanges, and timeframes. | **IMPLEMENT** | Phase 4/6 |
| **Profitability Research Engine**| PHP `ProfitabilityResearchApiTest` | Multi-second aggregations | Heavy SQL joins across 26 accounts. Go streaming attribution generates reports in sub-100ms. | **MIGRATE** | Phase 4 |
| **Candidate Search & Evolution**| PHP `StrategyEvolutionService` | High memory in PHP | Parameter mutation loops separated from Go backtest create IPC overhead. | **MIGRATE** | Phase 4 |
| **RiskEngine & Position Sizing** | PHP `RiskEngine.php` | Scheduled 15m polling | Polling delays fill acknowledgment. Go in-memory evaluation responds in milliseconds. | **MIGRATE** | Phase 5 |
| **Position Lifecycle & Fills** | PHP `PositionLifecycleService` | Sequential account loops | Sequential PHP transactions on 26 accounts cause queue latency spikes during volatile bars. | **MIGRATE** | Phase 5 |
| **Double-Entry Paper Ledger** | PHP `LedgerRecord.php` | Synchronous SQL | Must reside alongside Position Lifecycle to avoid distributed multi-phase commit overhead. | **MIGRATE** | Phase 5 |
| **Multi-Exchange Adapters** | PHP `app/Exchanges` | Disparate interfaces | Bybit and Binance use different contracts. Consolidate into standardized Go Exchange SDK. | **CONSOLIDATE** | Phase 6 |
| **Generic Config-Driven Adapter**| New in V3.2 | N/A (New capability) | Allows onboarding standard exchanges via validated CSV templates without code. | **IMPLEMENT** | Phase 6 |
| **Candidate Lab Governance** | PHP `PromotionPolicyService` | Low-frequency admin action | Approval requires human operator intervention. Laravel excels at authenticated admin workflows. | **RETAIN** | Control Plane |
| **REST API & Auth** | PHP Laravel 11/13 + Sanctum | P95 < 20 ms for API | Thin presentation layer, robust ecosystem for authentication, validation, and JSON schemas. | **RETAIN** | Control Plane |
| **Vue 3 Operational Dashboard**| TypeScript / Vue 3 / Quasar | Browser client | Clean, responsive UI with zero trading keys. Fully decoupled from backend language. | **RETAIN** | Frontend |

---

## 4. Objective Efficiency Metrics (Empirical Over Code Deletion)

All arbitrary code deletion goals are permanently struck. Technical efficiency is evaluated against empirical operational metrics:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   OBJECTIVE EFFICIENCY CRITERIA                        │
├─────────────────────────────────────┬──────────────────────────────────┤
│ Metric                              │ Measurement Target               │
├─────────────────────────────────────┼──────────────────────────────────┤
│ 1. Canonical Strategy Source        │ Exactly 1 Go implementation/rule │
│ 2. Binance 500-Candle Ingestion P95 │ PROPOSED < 20.0 ms (current 50ms)│
│ 3. Strategy Evaluation Latency (13) │ PROPOSED < 5.0 ms across all 13  │
│ 4. Paper Lifecycle Processing (26)  │ PROPOSED < 15.0 ms end-to-end    │
│ 5. Memory Footprint Stability       │ Bounded Go daemon RSS < 256 MiB  │
│ 6. Strategy Authoring Efficiency    │ 1 implementation OR 1 JSON config│
│ 7. Exchange Onboarding Efficiency   │ 1 validated CSV template for std │
│ 8. Historical Dataset Parsing Rate  │ PROPOSED > 100,000 candles/sec   │
│ 9. Financial Accounting Parity      │ 0.000000000000 discrepancy       │
│ 10. Deterministic Replay Parity     │ 100.000% bit-exact decision match│
│ 11. Profitability Report Generation │ PROPOSED < 100 ms for 90d window │
└─────────────────────────────────────┴──────────────────────────────────┘
```

---

## 5. The Nine-Phase Implementation Roadmap

Every phase follows a standardized 18-point execution contract.

---

### Phase 0 — Existing System Acceptance & Baseline Remediation

1. **Business Objective:** Complete production operational acceptance, diagnose production market data anomalies, and verify baseline integrity across all 26 PAPER accounts without altering frozen requirements.
2. **Technical Scope:** Execute production verification queries via operator checklist; diagnose Binance immutable candle conflicts; establish verified performance baselines; maintain Phase 0 gate integrity.
3. **Existing Components to Reuse:** Full test suite (922 tests, 8,202 assertions), Playwright browser suite (`scripts/verify-browser-phase0.py`), SQL diagnostics (`scripts/diagnostics/phase0_production_acceptance.sql`), operator checklist (`docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md`).
4. **Components to Migrate / Replace:** None. Phase 0 is strict stabilization and verification of the existing baseline.
5. **Go Packages / Services:** Performance benchmarking utilities (`research-bench`) to profile batch ingestion.
6. **Interfaces & Versioned Contracts:** Canonical envelope (`data`, `error`, `meta`), QIM health endpoints, dual kill-switch state machine.
7. **State and Event Ownership:** PostgreSQL 17 system of record; Laravel owns all authoritative writes.
8. **Dependencies:** Prerequisite for Phase 1.
9. **Implementation Work Packages:**
   - Package 0.1: Operator execution of production SQL diagnostic suite (`OPERATOR_CHECKLIST.md`).
   - Package 0.2: Structured log capture of Binance immutable candle conflicts to verify clock skew hypothesis.
   - Package 0.3: Scorecard freshness synchronization upon production evidence receipt.
10. **Exact Acceptance Criteria:**
    - All 45 Production V2 obligations verified via operator execution of `phase0_production_acceptance.sql`.
    - Zero cross-exchange position leaks across all 26 accounts.
    - Zero double-entry discrepancies (`abs(net_pnl - (gross_pnl - fees + funding)) <= 0.0001`).
    - Binance immutable observation conflict diagnosed with captured production telemetry.
    - Binance 500-candle ingestion evaluated honestly against frozen < 50.0 ms SLA.
11. **Test Requirements:** 922 backend PHP tests passing, 64 frontend tests passing, 9 browser tests passing, Pint compliant.
12. **Performance & Resource Budgets:** PHP-FPM <= 512 MiB, PostgreSQL <= 1 GiB RSS, Redis <= 256 MiB.
13. **Shadow Execution & Rollback:** Immediate rollback to commit `a4b82ea` if operational regressions occur.
14. **Security & Data Integrity:** Strictly virtual PAPER mode across 26 accounts; zero live orders; ECONOM resources untouched.
15. **Git Commit Boundaries:** Local evidence admission and scorecard refresh commits.
16. **Evidence & Scorecard Mappings:** `VEC-P0-001` through `VEC-P0-209` (45 requirements, 99 obligations).
17. **Explicit Out-of-Scope Items:** No live trading, no real order placement, no database schema breaking changes, no manual production deployments.
18. **Operator Approval Requirements:** Operator must execute checklist queries on the VPS and provide authentic execution logs.

---

### Phase 1 — Go Core Foundation, Dynamic Identity & Versioned Contracts

1. **Business Objective:** Build shared Go foundational packages, high-precision financial arithmetic, dynamic domain identity contracts supporting dynamic strategy/exchange topologies, and versioned dataset identity contracts.
2. **Technical Scope:** Universal domain primitives, 12-decimal-place scaled arithmetic, versioned Redis Stream envelopes, monotonic fencing token generation, dynamic identifier schemas, and dataset manifest types (`pkg/dataset`).
3. **Existing Components to Reuse:** `research-go/internal/decimal/`, `research-go/internal/contracts/job.go`, Redis 7.4.
4. **Components to Migrate / Replace:** Duplicate DTO definitions, status enum constants, serialization helpers.
5. **Go Packages / Services:**
   - `pkg/types`: Universal domain primitives (`ExchangeID`, `Symbol`, `Timeframe`, `Side`, `OrderState`, `AccountID`, `StrategyID`, `DatasetID`).
   - `pkg/decimal`: 12-decimal-place scaled fixed-point math (`ScaledDecimal`) guaranteeing bit-exact arithmetic with PostgreSQL `NUMERIC(24,12)`.
   - `pkg/protocol`: Versioned message envelopes (`v3.3.0`) with schema validation and cryptographic digests.
   - `pkg/fencing`: Monotonic epoch-based fencing token generator.
   - `pkg/dataset`: Canonical dataset manifest structures, source provider enums, and SHA-256 verification types (`MIG-24`).
6. **Interfaces & Versioned Contracts:** Versioned Redis Stream message envelope: `{"v": "3.3.0", "epoch": 1042, "type": "EVENT_NAME", "payload": {...}}`.
7. **State and Event Ownership:** Redis stream namespace reservation (`vector:stream:*`); zero PostgreSQL schema mutations.
8. **Dependencies:** Requires formal Phase 0 sign-off; prerequisite for all subsequent Go components.
9. **Implementation Work Packages:**
   - Package 1.1: Scaled fixed-point arithmetic package (`pkg/decimal`) with 12 decimal places.
   - Package 1.2: Domain identity types (`pkg/types`) decoupled from hardcoded strategy/exchange counts.
   - Package 1.3: Versioned protocol envelope parser and serializer (`pkg/protocol`).
   - Package 1.4: Monotonic epoch fencing generator (`pkg/fencing`).
   - Package 1.5: Historical dataset manifest schema and provenance types (`pkg/dataset`, `MIG-24`).
10. **Exact Acceptance Criteria:**
    - `ScaledDecimal` passes 1,000,000 randomized arithmetic operations against PostgreSQL `NUMERIC` without 1 ULP discrepancy.
    - IPC serialization benchmark PROPOSED < 5 microseconds per envelope.
    - Core domain types accept arbitrary valid strings for strategy, exchange, and dataset IDs without code changes.
    - Dataset manifest schema serializes and validates bit-exact JSON matching the canonical specification.
11. **Test Requirements:** Property-based testing (`testing/quick`), fuzzing of JSON/protocol decoders, Go race detector (`go test -race`).
12. **Performance & Resource Budgets:** Static binary size < 25 MB; zero heap allocations on hot-path decimal math; worker memory ceiling 64 MiB.
13. **Shadow Execution & Rollback:** Dual-parsing of contracts in PHP and Go unit tests; git revert rollback.
14. **Security & Data Integrity:** Strict compilation boundary; no reflection in hot paths; compile-time interface verification.
15. **Git Commit Boundaries:** Foundational packages and property test suite.
16. **Evidence & Scorecard Mappings:** Foundation for `VEC-P1-001` through `VEC-P1-008` and `MIG-24`.
17. **Explicit Out-of-Scope Items:** No database writes, no live networking, no strategy logic.
18. **Operator Approval Requirements:** Architecture review of binary protocol and dataset manifest schemas.

---

### Phase 2 — Go Market Data Engine, Ingestion & Dataset Manager

1. **Business Objective:** Eliminate PHP market data ingestion bottlenecks with a high-performance Go ingestion daemon utilizing exchange server-time finality, AND provide a secure offline Historical Dataset Manager for high-speed research data ingestion.
2. **Technical Scope:** Public REST/WebSocket streaming for Bybit and Binance; server-time synchronization to prevent clock-skew immutable observation conflicts; concurrent ring buffers; direct PostgreSQL bulk insertion; offline Historical Dataset Importer (`internal/dataset/importer`) supporting CSV/Parquet/ZIP; Data Quality & Validation Engine (`internal/dataset/validation`); Dataset Manager UI in Vue/Quasar.
3. **Existing Components to Reuse:** Bybit v5 and Binance USDT-M public REST/WS endpoints, rate-limit policies, file upload controllers in Laravel.
4. **Components to Migrate / Replace:** `BinanceCandleIngestion.php`, `RunMarketWebSocketCommand.php`, historical candle synchronizers.
5. **Go Packages / Services:**
   - `internal/marketdata/bybit`: Resilient Bybit v5 public WebSocket client with auto-reconnect and heartbeat.
   - `internal/marketdata/binance`: Binance public WebSocket client with sequence number tracking and server-time alignment.
   - `internal/marketdata/ringbuffer`: Lock-free circular candle buffer holding 500 closed bars per symbol/timeframe.
   - `internal/marketdata/writer`: Direct PostgreSQL bulk insertion using optimized binary `COPY` / unnest queries.
   - `internal/dataset/importer`: High-throughput streaming CSV, JSON, and Parquet parser (`MIG-25`).
   - `internal/dataset/validation`: Deterministic data quality engine: gap detection, OHLC integrity, conflict quarantine (`MIG-25`).
   - `internal/dataset/storage`: Isolated historical dataset persistence in PostgreSQL (`dataset_candles`, `dataset_manifests`) (`MIG-26`).
6. **Interfaces & Versioned Contracts:** `MarketDataStreamer` interface emitting immutable `CandleClosedEvent` structs; `DatasetImporter` interface producing validated `DatasetManifest` records.
7. **State and Event Ownership:** Go becomes authoritative writer for `market_candles`, `market_funding_rates`, `market_open_interests`, and `dataset_*` tables. Laravel cron ingestion retired.
8. **Dependencies:** Requires Phase 1; prerequisite for Phase 3 strategy runtime and Phase 4 research.
9. **Implementation Work Packages:**
   - Package 2.1: Multi-exchange WebSocket connection manager with exponential backoff and jitter.
   - Package 2.2: Server-time synchronization layer calculating clock skew $\Delta t = t_{\text{exchange}} - t_{\text{local}}$.
   - Package 2.3: Ring buffer memory structures for zero-allocation bar retrieval.
   - Package 2.4: High-throughput PostgreSQL binary writer with conflict quarantine.
   - Package 2.5: Secure Historical File Import & Validation Engine (`MIG-25`).
   - Package 2.6: Dataset Manager Storage & Versioning (`MIG-26`).
   - Package 2.7: Dataset Manager UI & Quality Reporting in Quasar/Vue (`MIG-28`).
10. **Exact Acceptance Criteria:**
    - 500-candle bulk ingestion PROPOSED P95 < 20.0 ms (resolving the failing 50.49 ms PHP baseline).
    - Zero dropped candles over 72 continuous hours.
    - Candle finality evaluated strictly against exchange server timestamp: $t_{\text{close}} \le t_{\text{server}}$.
    - Historical CSV parser processes > 100,000 candles/sec with memory RSS < 256 MiB.
    - Uploaded historical datasets are strictly quarantined from `market_candles` and `paper_*` tables.
    - Corrupted OHLC rows, timestamp gaps, and ZIP path traversals are rejected with explicit error diagnostics.
11. **Test Requirements:** Mock WebSocket servers emitting malformed payloads; clock skew injections; zip bomb and path traversal security test suite; corrupt OHLC boundary tests.
12. **Performance & Resource Budgets:** Peak RSS < 256 MiB; CPU load < 0.3 cores under simultaneous live ingestion and offline file import.
13. **Shadow Execution & Rollback:** Parallel ingestion to shadow database table; hash comparison against PHP; one-switch cutover via configuration.
14. **Security & Data Integrity:** Public endpoints only; zero trading credentials; untrusted file processing with strict 500 MB upload ceiling and ZIP bomb protection; SSRF protection on any remote fetches.
15. **Git Commit Boundaries:** Live market data engine packages, dataset ingestion packages, Dataset Manager UI, and test suites.
16. **Evidence & Scorecard Mappings:** Resolves `VEC-P0-009-V1` permanently; feeds live market stream to `VEC-P1-001` through `VEC-P1-008`; maps to `MIG-25`, `MIG-26`, `MIG-28`.
17. **Explicit Out-of-Scope Items:** No private exchange endpoints, no orderbook L2/L3 tick streaming, no real trading keys.
18. **Operator Approval Requirements:** Production configuration review before enabling Go ingestion daemon.

---

### Phase 3 — Extensible Go Strategy SDK & Declarative Strategy Builder

1. **Business Objective:** Implement all 13 PRIMARY strategy families in Go with bit-exact parity against PHP golden fixtures, while providing both an extensible Go SDK and a Declarative Strategy Builder (JSON/CSV configuration & UI) allowing new strategies to evaluate identically across live market streams and imported historical datasets.
2. **Technical Scope:** Strategy SDK interfaces (`StrategyDescriptor`, `StrategyInstance`, `StrategyRegistry`), native Go implementations of all 13 PRIMARY families, Declarative Rule Evaluation Engine (`pkg/strategy/declarative`), Visual Strategy Builder UI contracts, concurrent real-time evaluation engine, and differential testing harness.
3. **Existing Components to Reuse:** 5 Go candidate families in `research-go/internal/candidates/`, parameters in `config/strategy.php`.
4. **Components to Migrate / Replace:** Remaining 8 PHP strategy families (`BaselineStrategy`, `TrendPullbackStrategy`, `BollingerMeanReversionStrategy`, `DonchianBreakoutStrategy`, `RegimeSwitchingStrategy`, `MomentumStrategy`, `SupportResistanceBounceStrategy`, `SupportResistanceBreakoutStrategy`).
5. **Go Packages / Services:**
   - `pkg/strategy`: Universal Go Strategy SDK (`StrategyDescriptor`, `StrategyInstance`, `Evaluate(input)`).
   - `pkg/strategy/declarative`: Bounded declarative rule evaluation engine parsing JSON/CSV rule specifications.
   - `internal/strategy/primary`: All 13 PRIMARY strategy families implemented natively in Go.
   - `internal/strategy/runtime`: Concurrent strategy evaluation engine triggered by candle events or dataset iteration.
6. **Interfaces & Versioned Contracts:** `StrategyDecision` struct matching canonical JSON schema (BUY, SELL, SKIP with deterministic reason codes, price targets, ATR stop-loss).
7. **State and Event Ownership:** Go publishes `SignalGeneratedEvent` to Redis Stream; Laravel reads signals for UI/API display.
8. **Dependencies:** Requires Phase 2 candle stream / dataset loader; prerequisite for Phase 4 evolution and Phase 5 paper execution.
9. **Implementation Work Packages:**
   - Package 3.1: Strategy SDK interface specifications and registration engine.
   - Package 3.2: Implementation of the 8 remaining PRIMARY families in Go.
   - Package 3.3: Golden fixture test generator extracting 10,000 historical decisions from PHP.
   - Package 3.4: Declarative Strategy Rules Engine (`MIG-18`) and schema validator.
   - Package 3.5: Visual Strategy Builder UI contract and API endpoint in Laravel (`MIG-19`).
   - Package 3.6: Extensibility validation using a declarative test strategy (`declarative_rsi_macd_test`).
10. **Exact Acceptance Criteria:**
    - All 13 PRIMARY families implemented in Go; 100% bit-exact decision equivalence across 10,000 historical golden candle fixtures compared against PHP golden output.
    - Concurrent evaluation of all 13 families PROPOSED P95 < 5.0 ms per closed candle.
    - Declarative test strategy defined via JSON/CSV is evaluated identically by backtest and forward runtimes without generating custom Go code.
    - Unsupported strategy logic fails closed with `REQUIRES_CUSTOM_STRATEGY`.
11. **Test Requirements:** Differential testing comparing Go output against PHP output byte-for-byte; declarative rule mutation tests; boundary condition verification.
12. **Performance & Resource Budgets:** All 13 strategies evaluated concurrently in PROPOSED < 10 ms; memory allocation < 1 MiB per evaluation.
13. **Shadow Execution & Rollback:** Go runs in shadow mode emitting signals to shadow stream; diffs logged; Laravel forward scanner continues driving paper orders until Phase 5.
14. **Security & Data Integrity:** Pure function evaluation; zero I/O, network calls, or arbitrary script execution in strategy logic; deterministic RNG seeded by candle hash.
15. **Git Commit Boundaries:** Go strategy implementations committed family by family with corresponding golden fixtures.
16. **Evidence & Scorecard Mappings:** Maps to `VEC-P1-001` through `VEC-P1-008` and `MIG-18`, `MIG-19`.
17. **Explicit Out-of-Scope Items:** No automatic live account creation, no runtime shell or code execution.
18. **Operator Approval Requirements:** Sign-off on 100% parity verification results.

---

### Phase 4 — Autonomous Research, Profitability Intelligence & Evolution Engine

1. **Business Objective:** Build a high-throughput, autonomous quantitative research core that measures economic profitability honestly across both live accumulated data and imported historical datasets, explores parameter and pattern spaces, validates native and declarative strategies, and produces evidence-backed candidate proposals requiring human governance sign-off.
2. **Technical Scope:** Vectorized backtesting, chronological WFO, block bootstrap Monte Carlo, PROFIT-1 quantitative modules (slippage attribution, cost structures, concentration, counterfactual rejected signals), Pattern Discovery, multiplicity-adjusted LCB, and Research Dataset Selection (`STRATEGY + EXCHANGE + DATASET + INSTRUMENT + TIME WINDOW`).
3. **Existing Components to Reuse:** `research-go/internal/backtest/`, `research-go/internal/walkforward/`, `research-go/internal/simulation/`, `research-go/internal/patterns/`.
4. **Components to Migrate / Replace:** `ProfitabilityResearch.php`, `StrategyEvolutionService.php`, `PerformanceMetricsService.php`.
5. **Go Packages / Services:**
   - `internal/research/engine`: High-throughput backtest engine supporting both live and offline datasets.
   - `internal/research/loader`: Research Dataset Loader ensuring chronological loading, warm-up, and leakage checks (`MIG-27`).
   - `internal/research/comparison`: Cross-Dataset Research Comparison and offline exchange analytics (`MIG-29`).
   - `internal/research/profit`: PROFIT-1 quantitative intelligence modules.
   - `internal/research/evolution`: Parameter exploration, genetic mutation, and multiplicity-adjusted validation.
6. **Interfaces & Versioned Contracts:** `ResearchJobSpec` and `ResearchReport` structs carrying exact dataset version and source checksums.
7. **State and Event Ownership:** Go leases research jobs via `FOR UPDATE SKIP LOCKED`; writes immutable research reports to PostgreSQL; Laravel serves reports read-only.
8. **Dependencies:** Requires Phase 2 dataset ingestion and Phase 3 Strategy SDK.
9. **Implementation Work Packages:**
   - Package 4.1: Research Dataset Selection & Backtest Loader (`MIG-27`).
   - Package 4.2: PROFIT-1 Quantitative Intelligence suite.
   - Package 4.3: Autonomous Parameter Mutation and Evolution Engine.
   - Package 4.4: Cross-Dataset Research Comparison & Offline Exchange Analytics (`MIG-29`).
   - Package 4.5: Candidate Lab promotion gate and governance integration.
10. **Exact Acceptance Criteria:**
    - Backtest executes > 1,000,000 candles/sec in Go across imported historical datasets.
    - Research selection tuple `(strategy, exchange, dataset, instrument, window)` executes deterministically with zero lookahead.
    - Training, validation, and test periods maintain strict chronological isolation with required indicator warm-up.
    - Cost assumptions are explicitly tagged; missing costs never default to zero or fabricate facts.
    - Side-by-side comparison of imported vs live datasets surfaces gaps and distribution divergence without data contamination.
11. **Test Requirements:** Synthetic lookahead injection tests; causal walk-forward time verification; bootstrap Monte Carlo convergence checks.
12. **Performance & Resource Budgets:** Backtest memory RSS < 512 MiB; leased job execution completes in PROPOSED < 5.0 seconds for 100k candles.
13. **Shadow Execution & Rollback:** Jobs executed asynchronously; failed jobs do not affect paper trading; leases expire cleanly.
14. **Security & Data Integrity:** Strict governance boundary: autonomous engine cannot promote candidates to PRIMARY status; promotion requires human operator approval. Zero mandatory LLM credentials.
15. **Git Commit Boundaries:** Research packages, worker CLI, loader modules, and unit test suites.
16. **Evidence & Scorecard Mappings:** Maps to `VEC-P3-001` through `VEC-P3-011`, `VEC-P4-001` through `VEC-P4-010`, and `MIG-27`, `MIG-29`.
17. **Explicit Out-of-Scope Items:** No LLM inference in trade path, no unconstrained code generation, no automated production parameter mutation.
18. **Operator Approval Requirements:** Operator review of Candidate Lab promotion proposals.

---

### Phase 5 — Extensible Go RiskEngine, PAPER Engine & Financial Accounting (Shadow Validation)

1. **Business Objective:** Implement the complete portfolio risk engine, order lifecycle state machine, and double-entry accounting in Go; support dynamic multi-account topologies ($N$ Strategies $\times$ $M$ Exchanges); run in shadow mode while PHP remains authoritative; guarantee that imported historical datasets can NEVER write to paper trading accounts or ledgers.
2. **Technical Scope:** Dynamic account mapping, deterministic position state machine, 5%/10% drawdown enforcement, double-entry financial ledger, taker fee and funding settlement, and shadow reconciliation.
3. **Existing Components to Reuse:** PostgreSQL financial schemas (`paper_positions`, `paper_executions`, `paper_account_ledgers`), risk parameters in `config/paper.php`.
4. **Components to Migrate / Replace:** `PositionLifecycleService.php`, `RiskEngine.php`, `PaperProcessingService.php`, `DailyPerformanceService.php`.
5. **Go Packages / Services:**
   - `internal/paper/risk`: Portfolio risk evaluation, max drawdown enforcement, account isolation, leverage checks.
   - `internal/paper/lifecycle`: Order state machine (Pending -> Open -> TP1/TP2 -> Closed), trailing stops, timeout liquidation.
   - `internal/paper/accounting`: Double-entry transaction ledger engine ($\text{Equity} = \text{Balance} + \text{Unrealized PnL}$).
   - `internal/paper/settlement`: Certified 8h/4h funding rate fee calculations, taker commission deductions, slippage modeling.
6. **Interfaces & Versioned Contracts:** `RiskEvaluator`, `OrderExecutor`, `LedgerService`; transactional event signatures.
7. **State and Event Ownership:** **Strict Shadow Mode.** PHP remains the sole authoritative writer to `paper_accounts`, `paper_positions`, `paper_executions`, `paper_account_ledgers`. Go writes shadow records to isolated staging tables (`shadow_paper_*`) and emits reconciliation reports.
8. **Dependencies:** Requires Phase 3 strategy signals and Phase 2 real-time candle marks; prerequisite for Phase 7 cutover.
9. **Implementation Work Packages:**
   - Package 5.1: Scalable multi-account portfolio risk engine supporting dynamic account topologies.
   - Package 5.2: Order lifecycle state machine with partial closes and deterministic SL/TP priority.
   - Package 5.3: Bit-exact double-entry accounting engine in Go (`pkg/decimal`).
   - Package 5.4: Shadow execution harness and automated ledger diff reconciliation reporter.
   - Package 5.5: Blocked strategy diagnostic service recording structured blocker evidence.
10. **Exact Acceptance Criteria:**
    - Dynamic account scaling tested with up to 80 accounts with zero cross-contamination.
    - Zero ledger discrepancies across 100,000 synthetic lifecycle events.
    - Shadow execution runs for 14 continuous days without financial divergence from PHP live ledger.
    - Blocked strategy diagnostics record exact metric, threshold, and timestamp for any risk halt.
    - Absolute isolation: imported historical datasets are strictly blocked from writing to `paper_accounts` or triggering fills.
11. **Test Requirements:** PostgreSQL concurrency race tests (`FOR UPDATE` row locking), double-funding negative controls, partial exit precision tests.
12. **Performance & Resource Budgets:** Position processing latency PROPOSED P95 < 15 ms; memory footprint < 128 MiB; zero lock contention on concurrent accounts.
13. **Shadow Execution & Rollback:** Continuous comparison of Go shadow balance vs PHP live balance; discrepancy > 0.0001 USDT raises immediate alert. Rollback is trivial since PHP remains the live writer.
14. **Security & Data Integrity:** Zero real trading keys; accounts strictly isolated; preserved 5% entry-block and 10% halt risk thresholds.
15. **Git Commit Boundaries:** Risk engine, position lifecycle engine, accounting ledger engine, and migration scripts.
16. **Evidence & Scorecard Mappings:** Direct mapping to core Phase 0 financial requirements and Phase 4 operational controls.
17. **Explicit Out-of-Scope Items:** No direct writes to live production tables during Phase 5.
18. **Operator Approval Requirements:** Review of 14-day shadow reconciliation report.

---

### Phase 6 — Extensible Multi-Exchange SDK & Declarative Exchange Onboarding

1. **Business Objective:** Implement standardized multi-exchange adapter contracts in Go, provide a Declarative Exchange Builder (CSV import & UI workflow) allowing standard cryptocurrency perpetual exchanges to be onboarded without custom code, support offline exchange identities for research without API connections, and surface high-throughput comparative analytics via Laravel API.
2. **Technical Scope:** Universal Exchange SDK (`ExchangeDescriptor`, `ExchangeAdapter`, `ExchangeRegistry`, `ExchangeCapabilities`), Generic Config-Driven Exchange Adapter (`pkg/exchange/generic`), CSV Onboarding Parser with strict SSRF validation, offline research source registry, native Bybit/Binance adapters, third test-only exchange adapter verification, streaming financial analytics, and slippage decomposition.
3. **Existing Components to Reuse:** `MetricRegistry.php`, `ExchangeComparisonService.php`, frontend analytics views.
4. **Components to Migrate / Replace:** Performance statistics calculations, Sharpe/Sortino/Calmar math, correlation matrices, slippage attribution.
5. **Go Packages / Services:**
   - `pkg/exchange`: Unified exchange adapter interface (`Capabilities()`, `FetchKlines()`, `StreamTicks()`, `FundingSchedule()`).
   - `pkg/exchange/generic`: Generic configurable adapter executing declarative CSV/JSON exchange specifications.
   - `pkg/exchange/registry`: Unified registry distinguishing offline research sources from connected live adapters.
   - `internal/exchange/bybit`: Standardized Bybit linear perpetual adapter.
   - `internal/exchange/binance`: Standardized Binance USDT-M perpetual adapter.
   - `internal/exchange/stub`: Test-only third exchange adapter proving generic CSV extensibility.
   - `internal/analytics`: Streaming financial metrics calculation.
   - `internal/attribution`: Mark-to-market and slippage decomposition engine.
6. **Interfaces & Versioned Contracts:** Normalized multi-exchange DTOs; analytics query API contracts; Canonical Exchange Configuration Schema.
7. **State and Event Ownership:** Go maintains read-optimized analytical materialized caches in Redis; PostgreSQL remains system of record.
8. **Dependencies:** Requires Phase 5 financial accounting; prerequisite for Phase 7 cutover.
9. **Implementation Work Packages:**
   - Package 6.1: Unified Exchange SDK contracts and capability declaration system.
   - Package 6.2: Declarative Exchange Configuration Schema & CSV Parser (`MIG-20`).
   - Package 6.3: Generic Config-Driven Exchange Adapter Runtime (`MIG-21`).
   - Package 6.4: Exchange Onboarding UI, SSRF validator, and Approval Lifecycle in Laravel (`MIG-22`).
   - Package 6.5: Config-Driven Contract Conformance Test Harness (`MIG-23`).
   - Package 6.6: Standardization of Bybit and Binance adapters under the new SDK.
   - Package 6.7: Third test-only exchange onboarding via CSV template (`stub_exchange_csv`).
   - Package 6.8: Offline Exchange Research Identity registration (e.g. OKX historical research without live adapter).
10. **Exact Acceptance Criteria:**
    - Third test-only exchange onboarded via CSV template passes complete contract conformance suite without writing custom Go code.
    - Offline research exchange identity (OKX) supports full backtesting on imported data without active API adapter.
    - Unsupported APIs cleanly return `REQUIRES_CUSTOM_ADAPTER` without runtime failure.
    - Strict SSRF protection rejects internal, loopback, private, or metadata IPs in endpoint URLs.
    - Unified metric calculation for 26 accounts over 90 days generated in PROPOSED < 100 ms.
11. **Test Requirements:** Multi-exchange contract conformance test suite; CSV schema fuzzing; SSRF attack vector suite; extreme market data gap/delisting simulations; unsupported capability fail-closed tests.
12. **Performance & Resource Budgets:** Analytical query response PROPOSED P95 < 50 ms; Redis cache size < 64 MiB.
13. **Shadow Execution & Rollback:** Frontend toggles between PHP and Go analytics via feature flag; zero state mutations.
14. **Security & Data Integrity:** Public market data only; zero trading credentials; CSV upload cannot execute arbitrary code or bypass owner approval.
15. **Git Commit Boundaries:** Exchange adapter packages, analytics engine, and API integration.
16. **Evidence & Scorecard Mappings:** Maps to `VEC-P2-001` through `VEC-P2-010`, `VEC-P5-001` through `VEC-P5-008`, and `MIG-20`–`MIG-23`.
17. **Explicit Out-of-Scope Items:** No live real-money order routing to any exchange.
18. **Operator Approval Requirements:** Formal owner approval required to transition any exchange from `RESEARCH_READY` to `PAPER_ELIGIBLE`.

---

### Phase 7 — Controlled Cutover & PHP Business-Logic Retirement

1. **Business Objective:** Transfer authoritative trading and financial write ownership to Go under database-enforced fencing tokens; retire duplicate PHP computational logic while maintaining Laravel as a lean control plane.
2. **Technical Scope:** Monotonic fencing epoch handover, PostgreSQL trigger enforcement, Laravel financial write revocation, Go Core daemon supervisor integration, and duplicate PHP code decommissioning.
3. **Existing Components to Reuse:** Laravel routing, auth middleware, Vue 3 frontend UI, Docker Compose deployment framework.
4. **Components to Migrate / Retire:** Decommission duplicate PHP computational logic (`TradingPipelineEventService.php`, `PositionLifecycleService.php`, `RiskEngine.php`, `app/Strategy/Research/`, `BinanceCandleIngestion.php`).
5. **Go Packages / Services:** Unified Go Daemon (`cmd/vector-core`) orchestrating Ingestion, Strategies, Risk, and PAPER Lifecycle as a lean, single-process, multi-goroutine binary.
6. **Interfaces & Versioned Contracts:** Laravel acts as a client/reverse-proxy to the Go daemon via lightweight local Unix Domain Socket or HTTP on localhost.
7. **State and Event Ownership:** Go becomes the **sole authoritative writer** to trading and financial tables; database-enforced fencing token prevents any PHP writes. Laravel PostgreSQL user permissions restricted to `SELECT` on financial ledgers.
8. **Dependencies:** Requires successful completion and 14-day shadow validation of Phases 1 through 6.
9. **Implementation Work Packages:**
   - Package 7.1: PostgreSQL fencing trigger deployment (`enforce_account_writer_fencing()`).
   - Package 7.2: Account-by-account cutover orchestration CLI in Laravel.
   - Package 7.3: Activation of Go daemon as primary writer.
   - Package 7.4: Verification of 14-day zero-defect continuous operation.
   - Package 7.5: Decommissioning and removal of duplicate PHP computational classes.
10. **Exact Acceptance Criteria:**
    - Go daemon running continuously for 14 days with zero crashes, zero data-loss events, and zero ledger discrepancies.
    - End-to-end candle-to-ledger latency PROPOSED P95 < 20 ms across all accounts.
    - PostgreSQL trigger rejects any attempted write with stale epoch or unauthorized writer identity.
11. **Test Requirements:** Chaos testing (PostgreSQL restart, Redis crash, network drop); continuous integrity reconciler verifying ledger identities.
12. **Performance & Resource Budgets:** Total VPS footprint: Go Daemon < 256 MiB, PHP-FPM < 128 MiB (lean API mode), CPU load decreased significantly.
13. **Shadow Execution & Rollback:** Account-by-account transactional handover under monotonic fencing tokens; rollback script re-enables PHP writer with explicit audit log entry if an anomaly occurs. Never rollback by restoring a stale database backup.
14. **Security & Data Integrity:** Permanent read-only database permissions for Laravel on `paper_account_ledgers`; zero real trading access.
15. **Git Commit Boundaries:** Step-by-step deprecation commits with explicit test verification at each boundary.
16. **Evidence & Scorecard Mappings:** Transitions all remaining Phase 1–5 requirements to `GO_AUTH` (Go Authoritative).
17. **Explicit Out-of-Scope Items:** No removal of Laravel control plane, routing, auth, or frontend APIs.
18. **Operator Approval Requirements:** Formal operator sign-off required prior to executing the cutover CLI command on each account.

---

### Phase 8 — External Execution Boundary (Documentation Only)

1. **Business Objective:** Formally specify the architectural, security, and protocol boundaries for an out-of-process execution gateway, preserving VECTOR as a strictly paper-only research platform.
2. **Technical Scope:** Architectural documentation of mutual TLS, cryptographic HMAC signatures, intent nonces, order reconciliation, and emergency kill-switches.
3. **Existing Components to Reuse:** `docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md`.
4. **Components to Migrate / Replace:** None (Strictly documentation).
5. **Go Packages / Services:** None (Zero runtime implementation).
6. **Interfaces & Versioned Contracts:** Formal specification of execution gateway protocol messages.
7. **State and Event Ownership:** Zero database or runtime modifications.
8. **Dependencies:** Follows Phase 7; strictly gated behind explicit owner authorization.
9. **Implementation Work Packages:**
   - Package 8.1: Architecture specification of out-of-process execution boundary.
   - Package 8.2: Security protocol review (zero trade credentials in VECTOR core).
10. **Exact Acceptance Criteria:**
    - Complete, peer-reviewed architectural specification defining zero-trust boundaries, cryptographic message signing, and kill-switch precedence.
    - Explicit verification that VECTOR core contains zero order placement code and zero private exchange trading keys.
11. **Test Requirements:** Document review, schema validation for example JSON protocol envelopes.
12. **Performance & Resource Budgets:** Zero runtime resource impact.
13. **Shadow Execution & Rollback:** N/A (Documentation only).
14. **Security & Data Integrity:** Real money trading strictly prohibited; no exchange API trading keys; no automated order placement endpoints.
15. **Git Commit Boundaries:** Single documentation commit.
16. **Evidence & Scorecard Mappings:** Maps to `VEC-P6-001` through `VEC-P6-005`.
17. **Explicit Out-of-Scope Items:** Zero implementation of real trading, zero API keys.
18. **Operator Approval Requirements:** Architecture review by lead owner.

---

## 6. Strategy Extensibility Specification & Declarative Strategy Builder

### 6.1 The Three Creation Modes
1. **Mode A — Native Go Strategy (Maximum Performance & Custom Logic):** Written directly in Go by implementing `pkg/strategy` interfaces (`StrategyDescriptor`, `StrategyInstance`). Suitable for complex algorithms, regime switches, or custom mathematics.
2. **Mode B — Declarative Strategy Configuration (JSON / CSV Specification):** High-level declaration of indicators, condition groups, comparators, entry triggers, and position plans without writing code.
3. **Mode C — Visual Strategy Builder (Quasar UI & Operator Experience):** Visual rule composer in the Vue 3 dashboard allowing drag-and-drop conditions, threshold sliders, and visual backtesting.

### 6.2 Canonical Declarative Strategy Schema
```json
{
  "$schema": "https://vector.traderbybit.internal/schemas/v3.2/declarative-strategy.json",
  "strategy_id": "declarative_rsi_breakout_v1",
  "version": "1.0.0",
  "display_name": "Declarative RSI Breakout Strategy",
  "author": "quantitative-research-team",
  "market_type": "linear_perpetual",
  "supported_symbols": ["BTCUSDT", "ETHUSDT"],
  "timeframes": {
    "trend": "1h",
    "signal": "15m",
    "entry": "5m"
  },
  "indicators": [
    {"id": "ema_slow", "type": "EMA", "timeframe": "1h", "period": 200, "source": "close"},
    {"id": "ema_fast", "type": "EMA", "timeframe": "1h", "period": 50, "source": "close"},
    {"id": "rsi_signal", "type": "RSI", "timeframe": "15m", "period": 14, "source": "close"},
    {"id": "atr_volatility", "type": "ATR", "timeframe": "15m", "period": 14}
  ],
  "entry_rules": {
    "long": {
      "and": [
        {"left": "ema_fast", "op": "GREATER_THAN", "right": "ema_slow"},
        {"left": "close", "op": "GREATER_THAN", "right": "ema_fast"},
        {"left": "rsi_signal", "op": "CROSS_ABOVE", "right": 50.0}
      ]
    },
    "short": {
      "and": [
        {"left": "ema_fast", "op": "LESS_THAN", "right": "ema_slow"},
        {"left": "close", "op": "LESS_THAN", "right": "ema_fast"},
        {"left": "rsi_signal", "op": "CROSS_BELOW", "right": 50.0}
      ]
    }
  },
  "risk_plan": {
    "stop_loss": {"type": "ATR_MULTIPLE", "atr_indicator": "atr_volatility", "multiplier": 1.5},
    "take_profit_1": {"type": "RISK_REWARD_RATIO", "ratio": 1.5, "close_ratio": 0.5},
    "take_profit_2": {"type": "RISK_REWARD_RATIO", "ratio": 3.0, "close_ratio": 0.5},
    "max_hold_bars": 48
  }
}
```

### 6.3 Supported Primitives Registry
- **Indicator Primitives:** `EMA`, `SMA`, `RSI`, `MACD`, `ATR`, `BOLLINGER_BANDS`, `ADX`, `VWAP`, `VOLUME_SMA`.
- **Condition Operators:** `CROSS_ABOVE`, `CROSS_BELOW`, `GREATER_THAN`, `LESS_THAN`, `BETWEEN`, `PRICE_TOUCH_RANGE`, `DEVIATION_PCT`.
- **Logic Combinators:** `AND`, `OR`, `ALL_OF`, `ANY_OF`.
- **Exit & Risk Primitives:** `ATR_MULTIPLE`, `FIXED_PERCENTAGE`, `SWING_STRUCTURE`, `TRAILING_STOP`.
- **Fail-Closed Fallback:** When a requested strategy rule or indicator cannot be expressed using the supported primitives, the parser cleanly rejects the configuration with reason code `REQUIRES_CUSTOM_STRATEGY`.

### 6.4 14-Step Strategy Lifecycle
1. `DRAFT` $\rightarrow$ 2. `SCHEMA_VALIDATED` $\rightarrow$ 3. `SYNTACTICALLY_SOUND` $\rightarrow$ 4. `SIMULATION_READY` $\rightarrow$ 5. `BACKTEST_COMPLETE` $\rightarrow$ 6. `OVERFIT_SCREENED` $\rightarrow$ 7. `PROFIT_ATTRIBUTED` $\rightarrow$ 8. `PARITY_VERIFIED` $\rightarrow$ 9. `CANDIDATE_POOLED` $\rightarrow$ 10. `OPERATOR_APPROVED` $\rightarrow$ 11. `PAPER_ACTIVE` $\rightarrow$ 12. `UNDERPERFORMING` $\rightarrow$ 13. `QUARANTINED` $\rightarrow$ 14. `ARCHIVED`.

---

## 7. Multi-Exchange Extensibility Specification & Declarative Onboarding

### 7.1 Declarative Exchange Onboarding Architecture
VECTOR supports extending market data ingestion and trading analytics to new cryptocurrency derivatives exchanges via **Declarative CSV Templates** without writing Go adapter code.

### 7.2 Canonical CSV Template Format
```csv
schema_version,section,key,value
3.2,identity,exchange_id,kraken_futures
3.2,identity,display_name,Kraken Futures
3.2,identity,market_type,linear_perpetual
3.2,network,rest_base_url,https://futures.kraken.com/derivatives/api/v3
3.2,network,ws_public_url,wss://futures.kraken.com/ws/v1
3.2,ratelimits,requests_per_minute,600
3.2,endpoints,klines_path,/candles
3.2,endpoints,klines_method,GET
3.2,field_map,kline_open_time,time
3.2,field_map,kline_open,open
3.2,field_map,kline_high,high
3.2,field_map,kline_low,low
3.2,field_map,kline_close,close
3.2,field_map,kline_volume,volume
3.2,ws_subscriptions,candles_topic,candles_{timeframe}_{symbol}
3.2,funding,interval_hours,4
3.2,fees,taker_fee_pct,0.050
```

### 7.3 Security Boundaries & SSRF Prevention
1. **Network Allowlist & SSRF Defense:** Base URLs must resolve to public routable IP addresses. Loopback (`127.0.0.0/8`), private (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), link-local (`169.254.0.0/16`), and Cloud Metadata IP (`169.254.169.254`) are strictly blocked at DNS resolution.
2. **Protocol Restrictions:** Only `https://` and `wss://` protocols are allowed. Unencrypted `http://` and `ws://` are rejected.
3. **Zero Credentials:** The CSV template strictly forbids API keys, secrets, or passphrase fields. Any configuration attempting to supply credentials fails validation immediately.

### 7.4 10-State Exchange Lifecycle
`DRAFT` $\rightarrow$ `SCHEMA_VALIDATED` $\rightarrow$ `SSRF_CLEARED` $\rightarrow$ `SANDBOX_CONNECTED` $\rightarrow$ `PUBLIC_DATA_VERIFIED` $\rightarrow$ `CONFORMANCE_TESTED` $\rightarrow$ `READY_FOR_APPROVAL` $\rightarrow$ `APPROVED` $\rightarrow$ `RESEARCH_ACTIVE` $\rightarrow$ `RETIRED`.

---

## 8. Profitability Research & Economic Intelligence Specification

VECTOR evaluates strategies along two distinct certification dimensions:
- **Dimension A: Technical Correctness:** Zero lookahead, bit-exact replay, causal multi-timeframe alignment, double-entry reconciliation.
- **Dimension B: Economic Performance:** Positive net expectancy after taker commissions, slippage drag, and funding decay.

### 8.1 Dual Certification Framework
```
┌────────────────────────────────────────────────────────────────────────┐
│                   DUAL CERTIFICATION FRAMEWORK                         │
├───────────────────────────────────┬────────────────────────────────────┤
│ Dimension A: Technical Correctness│ Dimension B: Economic Performance  │
├───────────────────────────────────┼────────────────────────────────────┤
│ 1. Zero Lookahead Bias            │ 1. Positive Net Expectancy (Mean R)│
│ 2. Deterministic Replay (100.000%)│ 2. Profit Factor > 1.30 after costs│
│ 3. Accurate Risk Sizing           │ 3. Drawdown < 10% under stress     │
│ 4. Double-Entry Balance Parity    │ 4. Robust across Market Regimes    │
│ 5. Causal Multi-Timeframe Synch   │ 5. Statistical Sample Size (ESS>20)│
│ 6. Fail-Closed on Missing Input   │ 6. Out-of-Sample Walk-Forward LCB  │
│                                   │ 7. Bounded Slippage & Fee Decay    │
└───────────────────────────────────┴────────────────────────────────────┘
```

### 8.2 PROFIT-1 Quantitative Modules
- **Independent Slippage Attribution:** Measures execution cost drag without double-subtracting from fill-based gross PnL.
- **Risk-Adjusted Performance:** Reports mean/median net R, Profit Factor, Effective Sample Size (ESS), and HAC-adjusted Lower Confidence Bound (LCB).
- **Cost Structure Analysis:** Decomposes fees, funding, and slippage to detect fee-decay vulnerabilities.
- **Concentration Analysis:** Identifies correlated risk across overlapping positions and market breakouts.
- **Quarantined Counterfactual Replay:** Evaluates risk-rejected signals in `COUNTERFACTUAL_SHADOW` mode without touching live balances.

---

## 9. Research Data Import, Dataset Manager & Provenance Specification

### 9.1 Three Distinct Data Categories
To prevent confusion between live execution and historical simulation, VECTOR strictly segregates market data into three separately identified and typed categories:

```
┌────────────────────────────────────────────────────────────────────────┐
│                      THREE DATA CATEGORIES                             │
├───────────────────────────────────┬────────────────────────────────────┤
│ Category                          │ Ingestion Path & Invariant         │
├───────────────────────────────────┼────────────────────────────────────┤
│ A. Exchange-Collected Market Data │ Ingested continuously via public   │
│    (Live / Forward)               │ WebSocket/REST adapters. Immutable │
│                                   │ stream in `market_candles`.        │
├───────────────────────────────────┼────────────────────────────────────┤
│ B. Imported Historical Market Data│ Externally uploaded CSV/JSON/      │
│    (Offline / Historical)         │ Parquet/ZIP. Quarantined in        │
│                                   │ `dataset_candles`. Never mutates   │
│                                   │ live tables.                       │
├───────────────────────────────────┼────────────────────────────────────┤
│ C. Imported Research Evidence     │ Externally generated backtests or  │
│    (External Analysis)            │ reports. Marked as UNVERIFIED.     │
│                                   │ Never admitted as PAPER results.   │
└───────────────────────────────────┴────────────────────────────────────┘
```

### 9.2 Fundamental Data Isolation & Physical Storage Architecture
**IMPORTED HISTORICAL DATA MUST NEVER SILENTLY MODIFY LIVE MARKET HISTORY OR AUTHORITATIVE PAPER ACCOUNTING.**
- **Physical Partitioning:**
  - Live market data resides strictly in `market_candles`, `market_funding_rates`, and `market_open_interests`.
  - Imported historical datasets reside strictly in `dataset_manifests` and partitioned tables `dataset_candles` keyed by `(dataset_id, dataset_version)`.
  - PAPER trading engines, position state machines, and double-entry ledgers have **zero read or write access** to `dataset_*` tables.
  - Zero cross-contamination: No trigger, procedure, or query may copy or substitute imported data into live execution tables.
- **Artifact Storage Separation:**
  1. **Raw Upload Artifacts:** Stored in secure immutable blob storage (`/var/data/vector/datasets/raw/<hash>.bin`) with read-only permissions and recorded SHA-256 digests.
  2. **Normalized Datasets:** High-performance PostgreSQL partitions indexed by `(dataset_id, timestamp_utc)`.
  3. **Dataset Manifests:** Complete cryptographic and provenance metadata in `dataset_manifests`.
  4. **Research Run Linkages:** Every research run (`research_jobs`, `backtest_results`) stores explicit foreign keys to the exact `dataset_id` and `dataset_version`.

### 9.3 Dataset Manifest Canonical Schema
Every imported dataset is immutably defined by a versioned JSON manifest:

```json
{
  "$schema": "https://vector.traderbybit.internal/schemas/v3.3/dataset-manifest.json",
  "dataset_id": "okx_btcusdt_5m_2024_2025_v1",
  "dataset_version": 1,
  "dataset_name": "OKX BTC-USDT Linear Perpetual 5m (Jan 2024 - Dec 2024)",
  "source_type": "IMPORTED_HISTORICAL",
  "source_exchange_id": "okx",
  "source_provider": "tardis_historical_archive",
  "market_type": "linear_perpetual",
  "instrument_id": "BTC-USDT-SWAP",
  "original_symbol": "BTC-USDT-SWAP",
  "canonical_symbol": "BTCUSDT",
  "data_type": "OHLCV",
  "timeframe": "5m",
  "time_semantics": "BAR_OPEN_TIME",
  "timezone": "UTC",
  "timestamp_precision": "MILLISECONDS",
  "first_observation_at": "2024-01-01T00:00:00.000Z",
  "last_observation_at": "2024-12-31T23:55:00.000Z",
  "observation_count": 105120,
  "import_timestamp": "2026-10-29T00:00:00.000Z",
  "source_documentation": "Official public trading archive extracted via authenticated S3 vendor bucket.",
  "source_file_checksum": "sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "canonical_content_checksum": "sha256:cca7c3f87c3e1e86016e788c7f3e8f6bfdfd89e4c03b140995b05c5c16bc444d",
  "schema_version": "3.3",
  "validation_status": "VALIDATED",
  "quality_summary": {
    "accepted_rows": 105120,
    "rejected_rows": 0,
    "duplicate_rows": 0,
    "timestamp_gaps": 0,
    "conflicts": 0,
    "data_coverage_pct": 100.00
  },
  "provenance_status": "CERTIFIED_OFFLINE_HISTORICAL",
  "supported_research_scopes": ["BACKTEST", "WALK_FORWARD", "MONTE_CARLO", "PATTERN_DISCOVERY"]
}
```

### 9.4 Historical CSV Format Specification & Synthetic Template
The canonical historical CSV format is strictly typed:

```csv
open_time,open,high,low,close,volume
2025-01-01T00:00:00Z,93000.00,93100.00,92900.00,93050.00,123.450
2025-01-01T00:05:00Z,93050.00,93200.00,93000.00,93180.00,145.670
```
*(Synthetic illustrative prices for schema demonstration only).*

- **Schema Separation Notice:** CSV exchange configurations (Section 7), CSV strategy definitions (Section 6), and CSV historical market datasets (Section 9) belong to **three distinct schema families**. They have separate upload endpoints, separate parsers, and separate validation rules. Never mix their execution contexts.

### 9.5 Data Quality & Validation Engine
The Go validation pipeline (`internal/dataset/validation`) applies deterministic checks to every observation:
1. **Timestamp Normalization:** Strict UTC parsing (`RFC3339` or millisecond Unix epochs). Non-monotonic or out-of-order records trigger automatic sorting or quarantine.
2. **Timeframe Alignment:** Every bar must align with boundary multiples (e.g. $t \pmod{300} = 0$ for 5m bars).
3. **OHLC Relationship Integrity:**
   $$low \le open \le high \quad \text{and} \quad low \le close \le high \quad \text{and} \quad high \ge low$$
   $$volume \ge 0.0$$
4. **Finite Numerical Precision:** Rejection of `NaN`, `+Inf`, `-Inf`, nulls, or values exceeding 12-decimal scaled precision.
5. **Duplicate & Conflict Detection:** Identical timestamps with differing prices are flagged as `DATA_CONFLICT` and quarantined.
6. **Gap Reporting Without Fabrication:** Missing bars are recorded in `timestamp_gaps`. Under **NO CIRCUMSTANCES** does VECTOR fabricate, interpolate, or synthesize missing candles to fill gaps. Research engines must evaluate with authentic historical gaps.
7. **Action Policies:**
   - `REJECT`: File corrupted, unparseable, invalid headers, or security violation.
   - `QUARANTINE`: > 1% conflicting or out-of-order records pending manual inspection.
   - `WARN`: Minor gaps documented in the manifest without rejecting valid observations.
   - `ACCEPT_WITH_LIMITATIONS`: Clean data with minor documented coverage gaps.

### 9.6 Secure File Processing Architecture
Uploaded datasets are treated as untrusted external input:
- **Resource Constraints:**
  - Maximum upload size: **500 MB** (compressed archive) or **1.5 GB** (uncompressed CSV/Parquet).
  - Maximum expanded archive size: **2 GB**.
  - Maximum extracted file count: **100 files** per ZIP archive.
- **Streaming Parser:** Go parser reads files using streaming scanners (`bufio.Scanner`, `parquet-go`) with fixed-size worker pools. Memory footprint is strictly bounded (RSS < 256 MiB regardless of dataset length).
- **Security Defenses:**
  - **ZIP Bomb Protection:** Decompression ratio enforced strictly $\le 10:1$. Extraction halts immediately if expansion exceeds threshold.
  - **Path Traversal Protection:** Archive paths containing `../`, leading slashes `/`, null bytes, or symbolic links are rejected immediately.
  - **Executable Rejection:** Magic-byte sniffing rejects ELF, PE, Mach-O, shell scripts, or binary executables disguised as CSV/ZIP.
  - **CSV Formula Escaping:** Characters `=, +, -, @` at the beginning of fields are sanitized to prevent spreadsheet formula injection upon export.
  - **Credential Rejection:** Files containing strings resembling API keys or private credentials fail validation instantly.

### 9.7 Exchange Identity Without Exchange API Connection
A crucial architectural capability: **Historical research on an exchange does NOT require an active Exchange API connection.**
- VECTOR can register offline exchange source identities (e.g. OKX, Kraken, dYdX) solely for historical backtesting and cross-market comparisons.
- Five distinct exchange states:
  1. `OFFLINE_RESEARCH_SOURCE`: Datasets imported and validated; no API keys, no network connections.
  2. `CONNECTED_PUBLIC_ADAPTER`: Public REST/WS ingestion active in sandbox or staging.
  3. `RESEARCH_ENABLED_EXCHANGE`: Available for strategy research across both live and imported data.
  4. `PAPER_ELIGIBLE_EXCHANGE`: Passed 14-day data contract conformance tests.
  5. `ACTIVE_PAPER_EXCHANGE`: Human operator approved; PAPER accounts provisioned.

### 9.8 Historical Research Integration & Leakage Protection
Researchers can select any combination:
$$\mathbf{STRATEGY} + \mathbf{EXCHANGE} + \mathbf{DATASET} + \mathbf{INSTRUMENT} + \mathbf{TIME\ WINDOW}$$

**Execution Pipeline:**
$$\text{Dataset Selection} \rightarrow \text{Compatibility Validation} \rightarrow \text{Historical Data Loading} \rightarrow \text{Strategy Evaluation} \rightarrow \text{Backtest} \rightarrow \text{WFO} \rightarrow \text{Monte Carlo} \rightarrow \text{Attribution}$$

**Leakage Protection Invariants:**
1. **Chronological Replay:** Bars are fed strictly in ascending temporal order $t_0, t_1, \dots, t_n$.
2. **Strict Lookahead Elimination:** Indicators and decisions at bar $t$ can only access data $t_{close} \le t$.
3. **Warm-Up Periods:** Indicator warm-up bars (e.g. 200 bars for 200 EMA) are computed prior to recording strategy decisions. Decisions during the warm-up window are discarded.
4. **Chronological Splitting:** In WFO and Train/Validation/Test splits, the test period occurs strictly **after** the training period chronologically.
5. **No Forward Substitution:** Historical backtests on imported data can **NEVER** substitute for prospective forward PAPER observations required for strategy promotion.

### 9.9 Financial Accuracy, Cost Modeling & Disclaimers
Historical OHLCV observations do not contain private execution queue positions, liquidity depth, or funding schedules.
- **Explicit Cost Policies:**
  - When historical funding rates are missing, the system does **not** assume zero funding; it flags `FUNDING_UNKNOWN` and provides parameterizable stress estimates.
  - Taker commissions are applied based on documented tier defaults (e.g. 0.05% taker).
  - Slippage is modeled using empirical volatility-scaled models ($k \cdot \text{ATR}$) rather than zero-slippage assumptions.
  - Research reports clearly distinguish between **Observed Facts** (raw OHLCV) and **Simulated Assumptions** (fill prices, fees, slippage).
  - Profitability certification (Dimension B) cannot be granted if cost parameters are unverified.

### 9.10 Live and Imported Data Comparison
The platform provides analytical comparison views that juxtapose datasets without combining their physical records:
- **Divergence Analysis:** Compare exchange-collected candles against third-party imported candles for the same symbol and timeframe to detect vendor anomalies or exchange bad-prints.
- **Cross-Exchange Regime Analysis:** Compare strategy performance on Bybit vs OKX over the same historical window.
- **Reporting Disclosures:** Every comparative report explicitly discloses source hashes, observation counts, and data provenance.

### 9.11 User Interface Architecture
Three dedicated functional areas in Quasar 2 / Vue 3:
1. **`Research -> Data Manager`:**
   - Dataset upload dropzone (CSV, Parquet, ZIP).
   - Column mapping wizard with live data preview.
   - Comprehensive Data Quality Report (accepted rows, gaps, conflicts).
   - Dataset manifest browser with immutable versioning and provenance badges.
2. **`Research -> New Backtest`:**
   - Dropdown selectors: Strategy $\rightarrow$ Exchange $\rightarrow$ Dataset $\rightarrow$ Instrument $\rightarrow$ Date Range.
   - Real-time compatibility validator (warns if required indicators or timeframes are missing).
3. **`Research -> Dataset Comparison`:**
   - Side-by-side data coverage visualizer.
   - Backtest performance overlay across multiple datasets.

### 9.12 Data Governance, Retention & Reproducibility
- **Version Immutability:** Once published, a dataset version is read-only. Updates create a new monotonically incremented `dataset_version`.
- **Reproducibility Guarantee:** Any research report can be re-run bit-exact by specifying the exact `dataset_id` and `canonical_content_checksum`.
- **Safe Retention Policy:** Datasets referenced by approved Candidate Lab proposals or published research reports cannot be deleted while those reports remain active.

### 9.13 25 Acceptance Criteria for Research Data Ingestion & Dataset Manager
1. A valid historical OHLCV CSV is uploaded and accepted.
2. The file is validated and normalized into UTC and canonical units.
3. Its immutable SHA-256 checksum is recorded in the manifest.
4. Exchange, instrument, market type, and timeframe identities are strictly preserved.
5. Duplicate file upload is detected via content hash and flagged cleanly.
6. Timestamp gaps are detected, counted, and reported in the quality summary.
7. Corrupted OHLC rows ($high < low$, negative volume) fail validation and are rejected.
8. Decimal precision is maintained with zero floating-point rounding errors.
9. Parquet and JSON files follow the identical canonical normalization schema.
10. ZIP path traversal (`../`) and decompression bomb attacks (> 10:1 ratio) are rejected safely.
11. Dataset publication to PostgreSQL is atomic; failures leave zero orphaned records.
12. Incomplete or partially imported files cannot appear as certified complete datasets.
13. A published dataset can be selected immediately in the Research Engine.
14. A compatible existing PRIMARY or declarative strategy evaluates deterministically against it.
15. Strategy evaluation cleanly fails closed if the dataset lacks required indicators or timeframes.
16. Walk-Forward and Out-of-Sample evaluation windows maintain strict chronological isolation.
17. Exchange identity cannot be silently substituted or corrupted during import.
18. Imported data cannot modify or insert rows into `market_candles` or live tables.
19. Imported data cannot modify PAPER account balances, positions, or executions.
20. Research results reference exact dataset IDs, versions, and source checksums.
21. Missing cost data is never assumed to be zero; simulation assumptions are explicitly disclosed.
22. Offline OKX historical research operates deterministically without an active OKX API adapter.
23. Zero new PAPER accounts are provisioned as a result of dataset ingestion.
24. Existing Bybit and Binance live ingestion and paper trading continue completely uninterrupted.
25. Ingestion of 1,000,000 candles completes within documented CPU and memory budgets (RSS < 256 MiB).

---

## 10. Preserved Risk, Financial, and Safety Invariants

### 10.1 Preserved Risk Controls & Blocked Strategy Diagnostics
- **Entry-Block Threshold:** Strictly preserved at **5% drawdown** from high-water mark.
- **System Halt Threshold:** Strictly preserved at **10% drawdown** from high-water mark.
- **Forbidden Alterations:** No weakening of thresholds (e.g. relaxing to 15%) is permitted under any circumstances.
- **Structured Blocker Diagnostics:** Persists exact triggering metric, threshold, and timestamp for any blocked account.

### 10.2 Preserved Financial Identities & Reconciliation
$$\text{Equity} = \text{Balance} + \text{Unrealized PnL}$$
$$\text{Balance}_{t} = \text{Balance}_{0} + \sum \text{Realized Gross PnL} - \sum \text{Commissions} \pm \sum \text{Funding Settlements}$$
$$\text{Net Realized PnL} = \text{Gross Realized PnL} - \text{Commissions} + \text{Funding Settlements}$$
- No historical financial record may ever be rewritten, mutated, or deleted.
- Fills at candle close must model certified taker commissions and realistic slippage.
- All accounts maintain isolated positions, executions, balances, and drawdowns without cross-contamination.

### 10.3 Strict Safety & Platform Boundaries
- **Virtual PAPER Trading Only:** No real exchange orders; zero trade credentials; read-only public market data ingestion only.
- **Independent Infrastructure:** ECONOM / econom-group resources are strictly isolated and out of scope.

---

## 11. Database-Enforced Financial Fencing & Single-Writer Protocol

To guarantee that PHP and Go can **never simultaneously write** to authoritative financial records, a database-level fencing protocol is established in PostgreSQL:

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

## 12. Parity Validation Protocols

Before Go becomes authoritative for strategy execution, every family must achieve **bit-exact parity** against versioned PHP golden fixtures:

### Parity Protocol
1. **Fixture Extraction:** 10,000 closed candles extracted for each of the 13 families across BTCUSDT and ETHUSDT (1h, 30m, 15m, 5m).
2. **PHP Baseline Execution:** Run PHP strategy scanner, recording complete decision vector (`decision`, `reason_code`, `price_plan`, `indicator_snapshot`).
3. **Go Differential Runner:** Execute Go strategy on identical candle stream.
4. **Comparison Gate:** Decisions must match 100.000%; reason codes must match 100.000%; price levels must match within $1 \times 10^{-8}$.
5. **Resolution:** Any divergence must be documented, root-caused, and resolved before execution authority transitions.

---

## 13. Scorecard V3.3 Transition & Migration Registry

To maintain audit integrity, the frozen baseline is strictly protected:

1. **Frozen Baseline Preserved:** `VECTOR-SCOPE-20261027-v1` remains immutable in `docs/progress/baselines/VECTOR-SCOPE-20261027-v1.json`.
2. **Proposed Baseline V3.3:** Proposed file `docs/progress/baselines/VECTOR-SCOPE-20261028-v3-proposal.json` maps the requirements to the 9-phase sequence.
3. **Go Migration Registry (`docs/progress/VECTOR_GO_MIGRATION_REGISTRY_V3.md`):** Tracks the operational migration of each component independently across four states (`PHP_AUTH`, `GO_SHADOW`, `GO_AUTH`, `PHP_RETIRED`), now including `MIG-01` through `MIG-29`.
4. **Four Independent Progress Dimensions:** Functional Implementation %, Verification %, Product %, and Go Migration %.

---

## 14. Component Ownership & Migration Summary Matrix

| Subsystem | Specific Component | Phase 0–4 State | Phase 5–6 State | Target Phase 7 State | Authoritative Writer |
|:---|:---|:---:|:---:|:---:|:---:|
| **Market Data** | Public Candle REST / WS | PHP | Go (Authoritative) | Go | **Go Engine** |
| **Market Data** | Generic Config Ingestion (CSV)| Planned | Go Config Engine | Go Config Engine | **Go Engine** |
| **Market Data** | Candle Ring Buffer | PHP / Redis | Go In-Memory | Go | **Go Engine** |
| **Market Data** | Funding Rates & Open Interest | PHP | Go | Go | **Go Engine** |
| **Dataset Manager**| Historical Dataset Importer (CSV/Parquet)| Planned | Go Importer | Go Importer | **Go Engine** |
| **Dataset Manager**| Data Quality & Validation Engine | Planned | Go Validation Engine| Go Validation Engine| **Go Engine** |
| **Dataset Manager**| Dataset Storage & Versioning | Planned | Postgres / Go | Postgres / Go | **Go Engine** |
| **Dataset Manager**| Dataset Manager UI & Manifests | Planned | Vue 3 / Laravel | Vue 3 / Laravel | **Laravel Plane**|
| **Indicators** | Technical Indicators (EMA, RSI...) | PHP & Go | Go Library | Go Library | Pure Library |
| **Strategy** | 13 PRIMARY Strategy Families | PHP (5 in Go) | Go (Shadow Parity) | Go (Authoritative) | **Go Engine** |
| **Strategy** | Strategy Extensibility SDK | PHP Registry | Go Strategy SDK | Go Strategy SDK | **Go Engine** |
| **Strategy** | Declarative Strategy Engine | Planned | Go Declarative Engine| Go Declarative Engine| **Go Engine** |
| **Strategy** | Real-Time Signal Generation | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Research** | Backtesting Engine | Go | Go | Go | **Go Worker** |
| **Research** | Dataset Selection & Loader | Planned | Go Loader | Go Loader | **Go Worker** |
| **Research** | Cross-Dataset Analytics | Planned | Go Analytics | Go Analytics | **Go Worker** |
| **Research** | Walk-Forward & Monte Carlo | Go | Go | Go | **Go Worker** |
| **Research** | Profitability Research Engine | PHP | Go | Go | **Go Worker** |
| **Research** | Pattern Discovery & Squeezes | Go | Go | Go | **Go Worker** |
| **Research** | Candidate Search & Evolution | PHP | Go | Go | **Go Worker** |
| **Paper Trading**| Multi-Account Portfolio Risk Engine | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Position Lifecycle (Orders/Fills) | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Double-Entry Financial Ledger | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Paper Trading**| Funding & Fee Settlement | PHP | Go (Shadow) | Go (Authoritative) | **Go Engine** |
| **Adapters** | Multi-Exchange Extensibility SDK| PHP Adapters | Go Exchange SDK | Go Exchange SDK | **Go Engine** |
| **Adapters** | Generic Config Exchange Adapter| Planned | Go Generic Adapter | Go Generic Adapter | **Go Engine** |
| **Analytics** | Statistics, Drawdowns, Sharpe | PHP | Go Engine | Go Engine | **Go Engine** |
| **Control Plane**| Exchange & Strategy Builders UI| Laravel | Laravel | Laravel | **Laravel Plane**|
| **Control Plane**| Candidate Lab Approval Gates | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Operator Auth & Roles | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| REST API Endpoints | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Report & CSV Read Delivery | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **UI** | Vue 3 / Quasar Dashboard | Frontend SPA | Frontend SPA | Frontend SPA | Read-Side UI |

---

## 15. Implementation Handoff & Next Steps

### Current System Status at Baseline `7113f22`
- **Phase 0 Status:** **BLOCKED**. All 42 local V1 obligations and 9 browser V3 obligations have authentic passing verification evidence. All 922 backend PHP tests, 64 frontend tests, and Go research tests are passing cleanly.
- **Phase 0 Blocker:** 45 Production V2 obligations require operator execution of [`phase0_production_acceptance.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/phase0_production_acceptance.sql) on the VPS according to [`OPERATOR_CHECKLIST.md`](file:///home/thunder/work/traderbybit/docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md).
- **Binance Immutable Candle Investigation:** Server-time clock skew is an established hypothesis; awaiting operator capture of exact production conflict record to verify.
- **Phase 1 Status:** **NOT STARTED**. Strictly blocked until formal Phase 0 operator sign-off.

### Immediate Action Required by Next Engineer / Operator:
1. **Operator Action:** Execute the production diagnostic queries on the VPS and record output in `docs/progress/evidence/phase0-production-acceptance/`.
2. **Production Conflict Investigation:** Collect the structured Binance immutable candle conflict record from the VPS logs to verify the clock skew root cause.
3. **Evidence Closure:** Execute `python3 scripts/vector_progress.py --phase 0 --generate` to admit fresh production evidence and close Gate 0.
4. **Transition to Phase 1:** Upon owner authorization, initiate Phase 1 (Go Foundation: `pkg/decimal`, `pkg/types`, `pkg/protocol`, `pkg/fencing`, `pkg/dataset`).
