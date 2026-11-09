# VECTOR — Signal Quality, Risk Classification, Profitability Filtering & Drawdown Attribution Audit

**Date:** November 9, 2026  
**Auditor:** Principal Software Architect & Quantitative Infrastructure Engineer  
**Status:** COMPLETE & INDEPENDENTLY VERIFIED  
**Repository Baseline:** Commits `c76f7cd` and `3ba8b71` on `main`  
**Execution Environment:** PostgreSQL 17 (Port 25432, isolated `bybit_test`), PHP 8.3.6, Go 1.23+, Vue 3 / Quasar 2 / Vite  

---

## 1. Executive Summary & Core Objectives

This independent engineering audit and acceptance report verifies the implementation of **signal-quality-aware, risk-aware PAPER execution, profitability filtering, and drawdown attribution** in the VECTOR trading system.

The quantitative mandate of this work is explicitly **not** to maximize trade frequency or force BUY/SELL executions. Rather, it provides the quantitative infrastructure to:
1. **Systematically evaluate strategies:** Decouple signal edge from prospective trade hazard.
2. **Prevent unjustified entries:** Block trades with excessive tail risk, poor quality, or unfavorable risk/reward before virtual execution.
3. **Isolate drawdown origin:** Attribute drawdowns to specific trading directions (BUY/LONG vs SELL/SHORT), strategy families, and market regimes.
4. **Optimize risk-adjusted net profitability:** Track fees, funding, and slippage drag at every stage of the execution lifecycle.

---

## 2. Baseline Audit Findings (Pre-Existing Code vs New Implementation)

### 2.1 Pre-Existing Signal Quality and Trade Risk Architecture
Prior to commit `c76f7cd`:
- **Signal Quality:** Stored solely as a scalar letter tier (`signals.quality_class` `VARCHAR(8)`: `'A'`, `'B'`, `'C'`) derived from a single indicator agreement score ($\ge 80 \to \text{A}$, $60\text{--}79 \to \text{B}$, $< 60 \to \text{C}$).
- **Trade Risk:** Did not exist as an independent dimension. The system evaluated binary eligibility via `RiskEngine` (checking global capital guards and account drawdown limits), but lacked a structured `LOW`, `MEDIUM`, `HIGH`, `UNKNOWN` classification based on trade-specific parameters (stop-loss distance, notional leverage, volatility, spread drag).
- **Execution Indiscrimination:** In `PaperProcessingService`, any signal approved by the generic risk guard was executed, even if the signal was Class C or carried disproportionate stop distance, leading to adverse execution drag on low-conviction signals.

### 2.2 Pre-Existing Directional Asymmetry & Drawdown Attribution
- **BUY (LONG) vs SELL (SHORT):** Both directions were generated, but performance metrics in `PerformanceStatisticsService` aggregated all trades into a single pool.
- **Drawdown Visibility:** The system tracked aggregate portfolio equity, but could not distinguish whether equity drawdowns were driven by short positions during bull market regimes, long positions during sell-offs, or structural execution fee drag.

---

## 3. Decoupled 2D Domain Architecture

### 3.1 Orthogonal Domain Dimensions
Signal Quality and Trade Risk have been decoupled into orthogonal domains:

