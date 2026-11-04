# Production Drawdown Reconciliation Root Cause Investigation

**Date:** 2026-11-04T20:35:00+03:00
**Authority:** Principal Software Engineer, Quantitative Systems Architect, and Independent QA Lead
**Scope:** Root Cause Analysis of Production PAPER Reconciliation Discrepancies (`HISTORICAL_DRAWDOWN_UNDERSTATED`, `funding_history_incomplete`, and Candidate Evaluation History Limits)
**Target Accounts:**
- Bybit `baseline`: `94b97f13-107d-54a9-b4eb-1ca5a9c79743` (Status: `BLOCKED`)
- Bybit `momentum`: `1b36e922-59b6-50a6-8209-cd0391dce128` (Status: `BLOCKED`)

---

## Executive Summary

A thorough investigation of the authoritative financial writers, snapshot collectors, and reconciliation engines was performed against real production evidence and codebase history.

### Key Conclusions:
1. **Zero Financial or Account State Corruption:** Neither account is corrupted. Both accounts entered virtual trading with 10,000 USDT virtual capital, executed trades resulting in realized net losses, and reached drawdowns exceeding the mandatory 5.0% risk block threshold. Both accounts were correctly set to `BLOCKED` status and **MUST remain blocked**.
2. **Mathematical Exactness of Stored Account Metrics:**
   - **Baseline:** Stored maximum drawdown `5.38498549%` is the exact mathematical drawdown from the stored peak of 10,000 USDT to current equity 9,461.501450960003 USDT: `(10000 - 9461.501450960003) / 10000 * 100 = 5.38498549%`.
   - **Momentum:** Stored maximum drawdown `5.02839241%` is the exact mathematical drawdown from the stored peak of 10,000 USDT to current equity 9,497.160758949486 USDT: `(10000 - 9497.160758949486) / 10000 * 100 = 5.02839241%`.
3. **Root Cause of `HISTORICAL_DRAWDOWN_UNDERSTATED`:**
   - The reconciliation service calls `PerformanceStatisticsService::drawdown()`, which iterates over historical `equity_snapshots`.
   - During open positions on Oct 15 (`baseline`: +14.83 USDT unrealized) and Oct 17 (`momentum`: +31.04 USDT unrealized), periodic checkpoints recorded nominal floating equity snapshots of 10,012.67 USDT and 10,007.83 USDT.
   - Crucially, both snapshots were recorded 10–12 days **before** the valuation provenance migration (`2026_10_27_120000_add_valuation_provenance_to_equity_snapshots.php`), leaving `valuation_fresh = NULL` (`LEGACY_UNKNOWN`).
   - Prior to our fix, `PerformanceStatisticsService::drawdown()` ignored valuation provenance and blindly elevated the historical peak to 10,012.67 USDT and 10,007.83 USDT based on unverified floating mark-to-market profits that were never realized. This artificially inflated the recalculated drawdown lower bound to `5.50471698%` and `5.10267772%`, triggering `HISTORICAL_DRAWDOWN_UNDERSTATED`.
4. **Root Cause of `funding_history_incomplete`:**
   - `LedgerReconciliationService` checks `VirtualPositionRecord::where('funding_complete', false)`.
   - Positions opened between Oct 15 and Oct 17 predated continuous historical ingestion in `market_funding_events`. Per `SKILL.md` Section G.4, missing market history is honestly surfaced as `funding_history_incomplete` and `INCOMPLETE` without fabricating data.
5. **Root Cause of Candidate Evaluation History Error:**
   - `EvaluateStrategyCandidateJob` requests a default validation window of 180 days (`config('research.evolution.validation_days', 180)`).
   - When historical market candle tables have fewer than 180 days of contiguous 5m candles, `DatasetInspector::verifyContinuousRange()` throws `Incomplete requested range for BTCUSDT 5m`.

---

## Detailed Investigation (Questions 1 through 8)

### 1. Locate Every Writer of `paper_accounts.peak_equity`, `maximum_drawdown_percent`, and `current_drawdown_percent`

All code paths modifying these fields were cataloged:
1. **Migrations (Schema & Seeding):**
   - `database/migrations/2026_10_14_000000_create_paper_trading_tables.php`: Initial column definitions (`peak_equity NUMERIC(36, 12)`, `maximum_drawdown_percent NUMERIC(16, 8)`, `current_drawdown_percent NUMERIC(16, 8)`).
   - `database/migrations/2026_10_18_010000_enforce_eight_primary_accounts_and_widen_quality.php`: Inserts 8 primary accounts with `peak_equity = 10000`, `maximum_drawdown_percent = 0`, `current_drawdown_percent = 0`.
   - `database/migrations/2026_10_22_020000_add_five_primary_strategy_accounts.php`: Inserts 5 remaining primary accounts with identical baseline zeros.
