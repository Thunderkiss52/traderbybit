# VECTOR — Financial Reconciliation UI Audit & Ledger Integrity Report

> **Execution Date:** 2026-11-10 17:33:29 UTC  
> **Target Scope:** All 13 Primary Bybit Paper Portfolios  
> **Ledger Authority:** Laravel Double-Entry Ledger (`paper_accounts`, `virtual_positions`, `reconciliation_runs`)  
> **Mode:** STRICT VIRTUAL PAPER TRADING (`PAPER_TRADING=true`)  

---

## 1. Executive Summary & Reconciliation Matrix

| Strategy | Account ID | Status | Starting (USDT) | Equity (USDT) | Realized PnL | Double-Entry Identity | Reconciliation | Authentic UI Reason |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `baseline` | `94b97f13...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `bollinger_mean_reversion` | `6573dfda...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `btc_eth_lead_lag` | `b875d6f3...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `donchian_atr_breakout` | `5f742264...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `funding_open_interest_squeeze` | `b17787f4...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `liquidity_sweep_reversal` | `796f61f4...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `momentum` | `458c0fd5...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `multi_timeframe_trend_following` | `37978f75...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `regime_switching` | `38fcded4...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `support_resistance_bounce` | `794fa4f9...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `support_resistance_breakout` | `9eab0b93...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `trend_pullback` | `ec9dd1b1...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |
| `volatility_compression_breakout` | `0d3347a9...` | `ACTIVE` | `10,000.00` | `10,000.00` | `0.00` | **VERIFIED** (`diff=0`) | **PASSED** | Zero discrepancies, double-entry verified |

---

## 2. Aggregate Financial Invariants

- **Total Primary Accounts:** `13 Bybit Portfolios`
- **Aggregate Starting Capital:** `130,000.00 USDT`
- **Aggregate Current Equity:** `130,000.00 USDT`
- **Aggregate Realized PnL:** `0.00 USDT`
- **Aggregate Unrealized PnL:** `0.00 USDT`
- **Canonical Identity:** `Equity = Starting_Balance + Realized_PnL + Unrealized_PnL`
  * Total Expected: `130,000.00 USDT`
  * Variance: `0.000000 USDT` (**Double-Entry Conservation Confirmed**)

---

## 3. UI Display & Transparency Policy

1. **Authentic Reasons for INCOMPLETE Without Historical Fabrication:**
   - When an account exhibits incomplete historical funding or initial snapshot boundaries, the system explicitly reports the authentic condition (`initial_equity_snapshot_unavailable`, `funding_history_incomplete`, `global_orphan_executions_unattributable`).
   - The platform strictly forbids fabricating synthetic funding events, back-dating trades, or inserting artificial equity snapshots merely to force an audit pass.
   - The frontend visualizer in `ProfitabilityResearchPage.vue` now binds `reconciliation.incomplete_reasons` directly to tooltip and subtitle indicators.

2. **Isolated Portfolio Accounting:**
   - Every one of the 13 Bybit primary accounts is strictly scoped to its own `paper_account_id`.
   - Cross-strategy balance blending, shared margin pools, and mutualized risk buffers are strictly prohibited.
