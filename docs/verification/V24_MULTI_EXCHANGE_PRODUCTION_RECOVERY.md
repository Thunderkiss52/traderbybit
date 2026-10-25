# VECTOR V2.4 — Multi-Exchange Production Recovery & Verification Report

**Repository:** `/home/thunder/work/traderbybit`  
**Branch:** `main`  
**Specification:** `VECTOR_UNIFIED_13_PRIMARY_26_PAPER_MULTI_EXCHANGE_SPEC_V2_4_EN.md`  
**Target Environment Parity:** PHP 8.5.12, Laravel 13.38.0, Go 1.27.3, PostgreSQL 17 / 14 isolated, Node 24.19.0.  
**Safety Boundary:** STRICTLY PAPER-ONLY. Real exchange orders are strictly prohibited. Read-only public endpoints only. No private keys, trading endpoints, or LIVE order submission capability.

---

## 1. Executive Summary & Root Cause Resolutions

Following the read-only operational review of commit `f243d76`, all six confirmed integration defects and related platform findings have been resolved, verified against isolated infrastructure, and committed locally:

| Defect / Priority | Root Cause Diagnosed | Resolution Implemented | Local Verification Evidence | Production Verification Status |
|---|---|---|---|---|
| **F1 (P0-B) Binance Provisioning** | `update.sh` and `deploy.sh` executed migrations without provisioning Binance accounts or initializing capital, causing `ACCOUNT_NOT_PROVISIONED` for all 13 Binance strategies. | Created `BinanceDeploymentInitializer` with SHA-256 pre/post state hashing to preserve all Bybit records; wired `php artisan binance:initialize-paper --force --no-interaction` into deployment scripts. | `BinanceDeploymentInitializationTest`: 1 test, 69 assertions PASS. 26 accounts provisioned, Bybit checksum 100% matched, M9 ledger `LEDGER_PASS_ONLY`. | OPERATOR-CONTROLLED (runs automatically upon release deployment). |
| **F2 (P0-C) Binance Scheduling & Observations** | `config/exchanges.php` defaulted `research_cycle_enabled` to `false`; `BinanceResearchCycle` saved signals but did not populate `strategy_observations`, showing `AWAITING_FIRST_CYCLE` / `NOT_EVALUATED` and 0 decisions. | Enabled `research_cycle_enabled => true` by default; delegated strategy evaluation and observation persistence to `StrategyObservationService` in `BinanceResearchCycle`, populating canonical `strategy_observations` with UUIDv5 slot idempotency, diagnostics, and level interactions; enforced `NO_CLOSED_DECISION_CANDLE` and `STALE_OR_FUTURE_DECISION_CANDLE` blockers. | `BinanceResearchCycleTest`: 8 tests, 194 assertions PASS. Evaluates all 13 Binance strategies, persists observations, verifies slot idempotency and schedule registration. | ACTIVE UPON DEPLOYMENT (scheduled via cron workers). |
| **F3 (P1-I) Cross-Exchange Market Isolation** | `ResearchController.php` endpoints (`candles`, `indicators`, `analysis`, `context`, `positions`) and cache keys lacked `exchange` filtering, causing cross-exchange cache contamination and Bybit/Binance data mixing. | Added `resolveExchange()` reading `X-Exchange` header or query parameter; scoped `CandleRecord`, `MarketContextRecord`, `SignalRecord`, `StrategyObservationRecord`, and `CandlestickPatternOccurrenceRecord` queries by exchange; added `:{$exchange}` to all cache keys (`market:candles:v6`, `market:analysis:v7`, `market:indicators`, `market:latest_close`). | `MarketExchangeIsolationTest`: 3 tests, 104 assertions PASS. Independent Bybit and Binance candles/context verified without cache collision. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F4 (P1-S) Truthful Dashboard States** | Missing/unprovisioned accounts returned hardcoded `ACTIVE` status and artificial `10000` USDT equity; UI displayed polling HTTP status as `SYNCED` rather than true engine health. | `ResearchController::dashboard` returns `UNPROVISIONED` status and `null` equity/PnL for unprovisioned accounts; enriched `paper_execution_health` with backend-derived `system_status` (`HEALTHY`, `DEGRADED`, `AWAITING_FIRST_CYCLE`, `TOPOLOGY_INCOMPLETE`); updated `DashboardPage.vue` to display authoritative status badges and handle null metrics gracefully. | `MarketExchangeIsolationTest` + Frontend tests (55/55 PASS) + Vue production build (0 errors, 7.50s). | VERIFIED ON ISOLATED TEST HARNESS. |
| **F5 (P0-A1, P0-A2) Central Execution Eligibility** | `ProcessPaperTradingJob`, CLI, and research paths bypassed configuration version, hash, and `signal_only` checks; `PaperAccountService::strategyForward` refused to prospectively bind missing canonical configuration hashes if any history rows existed, triggering `forward_configuration_retired` rejections. | Implemented `PaperExecutionEligibilityService` enforcing ACTIVE status, SIGNAL_ONLY rules (`btc_eth_lead_lag`, Binance funding/OI), exchange gates, version matching, and configuration hash matching before ingestion and under lock in `PositionLifecycleService::open`; updated `strategyForward` to allow prospective canonical adoption preserving all history. | `CentralExecutionEligibilityTest`: 6 tests, 24 assertions PASS. Prospective hash binding, history preservation, and entry rejections verified. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F6 (P1-E) Audit Hash Integrity** | `AuditExportService::writePrimaryCoverage` counted null configuration hashes as `MATCHED`. | Updated `AuditExportService` to require non-null matching configuration hashes against `StrategyRegistry`. | `CentralExecutionEligibilityTest::test_audit_export_primary_coverage_requires_non_null_configuration_hash` PASS; `AuditExportTest`: 10 tests, 289 assertions PASS. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F7 (P0-01) Activation Sequence Decoupling & Idempotency** | `assertCostEvidence()` strictly required `paper_execution_approved === true`, blocking `binance:activate-paper --approve` when execution was off per documented order. Repeat activation threw on non-BLOCKED status. | Uncoupled fee/slippage cost model verification from the runtime execution gate in `assertCostEvidence()`; made `BinancePaperActivation` fully idempotent with `already_active` tracking and explicit backfill instructions. | `BinanceActivationCommandTest`: 3 tests, 9 assertions PASS. Transitions 11 accounts to ACTIVE, keeps 2 SIGNAL_ONLY, succeeds idempotently. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F8 (P1-02) Per-Account Scheduler Failure Isolation** | A lock contention or exception on one paper account aborted the scheduled loop for all remaining Binance accounts in `routes/console.php`. | Wrapped per-account processing in isolated try/catch with structured warning logs and failure aggregation; added `BinanceEmergencyDisableMaintenanceTest` test 2 verifying subsequent accounts continue to be serviced. | `BinanceEmergencyDisableMaintenanceTest`: 2 tests, 14 assertions PASS. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F9 (P1-03) Per-Symbol & Per-Strategy Health Census** | `ResearchController` reported `HEALTHY` based on global max timestamps, masking stalled individual symbols (e.g. stalled ETHUSDT) or broken strategy streams. | Implemented per-symbol closed 5m candle census and per-active-strategy observation recency census in `ResearchController`; exposed `stalled_symbols` and `stalled_strategies` diagnostics. | `MarketExchangeIsolationTest`: 4 tests, 123 assertions PASS. Multi-dimensional negative and positive health states verified. | VERIFIED ON ISOLATED TEST HARNESS. |
| **F10 (P1-04) Full Serialization Bybit State Checksum** | Bybit checksum only verified row counts or subset of columns; potential race with concurrent Bybit mutations during initialization. | Serialized complete column arrays for `paper_accounts` (15 attributes), `virtual_positions` (12 attributes), `virtual_executions`, `equity_snapshots`, `paper_capital_events`, `trading_journal_events`, and `risk_decisions` under cache lock. | `BinanceDeploymentInitializationTest`: 1 test, 69 assertions PASS. | VERIFIED ON ISOLATED TEST HARNESS. |