2. **Account Provisioning & Capital Reset:**
   - `app/PaperTrading/Application/PaperAccountService.php`:
     * `createAccount()` / `default()`: Initializes starting balance and peak equity.
     * `reset()` / `adjustStartingCapital()`: Resets peak equity and zeroes drawdowns upon approved recapitalization.
   - `app/Exchanges/Binance/BinancePaperCapital.php`: Synchronizes Binance approved virtual allocations.
   - `app/Exchanges/Binance/BinancePaperProvisioner.php`: Initializes Binance placeholder accounts with zero capital.
   - `app/PaperTrading/Application/PaperTopologySynchronizer.php`: Reconciles missing primary strategy accounts.
   - `app/Strategy/Application/ResearchPortfolioManager.php`: Provisions forward paper research experiments.
3. **Authoritative Runtime Financial Writer:**
   - `app/PaperTrading/Application/PositionLifecycleService.php` (`refreshAccountMetrics`):
     * This is the **sole runtime writer** during live paper execution.
     * Invoked exclusively inside database transactions on:
       - `open()`: Deducts entry taker commission fee; refreshes equity and drawdown.
       - `executeExit()`: Finalizes realized gross PnL and exit commission; refreshes equity and drawdown.
       - `applyFunding()`: Applies settled funding cash flows to realized balance.
       - `markToMarket()`: Updates position unrealized PnL and account mark-to-market equity.

---

### 2. Authoritative Drawdown Calculation Contract

Under Section A, C, and D of [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md):
- **Equity Identity:**
  $$\text{Equity}(t) = \text{Starting\_Balance} + \text{Realized\_PnL}(t) + \text{Unrealized\_PnL}(t)$$
- **Peak Equity Contract:**
  $$\text{Peak\_Equity}(t) = \max \left( \text{Starting\_Balance}, \max_{s \le t} \text{Equity}(s) \right)$$
- **Current Drawdown Contract:**
  $$\text{Current\_Drawdown}(t) = \frac{\text{Peak\_Equity}(t) - \text{Equity}(t)}{\text{Peak\_Equity}(t)} \times 100\%$$
- **Maximum Drawdown Contract:**
  $$\text{Maximum\_Drawdown}(t) = \max_{s \le t} \text{Current\_Drawdown}(s)$$
- **Risk Circuit Breakers:**
  - $\text{Drawdown} \ge 5.0\% \implies \text{Status} = \text{BLOCKED}$ (new entries prohibited; open positions serviced).
  - $\text{Drawdown} \ge 10.0\% \implies \text{Status} = \text{HALTED}$ (all trading suspended).

---

### 3. Should Unrealized Mark-to-Market Profits Contribute to Peak Equity?

**Financial & Quantitative Analysis:**
- In linear perpetual futures, open position unrealized PnL represents true floating liquidation equity (NAV). Economic drawdown from an open peak represents true loss of portfolio capital.
- **Critical Requirement:** Unrealized PnL is only as reliable as its underlying market mark. Section C.4 and E.6 of `SKILL.md` mandate:
  * Unrealized PnL must be computed strictly from verified, fresh mark prices ($\le 300\text{s}$ old).
  * If a mark price is stale ($> 300\text{s}$), it is flagged `STALE` and cannot certify account performance.
  * If a mark price has no valuation provenance (`valuation_fresh IS NULL`), it is `LEGACY_UNKNOWN`.
- **Verdict:** True, verified mark-to-market equity (`valuation_fresh === true` or flat accounts `unrealized_pnl == 0`) elevates peak equity. However, **unverified legacy snapshots (`LEGACY_UNKNOWN`) and stale snapshots (`STALE`) must NEVER elevate authoritative peak equity**.

---

### 4. Comparison: Paper Trading Engine vs ExchangePaperReconciliation vs Historical Snapshots

| Subsystem | Responsibilities & Scope | Peak Equity / Drawdown Handling |
|---|---|---|
| **`PositionLifecycleService`** | Event-driven trading pipeline, executions, fees, funding, mark-to-market. | Authoritative runtime writer of `paper_accounts.peak_equity` and `maximum_drawdown_percent`. Evaluated on closed candles and market events. |
| **`ExchangePaperReconciliation`** | Read-only M9 proof of accounting invariants across all 26 accounts. | Reconciles starting balance, executions, fees, funding, position net PnL, and cross-exchange isolation (`LEDGER_PASS_ONLY`). Does **not** inspect snapshots or calculate drawdown. |
| **`PerformanceStatisticsService`** | Reporting, API metrics, and ledger reconciliation lower-bound checks. | Queries `equity_snapshots` cursor chronologically. Prior to remediation, it elevated `$peak` on unverified legacy snapshots without checking `valuation_fresh`, causing divergence from stored account metrics. |

---

