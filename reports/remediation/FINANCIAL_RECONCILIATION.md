# VECTOR — Financial Reconciliation & Accounting Integrity Report

**Date:** 2026-11-10  
**Database Authority:** PostgreSQL (`bybit_test` / production schema)  
**Accounting Standard:** Strict Double-Entry Ledger with Point-in-Time Provenance  

---

## 1. Accounting Identities & Reconciliation Formula

For every virtual portfolio account $i$ on exchange $e$:

$$\text{Expected Balance} = \text{Starting Balance} + \sum \text{Realized Gross PnL} - \sum \text{Fees} + \sum \text{Funding Settlements}$$

$$\text{Current Equity} = \text{Current Balance} + \sum \text{Unrealized PnL of Open Positions}$$

### Invariants Strictly Enforced:
1. **Zero Double-Deduction:** Slippage is realized via execution fill price; it is never subtracted a second time from net realized PnL.
2. **Double-Entry Capital Events:** Every starting capital allocation is backed by an immutable row in `paper_capital_events` and an initial snapshot in `equity_snapshots`.
3. **Funding Settlement Windows:** Open positions allow a 15-minute ingestion grace period for current boundary intervals before flagging funding gaps.
4. **Historical Record Preservation:** Deactivation or unassignment of an exchange or strategy never mutates, zeroes, or deletes historical positions, trades, or equity snapshots.

---

## 2. Directional Performance Attribution (Finding F-03 Fixed)

[`DirectionalPerformanceService`](file:///home/thunder/work/traderbybit/app/PaperTrading/Application/DirectionalPerformanceService.php) isolates performance by:
- **Direction:** `LONG` (BUY orders) vs `SHORT` (SELL orders).
- **Signal Quality:** `HIGH` (Class A), `MEDIUM` (Class B), `LOW` (Class C), and `UNKNOWN`.
- **Trade Risk:** `LOW`, `MEDIUM`, `HIGH`, `UNKNOWN`.
- **Strategy & Venue Isolation:** Verified with explicit `$strategyId` and `$exchangeId` query filters.
- **Contract Compatibility:** Returns both canonical mathematical keys (`net_pnl`, `fees`, `win_rate`, `average_r`) and UI display bindings (`total_net_pnl`, `total_fees`, `win_rate_percent`, `avg_r`).

---

## 3. Reconciliation Execution Results

Executed PostgreSQL integration suites:
- [`LedgerReconciliationPostgresIntegrationTest.php`](file:///home/thunder/work/traderbybit/tests/Integration/LedgerReconciliationPostgresIntegrationTest.php): **4/4 PASSED (409 assertions)**
- [`FinancialAccountingAndReconciliationPostgresIntegrationTest.php`](file:///home/thunder/work/traderbybit/tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php): **3/3 PASSED (45 assertions)**
- [`LedgerReconciliationServiceTest.php`](file:///home/thunder/work/traderbybit/tests/Unit/Research/LedgerReconciliationServiceTest.php): **6/6 PASSED (27 assertions)**

Total reconciliation checks: **481/481 assertions passed with zero ledger discrepancies.**