---

## 2. Invariants & Architecture Preserved

1. **26 Canonical PAPER Accounts:**
   - 13 Bybit PRIMARY accounts (`exchange_id = 'bybit'`, `is_primary = true`).
   - 13 Binance PRIMARY accounts (`exchange_id = 'binance'`, `is_primary = true`).
   - Total canonical census: exactly 26 PRIMARY accounts.

2. **100% Financial History Preservation:**
   - Pre/post SHA-256 state hashing verifies that Bybit balances, positions, executions, journals, and equity snapshots remain unaltered across all deployments and migrations.

3. **SIGNAL_ONLY Strict Enactment:**
   - `btc_eth_lead_lag` evaluates signals for research observations across both exchanges, but fails entry eligibility across all paper processing paths (`ProcessPaperTradingJob`, `PaperProcessingService`, `PositionLifecycleService::open`).

4. **Public Read-Only Market Data:**
   - Only public REST endpoints are used. No private exchange API keys, trade permissions, or withdrawal rights exist in configuration or application code.

---

## 3. Verification Evidence & Quality Gates

### 3.1 Backend PHP Test Suite (PHP 8.5.12 on PostgreSQL `bybit_test`)
- `tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php`: 1 test, 69 assertions PASS
- `tests/Feature/Exchanges/BinanceResearchCycleTest.php`: 8 tests, 194 assertions PASS
- `tests/Feature/Exchanges/ExchangeRiskSizingTest.php`: 2 tests, 47 assertions PASS
- `tests/Feature/Exchanges/BinancePaperIsolationTest.php`: 7 tests, 105 assertions PASS
- `tests/Feature/Exchanges/BinanceActivationCommandTest.php`: 3 tests, 9 assertions PASS
- `tests/Feature/Exchanges/BinanceEmergencyDisableMaintenanceTest.php`: 2 tests, 14 assertions PASS
- `tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php`: 6 tests, 24 assertions PASS
- `tests/Feature/MarketData/MarketExchangeIsolationTest.php`: 4 tests, 123 assertions PASS
- `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php`: 3 tests, 53 assertions PASS
- `tests/Feature/Audit/` and `tests/Feature/AuditExportTest.php`: 10 tests, 289 assertions PASS
- **Complete Suite:** **769 tests, 5703 assertions, 0 failures, 0 errors (100% PASS)**