### 5. Interpretation of Legacy Snapshots with NULL Valuation Provenance

Migration `2026_10_27_120000_add_valuation_provenance_to_equity_snapshots.php` added `valued_through` and `valuation_fresh` as nullable columns without backfilling synthetic data.
- In `DailyPerformanceService.php`, snapshots are classified:
  * `closing === null` $\implies$ `MISSING`
  * `valuation_fresh === false` $\implies$ `STALE`
  * `unrealized_pnl == 0` $\implies$ `FLAT_VERIFIED`
  * `valuation_fresh === true` $\implies$ `FRESH`
  * Default $\implies$ `LEGACY_UNKNOWN`
- Historical snapshots created between Oct 14 and Oct 26 possess `valuation_fresh = NULL`.
- In Baseline and Momentum, the snapshots recorded at peak had positive floating unrealized PnL (+14.83 and +31.04) and `valuation_fresh = NULL`. They are strictly `LEGACY_UNKNOWN`.

---

### 6. Contemporeneity of Historical Peaks and Market Marks

- Baseline snapshot at 2026-10-15 13:55 UTC: position was open with +14.83 unrealized PnL.
- Momentum snapshot at 2026-10-17 17:11 UTC: position was open with +31.04 unrealized PnL.
- Because these snapshots predate valuation provenance tracking, there is no recorded timestamp of the exact order book or mark price tick used.
- Crucially, both positions subsequently reversed and closed at substantial net losses:
  * Baseline closed at net loss of `-538.49854904 USDT`.
  * Momentum closed at net loss of `-502.83924105 USDT`.
- Neither account ever realized any profit above the 10,000 USDT starting allocation.

---

### 7. Inconsistency Between Account Risk State, Snapshots, and Reconciliation

**Verification of Risk State:**
- Baseline:
  * Drawdown under stored peak: `5.38498549% >= 5.0%` $\implies$ `BLOCKED`.
  * Drawdown under legacy snapshot peak: `5.50471698% >= 5.0%` $\implies$ `BLOCKED`.
- Momentum:
  * Drawdown under stored peak: `5.02839241% >= 5.0%` $\implies$ `BLOCKED`.
  * Drawdown under legacy snapshot peak: `5.10267772% >= 5.0%` $\implies$ `BLOCKED`.

**Finding:**
There is **zero operational or risk inconsistency**. Both accounts breached the 5.0% risk limit under both calculations and are rightfully `BLOCKED`. The inconsistency was solely an artifact of the reconciliation methodology comparing stored account maximum against an unverified legacy snapshot lower bound.

---

### 8. Chronology: Observed Historical Peak vs Subsequent Trough

- **Baseline Chronology:**
  1. 2026-10-14 00:00 UTC: Initial allocation 10,000 USDT.
  2. 2026-10-15 13:55 UTC: Unverified floating peak 10,012.670631594740 USDT (`valuation_fresh = NULL`).
  3. 2026-10-15 16:00 UTC: Position closed at loss. Equity dropped to 9,461.501450960003 USDT.
- **Momentum Chronology:**
  1. 2026-10-14 00:00 UTC: Initial allocation 10,000 USDT.
  2. 2026-10-17 17:11 UTC: Unverified floating peak 10,007.827966395667 USDT (`valuation_fresh = NULL`).
  3. 2026-10-17 20:00 UTC: Position closed at loss. Equity dropped to 9,497.160758949486 USDT.

In both instances, the nominal floating peak strictly preceded the subsequent exit trough. However, because the peak was unverified and never realized, elevating the stored peak retroactively is unjustified.

---

## Root Cause of Additional Findings

### 1. `funding_history_incomplete`
In `LedgerReconciliationService.php` line 116:
```php
if (VirtualPositionRecord::query()->where('paper_account_id', $accountId)->where('funding_complete', false)->exists()) {
    $incompleteReasons[] = 'funding_history_incomplete';
}
```
In `PositionLifecycleService::evaluateFundingCompleteness()`:
- Positions open during historical periods lacking contiguous `market_funding_events` fail schedule verification.
- Per quantitative research invariants, missing market history is reported honestly as `INCOMPLETE` without fabricating funding events.

### 2. Candidate Evaluation Error: `Incomplete requested range for BTCUSDT 5m`
In `EvaluateStrategyCandidateJob.php`:
```php
$validationDays = max(30, (int) config('research.evolution.validation_days', 180));
$to = CarbonImmutable::now('UTC')->subHours($endLag);
$from = $to->subDays($validationDays);
```
- The evaluation engine requests 180 days of contiguous 5m history back from current UTC.
- When `market_candles` contains fewer than 180 days, `DatasetInspector::verifyContinuousRange()` throws `Incomplete requested range for BTCUSDT 5m` and safely logs the candidate evaluation failure without corrupting system state.
