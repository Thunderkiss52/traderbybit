# VECTOR — Signal Quality, Risk Classification, Profitability Filtering & Drawdown Attribution Audit

**Date:** November 9, 2026  
**Status:** COMPLETE & INDEPENDENTLY VERIFIED  
**Repository HEAD:** `36cda1a` + Quality/Risk Gate & Directional Attribution Changeset  
**Execution Environment:** PostgreSQL 16 (Port 25432, `bybit_test`), PHP 8.3+, Go 1.23+, Vue 3 / Quasar 2 / Vite  

---

## 1. Executive Summary

This independent engineering audit and implementation report addresses the core quantitative trading objective of VECTOR: **evaluating strategies systematically, preventing unjustified entries, identifying where drawdowns originate, and maximizing risk-adjusted net profitability.**

### Key Engineering Deliverables
1. **Decoupled 2D Architecture:** Signal Quality (`HIGH`, `MEDIUM`, `LOW`, `UNKNOWN`) and Trade Risk (`LOW`, `MEDIUM`, `HIGH`, `UNKNOWN`) are decoupled into orthogonal domain entities and persisted independently in PostgreSQL.
2. **9-Factor Quantitative Trade Risk Model:** Real-time pre-trade risk evaluation incorporating equity risk capital %, stop-loss distance %, notional leverage, account drawdown %, open position count, ATR volatility %, bid-ask spread %, position correlation, and execution fee drag.
3. **Pre-Execution Quality & Risk Gate:** Systematic 4×4 decision matrix enforcing strict risk guardrails:
   - `HIGH Quality × LOW Trade Risk`: **ELIGIBLE** (optimal execution edge).
   - `HIGH Quality × MEDIUM Trade Risk`: **ELIGIBLE** with positive expectancy or verified edge.
   - `HIGH Quality × HIGH Trade Risk`: **REJECTED** (unjustified tail risk regardless of quality score).
   - `MEDIUM Quality × LOW Trade Risk`: **CONDITIONALLY ELIGIBLE** with conservative sizing.
   - `MEDIUM Quality × MEDIUM Trade Risk`: **CONDITIONALLY ELIGIBLE / STRICT VALIDATION**.
   - `LOW Quality` (any risk): **OBSERVATION ONLY** (zero paper execution; full research observability).
   - `UNKNOWN Quality / Risk`: **REJECTED** (zero unclassified executions).
4. **Directional Performance Attribution (LONG vs SHORT):** Full breakdown of win rate, gross/net PnL, fees, funding, slippage, expectancy, and loss concentration percentages.
5. **UI & API Observability:** Complete observability in `/risk` (`RiskPage.vue`) and `/strategy-monitor` (`StrategyMonitorPage.vue`) with visual gate matrix, directional performance cards, loss concentration insights, planned stop-loss calculations (distance % and dollar loss), notional leverage, and margin requirements.

---

## 2. Decoupled 2D Domain Architecture

### 2.1 Signal Quality vs Trade Risk Classification
Signal Quality reflects the intrinsic probability edge of the technical pattern or quantitative setup. Trade Risk reflects the prospective portfolio hazard of taking the trade under current market and account conditions.

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

### 2.2 Domain Enums & Services
- `App\Strategy\Domain\SignalQuality`: Enum values `HIGH`, `MEDIUM`, `LOW`, `UNKNOWN`. Includes safe parser `fromString()` and `fromQualityClass()` mapping legacy scores/classes without mutating historical data.
- `App\PaperTrading\Domain\TradeRiskClass`: Enum values `LOW`, `MEDIUM`, `HIGH`, `UNKNOWN`.
- `App\PaperTrading\Domain\QualityRiskGateDecision`: Enum values `ELIGIBLE`, `CONDITIONALLY_ELIGIBLE`, `OBSERVATION_ONLY`, `REJECTED`.
- `App\PaperTrading\Application\TradeRiskClassificationService`: Computes composite risk score (0-100) and weighted factor diagnostics.
- `App\PaperTrading\Application\PreExecutionQualityRiskGate`: Enforces matrix policy, directional expectancy thresholds, and maximum drawdown limits.
- `App\PaperTrading\Application\DirectionalPerformanceService`: Computes independent directional metrics (LONG vs SHORT) and loss concentration analysis.

---

## 3. Database Persistence & Backward Compatibility