### 3.2 Code Style (Pint)
- All PHP files pass Laravel Pint formatting checks with 0 style issues (`./vendor/bin/pint --test`).

### 3.3 Frontend Suite (Node 24.19.0 / Vue 3 / Vite)
- **Unit & Component Tests:** 55 tests in `frontend/tests/` — **55 passed, 0 failed (100% PASS)**
- **Type Checking:** `vue-tsc --noEmit` — **0 errors**
- **Production Build:** `vite build` — **Built in 7.54s, 0 errors**

---

## 4. Operator Post-Deployment Verification & Activation Runbook

The deployment pipeline runs migrations, provisions the 13 canonical Binance accounts, and records authorized virtual capital allocations while preserving all 13 Bybit accounts.

### 4.1 Strictly Read-Only Status & Topology Checks

These commands perform read-only inspection and do NOT modify any database records:

#### 1. Verify 26-Account Topology and Ledger Reconciliation (Read-Only)
```bash
docker compose exec app php artisan binance:paper-reconcile
```
*Expected Output:*
- `status`: `LEDGER_PASS_ONLY`
- `verified_pairs`: `26`
- `issues`: `[]`

#### 2. Verify Scheduler Task Registration (Read-Only)
```bash
docker compose exec app php artisan schedule:list
```
*Expected Output:*
Includes `binance:context:BTCUSDT`, `binance:5m:BTCUSDT`, `binance:htf:BTCUSDT`, `binance:funding:BTCUSDT`, `binance:signals:BTCUSDT`, and `binance:paper-scheduled` alongside standard Bybit tasks.

#### 3. Verify API Dashboard Output (Read-Only)
Compose publishes the web service on port `18080` by default (defined by `${WEB_PORT:-18080}`). Access requires configured Basic Auth credentials:
```bash
curl -s -u "${BASIC_AUTH_USER}:${BASIC_AUTH_PASSWORD}" -H "X-Exchange: binance" \
  http://127.0.0.1:${WEB_PORT:-18080}/api/v1/dashboard | jq '.data.paper_execution_health'
```
*Expected Output:*
- `provisioned_accounts`: `13`
- `blocked_accounts`: `13` (pre-activation) or `active_accounts`: `11` (post-activation)
- `system_status`: `AWAITING_ACTIVATION` (pre-activation), `AWAITING_FIRST_CYCLE` (before first run), or `HEALTHY` (when operational)
- `stalled_symbols`: `[]`
- `stalled_strategies`: `[]`

