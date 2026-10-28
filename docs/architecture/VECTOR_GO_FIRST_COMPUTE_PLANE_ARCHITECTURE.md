# VECTOR Architecture: Go-First Compute Plane & Laravel Control Plane

This document codifies the architectural division of responsibilities between the **Laravel Control Plane** and the **Go Compute Plane (`research-go`)** in VECTOR.

---

## 1. Architectural Principles & System Boundary

VECTOR is designed around a strict decoupling of orchestration from high-throughput computation:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        LARAVEL CONTROL PLANE                           │
│  - Event Orchestration & Scheduling                                    │
│  - Double-Entry Virtual Accounting & Paper Ledgers (Authoritative)     │
│  - Web APIs, Vue 3 Operational Dashboards & Auth                       │
│  - Ingestion Gateways (Public Market Data Ingestion)                   │
│  - Research Autonomy Governance & Risk Controls                        │
└───────────────────────────────┬────────────────────────────────────────┘
                                │ PostgreSQL 17 (WAL, Shared Tables)
                                │ Redis 7.4 (Queues, Fencing Tokens)
┌───────────────────────────────▼────────────────────────────────────────┐
│                         GO COMPUTE PLANE                               │
│  - High-Throughput Deterministic Simulation (`research-worker`)       │
│  - Walk-Forward & Parameter Grid Optimizations                         │
│  - High-Density Candlestick & Market Structure Pattern Mining          │
│  - Monte Carlo Path Generation & Bootstrapping                         │
│  - Counterfactual Metric Calculation & Replay Engine                   │
└────────────────────────────────────────────────────────────────────────┘
```

### 1.1 Strict Invariants

1. **Accounting Authority:**
   The Laravel Control Plane is the **sole authoritative owner** of paper ledgers, account balances, and financial transaction histories. The Go Compute Plane performs purely deterministic simulations and analytical evaluations; it never directly mutates live paper account balances.

2. **Zero Order Placement:**
   Neither the Laravel Control Plane nor the Go Compute Plane possesses exchange trade credentials. Public market data API ingestion is strictly read-only.

3. **Stateless Compute Workers:**
   Go compute workers (`research-worker`) are stateless. They claim queued jobs from PostgreSQL (`research_jobs`) using transactional lease locking (`FOR UPDATE SKIP LOCKED`) and write verifiable manifests (`result_manifest_sha256`) back to PostgreSQL upon completion.

---

## 2. Go Compute Plane Design (`research-go`)

The Go worker architecture (`cmd/research-worker`) is designed for maximum throughput, low memory footprint (< 512 MiB), and high determinism:

### 2.1 Job Lifecycle and Fencing

1. **Job Claim:**
   Worker executes `claimNextJob(worker_id, lease_seconds)`.
   A fencing token increments with each lease assignment to prevent split-brain execution by lagging or zombie workers.

2. **Execution & Heartbeat:**
   Worker processes the job in bounded chunks. Periodic heartbeats extend lease expiration (`lease_expires_at`).

3. **Result Manifestation:**
   Upon completion, the Go worker writes:
   - `result_payload`: JSON containing exact evaluation metrics (win rate, profit factor, expected R).
   - `runtime_stats`: Execution duration (ms), memory bytes, rows processed.
   - `result_manifest_sha256`: Cryptographic digest guaranteeing non-repudiation and output immutability.

### 2.2 Implemented Worker Kinds

- `BACKTEST`: Deterministic single-run or split backtesting over millions of closed candles.
- `WALK_FORWARD`: Anchored and rolling walk-forward parameter stability evaluation.
- `PATTERN_MINE`: High-density scanning for candlestick patterns and structural support/resistance breakouts.
- `MONTE_CARLO`: Resampled return distributions with 10,000+ simulation paths.
- `REPLAY`: Bit-exact historical decision replay to evaluate counterfactual slippage and execution alternatives.

---

## 3. Communication Protocols

| Channel | Protocol | Payload | Guarantees |
| :--- | :--- | :--- | :--- |
| **Laravel -> Go** | PostgreSQL `research_jobs` | Job configuration JSON | Idempotent submission, transactional claim |
| **Go -> Laravel** | PostgreSQL `research_jobs` | Result manifest & runtime stats | SHA-256 integrity, fencing token validation |
| **Worker Health** | In-Memory / Redis Cache | Heartbeat timestamp & version | Dynamic capability routing, automated lease reclamation |

---

## 4. Phase 1 Roadmap & Scaling Path

In Phase 1, Go compute workloads will expand to include:
- Native gRPC transport for sub-millisecond job dispatch.
- Multi-node distributed worker clustering with GPU-accelerated tensor operations.
- Cross-exchange vectorized arbitrage simulation between Bybit and Binance paper datasets.