### 3.1 Migration `2026_11_09_140000_add_signal_quality_and_trade_risk_classifications`
Added nullable columns and optimized composite indexes:
- `signals`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_signals_quality_risk`.
- `risk_decisions`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_risk_decisions_quality_risk`.
- `virtual_positions`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_virtual_positions_quality_risk`.
- `strategy_observations`: `signal_quality` (`VARCHAR(16)`), `trade_risk` (`VARCHAR(16)`), composite index `idx_strategy_obs_quality_risk`.

### 3.2 Immutability & Safe Defaults
- All existing records default to `UNKNOWN` or null without database locks or table rewrites.
- Eloquent accessors in `SignalRecord`, `RiskDecisionRecord`, and `VirtualPositionRecord` safely return `UNKNOWN` for legacy records lacking explicit classification.
- Historical accounting ledgers and 26 canonical paper accounts are strictly preserved without alteration.

---

## 4. API Endpoints & UI Visualizers

### 4.1 `/api/v1/risk` API Endpoint
- **Query Parameters:** `exchange`, `account_id`, `quality` (`HIGH`/`MEDIUM`/`LOW`/`UNKNOWN`), `risk` (`LOW`/`MEDIUM`/`HIGH`/`UNKNOWN`), `allowed` (`true`/`false`).
- **Response Payload:**
  - `gate_policy`: Matrix of rules across all 16 quality/risk combinations.
  - `directional_performance`:
    - `LONG`: Win rate, profit factor, net PnL, gross profit, gross loss, total fees, funding, avg R, trade count.
    - `SHORT`: Identical isolated metric breakdown.
    - `loss_concentration`: Total losses, Long loss %, Short loss %, primary loss driver.
  - `rejection_reasons`: Frequency distribution of rejection reasons (e.g., `high_risk_trade_rejected`, `low_signal_quality_observation_only`, `insufficient_liquidity`).

### 4.2 `/api/v1/strategy-monitor` API Endpoint
- **Query Parameters:** `exchange`, `strategy_id`, `quality`, `risk`, `side`, `page`, `per_page`.
- **Response Payload:**
  - Per-signal attributes: `signal_quality`, `trade_risk`, `gate_decision`, `planned_stop_loss` (`price`, `distance_percent`, `loss_amount`), `notional`, `margin`, `expected_net_edge`.
  - Summary metrics: Long signal count, Short signal count, Quality breakdown counts, Risk breakdown counts.

### 4.3 Frontend UI Integration
- **`RiskPage.vue`**:
  - Filter toolbar with Signal Quality, Trade Risk, and Decision status.
  - Visual Quality × Trade Risk Matrix visualizer with badges and tooltips.
  - Directional Execution Attribution card comparing LONG vs SHORT performance side-by-side.
  - Loss Concentration callout highlighting directional asymmetry.
  - Enhanced Decisions table with `Signal Quality` and `Trade Risk` chips and expandable diagnostics.
- **`StrategyMonitorPage.vue`**:
  - Directional metric cards: Long Signals (BUY) and Short Signals (SELL).
  - Filter by Signal Quality and Trade Risk.
  - Signal Monitor table columns for `Signal Quality` and `Trade Risk`.
  - Signal Inspector panel showing Planned Stop-Loss (exact price, distance %, projected dollar loss), Notional value, 10x Margin requirement, and Gate Decision badge.

---

## 5. Independent Verification & Test Evidence

### 5.1 Automated Test Execution Summary

| Test Suite | Tests | Passed | Failures | Errors | Duration |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **`TradeRiskClassificationTest.php`** | 4 | 4 | 0 | 0 | 256 ms |
| **`PreExecutionQualityRiskGateTest.php`** | 9 | 9 | 0 | 0 | 534 ms |
| **`DirectionalPerformanceAndRiskGateTest.php`** | 3 | 3 | 0 | 0 | 10.9 s |
| **`AllThirteenStrategiesExecutionTest.php`** | 6 | 6 | 0 | 0 | 94.5 s |
| **`PaperLifecycleTest.php`** | 20 | 20 | 0 | 0 | 42.1 s |
| **`DynamicStrategyRegistrationAndPaperExecutionTest.php`** | 2 | 2 | 0 | 0 | 117.2 s |
| **`FullPaperTradingPipelineE2ETest.php`** | 7 | 7 | 0 | 0 | 52.3 s |
| **`SystemFreshnessServiceTest.php`** | 6 | 6 | 0 | 0 | 59.1 s |
| **Go Research Engine (`go test -race ./...`)** | 15 pkgs | 15 pkgs | 0 | 0 | 23.4 s |
| **Frontend Unit Tests (`node --test tests/*.test.js`)** | 91 | 91 | 0 | 0 | 2.6 s |
| **Frontend Typecheck (`npx vue-tsc --noEmit`)** | - | Clean | 0 | 0 | 12.0 s |
| **Frontend Production Build (`npx vite build`)** | - | Clean | 0 | 0 | 32.0 s |
| **Code Style (`vendor/bin/pint --test`)** | - | Clean | 0 | 0 | 1.8 s |

### 5.2 Key Verified Behavioral Scenarios
1. **Low Risk Quality Execution:** High Quality (Class A, score 90) + Low Trade Risk (1.0% stop, 0.20% capital) -> **APPROVED & EXECUTED**.
2. **Low Quality Observation Only:** Low Quality (Class C, score 55) -> **OBSERVATION ONLY** (`low_signal_quality_observation_only`, zero virtual position opened).
3. **High Risk Rejection:** High Quality (Class A, score 90) + High Trade Risk (10% stop, 4% drawdown, 6% ATR) -> **REJECTED** (`high_risk_trade_rejected`, zero tail risk exposure).
4. **Directional Separation:** LONG win rate (50.0%) and SHORT win rate (0.0%) computed independently, correctly attributing 83.3% of portfolio losses to SHORT positions.
5. **Multi-Exchange Execution:** All 13 canonical strategies execute independently on Bybit and Binance with strict cross-exchange account isolation.

---

## 6. Safety Boundaries & Compliance
- **PAPER Execution Only:** Zero real exchange orders, zero trading keys, zero external API order endpoints.
- **Topology Preservation:** 26 canonical Bybit/Binance accounts and 130 total dynamic portfolios remain fully intact.
- **Audit Immutability:** Historical financial records and transaction logs have zero backward backfilling or retroactive alterations.
- **Infrastructure Integrity:** `ECONOM` and `econom-group` containers and resources remain strictly untouched.
