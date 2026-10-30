# VECTOR Go-First Architecture Roadmap V3.4: Incremental Delivery & Engineering Governance

**Document Version:** V3.4 (Authoritative Engineering Specification)
**Document Status:** Canonical Approved Technical Roadmap & Architecture Plan — AMENDED TO V3.4 (Incremental Delivery & Engineering Governance)
**Governing Standards:** [`AGENTS.md`](file:///home/thunder/work/traderbybit/AGENTS.md) and [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md)
**Source Baseline SHA:** `aeffb318a2462aa92ae9d7fca9ae00ac064d2776` (Roadmap V3.4 Baseline)
**Historical Scope Baseline:** `VECTOR-SCOPE-20261027-v1` (Frozen, 97 Requirements, 229 Obligations)
**Target Proposal Baseline:** `VECTOR-SCOPE-20261028-v3.4`
**Execution Mode:** STRICTLY VIRTUAL PAPER TRADING ONLY. Zero real exchange trading; zero private trade credentials; zero order placement endpoints.

### P1 production deployment attempt — 2026-10-30

The operator-supplied GitHub Actions log for `39ca8063fcd324548cdf0b33ccb90dfd3e4ed73e` shows **FAILED** deployment at M9: six Bybit PRIMARY accounts reported `UNREALIZED_MISMATCH`. The workflow's application rollback restored the `671f72f11ba76a978dc1a6f29ae0ceeeb63e9322` image and the supplied healthcheck passed; additive conflict migrations `220000` and `220100` remain applied. The log does not establish whether the initializer already committed Binance account/capital/snapshot changes. See the [failure analysis and read-only diagnostic](../reports/M9_DEPLOYMENT_FAILURE_20261030.md). Do not retry deployment from the successful local V4 gate alone.

Local commit `2deae2121e7fc2c06b099eb07355b995697b3fc9` aligns M9 unrealized scope with active positions, flags nonzero closed-position marks, and makes initialization database writes atomic. The production values remain unclassified; these fixes are **LOCAL VERIFIED, PRODUCTION NOT VERIFIED**. Phase 0 stays **BLOCKED**, Phase 1 **NOT STARTED**. No production repair or deployment was performed by the coding agent.

### P1 Binance Candle Integrity V4 local gate — 2026-10-30

The [V4 report](../reports/P1_CANDLE_INTEGRITY_V4_20261030.md) supersedes the V3 local NOT READY verdict for its three reproduced blockers. Journal recovery is idempotent and durably receipted; malformed evidence fails closed; ongoing strategy inputs consult shared storage and reject affected scopes. The five V2 fixes and all three V3 fixes have focused PostgreSQL regression evidence. Targeted PHPUnit: 126 tests / 1,108 assertions; full PHPUnit: 1,069 tests / 10,581 assertions / one intentional network skip; both exit 0. Pint, PHP syntax, frontend tests/build, Go race, preserved V3 probes and strict tracker validation passed; full command records and hashes are in the [V4 evidence directory](../progress/evidence/p1-candle-integrity-v4-20261030/).

**Local P1 status: READY FOR OPERATOR PUSH — AWAITING EXPLICIT AUTHORIZATION.** This grants no push or deployment permission. Phase 0 remains **BLOCKED**, roadmap Phase 1 **NOT STARTED**, and no production/product acceptance is inferred. The four previously reported historical Binance conflicts need separate operator-authorized classification/remediation. Production storage, PAPER history and the ECONOM project were not accessed or modified. The provisional completion score remains 0% / 0% / 0%; frozen scope and the failed performance gate are unchanged.

### P1 Binance Candle Integrity V3 release gate — 2026-10-29

This release package is separate from roadmap Phase 1. Phase 0 remains **BLOCKED**;
roadmap Phase 1 remains **NOT STARTED**. No production acceptance is granted.

| Item | Source-qualified status |
|---|---|
| P1 implementation | **IMPLEMENTED** at `24e5096588c1aca66a19761ca7ed207e73514066` |
| V2 blocker fixes | **LOCAL VERIFIED** at `7a26d93a39e09417423f4a2f58d6c68a74b7c884`; see the [V2 report](../reports/P1_CANDLE_INTEGRITY_V2_20261029.md) and its independent PostgreSQL evidence |
| V3 release gate | **NOT READY** at inspected source `43bd3d77c084e23e83b845f7fce476ec0cd60fbd`; the five original probes now pass, but additional journal/recovery and strategy-input blockers are reproduced in the [V3 report](../reports/P1_FINAL_RELEASE_GATE_V3_20261029.md) |
| Journal recovery | **BLOCKED** — no recovery entrypoint or idempotent event acknowledgement; replay duplicates occurrence counts and leaves the original evidence pending |
| Pending evidence validation | **BLOCKED** — a pending entry with an array-valued symbol produces scoped integrity VALID and candle-window READY |
| Ongoing strategy input | **BLOCKED** — pending evidence rejects the candle-window check but is accepted by StrategyInputFactory, including in a fresh PHP process; no signals or PAPER fills were attempted by this probe |
| Targeted P1 PHPUnit | **LOCAL VERIFIED** — 66 tests / 794 assertions, exit 0 |
| Full PHPUnit | **LOCAL VERIFIED** — 1,008 passed / 1 intentional public-Bybit skip / 10,267 assertions, exit 0 |
| Frontend tests and build | **LOCAL VERIFIED** — 64 tests passed; typecheck and production build passed, exit 0 |
| Go verification | Go source unchanged; race tests not rerun in V2. Both Go worker PHP integration tests passed in the full suite; the earlier gate retains its dated Go race evidence |
| Conflict durability and fail-closed behavior | **LOCAL VERIFIED V2** — independent commits survive outer rollback; persistence/read failures block READY; atomic recurrence/concurrency and real scheduled failures covered |
| Historical correction | **PENDING SEPARATE OPERATOR AUTHORIZATION** for the four reported BTCUSDT/ETHUSDT 30m/1h bars at 2026-10-27T00:00:00Z; prepared SQL was not executed; current production values were not inspected |
| Push and deployment | **PRODUCTION PENDING / NOT READY FOR OPERATOR PUSH** — V3 supersedes the V2 gate-readiness verdict; no push, workflow dispatch, deployment, or production writes authorized or performed |

The historical pre-push gate and V2 report qualify unconditional durability/root-cause
claims in the earlier forensic report. V2 supersedes the five local blocker findings,
without asserting a production historical root cause. V3 preserves those narrow
results and rejects release on the additional reproduced blockers. Passing nominal
suites does not establish safety of
failure paths or close production obligations. Scope weights and acceptance
criteria remain frozen; no scorecard credit is inferred from this release review.

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
5. **Zero Silent Conflicts Policy:** The allowable rate of silent data conflicts is exactly zero. Every contradictory observation must be logged, counted, and audited. Duplicate identical rows are handled idempotently; conflicting prices at identical timestamps are quarantined.
6. **Measurable Performance Contracts (No Unconditional Fixed-Memory Claims):** Memory consumption is managed via bounded streaming parsers, chunked transactions, and backpressure. Ingestion memory is bounded by an explicit performance target (Peak RSS < 256 MiB under the standard benchmark profile) with hard container cgroup limits (512 MiB), rather than an unconditional claim of fixed memory for arbitrary adversarial inputs.
7. **Bounded Decompression Security:** Decompression bomb defenses enforce total decompressed byte ceilings (2.0 GB) and per-entry limits rather than arbitrary compression ratio thresholds, allowing highly compressible repetitive market data CSVs to be ingested safely.
8. **Scope Distinction Principle:** `APPROVED ARCHITECTURAL SCOPE != IMPLEMENTED RUNTIME CAPABILITY`. Planned migration packages (MIG-18 through MIG-29) are approved architectural milestones, not completed implementations.
9. **Zero Mandatory LLM / No AI Trade Decisions:** The platform operates with 100% determinism. No LLM credentials, embedded agents, prompt injections, or external token costs exist in the trading, market data, evaluation, or accounting execution paths. External AI tools (e.g. Codex) may be used offline by developers to generate code or configurations, but the runtime remains strictly deterministic.
10. **Dual Certification — Correctness vs Profitability:** Technical correctness (flawless execution, zero lookahead, deterministic calculations, accounting reconciliation) is strictly separated from economic profitability (net R-multiples, slippage drag, fee decay, drawdown, and statistical stability). An operationally sound strategy may be unprofitable; a backtest-profitable strategy may be statistically fragile. Neither condition is ever obscured.
11. **Single Authoritative Writer:** To guarantee absolute financial integrity across all independent PAPER accounts, at any given millisecond exactly **ONE** engine is the authoritative writer for any given financial or ledger entity. Dual-writing is strictly prohibited. Fencing tokens and monotonic epochs enforce this invariant in PostgreSQL.
12. **Strict Safety Invariants:** Virtual PAPER mode only; zero real exchange orders; zero trade credentials; preserved 5% entry-block and 10% halt risk thresholds; preserved independent account isolation; zero historical financial record mutation; ECONOM resources strictly untouched.

---

## Roadmap V3.4 Change Log: Six Approved Governance & Delivery Improvements

This amendment formalizes six owner-approved improvements designed to prevent over-engineering, reduce delivery risk, guarantee financial correctness, introduce verifiable operator attestation, and maintain strict alignment between approved scope and runtime capabilities:

1. **Phase 1 Incremental Delivery (Phases 1.0, 1.1, 1.2):**
   - Deconstructed the monolithic Phase 1 into three sequential, independently verifiable increments:
     - **Phase 1.0 (Financial Decimal & Core Types):** Scope restricted strictly to `pkg/decimal` and `pkg/types`. High-precision, deterministic fixed-point arithmetic compatible with PostgreSQL `NUMERIC(24,12)`. Explicit range enforcement avoiding signed 64-bit overflow (`int64` scaled by $10^{12}$ covers only $\pm 9.22 \times 10^6$ units, failing `NUMERIC(24,12)` which requires $\pm 10^{12}$ units; implementation mandates 128-bit capacity or strict bounds checking). Requires 1,000,000 randomized arithmetic parity tests against PostgreSQL. Binary floating point is strictly forbidden. Zero authority over PAPER financial writes.
     - **Phase 1.1 (Protocol & Fencing Contracts):** Scope restricted to `pkg/protocol` and `pkg/fencing`. Versioned PHP/Go IPC contracts, backward-compatible envelopes, deterministic serialization, monotonic fencing tokens, stale-worker rejection, duplicate-command handling, and cross-process concurrency verification.
     - **Phase 1.2 (Dataset Foundation Contracts):** Scope restricted to `pkg/dataset` foundational types, manifests, provenance, and validation contracts. File parsing, storage, and UI remain deferred to Phase 2.
   - **Global Prerequisite Preserved:** Formal Phase 0 Operator Attestation is mandatory before Phase 1 implementation begins.

2. **Formal Phase 0 Operator Attestation Contract:**
   - Replaced informal operator approval with a tamper-evident, evidence-backed attestation protocol.
   - Codified the 16-field Attestation Contract schema (environment, deployed SHA, container digests, timestamp, operator identity, gate evaluations, SQL diagnostics, ledger reconciliation, account census, exception ledger, and signature block).
   - Mandated strict gate behavior: missing evidence = `NOT VERIFIED`, no informal overrides, no cross-SHA evidence reuse, and no automatic application-generated acceptance. Attestation records an evidence-based decision and does not transfer unverified legal liability.

3. **Declarative Builders MVP Scope:**
   - Constrained initial builder implementations to verifiable minimal viable products:
     - **Declarative Strategy MVP (Phase 3 / MIG-18):** Three existing indicators from SDK (`EMA`, `RSI`, `ATR`), two comparison operators (`GREATER_THAN`, `LESS_THAN`), boolean composition (`AND`, `OR`), static typed parameters, strict JSON schema validation, zero dynamic code evaluation (`eval`), and bit-exact parity against native Go strategy implementations.
     - **Declarative Exchange MVP (Phase 6 / MIG-20):** Minimal CSV/JSON mapping contract (identity, category, symbol, timestamps, OHLCV) operating strictly in isolated research/import scope; strictly barred from activating live PAPER accounts.

4. **Atomic Migration Registry Updates:**
   - Established the mandatory synchronization rule for `MIG-01` through `MIG-29` and all future packages: any transition in package maturity (`APPROVED`, `PHP_AUTH`, `GO_IMPLEMENTED`, `GO_SHADOW`, `GO_AUTH`, `PHP_RETIRED`) must be committed atomically in the same Git commit as the underlying code change.
   - Added lightweight automated drift-check specifications.
   - Preserved all existing requirement IDs and uninflated maturity states during documentation revisions.

5. **Shadow Reconciliation & Divergence Diagnostics (Phase 5 / Phase 7):**
   - Defined the canonical 15-point comparison dimensions (Exchange, Account, Strategy, Instrument, Event sequence, State transition, Quantity, Gross PnL, Fees, Funding, Realized PnL, Unrealized PnL, Equity, Decimal rounding, Source marks).
   - Specified a deterministic, read-only diagnostic diff tool (`cmd/vector-reconcile`) that identifies the first divergent event, displays calculations side-by-side, and reproduces divergence offline.
   - Preserved the > 0.0001 USDT alert threshold, mandated 14-day validation timer restart on material unexplained divergence, and enforced the single-authoritative-writer invariant.

6. **Go Supply Chain Security Gate (Phases 1–8):**
   - Established a mandatory Go dependency governance section starting in Phase 1: maintainer verification, vulnerability scans (`govulncheck`), license compliance, exact version pinning in `go.mod`, immutable `go.sum` validation, dependency inventory/SBOM, transitive dependency audits, standard library preference, and zero automatic upgrades.

---

## Roadmap V3.3 Change Log & Final Clarifications

### Final Architectural Corrections & Freeze for Phase 0 Handoff
1. **Correction A — Memory Resource Budget & Performance Contract:**
   - Replaced the unconditional claim of "RSS < 256 MiB independently of dataset size" with a rigorous, measurable performance contract.
   - Defined the **Standard Benchmark Profile** (50,000 to 2,000,000 rows, 100 MB to 1.5 GB uncompressed CSV/Parquet, 1 vCPU, 512 MiB cgroup ceiling, chunk size 5,000 rows, channel depth 1,000).
   - Distinguished baseline RSS (idle: 20–30 MiB), steady-state streaming RSS (80–160 MiB), peak target RSS (< 256 MiB), and hard container ceiling (512 MiB).
   - Specified incremental chunked streaming into PostgreSQL with backpressure and context cancellation; jobs exceeding quotas abort safely without corrupting published datasets.
2. **Correction B — Bounded ZIP Decompression Security:**
   - Removed the rigid rejection rule `compression ratio > 10:1` which falsely rejected legitimate, highly repetitive historical OHLCV CSV archives.
   - Replaced with bounded decompression controls: maximum total decompressed size of **2.0 GB**, maximum single-entry size of **1.5 GB**, maximum entry count of **100 files**, and a **120-second** processing deadline.
   - Added streaming `io.LimitReader` tracking cumulative extracted bytes, path traversal rejection (`../`, leading slashes, symlinks), and rejection of nested or encrypted archives.
   - High compression ratios (> 10:1) are retained solely as informational audit signals, not automatic rejection triggers.
3. **Correction C — Zero-Tolerance Policy on Silent Conflicts:**
   - Clarified that the allowable rate of silent data conflicts is **EXACTLY ZERO**.
   - Differentiated identical duplicates (exact timestamp and OHLCV matches, handled idempotently and logged in `duplicate_rows`) from genuine contradictory observations (identical timestamp with differing OHLCV values, logged in `dataset_conflicts`).
   - Confirmed that the 1% threshold is an escalation/quarantine threshold for automatic dataset quarantine, but **ANY** non-zero conflict (> 0) prevents clean certification (`VALIDATED`) and must be explicitly recorded and audited.
   - Prohibited silent selection, averaging, interpolation, or overwriting of conflicting values.
4. **Scope vs Verification Distinction:**
   - Explicitly codified that `APPROVED ARCHITECTURAL SCOPE != IMPLEMENTED RUNTIME CAPABILITY`. Packages `MIG-18` through `MIG-29` are approved planned architecture, not implemented or verified code.
   - Phase 0 status remains strictly **BLOCKED** pending operator execution of `phase0_production_acceptance.sql` on the VPS; Phase 1 remains **NOT STARTED**.
5. **Roadmap Designation:** Marked as **`READY_FOR_OWNER_APPROVAL`** and frozen for Phase 0 production acceptance.

### What Changed in Roadmap V3.3 (Initial Draft)
1. **Research Data Import & Dataset Manager (Section 9):**
   - Added architectural specification for offline historical data ingestion, dataset versioning, and provenance tracking.
   - Formalized three distinct data categories: **(A) Exchange-Collected Market Data**, **(B) Imported Historical Market Data**, and **(C) Imported Research Evidence**.
   - Established the **Dataset Manifest Canonical Schema** (`dataset_id`, `dataset_version`, `source_exchange_id`, `canonical_content_checksum`, `provenance_status`, etc.) guaranteeing cryptographic immutability and provenance.
   - Defined the **Historical OHLCV CSV Format** with clear distinction from exchange configuration and strategy rule CSV formats.
2. **Exchange Identity Without Exchange API Connection (Section 9.7):**
   - Decoupled offline research source identities (e.g. OKX historical data) from live exchange API connectivity, WebSocket listeners, and PAPER account provisioning.
3. **Historical Research Integration & Leakage Protection (Phase 4 & Section 9.8):**
   - Implemented the research selection tuple: `STRATEGY + EXCHANGE + DATASET + INSTRUMENT + TIME WINDOW`.
   - Enforced chronological evaluation, point-in-time feature availability, indicator warm-up handling, and strict chronological isolation between training and out-of-sample periods.
4. **Live vs Imported Data Comparison & Dedicated UI (Section 9.10 & 9.11):**
   - Designed comparative analytical views combining datasets without compromising source isolation.
   - Added UI specifications for `Research -> Data Manager`, `Research -> New Backtest`, and `Research -> Dataset Comparison`.
5. **Migration Registry Updates:**
   - Added work packages `MIG-24` through `MIG-29` to `docs/progress/VECTOR_GO_MIGRATION_REGISTRY_V3.md`.
   - Defined 25 comprehensive, measurable acceptance criteria for dataset ingestion and management.

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
| **Historical Dataset Ingestion & Parsing**| Planned: Go `internal/dataset/importer` | **Planned in V3.3 (`MIG-25`)**. Bounded streaming CSV/Parquet importer. | **IMPLEMENT** in Go Core / Research. | Phase 2 |
| **Dataset Storage & Manifests** | Planned: Go `internal/dataset/storage` | **Planned in V3.3 (`MIG-26`)**. Immutability checksums, metadata, PostgreSQL. | **IMPLEMENT** in Go Core / Postgres. | Phase 2 |
| **Data Quality & Validation Engine** | Planned: Go `internal/dataset/validation` | **Planned in V3.3 (`MIG-25`)**. Deterministic validation, zero silent conflicts. | **IMPLEMENT** in Go Core. | Phase 2 |
| **Research Dataset Loader & Backtest** | Planned: Go `internal/research/loader` | **Planned in V3.3 (`MIG-27`)**. Chronological loading, warm-up, leakage checks. | **IMPLEMENT** in Go Research. | Phase 4 |
| **Dataset Manager UI & Reporting** | Planned: Vue 3 / Quasar + Laravel API | **Planned in V3.3 (`MIG-28`)**. Upload UI, quality reports, manifest browser. | **IMPLEMENT** in Laravel / Vue. | Phase 2 |
| **Cross-Dataset Research Comparison** | Planned: Go `internal/research/comparison` | **Planned in V3.3 (`MIG-29`)**. Analytical views without data contamination. | **IMPLEMENT** in Go Research. | Phase 4/6 |
| **Candidate Strategies (5 Families)**| Go: `research-go/internal/candidates/` | **Implemented in Go**, candidate contract tests passing. | **STANDARDIZE** into Go Strategy SDK. | Phase 3 |
| **PRIMARY Strategies (13 Families)**| PHP: `app/Strategy/Research/` | **Implemented in PHP**. Scoped by exchange in Backtest/PAPER. | **MIGRATE & CONSOLIDATE** into Go Strategy SDK. | Phase 3 |
| **Declarative Strategy Engine** | Planned: Go `pkg/strategy/declarative` | **Planned in V3.2 (`MIG-18`)**. Evaluates JSON/CSV rule definitions. | **IMPLEMENT** in Go Strategy SDK. | Phase 3 |
| **Technical Indicators** | PHP (`app/Indicators/`) & Go (`algorithms/`) | **Duplicated math across PHP and Go**. | **CONSOLIDATE** into shared Go package. | Phase 1 & 3 |
| **Candle Ingestion & WS Streaming** | PHP: `BinanceCandleIngestion`, Artisan WS | **Implemented in PHP**. Bottleneck (P95 50.49ms on 500 rows). | **MIGRATE** to Go Market Data Engine. | Phase 2 |
| **Generic Config-Driven Adapter** | Planned: Go `pkg/exchange/generic` | **Planned in V3.2 (`MIG-21`)**. Parses CSV exchange templates. | **IMPLEMENT** in Go Multi-Exchange SDK. | Phase 6 |
| **Portfolio Risk Engine** | PHP: `app/PaperTrading/Application/RiskEngine.php` | **Implemented in PHP**. Polling queue execution. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Position Lifecycle State Machine**| PHP: `PositionLifecycleService.php` | **Implemented in PHP**. Sequential account loops. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Double-Entry Financial Accounting**| PHP: `LedgerRecord.php`, `DailyPerformanceService.php` | **Implemented in PHP**. System of record in PostgreSQL. | **MIGRATE** to Go Core (Phase 5 Shadow, Phase 7 Cutover). | Phase 5 |
| **Multi-Exchange Adapters** | PHP: `app/Exchanges/` | **Implemented in PHP** (Bybit & Binance). | **CONSOLIDATE** into unified Go Exchange SDK. | Phase 6 |
| **REST API, Auth & Control Plane** | PHP Laravel 11/13 + Sanctum | **Implemented in PHP**. Operating effectively as Web API. | **RETAIN IN PHP** (Thin Control Plane). | Control Plane |
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
| **Declarative Strategy Engine**| New in V3.2 | N/A (Planned scope) | Allows non-programmers to define strategies via UI/config without compiling code. | **IMPLEMENT** | Phase 3 |
| **Historical Dataset Schema & Contracts**| New in V3.3 (`MIG-24`)| N/A (Planned scope) | Standardizes immutable dataset manifests, schema validation, and provenance. | **IMPLEMENT** | Phase 1/2 |
| **Secure Historical Import & Validation**| New in V3.3 (`MIG-25`)| N/A (Planned scope) | Bounded streaming Go parser for CSV/Parquet with zero silent conflict guarantee. | **IMPLEMENT** | Phase 2 |
| **Dataset Storage & Versioning**| New in V3.3 (`MIG-26`)| N/A (Planned scope) | Isolated PostgreSQL partitions with immutable content hashing and zero live contamination. | **IMPLEMENT** | Phase 2 |
| **Research Dataset Selection & Loader** | New in V3.3 (`MIG-27`)| N/A (Planned scope) | Chronological data loading, indicator warm-up, and leakage protection for research. | **IMPLEMENT** | Phase 4 |
| **Dataset Manager UI & Reporting** | New in V3.3 (`MIG-28`)| N/A (Planned scope) | Quasar/Vue UI for dataset upload, field mapping, quality summary, and version browsing. | **IMPLEMENT** | Phase 2 |
| **Multi-Dataset Research Comparison** | New in V3.3 (`MIG-29`)| N/A (Planned scope) | Side-by-side analytical evaluation across datasets, exchanges, and timeframes. | **IMPLEMENT** | Phase 4/6 |
| **Profitability Research Engine**| PHP `ProfitabilityResearchApiTest` | Multi-second aggregations | Heavy SQL joins across 26 accounts. Go streaming attribution generates reports in sub-100ms. | **MIGRATE** | Phase 4 |
| **Candidate Search & Evolution**| PHP `StrategyEvolutionService` | High memory in PHP | Parameter mutation loops separated from Go backtest create IPC overhead. | **MIGRATE** | Phase 4 |
| **RiskEngine & Position Sizing** | PHP `RiskEngine.php` | Scheduled 15m polling | Polling delays fill acknowledgment. Go in-memory evaluation responds in milliseconds. | **MIGRATE** | Phase 5 |
| **Position Lifecycle & Fills** | PHP `PositionLifecycleService` | Sequential account loops | Sequential PHP transactions on 26 accounts cause queue latency spikes during volatile bars. | **MIGRATE** | Phase 5 |
| **Double-Entry Paper Ledger** | PHP `LedgerRecord.php` | Synchronous SQL | Must reside alongside Position Lifecycle to avoid distributed multi-phase commit overhead. | **MIGRATE** | Phase 5 |
| **Multi-Exchange Adapters** | PHP `app/Exchanges` | Disparate interfaces | Bybit and Binance use different contracts. Consolidate into standardized Go Exchange SDK. | **CONSOLIDATE** | Phase 6 |
| **Generic Config-Driven Adapter**| New in V3.2 | N/A (Planned scope) | Allows onboarding standard exchanges via validated CSV templates without code. | **IMPLEMENT** | Phase 6 |
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
│ 8. Historical Dataset Ingestion     │ PROPOSED > 100,000 candles/sec   │
│    (Standard Benchmark Profile)     │ Target Peak RSS < 256 MiB        │
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
2. **Technical Scope:** Execute production verification queries via operator checklist; diagnose Binance immutable candle conflicts; establish verified performance baselines; execute formal evidence-backed Operator Attestation protocol; maintain Phase 0 gate integrity.
3. **Existing Components to Reuse:** Full test suite (922 tests, 8,202 assertions), Playwright browser suite (`scripts/verify-browser-phase0.py`), SQL diagnostics (`scripts/diagnostics/phase0_production_acceptance.sql`, `scripts/diagnostics/phase0_evidence_closure.sql`), operator checklist (`docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md`).
4. **Components to Migrate / Replace:** None. Phase 0 is strict stabilization and verification of the existing baseline.
5. **Go Packages / Services:** Performance benchmarking utilities (`research-bench`) to profile batch ingestion.
6. **Interfaces & Versioned Contracts:** Canonical envelope (`data`, `error`, `meta`), QIM health endpoints, dual kill-switch state machine, and the Formal Operator Attestation Contract (`vector.attestation.phase0.v1`).
7. **State and Event Ownership:** PostgreSQL 17 system of record; Laravel owns all authoritative writes.
8. **Dependencies:** Prerequisite for Phase 1. Phase 1 implementation remains strictly blocked until formal Phase 0 Operator Attestation is completed.
9. **Implementation Work Packages:**
   - Package 0.1: Operator execution of production SQL diagnostic suite (`OPERATOR_CHECKLIST.md`).
   - Package 0.2: Structured log capture of Binance immutable candle conflicts to verify clock skew hypothesis.
   - Package 0.3: Scorecard freshness synchronization upon production evidence receipt.
   - Package 0.4: Formal Operator Attestation protocol execution, cryptographic/tamper-evident signing, and evidence archival.
10. **Exact Acceptance Criteria:**
    - Formal Phase 0 Operator Attestation signed and verified against the canonical 16-field schema (`OPERATOR_CHECKLIST.md` Section 4). Missing evidence strictly yields `NOT VERIFIED`.
    - All 45 Production V2 obligations verified via operator execution of `phase0_production_acceptance.sql` on the exact deployed Git SHA.
    - Zero cross-exchange position leaks across all 26 accounts.
    - Zero double-entry discrepancies (`abs(net_pnl - (gross_pnl - fees + funding)) <= 0.0001`).
    - Binance immutable observation conflict diagnosed with captured production telemetry.
    - Binance 500-candle ingestion evaluated honestly against frozen < 50.0 ms SLA.
    - Any unresolved exception recorded with explicit scope, rationale, and operator decision in the attestation record.
11. **Test Requirements:** 922 backend PHP tests passing, 64 frontend tests passing, 9 browser tests passing, Pint compliant.
12. **Performance & Resource Budgets:** PHP-FPM <= 512 MiB, PostgreSQL <= 1 GiB RSS, Redis <= 256 MiB.
13. **Shadow Execution & Rollback:** Immediate rollback to commit `a4b82ea` if operational regressions occur.
14. **Security & Data Integrity:** Strictly virtual PAPER mode across 26 accounts; zero live orders; ECONOM resources untouched.
15. **Git Commit Boundaries:** Local evidence admission and scorecard refresh commits.
16. **Evidence & Scorecard Mappings:** `VEC-P0-001` through `VEC-P0-209` (45 requirements, 99 obligations).
17. **Explicit Out-of-Scope Items:** No live trading, no real order placement, no database schema breaking changes, no manual production deployments, no automated application self-attestation.
18. **Operator Approval Requirements:** Formal Operator Attestation signed by authorized human operator referencing exact release SHA and immutable evidence checksums. Deployment alone does not constitute acceptance.

---

### Phase 1 — Go Core Foundation, Dynamic Identity & Versioned Contracts

**Global Phase 1 Invariants & Incremental Delivery Policy:**
- **Global Prerequisite:** Phase 0 formal Operator Attestation must be completed and accepted before any Phase 1 implementation begins.
- **Incremental Structure:** Phase 1 is deconstructed into three sequential, independently verifiable increments: **Phase 1.0**, **Phase 1.1**, and **Phase 1.2**.
- **Financial Writing Boundary:** No authority over PAPER financial writes or live balances is transferred to Go during any increment of Phase 1. PHP/Laravel remains the sole authoritative writer.

---

#### Phase 1.0 — Financial Decimal & Core Types (`pkg/decimal`, `pkg/types`)

1. **Business Objective:** Build deterministic, high-precision fixed-point decimal arithmetic and universal domain identity primitives in Go, providing bit-exact arithmetic parity with PostgreSQL `NUMERIC(24,12)` and PHP `brick/math` without any binary floating-point inaccuracy.
2. **Technical Scope:** Scope is strictly restricted to `pkg/decimal` and `pkg/types`. No IPC protocol, fencing mechanisms, dataset importer, strategy runtime, or unrelated infrastructure is permitted in this increment.
3. **Existing Components to Reuse:** `research-go/internal/decimal/`, `research-go/internal/contracts/job.go`, PHP `app/Support/helpers.php` (decimal rounding rules).
4. **Components to Migrate / Replace:** Duplicate DTO definitions, untyped strings for financial values, binary float calculations in research workers.
5. **Go Packages / Services:**
   - `pkg/decimal`: Scaled fixed-point decimal type (`ScaledDecimal`) compatible with PostgreSQL `NUMERIC(24,12)`.
   - `pkg/types`: Universal domain primitives (`ExchangeID`, `Symbol`, `Timeframe`, `Side`, `OrderState`, `AccountID`, `StrategyID`).
6. **Decimal Specification & Range Requirements:**
   - **Scale and Precision:** Exactly 12 decimal places of fractional scale ($10^{-12}$) and 24 total digits of precision (12 digits integer part, 12 digits fractional part).
   - **Supported Range:** $[-999,999,999,999.999999999999, +999,999,999,999.999999999999]$.
   - **Integer Capacity & Overflow Prevention:** A signed 64-bit integer (`int64` max $\approx 9.22 \times 10^{18}$) scaled by $10^{12}$ supports only up to $\approx \pm 9,223,372.036854775807$ ($\approx \pm 9.22 \times 10^6$ units), failing to cover the full PostgreSQL `NUMERIC(24,12)` domain ($\pm 10^{12}$ units, requiring $\approx 10^{24}$ capacity). The Go implementation **MUST** explicitly use either 128-bit integer capacity (via `math/big.Int` or two `uint64` / custom 128-bit representation) or enforce documented, strictly bounded numerical range limits with explicit error returns on overflow. Never silently overflow, wrap around, or lose decimal precision.
   - **Zero Binary Float:** Binary floating point (`float32`, `float64`) is strictly prohibited for authoritative financial representations and calculations.
   - **Supported Arithmetic:** Addition (`Add`), Subtraction (`Sub`), Multiplication (`Mul`), Division (`Div`), Comparisons (`Cmp`, `Equal`, `LessThan`, `GreaterThan`, `LessThanOrEqual`, `GreaterThanOrEqual`), Absolute Value (`Abs`), and Negation (`Neg`).
   - **Rounding Semantics:** Financial RoundHalfUp (and Banker's RoundHalfEven where required by specific accounting identity), explicit truncation rules, and intermediate precision rules (minimum 24 to 36 intermediate decimal places before final rounding to scale 12).
   - **Overflow & Underflow Behavior:** Explicit error returns (`ErrOverflow`, `ErrDivisionByZero`) or panic on unhandled overflow; zero bitwise truncation or modular wraparound.
   - **Serialization & Deserialization:** Deterministic JSON string marshaling/unmarshaling, text formatting (`String()`), binary encoding, and database driver interfaces (`database/sql/driver.Valuer` and `sql.Scanner`).
   - **Negative Values & Zero Canonicalization:** Negative zero (`-0`) is strictly normalized to positive `0`.
   - **Semantics Distinction:** Explicitly distinguish `NULL` (uninitialized/absent), `ZERO` (value $0.000000000000$), and `UNAVAILABLE` (stale/uncomputable).
   - **Consistency with PHP/Laravel:** Identical calculation results to PHP's `brick/math` (`BigDecimal`) and `bcmath` configured to scale 12.
7. **Interfaces & Versioned Contracts:** `Decimal` value object interface; `database/sql/driver.Valuer` and `sql.Scanner`.
8. **Dependencies:** Requires formal Phase 0 Operator Attestation. Prerequisite for Phase 1.1.
9. **Implementation Work Packages:**
   - Package 1.0.1: `pkg/decimal` fixed-point arithmetic engine with 128-bit capacity and overflow protection.
   - Package 1.0.2: Rounding, formatting, serialization, and SQL driver interfaces.
   - Package 1.0.3: `pkg/types` universal domain identifier primitives.
   - Package 1.0.4: Deterministic 1,000,000-operation parity test suite and PostgreSQL batched verification harness.
10. **Exact Acceptance Criteria:**
    - Zero unexplained arithmetic discrepancies across 1,000,000 deterministic test operations compared against PostgreSQL `NUMERIC(24,12)` reference calculations (executed via batched queries).
    - Zero silent precision loss or wraparound under boundary values ($\pm 999,999,999,999.999999999999$, $\pm 0.000000000001$, epsilon, division near zero).
    - All boundary, negative, overflow, and serialization test cases pass.
    - Numerical range limitations are explicit, documented, and enforced by code.
    - Parity with PHP `brick/math` verified on golden financial test vectors.
    - Zero authority over PAPER financial writes transferred to Go.
11. **Test Requirements:** 1,000,000-case parity harness with batched PostgreSQL reference evaluation; reproducible random seed (`0xDEADBEEF`); preserved golden fixtures; property-based tests (`testing/quick`); Go race detector.
12. **Performance & Resource Budgets:** Hot-path addition/subtraction throughput > 10,000,000 ops/sec; multiplication/division > 2,000,000 ops/sec; zero heap allocations on hot-path stack operations.
13. **Shadow Execution & Rollback:** Unit-level differential testing against PHP and PostgreSQL. Git revert on regression.
14. **Security & Data Integrity:** Strict bounds checking; no reflection in arithmetic paths; compile-time interface verification.
15. **Git Commit Boundaries:** `pkg/decimal` and `pkg/types` implementation and parity test fixtures committed atomically.
16. **Evidence & Scorecard Mappings:** Foundation for `VEC-P1-001` through `VEC-P1-008` and `MIG-01`.
17. **Explicit Out-of-Scope Items:** No IPC protocol, no Redis streams, no fencing tokens, no dataset importer, no strategy logic, no database writes.
18. **Operator Approval Requirements:** Engineering review of the 1,000,000-case parity verification evidence.

---

#### Phase 1.1 — Protocol & Fencing Contracts (`pkg/protocol`, `pkg/fencing`)

1. **Business Objective:** Establish versioned, backward-compatible inter-process communication contracts between PHP and Go, and implement monotonic fencing token mechanisms preventing dual-writer race conditions.
2. **Technical Scope:** Scope restricted strictly to `pkg/protocol` and `pkg/fencing`.
3. **Existing Components to Reuse:** `research-go/internal/contracts/job.go`, Redis 7.4.
4. **Components to Migrate / Replace:** Ad-hoc JSON payloads across process boundaries.
5. **Go Packages / Services:**
   - `pkg/protocol`: Versioned message envelopes (`v3.4.0`) with deterministic JSON serialization, schema validation, and SHA-256 payload digests.
   - `pkg/fencing`: Monotonic epoch-based fencing token generator and validator.
6. **Interfaces & Versioned Contracts:**
   - Canonical envelope schema: `{"v": "3.4.0", "epoch": 1042, "type": "EVENT_NAME", "idempotency_key": "uuid", "timestamp_utc": "ISO8601", "payload": {...}}`.
   - Stale-worker rejection protocol: any command bearing `epoch < current_fence_epoch` is rejected with `ErrStaleEpoch`.
   - Duplicate-command handling: idempotency key deduplication with TTL.
7. **State and Event Ownership:** Redis stream namespace reservation (`vector:stream:*`); zero PostgreSQL schema mutations.
8. **Dependencies:** Requires Phase 1.0 accepted. Prerequisite for Phase 1.2 and Phase 2.
9. **Implementation Work Packages:**
   - Package 1.1.1: `pkg/protocol` versioned envelope parser, serializer, and validator.
   - Package 1.1.2: `pkg/fencing` epoch generator, verification primitives, and concurrent fencing test harness.
   - Package 1.1.3: Cross-process PHP/Go contract compatibility test suite.
10. **Exact Acceptance Criteria:**
    - IPC serialization benchmark PROPOSED < 5 microseconds per envelope.
    - Contract compatibility tests pass: PHP serializer output decoded bit-exact by Go parser, and vice versa.
    - Fencing generator rejects stale worker tokens under simulated concurrency with 100% reliability.
    - Duplicate commands with identical idempotency keys are handled idempotently without re-execution.
    - Zero authority over PAPER financial writes transferred to Go.
11. **Test Requirements:** Fuzzing of protocol envelope decoders (`go-fuzz`); concurrent fencing race tests; PHP/Go roundtrip contract tests; Go race detector (`go test -race`).
12. **Performance & Resource Budgets:** Envelope serialization < 5 µs; memory allocations < 512 bytes per message.
13. **Shadow Execution & Rollback:** Dual-parsing of contracts in PHP and Go; git revert rollback.
14. **Security & Data Integrity:** Schema validation rejects unknown fields; payload digests prevent tampering; zero execution of arbitrary commands.
15. **Git Commit Boundaries:** `pkg/protocol` and `pkg/fencing` packages with roundtrip contract fixtures.
16. **Evidence & Scorecard Mappings:** Maps to `MIG-02`.
17. **Explicit Out-of-Scope Items:** No live trading, no database triggers, no strategy logic.
18. **Operator Approval Requirements:** Lead architecture review of IPC protocol and fencing contracts.

---

#### Phase 1.2 — Dataset Foundation Contracts (`pkg/dataset`)

1. **Business Objective:** Define foundational data types, canonical manifest structures, cryptographic checksum schemas, and validation contracts for historical research datasets without implementing file parsers or UI.
2. **Technical Scope:** Scope restricted strictly to foundational contracts in `pkg/dataset`. Full CSV/JSON/Parquet streaming importer, storage engine, and UI remain deferred to Phase 2 (`MIG-25`, `MIG-26`, `MIG-28`).
3. **Existing Components to Reuse:** Section 9 Dataset Specification of Roadmap V3.3.
4. **Components to Migrate / Replace:** None. Foundational contract for future dataset capabilities.
5. **Go Packages / Services:**
   - `pkg/dataset`: Canonical dataset manifest structures, source exchange enums, provenance status types, and SHA-256 verification schemas (`MIG-24`).
6. **Interfaces & Versioned Contracts:** Canonical `DatasetManifest` Go struct matching schema `vector.dataset.manifest.v1`:
   - Fields: `dataset_id`, `dataset_version`, `source_exchange_id`, `market_type`, `symbols`, `timeframes`, `start_time_utc`, `end_time_utc`, `row_count`, `canonical_checksum_sha256`, `provenance_status`, `created_at_utc`.
7. **State and Event Ownership:** Manifest DTO contracts only; zero database schema mutations.
8. **Dependencies:** Requires Phase 1.0 and Phase 1.1 accepted. Prerequisite for Phase 2 dataset ingestion.
9. **Implementation Work Packages:**
   - Package 1.2.1: `pkg/dataset` manifest models, validation logic, and canonical JSON serialization.
   - Package 1.2.2: Provenance verification types and checksum generator contracts.
   - Package 1.2.3: Contract validation and schema roundtrip test suite.
10. **Exact Acceptance Criteria:**
    - `DatasetManifest` serializes and validates bit-exact JSON matching the canonical specification.
    - Checksum generator calculates reproducible SHA-256 hashes across varying line endings and platforms.
    - Invalid manifest attributes (unsupported market types, inverted time windows, invalid checksum formats) fail validation with structured error codes.
    - Zero authority over PAPER financial writes or live market data.
11. **Test Requirements:** JSON schema roundtrip tests; manifest validation negative test suite; hash reproducibility tests across architectures.
12. **Performance & Resource Budgets:** Manifest validation < 1 ms; zero external network calls.
13. **Shadow Execution & Rollback:** Git revert rollback.
14. **Security & Data Integrity:** Strict field validation; path traversal protections in dataset ID naming.
15. **Git Commit Boundaries:** `pkg/dataset` foundational package and contract test fixtures.
16. **Evidence & Scorecard Mappings:** Maps to `MIG-24`.
17. **Explicit Out-of-Scope Items:** No file parsing (CSV/Parquet), no decompression, no database table creation, no Vue UI.
18. **Operator Approval Requirements:** Review of dataset manifest canonical schema.

---

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
   - `internal/dataset/importer`: Bounded streaming CSV, JSON, and Parquet parser (`MIG-25`).
   - `internal/dataset/validation`: Deterministic data quality engine: zero silent conflicts, gap detection, OHLC integrity, conflict quarantine (`MIG-25`).
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
    - Historical CSV parser processes > 100,000 candles/sec under the Standard Benchmark Profile with Target Peak RSS < 256 MiB (container hard ceiling 512 MiB).
    - Uploaded historical datasets are strictly quarantined from `market_candles` and `paper_*` tables.
    - Corrupted OHLC rows, timestamp gaps, and archive security violations (path traversal, decompression bombs > 2 GB) are rejected with explicit error diagnostics.
    - Allowable rate of silent observation conflicts is exactly zero.
11. **Test Requirements:** Mock WebSocket servers emitting malformed payloads; clock skew injections; zip bomb (> 2 GB) and path traversal security test suite; legitimate highly compressible CSV test suite; corrupt OHLC boundary tests; conflict quarantine tests.
12. **Performance & Resource Budgets:** Target peak RSS < 256 MiB under benchmark profile; container hard limit 512 MiB; CPU load < 0.3 cores under simultaneous live ingestion and offline file import.
13. **Shadow Execution & Rollback:** Parallel ingestion to shadow database table; hash comparison against PHP; one-switch cutover via configuration.
14. **Security & Data Integrity:** Public endpoints only; zero trading credentials; untrusted file processing with strict 500 MB upload ceiling and bounded 2 GB extraction limit; SSRF protection on any remote fetches.
15. **Git Commit Boundaries:** Live market data engine packages, dataset ingestion packages, Dataset Manager UI, and test suites.
16. **Evidence & Scorecard Mappings:** Resolves `VEC-P0-009-V1` permanently; feeds live market stream to `VEC-P1-001` through `VEC-P1-008`; maps to `MIG-25`, `MIG-26`, `MIG-28`.
17. **Explicit Out-of-Scope Items:** No private exchange endpoints, no orderbook L2/L3 tick streaming, no real trading keys.
18. **Operator Approval Requirements:** Production configuration review before enabling Go ingestion daemon.

---

### Phase 3 — Extensible Go Strategy SDK & Declarative Strategy Builder

1. **Business Objective:** Implement all 13 PRIMARY strategy families in Go with bit-exact parity against PHP golden fixtures, while providing both an extensible Go SDK and a Declarative Strategy Builder MVP (JSON/CSV configuration & UI) allowing new strategies to evaluate identically across live market streams and imported historical datasets without dynamic code execution.
2. **Technical Scope:** Strategy SDK interfaces (`StrategyDescriptor`, `StrategyInstance`, `StrategyRegistry`), native Go implementations of all 13 PRIMARY families, Declarative Strategy MVP Engine (`pkg/strategy/declarative`), Visual Strategy Builder UI contracts, concurrent real-time evaluation engine, and differential testing harness.
3. **Existing Components to Reuse:** 5 Go candidate families in `research-go/internal/candidates/`, parameters in `config/strategy.php`, existing indicator calculators (`EmaCalculator.php`, `RsiCalculator.php`, `AtrCalculator.php`).
4. **Components to Migrate / Replace:** Remaining 8 PHP strategy families (`BaselineStrategy`, `TrendPullbackStrategy`, `BollingerMeanReversionStrategy`, `DonchianBreakoutStrategy`, `RegimeSwitchingStrategy`, `MomentumStrategy`, `SupportResistanceBounceStrategy`, `SupportResistanceBreakoutStrategy`).
5. **Go Packages / Services:**
   - `pkg/strategy`: Universal Go Strategy SDK (`StrategyDescriptor`, `StrategyInstance`, `Evaluate(input)`).
   - `pkg/strategy/declarative`: Bounded declarative rule evaluation engine parsing versioned JSON/CSV rule specifications.
   - `internal/strategy/primary`: All 13 PRIMARY strategy families implemented natively in Go.
   - `internal/strategy/runtime`: Concurrent strategy evaluation engine triggered by candle events or dataset iteration.
6. **Declarative Strategy MVP Specification:**
   - **Supported Indicators (Exactly Three):** `EMA` (Exponential Moving Average), `RSI` (Relative Strength Index), and `ATR` (Average True Range), directly sourced from existing SDK implementations.
   - **Comparison Operators (Exactly Two):** `GREATER_THAN` (`GT`) and `LESS_THAN` (`LT`).
   - **Boolean Composition:** `AND` and `OR` logical operators.
   - **Parameters:** Strictly typed static parameters (periods, thresholds, price sources: `close`, `open`, `high`, `low`).
   - **Missing Data & Warm-Up:** Explicit handling of insufficient historical bars or null values emitting deterministic `SKIP` with reason code (`INSUFFICIENT_HISTORY`, `NULL_INDICATOR`).
   - **Schema & Validation:** Versioned JSON schema (`declarative-strategy-v1.schema.json`). Rejects invalid syntax, unsupported indicators, or unknown fields at load time.
   - **Safety Boundary:** Strictly pure functional evaluation. No arbitrary executable expressions, no dynamic code evaluation (`eval`), no reflection, and no implicit financial authority.
7. **Interfaces & Versioned Contracts:** `StrategyDecision` struct matching canonical JSON schema (BUY, SELL, SKIP with deterministic reason codes, price targets, ATR stop-loss).
8. **State and Event Ownership:** Go publishes `SignalGeneratedEvent` to Redis Stream; Laravel reads signals for UI/API display.
9. **Dependencies:** Requires Phase 2 candle stream / dataset loader; prerequisite for Phase 4 evolution and Phase 5 paper execution.
10. **Implementation Work Packages:**
    - Package 3.1: Strategy SDK interface specifications and registration engine.
    - Package 3.2: Implementation of the 8 remaining PRIMARY families in Go.
    - Package 3.3: Golden fixture test generator extracting 10,000 historical decisions from PHP.
    - Package 3.4: Declarative Strategy Rules Engine MVP (`MIG-18` MVP) and schema validator.
    - Package 3.5: Visual Strategy Builder UI contract and API endpoint in Laravel (`MIG-19` MVP).
    - Package 3.6: Extensibility validation using a declarative test strategy (`declarative_ema_rsi_atr_test`).
11. **Exact Acceptance Criteria:**
    - All 13 PRIMARY families implemented in Go; 100% bit-exact decision equivalence across 10,000 historical golden candle fixtures compared against PHP golden output.
    - Declarative Strategy MVP evaluates with exact behavior parity against equivalent native Go strategy logic.
    - Deterministic repeated evaluations under identical input candles produce identical signal digests.
    - Configurations with unsupported indicators, syntax errors, or lookahead references fail closed with structured error codes (`REQUIRES_CUSTOM_STRATEGY`, `INVALID_RULE_CONFIG`).
    - Concurrent evaluation of all 13 families PROPOSED P95 < 5.0 ms per closed candle.
12. **Test Requirements:** Differential testing comparing Go output against PHP output byte-for-byte; declarative rule mutation tests; boundary condition verification.
13. **Performance & Resource Budgets:** All 13 strategies evaluated concurrently in PROPOSED < 10 ms; memory allocation < 1 MiB per evaluation.
14. **Shadow Execution & Rollback:** Go runs in shadow mode emitting signals to shadow stream; diffs logged; Laravel forward scanner continues driving paper orders until Phase 5.
15. **Security & Data Integrity:** Pure function evaluation; zero I/O, network calls, or arbitrary script execution in strategy logic; deterministic RNG seeded by candle hash.
16. **Git Commit Boundaries:** Go strategy implementations committed family by family with corresponding golden fixtures.
17. **Evidence & Scorecard Mappings:** Maps to `VEC-P1-001` through `VEC-P1-008` and `MIG-18`, `MIG-19`.
18. **Operator Approval Requirements:** Sign-off on 100% parity verification results and Declarative MVP parity report.

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

1. **Business Objective:** Implement the complete portfolio risk engine, order lifecycle state machine, and double-entry accounting in Go; support dynamic multi-account topologies ($N$ Strategies $\times$ $M$ Exchanges); run in shadow mode while PHP remains authoritative; guarantee that imported historical datasets can NEVER write to paper trading accounts or ledgers; verify parallel operations via a deterministic reconciliation diff tool.
2. **Technical Scope:** Dynamic account mapping, deterministic position state machine, 5%/10% drawdown enforcement, double-entry financial ledger, taker fee and funding settlement, shadow reconciliation across 15 canonical dimensions, deterministic divergence diff tooling (`cmd/vector-reconcile`), and offline reproduction.
3. **Existing Components to Reuse:** PostgreSQL financial schemas (`paper_positions`, `paper_executions`, `paper_account_ledgers`), risk parameters in `config/paper.php`.
4. **Components to Migrate / Replace:** `PositionLifecycleService.php`, `RiskEngine.php`, `PaperProcessingService.php`, `DailyPerformanceService.php`.
5. **Go Packages / Services:**
   - `internal/paper/risk`: Portfolio risk evaluation, max drawdown enforcement, account isolation, leverage checks.
   - `internal/paper/lifecycle`: Order state machine (Pending -> Open -> TP1/TP2 -> Closed), trailing stops, timeout liquidation.
   - `internal/paper/accounting`: Double-entry transaction ledger engine ($\text{Equity} = \text{Balance} + \text{Unrealized PnL}$).
   - `internal/paper/settlement`: Certified 8h/4h funding rate fee calculations, taker commission deductions, slippage modeling.
   - `cmd/vector-reconcile`: Read-only deterministic reconciliation diff and divergence diagnostic tool.
6. **Shadow Reconciliation & Divergence Protocol:**
   - **Canonical 15-Point Comparison Dimensions:**
     1. Exchange identity.
     2. Account ID.
     3. Strategy ID.
     4. Instrument (Symbol).
     5. Event identity and sequence order.
     6. Position state transition (e.g. `PENDING -> OPEN -> TP1 -> CLOSED`).
     7. Quantity (contract lots and remaining open size).
     8. Gross PnL.
     9. Fees (taker / maker).
     10. Funding settlements.
     11. Realized PnL.
     12. Unrealized PnL (mark price valuation).
     13. Equity ($\text{Balance} + \text{Unrealized PnL}$).
     14. Decimal rounding decisions (intermediate precision, truncation, half-up steps).
     15. Applicable source market observations (closed candle marks, funding rates).
   - **Reconciliation Diff Tool (`cmd/vector-reconcile`):**
     - Compares PHP authoritative events against Go shadow events across aligned observation windows.
     - Identifies the chronologically FIRST divergent event.
     - Displays side-by-side calculations, input values, formulas, and decimal scales.
     - Classifies root causes into structured categories: Data Mismatch, Timing Mismatch, Precision Mismatch, Missing Event, Duplicate Event, or State-Machine Divergence.
     - Exports machine-readable diffs (`reconciliation_diff_<timestamp>.json`) and human-readable operator reports.
     - Reproduces divergence offline using captured immutable event fixtures.
     - Strictly read-only: CANNOT mutate the authoritative PAPER ledger.
7. **Interfaces & Versioned Contracts:** `RiskEvaluator`, `OrderExecutor`, `LedgerService`; transactional event signatures.
8. **State and Event Ownership:** **Strict Shadow Mode.** PHP remains the sole authoritative writer to `paper_accounts`, `paper_positions`, `paper_executions`, `paper_account_ledgers`. Go writes shadow records to isolated staging tables (`shadow_paper_*`) and emits reconciliation reports. Single authoritative writer invariant strictly preserved.
9. **Dependencies:** Requires Phase 3 strategy signals and Phase 2 real-time candle marks; prerequisite for Phase 7 cutover.
10. **Implementation Work Packages:**
    - Package 5.1: Scalable multi-account portfolio risk engine supporting dynamic account topologies.
    - Package 5.2: Order lifecycle state machine with partial closes and deterministic SL/TP priority.
    - Package 5.3: Bit-exact double-entry accounting engine in Go (`pkg/decimal`).
    - Package 5.4: Shadow execution harness, automated ledger diff reporter, and `cmd/vector-reconcile` diagnostic tool.
    - Package 5.5: Blocked strategy diagnostic service recording structured blocker evidence.
11. **Exact Acceptance Criteria:**
    - Dynamic account scaling tested with up to 80 accounts with zero cross-contamination.
    - Zero ledger discrepancies across 100,000 synthetic lifecycle events.
    - Shadow execution runs for **14 continuous days** without financial divergence from PHP live ledger.
    - **Alert Threshold:** Any divergence $> 0.0001\text{ USDT}$ immediately raises a high-priority alert; financial accounting identities require exact equality ($0.000000000000$).
    - **Investigation Mandate:** A one-cent ($0.01\text{ USDT}$) mismatch must be fully investigated and cannot be waived merely because it appears financially small.
    - **Window Restart Policy:** Any material unexplained financial divergence or state-machine defect immediately restarts the 14-day continuous observation window from zero (Day 0).
    - Blocked strategy diagnostics record exact metric, threshold, and timestamp for any risk halt.
    - Absolute isolation: imported historical datasets are strictly blocked from writing to `paper_accounts` or triggering fills.
12. **Test Requirements:** PostgreSQL concurrency race tests (`FOR UPDATE` row locking), double-funding negative controls, partial exit precision tests, offline replay of captured divergent events.
13. **Performance & Resource Budgets:** Position processing latency PROPOSED P95 < 15 ms; memory footprint < 128 MiB; zero lock contention on concurrent accounts.
14. **Shadow Execution & Rollback:** Continuous comparison of Go shadow balance vs PHP live balance; discrepancy > 0.0001 USDT raises immediate alert. Rollback is trivial since PHP remains the live writer.
15. **Security & Data Integrity:** Zero real trading keys; accounts strictly isolated; preserved 5% entry-block and 10% halt risk thresholds.
16. **Git Commit Boundaries:** Risk engine, position lifecycle engine, accounting ledger engine, reconciliation diff tool, and migration scripts.
17. **Evidence & Scorecard Mappings:** Direct mapping to core Phase 0 financial requirements and Phase 4 operational controls.
18. **Explicit Out-of-Scope Items:** No direct writes to live production tables during Phase 5.
19. **Operator Approval Requirements:** Review of 14-day zero-defect shadow reconciliation report generated by `cmd/vector-reconcile`.

---

### Phase 6 — Extensible Multi-Exchange SDK & Declarative Exchange Onboarding

1. **Business Objective:** Implement standardized multi-exchange adapter contracts in Go, provide a Declarative Exchange Builder MVP (CSV/JSON mapping) allowing standard cryptocurrency perpetual exchanges to be onboarded in isolated research scope without custom code, support offline exchange identities for research without API connections, and surface high-throughput comparative analytics via Laravel API.
2. **Technical Scope:** Universal Exchange SDK (`ExchangeDescriptor`, `ExchangeAdapter`, `ExchangeRegistry`, `ExchangeCapabilities`), Declarative Exchange MVP Adapter (`pkg/exchange/generic`), CSV Onboarding Parser with strict SSRF validation, offline research source registry, native Bybit/Binance adapters, third test-only exchange adapter verification, streaming financial analytics, and slippage decomposition.
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
6. **Declarative Exchange MVP Specification:**
   - **Scope:** Minimal approved CSV/JSON mapping contract.
   - **Supported Metadata:** Exchange identity (`exchange_id`, `display_name`), market category (`linear_perpetual`), symbol mapping (`symbol_map`).
   - **Market Data Mapping:** Timestamp/timezone semantics in UTC millisecond epoch, OHLCV field mapping (`open`, `high`, `low`, `close`, `volume`).
   - **Provenance & Quality:** Validation checksums and provenance metadata.
   - **Strict Operational Boundary:** Operates **strictly in isolated research/import scope**.
   - **CRITICAL SAFETY RESTRICTION:** The MVP must **NOT** automatically activate an exchange for PAPER trading or authoritative market-data ingestion.
   - **Activation Gate:** Production PAPER exchange activation retains separately defined validation and operator gates.
   - **Architecture Invariant:** No unrestricted scripting language or second general-purpose programming environment inside VECTOR.
7. **Interfaces & Versioned Contracts:** Normalized multi-exchange DTOs; analytics query API contracts; Canonical Exchange Configuration Schema.
8. **State and Event Ownership:** Go maintains read-optimized analytical materialized caches in Redis; PostgreSQL remains system of record.
9. **Dependencies:** Requires Phase 5 financial accounting; prerequisite for Phase 7 cutover.
10. **Implementation Work Packages:**
    - Package 6.1: Unified Exchange SDK contracts and capability declaration system.
    - Package 6.2: Declarative Exchange Configuration Schema & Minimal CSV Parser MVP (`MIG-20` MVP).
    - Package 6.3: Generic Config-Driven Exchange Adapter Runtime MVP (`MIG-21` MVP).
    - Package 6.4: Exchange Onboarding UI, SSRF validator, and Approval Lifecycle in Laravel (`MIG-22` MVP).
    - Package 6.5: Config-Driven Contract Conformance Test Harness (`MIG-23`).
    - Package 6.6: Standardization of Bybit and Binance adapters under the new SDK.
    - Package 6.7: Third test-only exchange onboarding via CSV template (`stub_exchange_csv`).
    - Package 6.8: Offline Exchange Research Identity registration (e.g. OKX historical research without live adapter).
11. **Exact Acceptance Criteria:**
    - Third test-only exchange onboarded via CSV template passes complete contract conformance suite without writing custom Go code.
    - Declarative Exchange MVP functions cleanly in isolated research scope with zero leaks into PAPER trading.
    - Offline research exchange identity (OKX) supports full backtesting on imported data without active API adapter.
    - Unsupported APIs cleanly return `REQUIRES_CUSTOM_ADAPTER` without runtime failure.
    - Strict SSRF protection rejects internal, loopback, private, or metadata IPs in endpoint URLs.
    - Unified metric calculation for 26 accounts over 90 days generated in PROPOSED < 100 ms.
12. **Test Requirements:** Multi-exchange contract conformance test suite; CSV schema fuzzing; SSRF attack vector suite; extreme market data gap/delisting simulations; unsupported capability fail-closed tests.
13. **Performance & Resource Budgets:** Analytical query response PROPOSED P95 < 50 ms; Redis cache size < 64 MiB.
14. **Shadow Execution & Rollback:** Frontend toggles between PHP and Go analytics via feature flag; zero state mutations.
15. **Security & Data Integrity:** Public market data only; zero trading credentials; CSV upload cannot execute arbitrary code, trigger network requests without approval, or bypass owner gates.
16. **Git Commit Boundaries:** Exchange adapter packages, analytics engine, and API integration.
17. **Evidence & Scorecard Mappings:** Maps to `VEC-P2-001` through `VEC-P2-010`, `VEC-P5-001` through `VEC-P5-008`, and `MIG-20`–`MIG-23`.
18. **Explicit Out-of-Scope Items:** No live real-money order routing to any exchange; no automatic promotion to PAPER trading.
19. **Operator Approval Requirements:** Formal owner approval required to transition any exchange from `RESEARCH_READY` to `PAPER_ELIGIBLE`.

---

### Phase 7 — Controlled Cutover & PHP Business-Logic Retirement

1. **Business Objective:** Transfer authoritative trading and financial write ownership to Go under database-enforced fencing tokens; retire duplicate PHP computational logic while maintaining Laravel as a lean control plane.
2. **Technical Scope:** Monotonic fencing epoch handover, PostgreSQL trigger enforcement, Laravel financial write revocation, Go Core daemon supervisor integration, and duplicate PHP code decommissioning.
3. **Existing Components to Reuse:** Laravel routing, auth middleware, Vue 3 frontend UI, Docker Compose deployment framework.
4. **Components to Migrate / Retire:** Decommission duplicate PHP computational logic (`TradingPipelineEventService.php`, `PositionLifecycleService.php`, `RiskEngine.php`, `app/Strategy/Research/`, `BinanceCandleIngestion.php`).
5. **Go Packages / Services:** Unified Go Daemon (`cmd/vector-core`) orchestrating Ingestion, Strategies, Risk, and PAPER Lifecycle as a lean, single-process, multi-goroutine binary.
6. **Interfaces & Versioned Contracts:** Laravel acts as a client/reverse-proxy to the Go daemon via lightweight local Unix Domain Socket or HTTP on localhost.
7. **State and Event Ownership:** Go becomes the **sole authoritative writer** to trading and financial tables; database-enforced fencing token prevents any PHP writes. Laravel PostgreSQL user permissions restricted to `SELECT` on financial ledgers.
8. **Dependencies:** Requires successful completion, 14-day zero-defect continuous shadow operation verified by `cmd/vector-reconcile`, zero active divergences or unresolved exceptions across Phases 1 through 6. Single authoritative writer invariant strictly preserved.
9. **Implementation Work Packages:**
   - Package 7.1: PostgreSQL fencing trigger deployment (`enforce_account_writer_fencing()`).
   - Package 7.2: Account-by-account cutover orchestration CLI in Laravel.
   - Package 7.3: Activation of Go daemon as primary writer.
   - Package 7.4: Verification of 14-day zero-defect continuous operation post-cutover.
   - Package 7.5: Decommissioning and removal of duplicate PHP computational classes.
10. **Exact Acceptance Criteria:**
    - Go daemon running continuously for 14 days with zero crashes, zero data-loss events, and zero ledger discrepancies.
    - End-to-end candle-to-ledger latency PROPOSED P95 < 20 ms across all accounts.
    - PostgreSQL trigger rejects any attempted write with stale epoch or unauthorized writer identity.
    - Exactly ONE authoritative financial writer exists at any given millisecond.
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

### 6.5 Declarative Strategy MVP Specification

To prevent speculative abstraction and delivery risk, the Declarative Strategy Builder begins with a strictly bounded Minimal Viable Product (MVP) before expanding to complex grammars:

1. **Initial Indicator Set (Exactly Three Existing SDK Indicators):**
   - `EMA` (Exponential Moving Average) — derived from `app/Indicators/Calculators/EmaCalculator.php`.
   - `RSI` (Relative Strength Index) — derived from `app/Indicators/Calculators/RsiCalculator.php`.
   - `ATR` (Average True Range) — derived from `app/Indicators/Calculators/AtrCalculator.php`.
   *(No new mathematical indicators are invented; all logic reuses certified implementations).*

2. **Comparison Operators (Exactly Two):**
   - `GREATER_THAN` (`GT`): Left operand value strictly greater than right operand.
   - `LESS_THAN` (`LT`): Left operand value strictly less than right operand.

3. **Boolean Composition:**
   - Standard `AND` and `OR` boolean grouping trees. Arbitrary nested expressions without clear boolean operators are rejected.

4. **Static Typed Parameters:**
   - Numerical thresholds, periods (positive integers), price series enums (`close`, `open`, `high`, `low`).

5. **Deterministic Signal Semantics:**
   - Evaluation triggered strictly on closed candles.
   - Insufficient history or null indicator marks emit `SKIP` with structured reason codes (`INSUFFICIENT_HISTORY`, `NULL_INDICATOR`).

6. **Strict Schema Validation & Safety Boundaries:**
   - Validated against canonical schema `declarative-strategy-v1.schema.json`.
   - **Zero Dynamic Code Evaluation:** No `eval()`, JavaScript execution, Python bridging, shell calls, or arbitrary AST interpretation.
   - **Zero Implicit Authority:** A declarative strategy has no direct authority over orders, positions, or risk parameters; it emits candidate signals only.

7. **Mandatory Parity Acceptance Criteria:**
   - Must achieve 100% bit-exact decision parity against an equivalent native Go strategy implementation across 10,000 golden candle fixtures.
   - Deterministic execution across repeated runs with identical input data.
   - Rejection of invalid, unparseable, or out-of-range configurations at schema validation time.
   - Bounded execution time (< 1 ms per evaluation).
   - Expansion to advanced indicator catalogs (`MACD`, `BOLLINGER_BANDS`, `ADX`, `VWAP`), operators (`CROSS_ABOVE`, `CROSS_BELOW`), and visual builder UI occurs only after formal MVP acceptance.

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

### 7.5 Declarative Exchange MVP Specification

To avoid creating an unconstrained scripting engine or unverified integration surface, the Declarative Exchange Builder begins strictly as an isolated Research/Import MVP:

1. **Minimal CSV/JSON Mapping Contract:**
   - **Exchange Identity:** `exchange_id` (slug), `display_name`, `market_type` (`linear_perpetual`).
   - **Symbol Resolution:** Explicit mapping of exchange-specific ticker strings to canonical symbols (e.g. `XBTUSDT` $\rightarrow$ `BTCUSDT`).
   - **Timestamp & Timezone Semantics:** Explicit UTC millisecond epoch timestamps. Local timezones or ambiguous string dates without offset are rejected.
   - **OHLCV Field Mapping:** Canonical column mapping (`open`, `high`, `low`, `close`, `volume`).
   - **Metadata & Provenance:** Source checksums, license/terms tags, and data provider attribution.

2. **Strict Operational Boundary:**
   - The Declarative Exchange MVP operates **strictly within offline research, dataset ingestion, and backtesting scope**.
   - **Zero Live Ingestion:** MVP does not open live WebSocket streams or connect to live exchange REST endpoints.

3. **Mandatory Paper Trading Safety Invariant:**
   - The Declarative Exchange MVP **MUST NOT** automatically provision or activate an exchange for PAPER trading accounts.
   - Enabling an exchange for forward virtual PAPER trading requires explicit human operator governance, full 10-criteria conformance testing, and verified risk engine parameterization under a separate approved operational gate.

4. **Prohibition of Embedded Scripting:**
   - VECTOR strictly rejects embedding general-purpose programming languages (Lua, Python, JavaScript, shell scripts) inside exchange templates.
   - Complex non-standard protocols, specialized authentication handshakes, or non-linear settlement calculations cleanly fall back to native Go SDK implementations (`pkg/exchange`).

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

### 9.5 Data Quality & Zero-Tolerance Conflict Policy
The Go validation pipeline (`internal/dataset/validation`) applies deterministic checks to every observation:
1. **Zero-Tolerance Conflict Invariant:** The allowable rate of silent data conflicts is **EXACTLY ZERO**. Every contradictory observation must be logged, counted, and audited.
2. **Duplicate vs Contradictory Observation Distinction:**
   - **Identical Duplicates:** Multiple rows sharing the identical timestamp and identical OHLCV values. These are handled idempotently, deduplicated cleanly, and recorded in `quality_summary.duplicate_rows`.
   - **Contradictory Observations (Genuine Conflicts):** Rows sharing the identical timestamp but containing differing OHLCV values. These are strictly logged in `dataset_conflicts` with exact fields: `dataset_id`, `dataset_version`, `source_exchange_id`, `instrument_id`, `timeframe`, `observation_timestamp`, `source_row_number`, and conflicting values.
3. **No Silent Resolution:** The system **never** silently chooses one contradictory record over another, and **never** silently averages, interpolates, merges, or overwrites conflicting values.
4. **Escalation Thresholds & Action Policies:**
   - **Zero Conflicts (100% clean):** Dataset receives `validation_status = 'VALIDATED'`.
   - **Conflicts > 0 and <= 1%:** Dataset receives `validation_status = 'ACCEPT_WITH_LIMITATIONS'` ONLY IF conflicting records are explicitly isolated into a quarantine partition and the remaining research scope is certified with documented caveats. Unresolved conflicts permanently block clean certification.
   - **Conflicts > 1%:** Dataset automatically escalates to `validation_status = 'QUARANTINE'`. Research execution is blocked pending operator investigation.
   - **Fatal Flaws:** Corrupted headers, unparseable lines, or security violations trigger immediate `REJECT`.
5. **Timestamp Normalization & Gap Reporting:** Strict UTC parsing (`RFC3339` or millisecond Unix epochs). Missing bars are counted and recorded in `timestamp_gaps`. Under **NO CIRCUMSTANCES** does VECTOR fabricate, interpolate, or synthesize missing candles to fill gaps. Research engines must evaluate with authentic historical gaps.
6. **OHLC Relationship Integrity:**
   $$low \le open \le high \quad \text{and} \quad low \le close \le high \quad \text{and} \quad high \ge low$$
   $$volume \ge 0.0$$
7. **Finite Numerical Precision:** Rejection of `NaN`, `+Inf`, `-Inf`, nulls, or values exceeding 12-decimal scaled precision.

### 9.6 Secure File Processing & Bounded Decompression Architecture
Uploaded datasets are treated as untrusted external input:
- **Resource Constraints & Limits:**
  - Maximum upload size: **500 MB** (compressed archive) or **1.5 GB** (uncompressed CSV/Parquet).
  - Maximum total decompressed size: **2.0 GB** per archive across all extracted entries.
  - Maximum single entry decompressed size: **1.5 GB**.
  - Maximum extracted file count: **100 files** per ZIP archive.
  - Maximum wall-clock extraction timeout: **120 seconds** per archive job.
- **Bounded Decompression Security (Replacing Ratio-Only Rejection):**
  - **No False Rejection on Repetitive Data:** Legitimate historical market datasets with highly repetitive price structures can naturally achieve compression ratios exceeding 10:1 (e.g. 15:1 to 20:1). VECTOR does **not** automatically reject an archive solely because its compression ratio exceeds 10:1.
  - **Streaming `LimitReader` Protection:** Decompression is executed via a streaming counting reader (`io.LimitReader`). If cumulative decompressed bytes exceed the 2.0 GB ceiling at any moment during extraction, the process aborts immediately with error `ARCHIVE_DECOMPRESSED_SIZE_EXCEEDED`.
  - **Ratio as Anomaly Signal:** Compression ratios > 10:1 are logged as informational audit warnings for operator review, not hard rejections.
  - **Archive Traversal Defenses:** Archive entries containing `../`, leading slashes `/`, null bytes, or symbolic links escaping the extraction boundary are rejected immediately (`UNSAFE_ARCHIVE_PATH`).
  - **Structure Protections:** Encrypted archives, password-protected entries, and nested archives (ZIP within ZIP) are rejected by default (`UNSUPPORTED_ARCHIVE_STRUCTURE`).
  - **Declared vs Actual Size Check:** Archive entries whose actual decompressed stream length diverges from the archive header declaration by more than 10% are flagged as suspicious.
- **Executable & Script Defense:** Magic-byte sniffing rejects ELF, PE, Mach-O, shell scripts, or binary executables disguised as CSV/ZIP.
- **CSV Formula Injection Defense:** Characters `=, +, -, @` at the beginning of fields are sanitized upon export to prevent spreadsheet formula execution.
- **Zero Credentials:** Uploaded datasets must never contain or require exchange trading credentials.

### 9.7 Resource & Memory Performance Contract
Memory consumption during dataset ingestion is managed via a strict, measurable performance contract rather than an unconditional fixed-memory guarantee:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   MEMORY RESOURCE PERFORMANCE CONTRACT                 │
├─────────────────────────────────────┬──────────────────────────────────┤
│ Metric Category                     │ Specification & Threshold        │
├─────────────────────────────────────┼──────────────────────────────────┤
│ Baseline Idle RSS                   │ 20 – 30 MiB (idle Go worker)     │
├─────────────────────────────────────┼──────────────────────────────────┤
│ Steady-State Streaming RSS          │ 80 – 160 MiB during chunk stream │
├─────────────────────────────────────┼──────────────────────────────────┤
│ Target Peak RSS                     │ < 256 MiB under Standard Profile │
├─────────────────────────────────────┼──────────────────────────────────┤
│ Container cgroup Memory Ceiling     │ 512 MiB hard limit (OOM guard)   │
├─────────────────────────────────────┼──────────────────────────────────┤
│ Standard Benchmark Profile          │ 50,000 – 2,000,000 OHLCV rows    │
│                                     │ 100 MB – 1.5 GB uncompressed     │
│                                     │ Concurrency: 1–2 jobs / worker   │
│                                     │ Chunk size: 5,000 rows           │
│                                     │ Channel buffer depth: 1,000      │
└─────────────────────────────────────┴──────────────────────────────────┘
```

- **Incremental Chunked Streaming:** The Go parser processes files incrementally using streaming scanners (`bufio.Scanner`, `parquet-go`). Rows are batched into chunks of 5,000 records and flushed directly to PostgreSQL using binary `COPY` / unnest statements. Datasets are **never** loaded into memory in their entirety.
- **Backpressure & Cancellation:** A buffered channel (depth 1,000) provides backpressure between the file reader and the database writer. If the writer lags, the reader pauses. Context cancellation allows instant, clean termination.
- **Safe Quota Abortion:** If an ingestion job breaches memory thresholds or timeout limits, the active transaction is rolled back, temporary files are purged, and the manifest is marked `FAILED_RESOURCE_EXCEEDED`. Already published datasets remain completely uncorrupted.
- **Empirical Validation Required:** Memory consumption, throughput, and duration are collected empirically during Phase 2 benchmarks; no synthetic PASS claims are accepted prior to authentic test execution. Increasing dataset length does not authorize unbounded memory usage, nor does it imply a universal fixed-memory guarantee for arbitrary adversarial inputs.

### 9.8 Exchange Identity Without Exchange API Connection
A crucial architectural capability: **Historical research on an exchange does NOT require an active Exchange API connection.**
- VECTOR can register offline exchange source identities (e.g. OKX, Kraken, dYdX) solely for historical backtesting and cross-market comparisons.
- Five distinct exchange states:
  1. `OFFLINE_RESEARCH_SOURCE`: Datasets imported and validated; no API keys, no network connections.
  2. `CONNECTED_PUBLIC_ADAPTER`: Public REST/WS ingestion active in sandbox or staging.
  3. `RESEARCH_ENABLED_EXCHANGE`: Available for strategy research across both live and imported data.
  4. `PAPER_ELIGIBLE_EXCHANGE`: Passed 14-day data contract conformance tests.
  5. `ACTIVE_PAPER_EXCHANGE`: Human operator approved; PAPER accounts provisioned.

### 9.9 Historical Research Integration & Leakage Protection
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

### 9.10 Financial Accuracy, Cost Modeling & Disclaimers
Historical OHLCV observations do not contain private execution queue positions, liquidity depth, or funding schedules.
- **Explicit Cost Policies:**
  - When historical funding rates are missing, the system does **not** assume zero funding; it flags `FUNDING_UNKNOWN` and provides parameterizable stress estimates.
  - Taker commissions are applied based on documented tier defaults (e.g. 0.05% taker).
  - Slippage is modeled using empirical volatility-scaled models ($k \cdot \text{ATR}$) rather than zero-slippage assumptions.
  - Research reports clearly distinguish between **Observed Facts** (raw OHLCV) and **Simulated Assumptions** (fill prices, fees, slippage).
  - Profitability certification (Dimension B) cannot be granted if cost parameters are unverified.

### 9.11 Live and Imported Data Comparison
The platform provides analytical comparison views that juxtapose datasets without combining their physical records:
- **Divergence Analysis:** Compare exchange-collected candles against third-party imported candles for the same symbol and timeframe to detect vendor anomalies or exchange bad-prints.
- **Cross-Exchange Regime Analysis:** Compare strategy performance on Bybit vs OKX over the same historical window.
- **Reporting Disclosures:** Every comparative report explicitly discloses source hashes, observation counts, and data provenance.

### 9.12 User Interface Architecture
Three dedicated functional areas in Quasar 2 / Vue 3:
1. **`Research -> Data Manager`:**
   - Dataset upload dropzone (CSV, Parquet, ZIP).
   - Column mapping wizard with live data preview.
   - Comprehensive Data Quality Report (accepted rows, gaps, conflicts, duplicate count).
   - Dataset manifest browser with immutable versioning and provenance badges.
2. **`Research -> New Backtest`:**
   - Dropdown selectors: Strategy $\rightarrow$ Exchange $\rightarrow$ Dataset $\rightarrow$ Instrument $\rightarrow$ Date Range.
   - Real-time compatibility validator (warns if required indicators or timeframes are missing).
3. **`Research -> Dataset Comparison`:**
   - Side-by-side data coverage visualizer.
   - Backtest performance overlay across multiple datasets.

### 9.13 Data Governance, Retention & Reproducibility
- **Version Immutability:** Once published, a dataset version is read-only. Updates create a new monotonically incremented `dataset_version`.
- **Reproducibility Guarantee:** Any research report can be re-run bit-exact by specifying the exact `dataset_id` and `canonical_content_checksum`.
- **Safe Retention Policy:** Datasets referenced by approved Candidate Lab proposals or published research reports cannot be deleted while those reports remain active.

### 9.14 25 Acceptance Criteria for Research Data Ingestion & Dataset Manager
1. A valid historical OHLCV CSV is uploaded and accepted.
2. The file is validated and normalized into UTC and canonical units.
3. Its immutable SHA-256 checksum is recorded in the manifest.
4. Exchange, instrument, market type, and timeframe identities are strictly preserved.
5. Duplicate file upload is detected via content hash and flagged cleanly.
6. Timestamp gaps are detected, counted, and reported without synthesizing or interpolating missing candles.
7. Corrupted OHLC rows ($high < low$, negative volume, non-finite values) fail validation and are rejected.
8. Decimal precision is maintained with zero floating-point rounding errors.
9. Parquet and JSON files follow the identical canonical normalization schema.
10. ZIP decompression security: Archives exceeding 2.0 GB decompressed size or containing path traversals (`../`) are safely rejected; legitimate highly compressible CSV archives (> 10:1 ratio, < 2.0 GB) pass extraction successfully.
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
25. Ingestion worker respects documented resource budgets under the Standard Benchmark Profile (Target Peak RSS < 256 MiB, container ceiling 512 MiB); jobs exceeding quotas abort safely without corrupting published datasets.

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

### 10.4 Go Supply Chain Security & Dependency Governance

To maintain platform safety, cryptographic integrity, and compliance across all Go components, a mandatory Supply Chain Security Gate is established in Phase 1 and governs all subsequent Go phases:

1. **Explicit Justification & Allowlist:** Every external Go module introduced into `go.mod` must have a documented business justification, identified owner, and be registered in the Approved Dependency Register.
2. **Provenance & Maintenance Review:** External dependencies must be actively maintained, have a transparent release cadence, well-documented source provenance, and an established security disclosure policy.
3. **Mandatory Vulnerability Auditing:** Every dependency must be audited using `govulncheck` before addition or version bumping. Zero known high or critical vulnerabilities are permitted in production binaries.
4. **License Compatibility:** All dependencies must possess permissive open-source licenses compatible with VECTOR (MIT, Apache 2.0, BSD-2/3). Restrictive or copyleft licenses (e.g. GNU GPL, AGPL) are strictly scrutinized or rejected.
5. **Standard Library Preference:** Developers must prefer the Go Standard Library for networking, cryptography, encoding, and data structures. External third-party packages are permitted only when specialized computational requirements (e.g. Parquet columnar reader, PostgreSQL binary driver) cannot be met by standard primitives.
6. **Exact Version Pinning & Immutable Checksums:** Floating versions, ranges, or `@latest` tags are forbidden. Exact semantic versions must be pinned in `go.mod`. The `go.sum` file must be committed, preserved, and validated via `go mod verify` in CI.
7. **SBOM & Transitive Dependency Tracking:** A Software Bill of Materials (SBOM) must be generated for all compiled binaries. Transitive dependencies are audited with equal rigor to direct dependencies.
8. **Prohibition of Untrusted Forks & Network Builds:** `replace` directives pointing to untrusted forks, unvetted GitHub branches, or local filesystem paths are prohibited in production builds. Build scripts must not execute arbitrary network-dependent downloads during compilation (`go build -mod=readonly`).
9. **Zero Automatic Deployment Upgrades:** Container builds and deployment workflows must never execute `go get -u` or silently update dependencies at deployment time.

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

## 12. Parity Validation & Shadow Reconciliation Protocols

### 12.1 Strategy Parity Protocol
Before Go becomes authoritative for strategy execution, every family must achieve **bit-exact parity** against versioned PHP golden fixtures:
1. **Fixture Extraction:** 10,000 closed candles extracted for each of the 13 families across BTCUSDT and ETHUSDT (1h, 30m, 15m, 5m).
2. **PHP Baseline Execution:** Run PHP strategy scanner, recording complete decision vector (`decision`, `reason_code`, `price_plan`, `indicator_snapshot`).
3. **Go Differential Runner:** Execute Go strategy on identical candle stream.
4. **Comparison Gate:** Decisions must match 100.000%; reason codes must match 100.000%; price levels must match within $1 \times 10^{-8}$.
5. **Resolution:** Any divergence must be documented, root-caused, and resolved before execution authority transitions.

### 12.2 Shadow Reconciliation & Divergence Diagnostic Protocol
During Phase 5 shadow execution, Go evaluates live market streams and simulates orders, fills, and double-entry accounting in parallel with PHP without mutating authoritative live ledgers:

1. **Aligned Observation Boundaries:** All comparisons execute against identical closed-candle events and synchronized snapshot epochs. Comparisons across differing timestamps or unclosed candles are invalid.
2. **Canonical 15-Point Comparison Dimensions:**
   `Exchange`, `Account ID`, `Strategy ID`, `Instrument`, `Event Sequence`, `State Transition`, `Quantity`, `Gross PnL`, `Fees`, `Funding Settlements`, `Realized PnL`, `Unrealized PnL`, `Equity`, `Decimal Rounding Decisions`, `Source Market Marks`.
3. **Deterministic Diagnostic Tool (`cmd/vector-reconcile`):**
   - Ingests parallel PHP and Go execution event streams.
   - Pinpoints the **FIRST** chronological event where state or accounting values diverge.
   - Renders side-by-side tabular comparison of both state transitions, decimal values, and math operations.
   - Classifies discrepancy into standard root-cause types:
     - `DATA_MISMATCH`: Differing input candle or market mark.
     - `TIMING_MISMATCH`: Processing events out of order or lagging queue consumer.
     - `PRECISION_MISMATCH`: Premature intermediate rounding or floating-point pollution.
     - `MISSING_EVENT`: Dropped fill, funding settlement, or execution notice.
     - `DUPLICATE_EVENT`: Redundant processing of an identical event without idempotency suppression.
     - `STATE_MACHINE_DIVERGENCE`: Inconsistent transition order (e.g. TP1 before SL).
   - Generates machine-readable diff files (`reconciliation_diff_<timestamp>.json`) and Markdown summary reports.
   - Provides deterministic offline reproduction fixtures enabling engineers to replay divergent scenarios in isolated unit tests.
   - Strictly read-only: does not modify live or shadow ledgers.
4. **Alert & Resolution Policy:**
   - **Alert Ceiling:** Any balance, equity, or PnL divergence $> 0.0001\text{ USDT}$ triggers an immediate high-priority diagnostic alert.
   - **Financial Invariant Exact Equality:** Double-entry accounting identities require exact equality ($0.000000000000\text{ USDT}$).
   - **One-Cent Mismatch Mandate:** A divergence of even one cent ($0.01\text{ USDT}$) represents a material systemic failure and must be investigated to complete root-cause resolution; it can never be waived as immaterial.
   - **14-Day Validation Reset:** Any material, unexplained financial divergence or state-machine defect immediately restarts the 14-day continuous validation timer from zero (Day 0).
   - **Cutover Blocker:** Phase 7 cutover is strictly prohibited while any active or unclassified reconciliation divergence exists.

---

## 13. Scorecard V3.4 Transition & Migration Registry Governance

To maintain audit integrity and prevent aspirational reporting:

1. **Frozen Baseline Preserved:** `VECTOR-SCOPE-20261027-v1` remains permanently immutable in `docs/progress/baselines/VECTOR-SCOPE-20261027-v1.json`.
2. **Proposed Baseline V3.4:** Proposed tracking baseline incorporates the 9-phase sequence, Phase 1.0–1.2 increments, and Declarative MVP gates.
3. **Atomic Migration Registry Updates Rule:**
   - Whenever a migration package's implementation or ownership maturity changes, the authoritative migration registry (`docs/progress/VECTOR_GO_MIGRATION_REGISTRY_V3.md`) **MUST be updated in the exact same Git commit** as the relevant code implementation change.
   - Documentation-only amendments do not constitute implementation progress and must never alter maturity states.
   - No automatic promotion of maturity status based on test counts alone.
   - Evidence recorded in the registry must correspond to the exact commit SHA where verification passed.
4. **Six Defined Package Maturity States:**
   - `APPROVED`: Architectural scope and acceptance criteria approved; runtime implementation not started.
   - `PHP_AUTH`: Existing PHP implementation is the sole authoritative writer.
   - `GO_IMPLEMENTED`: Go code implemented, tested, and passing unit/benchmark tests, but not yet verified in operational parallel shadow mode.
   - `GO_SHADOW`: Go implementation running concurrently in parallel shadow mode; verified by `cmd/vector-reconcile` diff harness without write authority.
   - `GO_AUTH`: Go promoted to sole authoritative single writer under database fencing; PHP financial write permissions revoked.
   - `PHP_RETIRED`: Duplicate legacy PHP computational logic cleanly decommissioned and removed from repository.
5. **Lightweight Automated Drift Check Specification:**
   - Future verification tooling (`scripts/verify_migration_registry.py`) will check consistency between Git commits, declared package maturity, and corresponding source path existence.
6. **Scope vs Verification Distinction:** `APPROVED ARCHITECTURAL SCOPE != IMPLEMENTED RUNTIME CAPABILITY`. Packages `MIG-18` through `MIG-29` represent approved planned milestones, not completed implementations.
7. **Four Independent Progress Dimensions:** Functional Implementation %, Verification %, Product %, and Go Migration %.

---

## 14. Component Ownership & Migration Summary Matrix

| Subsystem | Specific Component | Phase 0–4 State | Phase 5–6 State | Target Phase 7 State | Authoritative Writer |
|:---|:---|:---:|:---:|:---:|:---:|
| **Foundation** | Scaled Financial Decimal (`pkg/decimal` - Phase 1.0) | Planned / Approved | Go Pure Library | Go Pure Library | Pure Library |
| **Foundation** | Core Types & Universal Enums (`pkg/types` - Phase 1.0) | Planned / Approved | Go Pure Library | Go Pure Library | Pure Library |
| **Foundation** | IPC Message Protocol Envelopes (`pkg/protocol` - Phase 1.1)| Planned / Approved | Go Protocol Engine | Go Protocol Engine | Pure Library |
| **Foundation** | Fencing Token Generator (`pkg/fencing` - Phase 1.1) | Planned / Approved | Go Fencing Engine | Go Fencing Engine | Pure Library |
| **Foundation** | Dataset Foundational Manifest Types (`pkg/dataset` - Phase 1.2)| Planned / Approved| Go Manifest Engine | Go Manifest Engine | Pure Library |
| **Market Data** | Public Candle REST / WS | PHP | Go (Authoritative) | Go | **Go Engine** |
| **Market Data** | Generic Config Ingestion (CSV MVP - Phase 6)| Planned | Go Config Engine | Go Config Engine | **Go Engine** |
| **Market Data** | Candle Ring Buffer | PHP / Redis | Go In-Memory | Go | **Go Engine** |
| **Market Data** | Funding Rates & Open Interest | PHP | Go | Go | **Go Engine** |
| **Dataset Manager**| Historical Dataset Importer (CSV/Parquet - Phase 2)| Planned | Go Importer | Go Importer | **Go Engine** |
| **Dataset Manager**| Data Quality & Validation Engine (Phase 2) | Planned | Go Validation Engine| Go Validation Engine| **Go Engine** |
| **Dataset Manager**| Dataset Storage & Versioning (Phase 2) | Planned | Postgres / Go | Postgres / Go | **Go Engine** |
| **Dataset Manager**| Dataset Manager UI & Manifests (Phase 2) | Planned | Vue 3 / Laravel | Vue 3 / Laravel | **Laravel Plane**|
| **Indicators** | Technical Indicators (EMA, RSI, ATR...) | PHP & Go | Go Library | Go Library | Pure Library |
| **Strategy** | 13 PRIMARY Strategy Families | PHP (5 in Go) | Go (Shadow Parity) | Go (Authoritative) | **Go Engine** |
| **Strategy** | Strategy Extensibility SDK | PHP Registry | Go Strategy SDK | Go Strategy SDK | **Go Engine** |
| **Strategy** | Declarative Strategy MVP (EMA, RSI, ATR - Phase 3)| Planned | Go Declarative MVP | Go Declarative Engine| **Go Engine** |
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
| **Paper Trading**| Reconciliation Diff Tool (`cmd/vector-reconcile` - Phase 5)| Planned | Go Diagnostic Tool | Go Diagnostic Tool | Read-Only Tool |
| **Adapters** | Multi-Exchange Extensibility SDK| PHP Adapters | Go Exchange SDK | Go Exchange SDK | **Go Engine** |
| **Adapters** | Declarative Exchange MVP (Research Scope - Phase 6)| Planned | Go Generic MVP | Go Generic Adapter | **Go Engine** |
| **Analytics** | Statistics, Drawdowns, Sharpe | PHP | Go Engine | Go Engine | **Go Engine** |
| **Control Plane**| Exchange & Strategy Builders UI| Laravel | Laravel | Laravel | **Laravel Plane**|
| **Control Plane**| Candidate Lab Approval Gates | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Operator Auth & Roles | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| REST API Endpoints | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **Control Plane**| Report & CSV Read Delivery | PHP Laravel | PHP Laravel | PHP Laravel | **Laravel Plane**|
| **UI** | Vue 3 / Quasar Dashboard | Frontend SPA | Frontend SPA | Frontend SPA | Read-Side UI |

---

## 15. Implementation Handoff & Next Steps

### Current System Status at Baseline `aeffb31` (Roadmap V3.4)
- **Phase 0 Status:** **BLOCKED**. All 42 local V1 obligations and 9 browser V3 obligations have authentic passing verification evidence. All 922 backend PHP tests, 64 frontend tests, and Go research tests are passing cleanly.
- **Phase 0 Blocker:** 45 Production V2 obligations require operator execution of [`phase0_production_acceptance.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/phase0_production_acceptance.sql) and completion of the formal evidence-backed Operator Attestation protocol on the VPS according to [`OPERATOR_CHECKLIST.md`](file:///home/thunder/work/traderbybit/docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md).
- **Binance Immutable Candle Investigation:** Server-time clock skew is an established hypothesis; awaiting operator capture of exact production conflict record to verify.
- **Phase 1 Status:** **NOT STARTED**. Strictly blocked until formal Phase 0 Operator Attestation is signed. Implementation of planned packages `MIG-18` through `MIG-29` is **not** a prerequisite for Phase 1.

### Immediate Action Required by Next Engineer / Operator:
1. **Operator Action:** Execute the production diagnostic queries on the VPS and complete the formal Operator Attestation record in `docs/progress/evidence/phase0-production-acceptance/operator_attestation.json`.
2. **Production Conflict Investigation:** Collect the structured Binance immutable candle conflict record from the VPS logs to verify the clock skew root cause.
3. **Evidence Closure:** Execute `python3 scripts/vector_progress.py --phase 0 --generate` to admit fresh production evidence and close Gate 0.
4. **Transition to Phase 1.0:** Upon explicit owner authorization, initiate **Phase 1.0** (Financial Decimal and Core Types: `pkg/decimal`, `pkg/types`). Phase 1.1 and 1.2 remain blocked until Phase 1.0 parity verification is accepted.