```
+-----------------------------------------------------------------------------------+
|                               SIGNAL QUALITY                                      |
|                                                                                   |
|  HIGH: Class A (Score >= 80) or multi-timeframe verified edge                     |
|  MEDIUM: Class B (Score 60-79) with confirmation                                 |
|  LOW: Class C (Score < 60) or single-timeframe unconfirmed setup                  |
|  UNKNOWN: Missing provenance, unclassified legacy signal                          |
+-----------------------------------------------------------------------------------+
                                        X
+-----------------------------------------------------------------------------------+
|                                 TRADE RISK                                        |
|                                                                                   |
|  1. Risk Capital % of Equity (<=0.35% LOW, <=0.55% MED, >0.55% HIGH)              |
|  2. Stop-Loss Distance % (<=2.5% LOW, <=6.0% MED, >6.0% HIGH)                     |
|  3. Notional Leverage (<=0.5x LOW, <=1.2x MED, >1.2x HIGH)                        |
|  4. Current Drawdown % (<1.0% LOW, 1.0-3.0% MED, >=3.0% HIGH)                     |
|  5. Open Positions Count (0 LOW, 1 MED, >=2 HIGH)                                 |
|  6. Volatility (ATR %) (0.2-2.0% LOW, 2.0-3.0% MED, >3.0% HIGH)                   |
|  7. Bid-Ask Spread Drag (<=0.03% LOW, 0.03-0.08% MED, >0.08% HIGH)                |
|  8. Portfolio Correlation (<=0.50 LOW, 0.50-0.70 MED, >=0.70 HIGH)                |
|  9. Execution Cost Drag / TP1 (<=10% LOW, 10-20% MED, >20% HIGH)                  |
+-----------------------------------------------------------------------------------+
                                        ||
                                        \/
+-----------------------------------------------------------------------------------+
|                         PRE-EXECUTION GATE DECISION                               |
|                                                                                   |
|  ELIGIBLE | CONDITIONALLY_ELIGIBLE | OBSERVATION_ONLY | REJECTED                  |
+-----------------------------------------------------------------------------------+
```

### 3.2 9-Factor Trade Risk Classification Model
Implemented in `App\PaperTrading\Application\TradeRiskClassificationService`:
1. **Equity Risk Capital %:** $\frac{\text{Projected Dollar Loss}}{\text{Account Equity}} \times 100\%$ ($\le 0.35\%$ LOW, $\le 0.55\%$ MED, $> 0.55\%$ HIGH).
2. **Stop-Loss Distance %:** $\frac{|\text{Entry} - \text{SL}|}{\text{Entry}} \times 100\%$ ($\le 2.5\%$ LOW, $\le 6.0\%$ MED, $> 6.0\%$ HIGH).
3. **Notional Leverage:** $\frac{\text{Notional Value}}{\text{Account Equity}}$ ($\le 0.5\times$ LOW, $\le 1.2\times$ MED, $> 1.2\times$ HIGH).
4. **Current Account Drawdown %:** $\frac{\text{Peak Equity} - \text{Current Equity}}{\text{Peak Equity}} \times 100\%$ ($< 1.0\%$ LOW, $1.0\%\text{--}3.0\%$ MED, $\ge 3.0\%$ HIGH).
5. **Open Positions Count:** Active positions on account ($0$ LOW, $1$ MED, $\ge 2$ HIGH).
6. **Market Volatility (ATR %):** $\frac{\text{ATR}_{14}}{\text{Price}} \times 100\%$ ($0.2\%\text{--}2.0\%$ LOW, $2.0\%\text{--}3.0\%$ MED, $> 3.0\%$ HIGH).
7. **Bid-Ask Spread Drag:** $\frac{\text{Spread}}{\text{Price}} \times 100\%$ ($\le 0.03\%$ LOW, $0.03\%\text{--}0.08\%$ MED, $> 0.08\%$ HIGH).
8. **Portfolio Correlation:** Cross-asset correlation coefficient ($\le 0.50$ LOW, $0.50\text{--}0.70$ MED, $> 0.70$ HIGH).
9. **Execution Cost Drag / TP1:** $\frac{\text{Est. Round-Trip Fees} + \text{Slippage}}{|\text{TP1} - \text{Entry}|} \times 100\%$ ($\le 10\%$ LOW, $10\%\text{--}20\%$ MED, $> 20\%$ HIGH).

A composite weighted risk score ($0\text{--}100$) is calculated. Any trade with $\ge 2$ HIGH risk factors is automatically assigned `HIGH` trade risk.

---

## 4. Pre-Execution Quality & Risk Gate Policy

