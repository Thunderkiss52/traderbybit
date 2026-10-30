# VECTOR Phase 0 Operator Production Acceptance Checklist

**Document Version:** V2.0 (Authoritative Production Operational Acceptance Specification)
**Governing Standards:** [`AGENTS.md`](file:///home/thunder/work/traderbybit/AGENTS.md), [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md), [`VECTOR_GO_FIRST_ROADMAP_V3.md`](file:///home/thunder/work/traderbybit/docs/roadmap/VECTOR_GO_FIRST_ROADMAP_V3.md)
**Target Scope Baseline:** `VECTOR-SCOPE-20261027-v1` (45 Phase 0 Requirements, 45 Operational V2 Obligations)
**Operating Safety Invariants:**
1. **Strictly Virtual PAPER Mode:** Real exchange orders are strictly prohibited. Zero trade credentials, zero order placement endpoints.
2. **26 Independent Accounts:** 13 Bybit PRIMARY + 13 Binance PRIMARY accounts with completely isolated balances, positions, and ledgers.
3. **Strictly Read-Only Execution:** Zero database mutations, zero schema alterations, zero account resets, zero unauthorized repairs.
4. **Defensive Transaction Framing:** Diagnostics execute wrapped in `BEGIN TRANSACTION READ ONLY` and terminate with an explicit `ROLLBACK`.
5. **Session Safety Pragmas:** Enforce `psql -X -v ON_ERROR_STOP=1` with `statement_timeout = '30s'` and `lock_timeout = '5s'`.
6. **Zero Credential Leaks:** No passwords, private keys, or API tokens may appear in command lines, outputs, or evidence files.
7. **ECONOM Isolation:** `ECONOM / econom-group` resources are strictly isolated and must never be touched.

---

## 1. Safety Architecture & Query Semantics

### 1.1 Read-Only Transaction & Execution Guarantees
All production database diagnostics in [`scripts/diagnostics/phase0_production_acceptance.sql`](file:///home/thunder/work/traderbybit/scripts/diagnostics/phase0_production_acceptance.sql) must be executed inside a strictly read-only transaction:
- **`psql -X`:** Disables reading `.psqlrc`, preventing user aliases, custom formatting, or unintended initialization commands.
- **`-v ON_ERROR_STOP=1`:** Halts execution immediately upon any SQL error, preventing cascading errors or masked failures.
- **`BEGIN TRANSACTION READ ONLY;`:** PostgreSQL rejects any DML (`INSERT`, `UPDATE`, `DELETE`) or DDL (`DROP`, `ALTER`, `CREATE`) at the storage engine level.
- **`SET LOCAL statement_timeout = '30s';`:** Cancels any statement that takes longer than 30 seconds, protecting database CPU and I/O.
- **`SET LOCAL lock_timeout = '5s';`:** Prevents waiting more than 5 seconds for any lock, protecting live ingestion workers from contention.
- **`ROLLBACK;`:** Concludes the session by discarding any potential transaction state, guaranteeing absolute immutability.

### 1.2 Query 3 Verification Semantics (Candle Ingestion Deduplication & Conflict Isolation)
In PostgreSQL, uniqueness for market candles is enforced by the constraint:
`market_candles_identity_unique UNIQUE (exchange, category, symbol, interval, open_time)`

To ensure diagnostic safety without risking query timeouts or buffer pool thrashing on production tables with millions of historical candles, Query 3 is split into distinct verification layers:
1. **Query 3a (Catalog Constraint Verification):** Inspects `pg_constraint` and `pg_index` to prove mathematically that `market_candles_identity_unique` is active (`contype = 'u'`) and valid (`indisvalid = true`).
2. **Query 3b (Operational 7-Day Window Deduplication):** Executes an index-supported query over the recent operational window (`open_time >= NOW() - INTERVAL '7 days'`) matching the 7-day `max_age_days` SLA of V2 obligations, proving zero duplicate rows exist in active ingestion.
3. **Query 3c (24-Hour Active Freshness & Ingestion Health):** Aggregates candle counts, timestamps, and last update marks per exchange and symbol over the last 24 hours.
4. **Separate Binance Immutable Conflict Isolation:**
   - *Critical Semantics Notice:* Zero duplicate rows in PostgreSQL does **NOT** prove zero candle conflicts. When Binance ingestion encounters an immutable candle with conflicting values, it rejects the incoming observation, throws a `DomainException`, and logs a structured error in application logs:
     `[Binance immutable candle validation failed]`
   - The stored row in `market_candles` remains unaltered. Therefore, Binance immutable conflict evidence is collected separately from application logs (Step 3 in the execution procedure) and must not be conflated with database deduplication.

---

## 2. Complete 45-Row Production V2 Acceptance Matrix

Every obligation below corresponds 1:1 to an authoritative requirement in `docs/progress/vector-requirements.json`. All obligations are initialized to **`PENDING`** until executed and verified by the operator on the production VPS.

| Req ID | Obligation ID | Requirement Title | Evidence Source | Expected Verification Predicate | Coverage Window | Evaluation Rule (PASS / FAIL / PENDING) | Status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :---: |
| **VEC-P0-001** | `VEC-P0-001-V2` | 26 independent PAPER account topology | SQL Query 1 & Query 13 | Exactly 13 Bybit + 13 Binance PRIMARY strategy identities; include legacy Bybit baseline `FORWARD_PAPER`; independent histories and approved capital | Current live state | PASS only if 26 distinct expected exchange/strategy pairs and history continuity verified; zero current equity alone is not a census failure | **PENDING** |
| **VEC-P0-002** | `VEC-P0-002-V2` | PAPER pipeline terminalization and recovery | SQL Query 2 & Query 11 | 0 cross-exchange leaks; pipeline terminalization events recorded without stuck stages | Last 7 days | PASS if mismatch_count=0 and pipeline terminalizes cleanly; FAIL if leaks exist | **PENDING** |
| **VEC-P0-003** | `VEC-P0-003-V2` | Financial ledger and funding completeness | SQL Query 4 & Query 10 | Realized Net PnL discrepancy <= 0.0001; closed positions have funding_complete=true | All closed positions | PASS if discrepancy=0 and funding resolved; FAIL if financial identities diverge | **PENDING** |
| **VEC-P0-004** | `VEC-P0-004-V2` | Risk gates and account isolation | SQL Query 2 & Query 9 | 0 cross-exchange leaks; accounts with drawdown >= 5% have entry blocked; existing positions serviced | Current live state | PASS if cross-exchange leaks=0 and drawdown limits enforced; FAIL otherwise | **PENDING** |
| **VEC-P0-005** | `VEC-P0-005-V2` | Binance execution approval configuration | SQL Query 5 & Query 7 | invalid_executions_count=0 (quantity > 0, price > 0); live_exchange_trading=false | All executions | PASS if invalid_count=0 and live trading disabled; FAIL otherwise | **PENDING** |
| **VEC-P0-006** | `VEC-P0-006-V2` | Support/resistance statistical bootstrap | DB table `level_interaction_forecasts` | Forward observation accumulation recorded; forecasts mature causal price-bar outcomes | Last 7 days | PASS if mature forecasts exist without lookahead; FAIL if sample counts fabricated | **PENDING** |
| **VEC-P0-007** | `VEC-P0-007-V2` | PostgreSQL acceptance infrastructure | Docker Compose & migration logs | PostgreSQL 17 container healthy; zero migration rollbacks; connection pool stable | Current deployment | PASS if DB healthy and all migrations applied cleanly; FAIL otherwise | **PENDING** |
| **VEC-P0-008** | `VEC-P0-008-V2` | Production acceptance gate | Complete checklist execution | Operator-authorized runtime evidence collected for current release; all gates reviewed | Current release SHA | PASS only when all 45 obligations are verified with authentic evidence; FAIL otherwise | **PENDING** |
| **VEC-P0-009** | `VEC-P0-009-V2` | Resource and performance baseline | SQL Query 3a, 3b, 3c & Ingestion telemetry | Unique constraint active; zero duplicates in 7d; production P95 ingestion latency logged | Last 7 days | PASS if constraint valid, duplicates=0, and latency monitored; FAIL otherwise | **PENDING** |
| **VEC-P0-101** | `VEC-P0-101-V2` | F01 — False Decision Replay Verification & State Mutation | API `GET /api/v1/research/replay/{id}` | Replay endpoint returns stored decision; performs zero writes on read request | Real-time query | PASS if HTTP 200, zero DB mutations on GET; FAIL if write occurs | **PENDING** |
| **VEC-P0-102** | `VEC-P0-102-V2` | F02 — Profitability Response Envelope Chain Breakdown | API `GET /api/v1/research/profitability/summary` & Query 4 | Canonical response envelope `{success: true, data: {...}}`; agrees with SQL Query 4 | Real-time query | PASS if envelope schema matches and net PnL matches SQL; FAIL otherwise | **PENDING** |
| **VEC-P0-103** | `VEC-P0-103-V2` | F03 — Fabricated Financial Fallback Values in UI | API `GET /api/v1/research/profitability/strategies` | Unreconciled accounts return `null`, never invented fallback equity or zero PnL | Real-time query | PASS if unreconciled accounts show null/unavailable; FAIL if fabricated | **PENDING** |
| **VEC-P0-104** | `VEC-P0-104-V2` | F04 — Reconciliation Findings Misread in Controller | API `GET /api/v1/research/health/reconciliation-runs` | Controller consumes actual reconciliation findings; surfaces incomplete/mismatched data | Last 7 days | PASS if reconciliation report exposes actual discrepancies; FAIL otherwise | **PENDING** |
| **VEC-P0-105** | `VEC-P0-105-V2` | F05 — Outdated Roadmap Module Statuses | API `GET /api/v1/research/profitability/summary` | Module status reflects actual implementation state (P-1/P-2 implemented, P-3 blocked) | Real-time query | PASS if module statuses reflect authentic implementation state; FAIL otherwise | **PENDING** |
| **VEC-P0-106** | `VEC-P0-106-V2` | F06 — Reconciliation Ignores Drawdown Mismatch | API `/api/v1/research/health/slot-integrity` & Query 9 | Reconciliation detects drawdown mismatch in addition to balance and position mismatches | Current live state | PASS if drawdown checks are included in reconciliation; FAIL otherwise | **PENDING** |
| **VEC-P0-107** | `VEC-P0-107-V2` | F07 — Audit ZIP Export Silent Column Dropping | API `POST /api/v1/audit/exports/quick` | Generated audit export preserves all declared schema columns without dropping | Real-time test | PASS if exported CSVs contain all declared columns; FAIL if columns dropped | **PENDING** |
| **VEC-P0-108** | `VEC-P0-108-V2` | F08 — Strategy Funnel Cohort & Status Discrepancy | API `GET /api/v1/audit/strategy-funnel` | Funnel populations, lifecycle states, and exchange scopes agree with source queries | Last 7 days | PASS if funnel counts match underlying signal/position counts; FAIL otherwise | **PENDING** |
| **VEC-P0-109** | `VEC-P0-109-V2` | F09 — Frontend Single-Account Display Default Blinds Multi-Account System | SQL Query 1 & UI Inspection | All 26 accounts selectable with exchange-qualified identity and distinct values | Current live state | PASS if all 26 accounts distinct and selectable in UI/API; FAIL otherwise | **PENDING** |
| **VEC-P0-110** | `VEC-P0-110-V2` | F10 — Signal Annotation Query Reversal and Account Scope Defect | API `GET /api/v1/signals` | Signal annotation selects latest matching record within requested account scope | Last 24 hours | PASS if signal annotations respect chronological order and scope; FAIL otherwise | **PENDING** |
| **VEC-P0-111** | `VEC-P0-111-V2` | F11 — Statistics Page Lifecycle Population Mixing | SQL Query 10 & API statistics | Closed-trade metrics do not mix open/partial positions or count partial exits as trades | All closed trades | PASS if closed trade population strictly excludes open positions; FAIL otherwise | **PENDING** |
| **VEC-P0-112** | `VEC-P0-112-V2` | F12 — Daily Performance Marks Missing Opening as Complete | SQL Query 12 & Query 14 & API `GET /api/v1/paper/daily-performance` | Daily performance without verified opening equity marked incomplete, not certified | Last 7 days | PASS if opening and closing provenance verified, with pre-capital states separated; zero equity can be legitimate | **PENDING** |
| **VEC-P0-113** | `VEC-P0-113-V2` | F13 — Research Health Funnel False Healthy Parent Status and Hardcoded Lookback | API `GET /api/v1/research/health/strategy-funnel` | Research health propagates insufficient child evidence; lookback derived dynamically | Real-time query | PASS if child errors propagate to parent status; FAIL if false healthy reported | **PENDING** |
| **VEC-P0-114** | `VEC-P0-114-V2` | F14 — Research Report Freshness Shape Mismatches | API `GET /api/v1/research/health/data-freshness` | Report freshness fields conform to actual service shape and expose stale/unknown state | Real-time query | PASS if JSON schema matches consumer contract; FAIL if fields missing | **PENDING** |
| **VEC-P0-115** | `VEC-P0-115-V2` | F15 — Portfolio Correlation Invalid Status Check and Trade-Order Pairing | API `GET /api/v1/research/profitability/concentration-dependence` | Correlation aligns valid lifecycle populations in time, not query order pairing | Last 30 days | PASS if correlation time-alignment is chronological; FAIL if paired by row ID | **PENDING** |
| **VEC-P0-116** | `VEC-P0-116-V2` | F16 — Strategy Degradation Cross-Exchange Pollution | SQL Query 2 & Degradation service logs | Degradation calculations do not pool Bybit and Binance histories | Last 7 days | PASS if degradation metrics calculated independently per exchange; FAIL otherwise | **PENDING** |
| **VEC-P0-117** | `VEC-P0-117-V2` | F17 — Learning Feature Store Missing Exchange Filter | DB table `learning_feature_snapshots` | Learning features filter exchange-qualified populations before model inputs | Last 7 days | PASS if feature snapshots contain valid exchange_id filter; FAIL if cross-pooled | **PENDING** |
| **VEC-P0-118** | `VEC-P0-118-V2` | F18 — Model Training Purging Fallback Leakage | Model training job logs | Training fallback preserves chronological purging; zero future label leakage | Last 7 days | PASS if chronological purging enforced; FAIL if future data leaked | **PENDING** |
| **VEC-P0-119** | `VEC-P0-119-V2` | F19 — Autonomy Settings Raw Flags vs Permissions | SQL Query 7 | Autonomy settings expose effective permissions, not only configured raw flags | Current live state | PASS if effective permissions evaluate false under kill switch; FAIL otherwise | **PENDING** |
| **VEC-P0-120** | `VEC-P0-120-V2` | F20 — Dual Kill-Switch Divergence & Blindness | SQL Query 7 | Both kill-switch authorities agree on effective blocking state; zero divergence | Current live state | PASS if both authorities synchronized and visible; FAIL if divergent | **PENDING** |
| **VEC-P0-121** | `VEC-P0-121-V2` | F21 — Compute Workers Static Capability Map | Research worker heartbeat logs | Research capabilities reflect actual supported worker/version availability | Last 24 hours | PASS if dynamic capabilities match active workers; FAIL if static hardcoded | **PENDING** |
| **VEC-P0-122** | `VEC-P0-122-V2` | F22 — Quote Integrity Silent Error Swallowing | API `GET /api/v1/quote-integrity/status` | Quote integrity failures surface as explicit errors (e.g. HTTP 503), not silent empty data | Real-time query | PASS if errors propagate explicitly; FAIL if swallowed silently | **PENDING** |
| **VEC-P0-123** | `VEC-P0-123-V2` | F23 — UI Pagination Truncations | API `GET /api/v1/paper/daily-performance?page=2` | Paginated API retrieves subsequent pages with stable ordering, no truncation | Real-time query | PASS if page 2 retrieval succeeds with valid meta; FAIL if truncated | **PENDING** |
| **VEC-P0-124** | `VEC-P0-124-V2` | F24 — Slippage Position Trace Scope | SQL Query 8 | Slippage position trace uses requested qualified scope; mismatch_count=0 | Last 7 days | PASS if mismatch_count=0 across all generations; FAIL if cross-account fills | **PENDING** |
| **VEC-P0-125** | `VEC-P0-125-V2` | F25 — Candidate Lab Immediate Evaluation Rejection Due to Missing Backtest Linkage | Candidate strategy DB records | Candidate validation durably links owned backtest run; distinguishes queued vs rejected | Last 7 days | PASS if candidate strategies link to valid backtest runs; FAIL otherwise | **PENDING** |
| **VEC-P0-126** | `VEC-P0-126-V2` | F26 — Autonomy Approval Audit Trail Loses Rejected Promotions | SQL Query 6 | Rejected promotions persist immutable audit event outside any rolled-back transaction | All audit events | PASS if rejected promotion audit events exist and are immutable; FAIL otherwise | **PENDING** |
| **VEC-P0-127** | `VEC-P0-127-V2` | F27 — Account-Level Entry Block Observability Void | SQL Query 9 | Pre-risk account entry blocks persist signal state and decision lineage | Last 7 days | PASS if entry-blocked accounts record structured reasons and lineage; FAIL otherwise | **PENDING** |
| **VEC-P0-201** | `VEC-P0-201-V2` | I01 — Lifecycle: `SIGNALS_EVALUATED` never correctly terminalizes | SQL Query 11 | `SIGNALS_EVALUATED` stage terminalizes cleanly; zero stuck pipeline events | Last 7 days | PASS if pipeline events transition beyond evaluation; FAIL if permanently stuck | **PENDING** |
| **VEC-P0-202** | `VEC-P0-202-V2` | I02 — TP1 fractional exit can round to zero | SQL Query 5 & Execution logs | Virtual execution quantities strictly positive; TP1 exits do not round to zero | All closed positions | PASS if all execution quantities > 0; FAIL if zero-quantity fill occurs | **PENDING** |
| **VEC-P0-203** | `VEC-P0-203-V2` | I03 — `funding_complete=false` persists for all positions | SQL Query 10 | Closed positions resolve funding settlement; funding_complete transitions to true | All closed positions | PASS if closed settled positions have funding_complete=true; FAIL otherwise | **PENDING** |
| **VEC-P0-204** | `VEC-P0-204-V2` | I04 — `PAPER_PROCESSED=COMPLETED` despite per-account failures | SQL Query 11 | Pipeline event status does not certify `COMPLETED` when individual accounts fail | Last 7 days | PASS if per-account failure marks stage FAILED or PARTIAL; FAIL if false complete | **PENDING** |
| **VEC-P0-205** | `VEC-P0-205-V2` | I05 — Binance 5m candle gap invokes Bybit-only recovery | Ingestion container logs | Binance candle gap recovery invokes Binance adapter endpoint, never Bybit | Last 7 days | PASS if Binance recovery routes strictly to Binance; FAIL if Bybit cross-invoked | **PENDING** |
| **VEC-P0-206** | `VEC-P0-206-V2` | I06 — Binance provenance exception outside per-account catch | Ingestion container logs | Provenance exceptions caught within per-account loop; does not abort entire batch | Last 7 days | PASS if single account provenance failure isolates cleanly; FAIL if batch crashes | **PENDING** |
| **VEC-P0-207** | `VEC-P0-207-V2` | I07 — Late funding may never settle on closed positions | SQL Query 10 | Late-arriving funding marks settle cleanly against closed positions | All closed positions | PASS if late funding events attach to closed positions; FAIL if dropped | **PENDING** |
| **VEC-P0-208** | `VEC-P0-208-V2` | I08 — Fresh snapshot timestamp can certify stale market valuation | SQL Query 12 | Valuation provenance verified; fresh snapshot cannot certify stale candle mark | Last 7 days | PASS if snapshot valuation timestamp matches fresh market mark; FAIL otherwise | **PENDING** |
| **VEC-P0-209** | `VEC-P0-209-V2` | I09 — 5m `CANDLE_CLOSED` acknowledged before downstream PAPER completion | SQL Query 11 | 5m candle closed event acknowledged only after downstream PAPER processing completes | Last 7 days | PASS if candle close acknowledgment strictly follows PAPER pipeline; FAIL otherwise | **PENDING** |

---

## 3. Operator Execution Procedure on Production VPS

Execute the following steps directly on the production host following owner-controlled deployment.

### Step 1: Pre-Flight Environment & Health Verification
Confirm the deployed Git commit SHA and verify that all VECTOR containers are healthy:
```bash
cd /srv/bybit-signals/current

# 1. Verify deployed commit SHA matches GitHub Actions release
git rev-parse HEAD

# 2. Verify all Docker Compose containers are healthy
docker compose ps
```

### Step 2: Execute Hardened Read-Only Diagnostic SQL
Run the diagnostic suite using the containerized `postgres` service with strict error stopping and full log capture:
```bash
# Capture full output, timestamps, and exit code without credential leakage
{
  echo "=== VECTOR PHASE 0 DIAGNOSTICS EXECUTION START ==="
  date -u +"TIMESTAMP_UTC=%Y-%m-%dT%H:%M:%SZ"
  echo "DEPLOYED_SHA=$(git rev-parse HEAD)"
  docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1     -U "${POSTGRES_USER:-bybit_signals}"     -d "${POSTGRES_DB:-bybit_signals}"     < scripts/diagnostics/phase0_production_acceptance.sql
  EXIT_CODE=$?
  echo "EXIT_CODE=${EXIT_CODE}"
  date -u +"TIMESTAMP_END_UTC=%Y-%m-%dT%H:%M:%SZ"
  echo "=== VECTOR PHASE 0 DIAGNOSTICS EXECUTION END ==="
} 2>&1 | tee docs/progress/evidence/phase0-production-acceptance/production_diagnostic.log
```

### Step 3: Collect Application Logs for Binance Immutable Conflict Diagnostics
Inspect application container logs for structured immutable candle conflict telemetry:
```bash
docker compose logs --since 7d app | grep -E "Binance immutable candle validation failed"   > docs/progress/evidence/phase0-production-acceptance/binance_conflict_telemetry.log || true
```

### Step 4: Verify Read-Only API Health Endpoints
Query the local web container endpoint directly via localhost port `18080` (no public DNS or secrets exposed):
```bash
# 1. Data Freshness health
curl -fsS http://127.0.0.1:18080/api/v1/research/health/data-freshness   | jq . > docs/progress/evidence/phase0-production-acceptance/health_freshness.json

# 2. Profitability summary
curl -fsS http://127.0.0.1:18080/api/v1/research/profitability/summary   | jq . > docs/progress/evidence/phase0-production-acceptance/profitability_summary.json

# 3. Quote integrity status
curl -fsS http://127.0.0.1:18080/api/v1/quote-integrity/status   | jq . > docs/progress/evidence/phase0-production-acceptance/quote_integrity_status.json
```

### Step 5: Evaluate Obligations & Update Sign-Off Record
1. Review the output in `production_diagnostic.log`, `binance_conflict_telemetry.log`, and the JSON endpoint responses.
2. Evaluate each of the 45 rows in Section 2 against its exact expected predicate.
3. Record the evaluation in `docs/progress/evidence/phase0-production-acceptance/operator_signoff.json`.
4. Regenerate the authoritative Phase 0 scorecard:
   ```bash
   python3 scripts/vector_progress.py --phase 0 --generate
   ```


## 2026-10-29 integrity investigation addendum

SQL exit 0 establishes query execution only. Every V2 remains PENDING until its
individual predicate is reviewed on the release under acceptance. Supplied October
25–28 aggregates are historical evidence, not current verification of this patch.
Queries 13–16 supplement the original matrix; they do not replace non-SQL evidence.

- Census: enumerate `is_primary` and exact exchange/strategy pairs. The canonical
  Bybit baseline intentionally uses `FORWARD_PAPER`; no account migration is required.
- Snapshots: inspect IDs, source keys and capital-event linkage. Snapshot IDs are
  insertion sequences; equal timestamps alone cannot establish order. Zero equity
  is a value requiring classification, not automatically corrupt data.
- Funding: export Query 16 and independently obtain the historical exchange schedule,
  rates and boundary marks. Null mark provenance means legacy/unverified. Run the
  offline classifier described in `REMEDIATION_20261029.md`; no Artisan reconciliation,
  backfill, account reset or automatic `funding_complete` update is authorized.
- Pipeline: inspect each Query 15 row and matching downstream event. STALE excludes
  late fills; PARTIAL requires failed-account identities. Missing legacy identities
  are missing evidence, not permission to replay all peers. Preserve the original rows.
- Risk: Query 9 reports threshold requirements and stored status; it does not prove
  that a fill was rejected or that open positions were serviced. Match decision and
  execution times to account equity history and processing logs.
- Autonomy: the stored capability alone is not an effective promotion permission.
  Check both kill-switch authorities, configuration, and approval audit. Research
  eligibility, prediction-model champion selection, isolated candidate promotion,
  and PRIMARY replacement are distinct mechanisms.

Minimum next round: run the revised read-only SQL against the owner-selected release,
retain complete output and SHA, and provide rows from Queries 13–16 plus independently
validated funding schedules/marks. Do not run mutation-based checklist examples
(including audit-export POSTs) under this read-only handoff. Browser/API obligations
require separate, source-reviewed read-only evidence; absent evidence remains PENDING.

## 2026-10-29 evidence closure package

Use [`EVIDENCE_CLOSURE_20261029.md`](file:///home/thunder/work/traderbybit/docs/progress/evidence/phase0-production-acceptance/EVIDENCE_CLOSURE_20261029.md)
for the bounded row-level closure run. It adds
`scripts/diagnostics/phase0_evidence_closure.sql` without replacing this matrix
or the original 16-query suite.

The closure run is strictly read-only. It does not authorize the audit-export
`POST` example above, any autonomy settings mutation, any funding repair, any
pipeline replay, or any migration rollback. Those obligations remain PENDING
until separately approved evidence is reviewed.

---

## 4. Formal Operator Attestation Specification & Workflow

### 4.1 Purpose & Governance Principles
To transition Phase 0 from informal, ambiguous sign-off into an immutable, verifiable engineering record, every Phase 0 evaluation must terminate with a formal, evidence-backed **Operator Attestation**.

1. **Evidence-Based Decision:** An attestation records a human operator's evidence-based finding. It does **not** falsely claim that unverified anomalies were magically resolved, nor does it imply an automatic transfer of legal liability.
2. **Strict Gate Behavior:**
   - **Missing Evidence = NOT VERIFIED:** If diagnostic logs, SQL outputs, or API responses are missing, the obligation is strictly `NOT VERIFIED`. It cannot be assumed to pass.
   - **No Informal Overrides:** A failed or missing mandatory gate cannot be overridden by oral agreement, chat messages, or informal comments.
   - **Approved Exceptions Required:** Any gate approved despite an anomaly must be recorded with explicit scope, rationale, risk assessment, and operator decision in the `unresolved_exceptions` ledger.
   - **Exact SHA Binding:** Evidence collected for a specific Git SHA cannot be reused for a different SHA without fresh, documented compatibility verification.
   - **Successful Deployment Alone != Acceptance:** A passing deployment health check or green CI pipeline proves container startup only, not financial correctness, ledger reconciliation, or data integrity.
   - **Zero Automatic Acceptance:** The application or test harness must never generate self-attestation or mark Phase 0 accepted without human operator execution.

### 4.2 Required Attestation Fields
Every authoritative attestation record must contain the following 16 canonical fields:

1. `attestation_schema_version`: Version of the attestation contract (e.g. `vector.attestation.phase0.v1`).
2. `environment_identity`: Target environment identifier (e.g. `production-vps-primary`).
3. `deployed_git_sha`: Exact 40-character commit SHA deployed and inspected.
4. `docker_image_digests`: Map of service names to immutable image digests (`sha256:...`).
5. `verification_timestamp_utc`: ISO 8601 UTC timestamp when verification occurred.
6. `operator_identity`: Name, title, and public key or email of the authorized human operator.
7. `requirement_gate_evaluations`: Map of all 45 V2 obligations + 9 P0 requirements to explicit status: `PASS`, `FAIL`, or `NOT_VERIFIED`.
8. `sql_diagnostic_manifest`: List of diagnostic scripts (`phase0_production_acceptance.sql`, etc.), SHA-256 checksums, execution results, and exit codes.
9. `postgresql_readonly_evidence`: Transaction read-only confirmation, duration, query error counts (must be 0).
10. `financial_reconciliation_summary`: Net PnL discrepancy metric (must be $\le 0.0001\text{ USDT}$), double-entry balance status.
11. `paper_census_summary`: Verified census of all 26 independent accounts (13 Bybit + 13 Binance PRIMARY accounts, isolated equity).
12. `pipeline_terminalization_summary`: Pipeline terminalization evidence (zero cross-exchange leaks, zero permanently stuck stages).
13. `unresolved_exceptions`: Array of structured exception objects (`requirement_id`, `scope`, `rationale`, `mitigation`, `operator_decision`).
14. `evidence_hashes`: Cryptographic SHA-256 hashes for all captured log and JSON evidence files.
15. `operator_declaration`: Exact mandatory human-readable approval text:
    > *"I have reviewed the attached evidence and explicitly approve the listed Phase 0 acceptance results for the identified release SHA."*
16. `signature_block`: Cryptographic signature (Git commit signature or PGP signature block) OR tamper-evident append-only audit reference.

### 4.3 Signing & Tamper-Evident Integrity
- **Cryptographic Signing (Preferred):** Where Git signing keys or PGP infrastructure are configured, the attestation artifact must be cryptographically signed by the operator identity.
- **Tamper-Evident Audit Logging (Alternative):** If PGP keys are not available in the operational environment, the attestation must be logged in an append-only audit ledger with SHA-256 artifact checksums and verified timestamps.
- **No Fabricated Signatures:** Never invent signatures, insert placeholder key IDs, or claim cryptographic verification when no signature was generated.

### 4.4 Canonical JSON Schema Template (`vector.attestation.phase0.v1`)

```json
{
  "$schema": "https://vector.traderbybit.internal/schemas/v3.4/phase0-attestation.json",
  "attestation_schema_version": "vector.attestation.phase0.v1",
  "environment_identity": "production-vps-primary",
  "deployed_git_sha": "9ba6970b4514c32149866e77500757a5784ddcf8",
  "docker_image_digests": {
    "app": "sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "postgres": "sha256:ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb",
    "redis": "sha256:8843d7f92416211de9ebb963ff4ce28125932878139589d9361ad582a8848f32"
  },
  "verification_timestamp_utc": "2026-10-30T12:00:00Z",
  "operator_identity": {
    "name": "Operator Name",
    "role": "Technical Owner / Release Engineer",
    "email": "operator@traderbybit.internal",
    "key_id": "0xDEADBEEFCAFE0001"
  },
  "gate_summary": {
    "total_obligations": 45,
    "pass_count": 0,
    "fail_count": 0,
    "not_verified_count": 45,
    "verdict": "NOT_READY"
  },
  "sql_diagnostic_manifest": [
    {
      "script_name": "scripts/diagnostics/phase0_production_acceptance.sql",
      "sha256": "4b227777d4dd1fc61c6f884f48641d02b4d121d3fd328cb08b5531fcacdabf8a",
      "execution_status": "PENDING_EXECUTION",
      "exit_code": null
    }
  ],
  "postgresql_readonly_evidence": {
    "read_only_transaction_enforced": true,
    "statement_timeout_sec": 30,
    "lock_timeout_sec": 5,
    "error_count": 0
  },
  "financial_reconciliation_summary": {
    "max_net_pnl_discrepancy": null,
    "identity_verified": false
  },
  "paper_census_summary": {
    "bybit_primary_count": 13,
    "binance_primary_count": 13,
    "total_verified_accounts": 26,
    "isolation_confirmed": false
  },
  "pipeline_terminalization_summary": {
    "stuck_stages_count": 0,
    "cross_exchange_leaks_count": 0,
    "terminalization_confirmed": false
  },
  "unresolved_exceptions": [],
  "evidence_hashes": {
    "production_diagnostic.log": "PENDING",
    "binance_conflict_telemetry.log": "PENDING"
  },
  "operator_declaration": "I have reviewed the attached evidence and explicitly approve the listed Phase 0 acceptance results for the identified release SHA.",
  "signature_block": {
    "signature_type": "PGP_ARMORED_DETACHED",
    "signature": null,
    "signed_at_utc": null
  }
}
```

### 4.5 Operator Workflow for Attestation Submission
1. **Deploy Release:** Operator triggers GitHub Actions deployment to VPS.
2. **Execute Read-Only Diagnostics:** Run Step 2 through Step 4 of Section 3.
3. **Inspect Output & Predicates:** Verify every obligation in Section 2 against the captured evidence.
4. **Populate Attestation Record:** Fill `docs/progress/evidence/phase0-production-acceptance/operator_attestation.json` with authentic evidence hashes, exact Git SHA, container digests, and gate results.
5. **Sign & Commit:** Cryptographically sign the record (or generate audit receipt) and commit locally.
6. **Authorization Gate:** Only upon verified `verdict: "ACCEPTED"` and operator signature is Phase 1.0 implementation authorized to commence.
