# VECTOR Financial Reconciliation & Accounting Integrity Report

**Date:** 2026-11-04T18:38:00+03:00  
**Verification Target:** Double-Entry Accounting Identities, M9 Full-Cohort Reconciliation, and Ledger Audit  
**Authoritative Engine:** PostgreSQL 17, Laravel Financial Pipeline  

---

## 1. Accounting Identities & Reconciliation Formula

VECTOR implements authoritative double-entry virtual accounting:

$$\text{Current Balance} = \text{Starting Balance} + \text{Realized PnL}$$

Where:

$$\text{Realized PnL} = \sum \text{Gross PnL} - \sum \text{Commissions} + \sum \text{Funding Settlements}$$

$$\text{Current Equity} = \text{Current Balance} + \sum \text{Unrealized PnL}$$

$$\text{Drawdown} = \frac{\text{Peak Equity} - \text{Current Equity}}{\text{Peak Equity}} \times 100$$

---

## 2. Verified Reconciliation Invariants

1. **M9 Ledger Pass Invariant:**
   - `ExchangePaperReconciliation::inspect()` runs under `REPEATABLE READ` snapshot isolation.
   - Verifies all 26 canonical PRIMARY pairs (13 Bybit + 13 Binance).
   - Recalculates sum of all `virtual_executions.gross_pnl`, `virtual_executions.commission`, and `position_funding_events.amount`.
   - Confirms zero discrepancy between stored `paper_accounts` fields and reconstructed ledger sums (`LEDGER_PASS_ONLY`).

2. **Equity Snapshot Integrity:**
   - Stored peak equity cannot fall below starting balance (`starting_balance <= peak_equity`).
   - Drawdown percent is strictly non-negative and monotonic relative to peak equity.
   - Initial opening capital snapshots (`account:initial`) are preserved and immutable.

3. **Funding Event Audit:**
   - Every market funding settlement applies to open positions at funding timestamps.
   - Recorded in `position_funding_events` with Mark Price, Funding Rate, and Applied Quantity.
   - Journal event `FUNDING_APPLIED` recorded with unique idempotency keys preventing double-crediting/debiting.

4. **Fee Attribution:**
   - Exchange-specific taker fee rates enforced:
     - Bybit linear perps: `0.0006` (0.06%).
     - Binance linear perps: `0.0005` (0.05%).
   - Taker fees deducted at both entry and exit executions.

---

## 3. Discrepancy Prevention & Negative Controls

- **Overclosed Positions:** Any execution attempting to close more than `remaining_quantity` is rejected.
- **Duplicate Fills:** Unique index and transaction locking prevent multiple positions per signal state.
- **Orphan Executions:** Every execution row strictly references a valid `position_id` and `exchange_id`.