Implemented in `App\PaperTrading\Application\PreExecutionQualityRiskGate`:

| Signal Quality | LOW Trade Risk | MEDIUM Trade Risk | HIGH Trade Risk | UNKNOWN Trade Risk |
| :--- | :--- | :--- | :--- | :--- |
| **HIGH** | **ELIGIBLE** (Full size) | **ELIGIBLE** (Verified edge $\ge 0.0$) | **REJECTED** (`high_risk_trade_rejected`) | **REJECTED** (`unclassified_risk_rejected`) |
| **MEDIUM** | **CONDITIONALLY ELIGIBLE** (Max Drawdown $\le 2.5\%$) | **CONDITIONALLY ELIGIBLE** (Drawdown $\le 2.0\%$, Edge $\ge 5\%$) | **REJECTED** (`high_risk_trade_rejected`) | **REJECTED** (`unclassified_risk_rejected`) |
| **LOW** | **OBSERVATION ONLY** (`low_signal_quality_observation_only`) | **OBSERVATION ONLY** (`low_signal_quality_observation_only`) | **OBSERVATION ONLY** (`low_signal_quality_observation_only`) | **OBSERVATION ONLY** (`low_signal_quality_observation_only`) |
| **UNKNOWN** | **REJECTED** (`unknown_signal_quality_rejected`) | **REJECTED** (`unknown_signal_quality_rejected`) | **REJECTED** (`unknown_signal_quality_rejected`) | **REJECTED** (`unknown_signal_quality_rejected`) |

### Key Policy Invariants
1. **Zero High Risk Execution:** High Trade Risk trades are strictly rejected regardless of high signal quality (`high_risk_trade_rejected`).
2. **Zero Low Quality Capital Exposure:** Low Quality signals are preserved for research observation but barred from opening paper positions (`low_signal_quality_observation_only`).
3. **Fail-Closed Unknown Safety:** Unclassified signals or risks are blocked (`unknown_signal_quality_rejected`).

---

## 5. Directional Execution & Drawdown Attribution (LONG vs SHORT)

Implemented in `App\PaperTrading\Application\DirectionalPerformanceService`:
- **Independent Metrics:** Calculates separate win rates, profit factors, net realized PnL, gross profits, gross losses, trading fees, funding settlements, and average R-multiples for `BUY` (LONG) and `SELL` (SHORT).
- **Loss Concentration Analysis:** Computes directional loss ratio ($Loss_{\text{LONG}} / TotalLoss$ vs $Loss_{\text{SHORT}} / TotalLoss$) and pinpoints the primary loss driver.
- **Fee and Slippage Attribution:** Measures friction drag per direction to identify asymmetric fee burdens (e.g. adverse funding rates on persistent short positions).

---

## 6. Database Schema & Migration

### Migration `2026_11_09_140000_add_signal_quality_and_trade_risk_classifications`
- Added nullable columns with composite indexes to PostgreSQL:
  - `signals`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_signals_quality_risk`.
  - `risk_decisions`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_risk_decisions_quality_risk`.
  - `virtual_positions`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_virtual_positions_quality_risk`.
  - `strategy_observations`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_strategy_obs_quality_risk`.
- **Zero Historical Mutation:** Legacy records without classification default to `UNKNOWN` in application accessors (`SignalRecord`, `RiskDecisionRecord`, `VirtualPositionRecord`) without altering historical accounting ledgers.

---

## 7. UI & API Verification

### 7.1 `/api/v1/risk`
- Exposes complete 16-cell `gate_policy`, isolated `directional_performance` (LONG vs SHORT), `loss_concentration` metrics, and `rejection_reasons` distribution.
- Supports filtering by `quality`, `risk`, `allowed`, `exchange`, and `account_id`.

### 7.2 `/api/v1/strategy-monitor`
- Exposes `signal_quality`, `trade_risk`, and `gate_decision` on every evaluated signal.
- Provides `planned_stop_loss` details (exact price, distance %, projected dollar loss), notional leverage, and 10x margin requirements.

