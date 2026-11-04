# VECTOR 26 PAPER Account Onboarding & Portfolio Isolation Verification

**Date:** 2026-11-04T18:38:00+03:00  
**Test Suite:** `tests/Feature/PaperTrading/TwentySixPaperAccountsVerificationTest.php`  
**Runtime Environment:** Docker container `vector-agent-php85-test:local`, PostgreSQL 17  
**Git Commit:** `07fac76`  

---

## 1. Overview

Work Unit B required comprehensive verification of the 26 canonical PAPER accounts across five mandatory onboarding scenarios (Scenarios A through E) and sizing configuration options.

**Test Results:** 6 passed, 0 failed, 320 assertions in 2.38 seconds.

---

## 2. Onboarding Scenarios Verification

### 2.1 Scenario A — 26 Canonical Primary Accounts Isolated and Funded

- **Description:** 13 PRIMARY strategies registered on Bybit and 13 PRIMARY strategies registered on Binance.
- **Verification:**
  - `PaperTopologySynchronizer::sync(apply: true)` created exactly 13 Bybit primary accounts and 13 Binance primary accounts (26 total).
  - All 13 Bybit accounts funded with `10,000` USDT opening balance.
  - All 13 Binance accounts provisioned with `10,000` USDT virtual opening balance via `BinancePaperCapital::allocate`.
  - Every account maintains strict isolation: unique UUIDs, distinct `exchange_id` values, zero cross-exchange balance leakage.

### 2.2 Scenario B — Compatible Strategy Addition Without Mutating Existing Accounts

- **Description:** Dynamically registering a 14th strategy family in `StrategyRegistry` and running synchronization.
- **Verification:**
  - Topology synchronizer created exactly 1 new Bybit account and 1 new Binance account (28 total accounts).
  - New accounts provisioned in `BLOCKED` status with zero capital on Binance until explicit operator allocation.
  - All 26 existing account states, starting balances, current equities, and ledger histories remained 100% unchanged.

### 2.3 Scenario C — Research-Only Exchange Onboarding

- **Description:** Introducing a new public exchange adapter without paper trading execution approval.
- **Verification:**
  - Synchronizer provisioned placeholder rows for the new exchange with `BLOCKED` status, `0` starting balance, and `0` equity.
  - Confirmed that zero virtual capital is exposed to unreviewed exchange adapters.

### 2.4 Scenario D — Idempotent Repeated Synchronization

- **Description:** Running `PaperTopologySynchronizer::sync(apply: true)` multiple times consecutively.
- **Verification:**
  - Second and third runs reported `0` created accounts.
  - Account count remained exactly 26.
  - Balances, equity snapshots, and journal events remained unperturbed.

### 2.5 Scenario E — Custom Opening Balance Policy

- **Description:** Verifying `NewPrimaryCapitalPolicy` custom opening balances per strategy.
- **Verification:**
  - Configured custom balance of `25,000` USDT for `trend_pullback`.
  - Account service correctly initialized opening balance to `25,000` USDT rather than default fallback.

---

## 3. Position Sizing Modes and Leverage Caps

Verified across all accounts:

1. **Risk Percent Mode:** Reserves exact worst-case loss (stop distance + round-trip taker fees and slippage). Max risk percent capped at `0.50%` per Class A trade.
2. **Fixed Notional Mode:** Sizing constrained by target dollar amount without exceeding maximum leverage or minimum notional boundaries.
3. **Fixed Margin Mode with 2x Leverage Cap:** Fixed margin multiplied by configured leverage. Any leverage exceeding `2.0x` (e.g. `2.5x`, `5.0x`, `10.0x`) is strictly rejected by `PositionSizingService` with `notional_limit_exceeded`.
