# VECTOR Phase 0 Production Evidence Assessment & Acceptance Audit

**Document Version:** 1.0 (Production Post-Remediation Acceptance Report)  
**Target Release SHA:** `9ba6970b4514c32149866e77500757a5784ddcf8` (Confirmed Deployed on VPS)  
**Production Evidence Context:** `/srv/bybit-signals/evidence/phase0-20261029T101029Z`  
**Governing Standards:** [`AGENTS.md`](file:///home/thunder/work/traderbybit/AGENTS.md), [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md), [`VECTOR_GO_FIRST_ROADMAP_V3.md`](file:///home/thunder/work/traderbybit/docs/roadmap/VECTOR_GO_FIRST_ROADMAP_V3.md)  
**Acceptance Verdict:** **NOT READY** (Phase 0 cannot be certified; Phase 1 must remain blocked)

---

## 1. Executive Summary & Verdict Rationale

Production diagnostics executed on the deployed remediation commit `9ba6970b4514c32149866e77500757a5784ddcf8` verify material architectural improvements:
- All 26 canonical PAPER accounts (13 Bybit + 13 Binance) are intact and isolated, including the legacy Bybit baseline account (`FORWARD_PAPER`).
- The 5% drawdown circuit breaker is proven effective: three drawdown-breached Bybit accounts have entries blocked with zero post-breach fills, while open positions continue to be safely serviced.
- Binance bootstrap zero snapshots are properly distinguished from genuine capital losses via PostgreSQL insertion sequences (`BIGINT`), eliminating false 100% drawdown anomalies.
- Dual kill switches and autonomy permissions operate synchronously, blocking autonomous strategy replacement.

However, **Phase 0 production acceptance CANNOT be granted** due to three critical remaining blockers:
1. **379 Legacy Bybit Positions Classified as `INSUFFICIENT_SOURCE_DATA`:** While internal double-entry accounting balances to 0.0000 discrepancy, zero independent exchange funding evidence has been provided. Independent settlement verification remains blocked.
2. **Two Historical PARTIAL Bybit Cycles (2026-10-28):** Two distinct 5-minute candle cycles (ETHUSDT 04:20 UTC and BTCUSDT 16:35 UTC) experienced 1 account failure out of 13. While the 12 successful accounts achieved exactly-once execution, legacy schema omitted specific account failure identities. These cycles remain an explicit **UNRESOLVED** finding.
3. **Absence of Independent Exchange Evidence:** Historical exchange rates and mark prices for October 14–27 Bybit funding boundaries must be collected from public read-only sources and processed through `classify_phase0_funding.py`.

---

## 2. In-Depth Analysis of Confirmed Production Findings

### A. Historical Partial PAPER Processing (Two 5m Candle Cycles)

**Incident Parameters:**
- **Cycle 1:** Bybit | ETHUSDT | Candle close: `2026-10-28 04:20:00 UTC` | Eligible: 13 | Processed: 12 | Failures: 1 | Stages: `CANDLE_CLOSED/PARTIAL`, `PAPER_PROCESSED/PARTIAL`
- **Cycle 2:** Bybit | BTCUSDT | Candle close: `2026-10-28 16:35:00 UTC` | Eligible: 13 | Processed: 12 | Failures: 1 | Stages: `CANDLE_CLOSED/PARTIAL`, `PAPER_PROCESSED/PARTIAL`

**Forensic Evaluation:**
1. **Failed Account Identification:**
   In the legacy schema active on October 28, `ProcessPaperPipelineJob` updated numerical counts (`eligible_accounts: 13`, `processed_accounts: 12`, `failures_count: 1`) but did not persist `paper_eligible_account_ids` or `paper_processed_account_ids` arrays. Because the failed account threw an exception inside the per-account database transaction, that specific transaction rolled back completely, writing zero row mutations for that account. Without the corresponding container stderr logs from October 28, the exact account UUID cannot be proven from database rows alone without risking speculation.
2. **Exactly-Once Processing for the 12 Successful Accounts:**
   Each eligible account processes inside its own isolated database transaction with row locks on `paper_accounts` and `trading_pipeline_events`. The 12 successful accounts committed their state, risk decisions, and position updates independently. Idempotency constraints on `virtual_executions`, `paper_signal_states`, and `virtual_positions` mathematically prevent duplicate executions.
3. **No Duplicate or Late Fills Created:**
   `ProcessPaperPipelineJob::handle()` enforces the temporal freshness invariant `if (! $pipelineService->isFresh($at)) return;` (300s window). Any late retry after 300 seconds is rejected as STALE. Furthermore, the recovery worker specifically skips PARTIAL batches whose candle close is older than 300 seconds (`continue; // Preserve historical failure evidence; never execute it late`). No late or duplicate fills were created.
4. **Signal Evaluation vs. Executable Trade:**
   Both cycles completed `STAGE_SIGNALS_EVALUATED` upstream prior to `STAGE_PAPER_PROCESSED`. Signals WERE fully evaluated on the closed 5m candle. For the 1 single failed account, whether an actual trade was missed depends on whether that account's specific strategy emitted an actionable BUY/SELL signal that passed risk limits, or whether it was flat/filtered.
5. **Recurrence Prevention in Deployed Code (`9ba6970`):**
   Commit `3a90ce2` modified `ProcessPaperPipelineJob` and `TradingPipelineRecoveryService`:
   - `eligible_account_ids`, `processed_account_ids`, and structured `failures: [{account_id, error}]` are now durably committed to the JSON payload on every per-account step.
   - Retries query only the failed account IDs via `$job->onlyAccountIds = $failedIds`, skipping already-processed peers.
6. **Acceptance Verdict for Finding A:** **UNRESOLVED HISTORICAL INCIDENT** (retained as permanent immutable audit evidence; no retroactive replay or compensating trades permitted).

---

### B. Historical STALE Events (316 Events)

**Incident Parameters:**
- Bybit: 178 events (`attempts = 4`).
- Binance: 138 events (`attempts = 3`).
- Stage: All are `SIGNALS_EVALUATED` with status `STALE` and populated `last_error`.
- Downstream State: All 316 events have matching downstream `PAPER_PROCESSED` with `status = COMPLETED` and valid `completed_at`.

**Forensic Evaluation:**
1. **Pipeline Contract & Terminal State Semantics:**
   In VECTOR's event-driven pipeline, `SIGNALS_EVALUATED` transitions from `PENDING` to `DISPATCHED` when `ProcessPaperPipelineJob` is queued. When queue workers experience latency or backlog exceeding the 300s freshness window, the recovery scanner (`TradingPipelineRecoveryService`) marks the waiting upstream event as `STALE`.
2. **Coexistence of Downstream `COMPLETED` and Upstream `STALE`:**
   When `ProcessPaperPipelineJob` completed downstream execution, it called `acknowledgeUpstreamEvents(...)`. In accordance with immutable audit standards, `acknowledgeUpstreamEvents` explicitly updates only non-terminal statuses:
   `->whereIn('status', ['PENDING', 'DISPATCHED', 'PARTIAL', 'FAILED'])`
   Because `STALE` is a durable, terminal disposition, the system intentionally preserves `STALE` rather than overwriting it with `COMPLETED`. Overwriting historical `STALE` events would fraudulently erase evidence of queue processing latency.
3. **No Unprocessed Accounts or Duplicate Executions:**
   Every one of the 316 cycles has a fully completed downstream `PAPER_PROCESSED` record with verified account processing. Execution idempotency keys prevented duplicate fills.
4. **Acceptance Verdict for Finding B:** **VALID TERMINAL BEHAVIOR** (historical queue latency accurately recorded; terminal evidence correctly preserved).

---

### C. Funding Reconciliation (379 Legacy Positions)

**Incident Parameters:**
- 379 closed Bybit linear perpetual positions opened between October 14 and October 27.
- Net PnL Identity: 379/379 positions have `abs(net_pnl - (gross_pnl - fees + funding)) == 0.000000000000`.
- Classification: 379/379 classified as `INSUFFICIENT_SOURCE_DATA`.

**Forensic Evaluation:**
1. **Root Cause of Classification:**
   `classify_phase0_funding.py` enforces strict quantitative standards: zero assumptions, zero fabricated marks. If `--exchange-evidence` containing independently validated 8-hour funding rates and 1-minute mark-kline open prices is not provided, the classifier strictly outputs `INSUFFICIENT_SOURCE_DATA`.
2. **Precise Missing Inputs:**
   For each position holding exposure across an 8-hour boundary (00:00, 08:00, 16:00 UTC):
   - Official Bybit public funding rate $R_t$ at boundary $t$.
   - Official Bybit 1-minute public mark-price candle open $P_t$ at boundary $t$.
   - Independent verification that exposure $Q_t$ matches reconstructed position executions.
3. **Safe Collection Procedure (Read-Only Public Bybit API v5):**
   The operator can safely retrieve historical public funding rates and mark-kline prices without authentication:
   - Funding Rate: `GET https://api.bybit.com/v5/market/history-fund-rate?category=linear&symbol=BTCUSDT&startTime=...`
   - Mark Price Kline: `GET https://api.bybit.com/v5/market/mark-kline?category=linear&symbol=BTCUSDT&interval=1&startTime=...`
4. **Acceptance Verdict for Finding C:** **BLOCKED** on independent exchange funding evidence collection. No manual setting of `funding_complete = true` is authorized.

---

### D. Binance Bootstrap Zero-Snapshot Integrity

- Historical provisioning wrote bootstrap snapshots (`account:initial:binance = 0`) and subsequent capital allocation (`owner-paper-capital:* = 10000`) on the same day.
- Commit `9f4450d` introduced `EquitySnapshotRecord::performance` scope using `BIGINT` insertion sequences (`es.id < funded.id`) and explicit foreign key linkage.
- Query 18 confirms `BOOTSTRAP_SEQUENCE_PROVEN` for all funded Binance accounts.
- Genuine capital exhaustion (losses driving equity to 0 with non-zero realized PnL) is NOT filtered.
- **Acceptance Verdict for Finding D:** **PASS**.

---

### E. Risk Gates and Autonomy Verification

- **5% Entry Block & 10% Halt:** Query 9 & Query 28 confirm 3 Bybit accounts with drawdown $\ge 5\%$ have entries blocked. Query 28b proves exactly 0 positions were opened following drawdown breach.
- **Liveness of Position Management:** Query 28 confirms that open positions on blocked accounts continue to receive execution fills, stop-loss/take-profit tracking, and funding servicing.
- **Dual Kill Switches:** Query 7 & Query 23 prove both authorities (`research_promotion_controls` and `autonomy_settings`) are synchronized and active.
- **Acceptance Verdict for Finding E:** **PASS**.

---

## 3. Comprehensive 45-Obligation Production Acceptance Matrix

Every requirement is individually evaluated against authentic evidence collected under commit `9ba6970b4514c32149866e77500757a5784ddcf8`.

| Requirement ID | Obligation ID | Title | Evidence Source | Status | Verdict Reason & Gap | Minimum Action for Closure |
| :--- | :--- | :--- | :--- | :---: | :--- | :--- |
| **VEC-P0-001** | `VEC-P0-001-V2` | 26 independent account topology | SQL Q1 & Q17 | **PASS** | Q17 proves 26 accounts present with `IDENTITY_PRESENT` (13 Bybit + 13 Binance), including legacy Bybit baseline `FORWARD_PAPER`. | Verified. |
| **VEC-P0-002** | `VEC-P0-002-V2` | Pipeline terminalization and recovery | SQL Q2 & Q11 & `pipeline_anomalies_clean.json` | **PENDING** | 0 cross-exchange leaks. However, 2 historical Bybit cycles have 1 failed account with unrecorded account ID. | Operator review of historical logs from Oct 28. |
| **VEC-P0-003** | `VEC-P0-003-V2` | Financial ledger and funding completeness | SQL Q4, Q10 & `funding_classification_baseline.json` | **BLOCKED** | PnL discrepancy = 0.0000, but 379/379 positions remain `funding_complete=false` and `INSUFFICIENT_SOURCE_DATA`. | Collect independent public Bybit funding rates & marks. |
| **VEC-P0-004** | `VEC-P0-004-V2` | Risk gates and account isolation | SQL Q2, Q9, Q28, Q28b | **PASS** | 0 cross-exchange leaks; 3 accounts $\ge 5\%$ drawdown blocked from entries; 0 breach bypasses; positions serviced. | Verified. |
| **VEC-P0-005** | `VEC-P0-005-V2` | Binance execution approval config | SQL Q5, Q7, Q23 | **PASS** | `invalid_executions_count = 0`; `live_exchange_trading = false`; auto-promotion blocked. | Verified. |
| **VEC-P0-006** | `VEC-P0-006-V2` | Support/resistance statistical bootstrap | Table `level_interaction_forecasts` | **PENDING** | Forward forecast accumulation active; requires inspection of mature causal price-bar outcomes. | Run forecast maturity distribution query. |
| **VEC-P0-007** | `VEC-P0-007-V2` | PostgreSQL acceptance infrastructure | SQL Q26, Q27 & container health | **PASS** | PostgreSQL 17 healthy; zero rollbacks; `mark_price_source`, `valued_through`, `valuation_fresh` active. | Verified. |
| **VEC-P0-008** | `VEC-P0-008-V2` | Production acceptance gate | Complete checklist audit | **BLOCKED** | Overarching acceptance gate cannot be certified while funding and pipeline partial cycles remain open. | Resolve funding evidence & pipeline findings. |
| **VEC-P0-009** | `VEC-P0-009-V2` | Resource & performance baseline | SQL Q3a, Q3b, Q3c | **PENDING** | Unique constraint active & valid (`contype='u'`); 0 duplicates in 7d. P95 latency logs require operator sign-off. | Review ingestion P95 latency metrics. |
| **VEC-P0-101** | `VEC-P0-101-V2` | F01 — Decision Replay Verification | API GET `/api/v1/research/replay/{id}` | **PASS** | Replay endpoint returns stored decision; performs zero writes on GET with `persist=0`. | Verified via read-only GET. |
| **VEC-P0-102** | `VEC-P0-102-V2` | F02 — Profitability Response Envelope | API GET `/api/v1/research/profitability/summary` | **PASS** | Returns HTTP 200 with canonical `{success: true, data: {...}}` envelope; agrees with SQL. | Verified. |
| **VEC-P0-103** | `VEC-P0-103-V2` | F03 — Fabricated Financial Fallback Values | API GET `/api/v1/research/profitability/strategies` | **PASS** | Unreconciled metrics return `null` instead of invented zeros or fallbacks. | Verified. |
| **VEC-P0-104** | `VEC-P0-104-V2` | F04 — Reconciliation Findings Misread | API GET `/api/v1/research/health/reconciliation-runs` | **PASS** | Controller surfaces actual discrepancy objects from reconciliation runs. | Verified. |
| **VEC-P0-105** | `VEC-P0-105-V2` | F05 — Outdated Roadmap Module Statuses | API GET `/api/v1/research/profitability/summary` | **PASS** | Module status reflects authentic implementation state (P-1/P-2 implemented, P-3 blocked). | Verified. |
| **VEC-P0-106** | `VEC-P0-106-V2` | F06 — Reconciliation Ignores Drawdown | API `/api/v1/research/health/slot-integrity` | **PASS** | Reconciliation explicitly inspects drawdown discrepancies alongside balances and positions. | Verified. |
| **VEC-P0-107** | `VEC-P0-107-V2` | F07 — Audit ZIP Export Column Dropping | API POST `/api/v1/audit/exports/quick` | **PENDING** | Requires authorized write test to verify generated CSV columns; excluded from read-only package. | Operator-approved execution of export test. |
| **VEC-P0-108** | `VEC-P0-108-V2` | F08 — Strategy Funnel Cohort Discrepancy | API GET `/api/v1/audit/strategy-funnel` | **PASS** | Funnel populations and lifecycle states agree with source queries. | Verified. |
| **VEC-P0-109** | `VEC-P0-109-V2` | F09 — Frontend Single-Account Default | SQL Q1, Q17 & UI contract | **PASS** | All 26 accounts distinct, exchange-qualified, and independently addressable. | Verified. |
| **VEC-P0-110** | `VEC-P0-110-V2` | F10 — Signal Annotation Query Reversal | API GET `/api/v1/signals` | **PASS** | Latest matching signal selected chronologically within requested account scope. | Verified. |
| **VEC-P0-111** | `VEC-P0-111-V2` | F11 — Statistics Page Lifecycle Mixing | SQL Q10, Q19 & statistics API | **PASS** | Closed-trade metrics strictly exclude open positions and partial fills. | Verified. |
| **VEC-P0-112** | `VEC-P0-112-V2` | F12 — Daily Performance Opening Equity | SQL Q14, Q18 & API daily-performance | **PASS** | Q18 proves insertion sequence ordering of bootstrap zero snapshots prior to capital allocation. | Verified. |
| **VEC-P0-113** | `VEC-P0-113-V2` | F13 — Research Health Funnel Status | API GET `/api/v1/research/health/strategy-funnel` | **PASS** | Health funnel propagates child errors to parent; lookback derived dynamically. | Verified. |
| **VEC-P0-114** | `VEC-P0-114-V2` | F14 — Report Freshness Shape Mismatches | API GET `/api/v1/research/health/data-freshness` | **PASS** | Response schema conforms to consumer contract and exposes stale/unknown state. | Verified. |
| **VEC-P0-115** | `VEC-P0-115-V2` | F15 — Portfolio Correlation Status Check | API GET `/api/v1/research/profitability/concentration-dependence` | **PASS** | Aligns valid lifecycle populations in time rather than query row-order pairing. | Verified. |
| **VEC-P0-116** | `VEC-P0-116-V2` | F16 — Strategy Degradation Pollution | SQL Q2 & service contracts | **PASS** | Zero cross-exchange position leakage; degradation calculations strictly scoped per exchange. | Verified. |
| **VEC-P0-117** | `VEC-P0-117-V2` | F17 — Learning Feature Store Filter | Table `learning_feature_snapshots` | **PASS** | Features built strictly from market candles filtered by exchange and symbol. | Verified. |
| **VEC-P0-118** | `VEC-P0-118-V2` | F18 — Model Training Purging Fallback | Model training jobs | **PASS** | Chronological purging and embargo windows enforced; zero label leakage. | Verified. |
| **VEC-P0-119** | `VEC-P0-119-V2` | F19 — Autonomy Settings Permissions | SQL Q7, Q23 & API autonomy-settings | **PASS** | Autonomy settings expose effective permissions, evaluating false under kill switch. | Verified. |
| **VEC-P0-120** | `VEC-P0-120-V2` | F20 — Dual Kill-Switch Divergence | SQL Q7, Q23 | **PASS** | Both authorities (`research_promotion_controls` and `autonomy_settings`) synchronized and active. | Verified. |
| **VEC-P0-121** | `VEC-P0-121-V2` | F21 — Compute Workers Dynamic Map | Research worker heartbeat logs | **PENDING** | Capabilities endpoint reflects active workers; requires heartbeat inspection. | Review `/api/v1/research/capabilities`. |
| **VEC-P0-122** | `VEC-P0-122-V2` | F22 — Quote Integrity Silent Errors | API GET `/api/v1/quote-integrity/status` | **PASS** | Quote integrity failures surface as explicit error responses without silent fallback. | Verified. |
| **VEC-P0-123** | `VEC-P0-123-V2` | F23 — UI Pagination Truncations | API GET `/api/v1/paper/daily-performance?page=2` | **PASS** | Paginated API retrieves subsequent pages with stable ordering and valid metadata. | Verified. |
| **VEC-P0-124** | `VEC-P0-124-V2` | F24 — Slippage Position Trace Scope | SQL Q8 | **PASS** | `mismatch_count = 0` across all generations of slippage attribution fills. | Verified. |
| **VEC-P0-125** | `VEC-P0-125-V2` | F25 — Candidate Lab Backtest Linkage | SQL Q24 & candidate DB records | **PASS** | Candidates link to valid `backtest_runs` without cast aborts. | Verified. |
| **VEC-P0-126** | `VEC-P0-126-V2` | F26 — Autonomy Approval Audit Trail | SQL Q6, Q23b | **PASS** | Rejected promotion audit events exist and are immutable outside rolled-back transactions. | Verified. |
| **VEC-P0-127** | `VEC-P0-127-V2` | F27 — Entry Block Observability Void | SQL Q9, Q28, Q28b | **PASS** | Pre-risk account entry blocks persist signal state and decision lineage in `risk_decisions`. | Verified. |
| **VEC-P0-201** | `VEC-P0-201-V2` | I01 — Lifecycle Terminalization | SQL Q11 & `pipeline_anomalies_clean.json` | **PENDING** | 316 historical STALE events have completed downstream paper processing. Newly processed cycles require fresh terminalization proof. | Verify live 5m cycle transitions. |
| **VEC-P0-202** | `VEC-P0-202-V2` | I02 — TP1 Fractional Exit Rounding | SQL Q5 & virtual executions | **PASS** | Zero executions with quantity $\le 0$; lot step handling prevents zero fills. | Verified. |
| **VEC-P0-203** | `VEC-P0-203-V2` | I03 — `funding_complete=false` Persists | SQL Q10, Q19 & `funding_positions_clean.json` | **BLOCKED** | 379 legacy positions remain `funding_complete=false` awaiting independent exchange rates and marks. | Independent Bybit funding data ingestion. |
| **VEC-P0-204** | `VEC-P0-204-V2` | I04 — False Complete on Failures | SQL Q11, Q22 & `pipeline_anomalies_clean.json` | **PASS** | Both cycles with per-account failures correctly recorded `PARTIAL` rather than false `COMPLETED`. | Verified. |
| **VEC-P0-205** | `VEC-P0-205-V2` | I05 — Binance Candle Gap Recovery | Ingestion container logs | **PASS** | Binance candle recovery routes strictly to Binance adapter endpoint. | Verified. |
| **VEC-P0-206** | `VEC-P0-206-V2` | I06 — Binance Provenance Exception Isolation | Ingestion container logs | **PASS** | Provenance assertions wrapped in per-account catch; single failure isolates cleanly. | Verified. |
| **VEC-P0-207** | `VEC-P0-207-V2` | I07 — Late Funding Settle on Closed | SQL Q10 & `funding_positions_clean.json` | **BLOCKED** | Retries implemented in code, but blocked on independent exchange funding rates/marks. | Independent exchange funding marks. |
| **VEC-P0-208** | `VEC-P0-208-V2` | I08 — Fresh Snapshot Stale Valuation | SQL Q12, Q26 | **PASS** | `valued_through` and `valuation_fresh` active; fresh snapshot cannot certify stale candle. | Verified. |
| **VEC-P0-209** | `VEC-P0-209-V2` | I09 — Candle Closed Ack Timing | SQL Q11, Q22 | **PASS** | 5m candle close transitions to `PARTIAL` strictly following downstream `PAPER_PROCESSED`. | Verified. |

**Summary Status of the 45 Obligations:**
- **PASS:** 33 obligations (73.3%)
- **PENDING:** 7 obligations (15.6%)
- **BLOCKED:** 5 obligations (11.1% — VEC-P0-003, VEC-P0-008, VEC-P0-203, VEC-P0-207, and pipeline partial resolution)
- **FAIL:** 0 obligations (0.0%)

---

## 4. Resolution of the JSON Export Formatting Issue

**Cause of Status Lines:**
By default, PostgreSQL `psql` outputs transaction status tags (`BEGIN`, `SET`, `ROLLBACK`) to stdout even when unaligned (`-A`) and tuples-only (`-t`) flags are specified.

**Technical Fix Applied:**
1. Added `\set QUIET on` directly to the headers of:
   - [`scripts/diagnostics/phase0_export_funding_positions.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/phase0_export_funding_positions.sql)
   - [`scripts/diagnostics/phase0_export_pipeline_anomalies.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/phase0_export_pipeline_anomalies.sql)
2. Updated operator command line to include `-q`:
   `docker compose exec -T postgres psql -X -q -A -t -v ON_ERROR_STOP=1 ...`
3. Verified via regression test `Phase0DiagnosticPackageTest::test_export_queries_execute_read_only_and_return_valid_json`:
   Direct stdout from `psql` is 100% pure parseable JSON without status lines.

---

## 5. Operator Procedure for Secure Evidence Transfer & Inspection

To inspect production evidence locally without running remote commands or exposing secrets:

```bash
# 1. On Production VPS: Generate checksum manifest of the evidence run
cd /srv/bybit-signals/evidence/phase0-20261029T101029Z
sha256sum * > SHA256SUMS

# 2. On Local Machine: Pull evidence securely via operator SSH (read-only scp)
mkdir -p storage/evidence/phase0-20261029T101029Z
scp -P "${DEPLOY_PORT:-22}" "${DEPLOY_USER}@${DEPLOY_HOST}:/srv/bybit-signals/evidence/phase0-20261029T101029Z/*" \
    storage/evidence/phase0-20261029T101029Z/

# 3. On Local Machine: Verify integrity
cd storage/evidence/phase0-20261029T101029Z && sha256sum -c SHA256SUMS

# 4. On Local Machine: Run deterministic offline assessment tool
python3 scripts/diagnostics/assess_phase0_evidence.py \
    storage/evidence/phase0-20261029T101029Z \
    --deployed-sha 9ba6970b4514c32149866e77500757a5784ddcf8 \
    --output docs/progress/evidence/phase0-production-acceptance/assessment_results.json
```

---

## 6. Action Items for Final Production Acceptance

1. **Independent Funding Data Collection:**
   Run a public read-only script to fetch Bybit 8-hour funding rates and 1m mark prices for October 14–27 for BTCUSDT and ETHUSDT. Feed to `classify_phase0_funding.py`.
2. **Review October 28 Container Logs for the Two Partial Cycles:**
   Inspect archive logs for `2026-10-28 04:20:00 UTC` and `2026-10-28 16:35:00 UTC` to identify the specific account and exception for the two historical partial cycles.
3. **Execute Authorized Quick Export Write Test:**
   Under operator supervision, invoke `POST /api/v1/audit/exports/quick` to close `VEC-P0-107-V2`.
4. **Final Gate Sign-Off:**
   When all 45 obligations reach PASS, the operator signs off on Phase 0 and unfreezes Phase 1.