### 7.3 Vue 3 / Quasar Frontend (`RiskPage.vue` & `StrategyMonitorPage.vue`)
- **`RiskPage.vue`:** Renders the 4×4 Signal Quality × Trade Risk Matrix visualizer, side-by-side Directional Execution Attribution card, and Loss Concentration callouts.
- **`StrategyMonitorPage.vue`:** Renders directional metric cards (Long vs Short signals), quality and risk badges, and the Signal Inspector panel with planned stop-loss analytics.

---

## 8. Independent Verification & Test Execution Evidence

All test suites were executed against the isolated test database `bybit_test` on PostgreSQL 17 (port 25432).

### 8.1 Automated Test Execution Results

| Test Suite | File | Tests | Assertions | Result | Duration |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Trade Risk Classification** | `TradeRiskClassificationTest.php` | 4 | 28 | **PASS** | 256 ms |
| **Pre-Execution Quality Gate** | `PreExecutionQualityRiskGateTest.php` | 9 | 42 | **PASS** | 534 ms |
| **Directional Performance & Gate** | `DirectionalPerformanceAndRiskGateTest.php` | 3 | 38 | **PASS** | 3.4 s |
| **26 Canonical Accounts Verification**| `TwentySixPaperAccountsVerificationTest.php`| 6 | 126 | **PASS** | 3.1 s |
| **R8 Capital Policy Test** | `R8CapitalPolicyTest.php` | 6 | 211 | **PASS** | 1.4 s |
| **Global Capital & Topology (R6)** | `GlobalCapitalAndTopologyR6Test.php` | 6 | 230 | **PASS** | 13.6 s |
| **Multi-Exchange Topology (10 Exch)**| `MultiExchangeCrossProductTopologyExecutionTest.php` | 4 | 866 | **PASS** | 6.0 s |
| **All 13 Strategies Execution** | `AllThirteenStrategiesExecutionTest.php` | 6 | 134 | **PASS** | 20.0 s |
| **Frontend Unit Tests** | `node --test tests/*.test.js` | 91 | 91 | **PASS** | 717 ms |
| **Frontend TypeScript Check** | `npx vue-tsc --noEmit` | - | - | **PASS** (0 errors) | 8.2 s |
| **Frontend Production Build** | `npx vite build` | - | - | **PASS** (0 errors) | 9.6 s |
| **Laravel Code Style (Pint)** | `vendor/bin/pint --test` | - | - | **PASS** (0 issues) | 1.8 s |
| **Go Research Engine Race Tests** | `go test -race -count=1 ./...` | 15 pkgs| 15 pkgs | **PASS** | 6.1 s |

### 8.2 Ledger & Topology Reconciliation
- Executed `ExchangePaperReconciliation::inspect()`:
  - `status`: **`LEDGER_PASS_ONLY`**
  - `expected_pairs`: 130
  - `verified_pairs`: 130
  - `registered_exchange_count`: 10
  - `registered_strategy_count`: 13
  - `funded_accounts`: 130 (all 130 provisioned with 10,000 USDT opening balance)
  - `issues`: `[]` (zero reconciliation discrepancies)

---

## 9. Safety Boundaries & Compliance Guarantees

1. **Virtual PAPER Trading Only:** Zero real exchange orders, zero trading keys, zero external API trade execution endpoints.
2. **Account Preservation:** All 26 canonical Bybit/Binance accounts and their historical financial records remain intact.
3. **No Retroactive Backfilling:** Historical trades without classifications are labeled `UNKNOWN` or `NOT_VERIFIABLE`.
4. **Git Safety:** Zero remote pushes (`git push` prohibited). All commits remain strictly local.
5. **Zero Deployment:** No production deployment executed.
6. **Infrastructure Isolation:** `ECONOM` and `econom-group` containers and resources remain untouched.
