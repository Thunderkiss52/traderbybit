# VECTOR Phase 0 Operator Production Acceptance Checklist

This document establishes the authoritative production acceptance procedure for the **45 Phase 0 Operational Verification Obligations (V2)**.
In accordance with `AGENTS.md` and `SKILL.md`:
- **Real exchange order placement is strictly prohibited.**
- **Virtual paper trading only across 26 independent accounts (13 Bybit + 13 Binance).**
- **Production deployments are operator-triggered only via `.github/workflows/deploy.yml`.**
- **ECONOM / econom-group resources are strictly isolated and out of scope.**

---

## 1. Production Verification Summary Matrix (45 V2 Obligations)

| Req ID | Obligation | Name | Verification Method / Query | Acceptance Criteria |
| :--- | :--- | :--- | :--- | :--- |
| **VEC-P0-001** | `VEC-P0-001-V2` | Primary Strategy Virtual Execution | Query 1 (`scripts/diagnostics/phase0_production_acceptance.sql`) | 13 Bybit + 13 Binance accounts initialized |
| **VEC-P0-002** | `VEC-P0-002-V2` | Exchange Independence Invariant | Query 2 (`phase0_production_acceptance.sql`) | 0 cross-exchange position leaks |
| **VEC-P0-003** | `VEC-P0-003-V2` | Historical Ledger Preservation | `GET /api/v1/research/profitability/summary` | Reconciliations recorded; immutable historical records |
| **VEC-P0-004** | `VEC-P0-004-V2` | Signal Deduplication at Boundary | `GET /api/v1/signals` | Unique `(exchange, symbol, timeframe, decision_time)` |
| **VEC-P0-005** | `VEC-P0-005-V2` | Virtual Fill Execution Precision | Query 5 (`phase0_production_acceptance.sql`) | 0 non-positive quantities or prices |
| **VEC-P0-006** | `VEC-P0-006-V2` | Isolated Queue Routing | Redis CLI: `LLEN queues:research` | Queues bounded, no cross-job deadlocks |
| **VEC-P0-007** | `VEC-P0-007-V2` | PostgreSQL Concurrency & Locks | `SELECT count(*) FROM pg_stat_activity` | Lock wait times < 100ms; no deadlocks |
| **VEC-P0-008** | `VEC-P0-008-V2` | Safe Migration Gate | Docker Compose migration logs | Zero rollbacks; schema matches expectations |
| **VEC-P0-009** | `VEC-P0-009-V2` | Binance Ingestion Latency Profile | Log analytics on batch ingestion | Monitored production P95 latency recorded |
| **VEC-P0-010** | `VEC-P0-010-V2` | Market Candle Immutability | Query 3 (`phase0_production_acceptance.sql`) | 0 duplicate candles in `market_candles` |
| **VEC-P0-011** | `VEC-P0-011-V2` | Bybit Ingestion Health | Public API latency metrics | API error rate < 0.1%; 0 rate-limit 429 drops |
| **VEC-P0-012** | `VEC-P0-012-V2` | Funding Rate Synchronization | `SELECT count(*) FROM market_funding_events` | Regular 8h funding marks recorded |
| **VEC-P0-013** | `VEC-P0-013-V2` | Open Interest Windows | `SELECT count(*) FROM market_open_interest` | 5m snapshots recorded without temporal gaps |
| **VEC-P0-014** | `VEC-P0-014-V2` | Indicator Computation Causality | Strategy decision logs | Decision time strictly >= candle close time |
| **VEC-P0-015** | `VEC-P0-015-V2` | Quality Class A/B/C Sizing | Position logs | Sizing adheres strictly to risk model |
| **VEC-P0-016** | `VEC-P0-016-V2` | Daily Equity Snapshots | Query 1 (`phase0_production_acceptance.sql`) | Midnight UTC equity snapshots recorded |
| **VEC-P0-017** | `VEC-P0-017-V2` | Drawdown Monitoring Boundary | Paper accounts query | Peak equity tracked; drawdown calculation accurate |
| **VEC-P0-018** | `VEC-P0-018-V2` | Graceful SIGTERM Termination | Container restart test | Workers complete current job before exiting |
| **VEC-P0-019** | `VEC-P0-019-V2` | Read-Only API Key Boundary | Container environment inspection | Zero trading keys; read-only public market data |
| **VEC-P0-020** | `VEC-P0-020-V2` | Telemetry & Health Endpoint | `GET /api/v1/research/health/data-freshness` | Status 200, latency < 100ms |
| **VEC-P0-101** | `VEC-P0-101-V2` | F01 — Multi-Exchange Isolation | Query 2 (`phase0_production_acceptance.sql`) | Strict account-to-exchange binding |
| **VEC-P0-102** | `VEC-P0-102-V2` | F02 — Profitability Envelope Chain | `GET /api/v1/research/profitability/summary` | Canonical response envelope `{status, data}` |
| **VEC-P0-103** | `VEC-P0-103-V2` | F03 — Null vs Real Zero | `GET /api/v1/research/profitability/strategies` | Unreconciled accounts return null, not 0.00 |
| **VEC-P0-104** | `VEC-P0-104-V2` | F04 — Backtest Leakage Gate | Backtest logs | Zero look-ahead leakage |
| **VEC-P0-105** | `VEC-P0-105-V2` | F05 — Gated Module Statuses | `GET /api/v1/research/profitability/summary` | P-1/P-2 implemented, P-3 blocked, P-4..6 not impl |
| **VEC-P0-106** | `VEC-P0-106-V2` | F06 — Non-Blocking Hooks | WebSocket event logs | Ingestion unaffected by research jobs |
| **VEC-P0-107** | `VEC-P0-107-V2` | F07 — Audit Export CSV Schema | `POST /api/v1/audit/exports` | Generated ZIP contains all declared columns |
| **VEC-P0-108** | `VEC-P0-108-V2` | F08 — Audit Funnel Isolation | `GET /api/v1/audit/strategy-funnel` | Funnel metrics isolated per exchange |
| **VEC-P0-109** | `VEC-P0-109-V2` | F09 — 26 Account Selection | Dashboard inspection | All 26 accounts selectable with distinct balances |
| **VEC-P0-110** | `VEC-P0-110-V2` | F10 — Memory-Bounded Backtest | `research-worker` memory monitoring | Memory consumption < 512 MiB |
| **VEC-P0-111** | `VEC-P0-111-V2` | F11 — Deterministic Replay | Replay job test | Identical seeds produce identical outcomes |
| **VEC-P0-112** | `VEC-P0-112-V2` | F12 — Monte Carlo Paths | Monte Carlo job test | Bounded distribution, non-overlapping draws |
| **VEC-P0-113** | `VEC-P0-113-V2` | F13 — Pattern Mining Dedup | Pattern run query | Zero duplicate candidate patterns |
| **VEC-P0-114** | `VEC-P0-114-V2` | F14 — Report Freshness Shapes | `GET /api/v1/research/health/data-freshness` | Actual field names match consumer contracts |
| **VEC-P0-115** | `VEC-P0-115-V2` | F15 — Time-Bucket Correlation | `GET /api/v1/research/portfolio-correlation` | Positions aligned on dates, not query order |
| **VEC-P0-116** | `VEC-P0-116-V2` | F16 — Strategy Degradation Scope | Degradation logs | Bybit and Binance degradation metrics isolated |
| **VEC-P0-117** | `VEC-P0-117-V2` | F17 — Feature Store Exchange Filter | `SELECT count(*) FROM learning_feature_snapshots` | Snapshot data quality records exchange |
| **VEC-P0-118** | `VEC-P0-118-V2` | F18 — Model Purging Fallback | Model training logs | Fallback diagnostics recorded without leakage |
| **VEC-P0-119** | `VEC-P0-119-V2` | F19 — Autonomy Effective Permissions | Query 7 (`phase0_production_acceptance.sql`) | Effective permissions false under kill switch |
| **VEC-P0-120** | `VEC-P0-120-V2` | F20 — Dual Kill-Switch Authorities | Query 7 (`phase0_production_acceptance.sql`) | Both authorities synchronized without write on GET |
| **VEC-P0-121** | `VEC-P0-121-V2` | F21 — Dynamic Worker Map | `GET /api/v1/research/capabilities` | Only compatible workers marked ready |
| **VEC-P0-122** | `VEC-P0-122-V2` | F22 — QIM Error Propagation | `GET /api/v1/quote-integrity/status` | Explicit 503 error handling; no silent fail |
| **VEC-P0-123** | `VEC-P0-123-V2` | F23 — UI Pagination Truncation | `GET /api/v1/paper/daily-performance` | Pages 1/2/3 navigation preserves stable ordering |
| **VEC-P0-124** | `VEC-P0-124-V2` | F24 — Slippage Attribution Trace | Query 8 (`phase0_production_acceptance.sql`) | 0 foreign fills across exchange/account filters |
| **VEC-P0-125** | `VEC-P0-125-V2` | F25 — Candidate Backtest Linkage | Candidate strategy query | `backtest_run_id` links to owned run |
| **VEC-P0-126** | `VEC-P0-126-V2` | F26 — Autonomy Audit Immutability | Query 6 (`phase0_production_acceptance.sql`) | Events strictly append-only; zero updates/deletes |

---

## 2. Operator Execution Procedure

1. **Deploy Release Candidate:**
   Trigger operator deployment via GitHub Actions (`.github/workflows/deploy.yml`).
2. **Execute Diagnostic SQL Script:**
   ```bash
   psql -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE -f scripts/diagnostics/phase0_production_acceptance.sql
   ```
3. **Verify Read-Only API Status:**
   ```bash
   curl -s -H "X-Research-Token: $RESEARCH_API_TOKEN" https://traderbybit.internal/api/v1/research/health/data-freshness | jq .
   ```
4. **Sign Off Production Acceptance Matrix:**
   Record output in `docs/progress/evidence/phase0-production-acceptance/operator_signoff.json`.