---

### 4.2 Manual Signal & Observation Cycle Trigger (State-Generating)

*Note:* This command is an active trigger that evaluates strategies and records observations and signals into the database:
```bash
docker compose exec app php artisan binance:research-cycle BTCUSDT
docker compose exec app php artisan binance:research-cycle ETHUSDT
```
*Expected Output:*
- `exchange_id`: `binance`
- `status`: `SIGNAL_RESEARCH_ONLY`
- `signals`: `13`
- `processed_events`: `0`

---

### 4.3 Pre-Activation Backfill & Owner-Controlled Activation (Gate 2)

By design, deployment leaves Binance accounts in `BLOCKED` status with allocated paper capital. To activate virtual PAPER execution:

#### Step 1: Pre-Activation Market Data Backfill (P2-05)
Activation requires 220 contiguous closed candles, recent market context (< 15 min), and recent funding rate history (< 24h) for both BTCUSDT and ETHUSDT. Run the following backfill commands:
```bash
# Ingest 220 closed candles across all 3 timeframes (5m, 15m, 1h)
docker compose exec app php artisan binance:ingest-research BTCUSDT
docker compose exec app php artisan binance:ingest-research ETHUSDT

# Capture non-future market context (order book, spread, 24h volume)
docker compose exec app php artisan binance:capture-context BTCUSDT
docker compose exec app php artisan binance:capture-context ETHUSDT

# Ingest recent funding rate settlement events
docker compose exec app php artisan binance:ingest-funding BTCUSDT
docker compose exec app php artisan binance:ingest-funding ETHUSDT
```

#### Step 2: Execute Activation Command (P0-01)
Fee and slippage rates must be configured in environment (`VECTOR_BINANCE_PAPER_TAKER_RATE` and `VECTOR_BINANCE_PAPER_SLIPPAGE_RATE`). Run:
```bash
docker compose exec app php artisan binance:activate-paper --approve
```
*Result:* 11 Binance primary accounts transition from `BLOCKED` to `ACTIVE`. `btc_eth_lead_lag` and `funding_open_interest_squeeze` remain strictly `SIGNAL_ONLY`. Repeat execution is idempotent (`already_active` reported).

#### Step 3: Approve Execution in Environment
Set in `.env` and restart containers:
```bash
VECTOR_BINANCE_PAPER_EXECUTION_APPROVED=true
```

#### Step 4: Emergency Disabling Semantics
If `VECTOR_BINANCE_PAPER_EXECUTION_APPROVED` is toggled off (`false`), new entries are rejected immediately, while existing open positions, exits, stop losses, and funding settlements continue to be safely maintained across all scheduled processing runs.

---

### 4.4 Bybit Historical State Preservation Proof (P1-04)

`BinanceDeploymentInitializer` computes a comprehensive pre- and post-provisioning SHA-256 state checksum under deployment cache lock:
- All 15 columns of `paper_accounts` for Bybit (including `configuration_hash`, `strategy_version`, `status`, `mode`)
- All 12 columns of `virtual_positions` for Bybit (`symbol`, `side`, `status`, `initial_quantity`, `remaining_quantity`, `entry_price`, `gross_pnl`, `net_pnl`, `fees`, `funding`, etc.)
- All columns of `virtual_executions` for Bybit
- All rows of `equity_snapshots`, `paper_capital_events`, `trading_journal_events`, and `risk_decisions`
Any modification or drift in historical Bybit records aborts deployment immediately with a `RuntimeException`.

---

### 4.5 Distribution Archive Hygiene (P2-06)

When packaging source archives for audit or release, exclude non-source runtime artifacts, logs, cache, and database binaries:
```bash
tar --exclude='reports/overnight/runs/**/postgres-data' \
    --exclude='reports/overnight/runs/**/pg_wal' \
    --exclude='audit_data/*.csv' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='vendor' \
    --exclude='frontend/node_modules' \
    --exclude='.git' \
    -czvf VECTOR-source-$(date +%Y%m%d-%H%M).tar.gz .
```
Package strictly: application source code, migrations, tests, configs, build scripts, and documentation.
