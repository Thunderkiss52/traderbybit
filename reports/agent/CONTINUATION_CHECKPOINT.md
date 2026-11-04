# VECTOR Continuation Checkpoint — CI Ledger Reconciliation Fix & Primary Account Isolation

**Date & Time:** 2026-11-04T21:25:00+03:00
**Repository Working Directory:** `/home/thunder/work/traderbybit`
**Base Commit / Upstream:** `origin/main` (`c6dbcd0fc28a7e0a895c9b19e26ba1a1cabf450a`)
**Branch:** `main`

---

## 1. Current HEAD and Working-Tree State

- **Starting Baseline:** `c6dbcd0` ("fix(paper): respect valuation provenance in drawdown reconciliation and eliminate false discrepancy")
- **Untracked Historical References Preserved:**
  - `VECTOR_INDEPENDENT_AUDIT_20261031.md` (historical audit report from 2026-10-31)
  - `VECTOR_RELEASE_HARDENING.patch` (uncommitted patch from 2026-10-31)
- **Safety Boundaries Strictly Maintained:**
  - Strictly virtual PAPER trading mode; zero live exchange orders or trading credentials.
  - Zero modification to `econom-group` containers, networks, or volumes.
  - Zero Git pushes or remote branch alterations without explicit operator instruction.

---

## 2. CI Failure Investigation & Root Cause Remediation

### 2.1 The Issue
During CI execution of the Integration test suite (`vendor/bin/phpunit --testsuite Integration`), test 63:
`Tests\Integration\LedgerReconciliationPostgresIntegrationTest::test_reconcile_all_primary_accounts_in_postgresql_succeeds_without_sql_error`
failed with:
```text
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'PASSED'
+'DISCREPANCY_DETECTED'
/app/tests/Integration/LedgerReconciliationPostgresIntegrationTest.php:92
```

### 2.2 Root Cause Analysis
1. In `tests/Integration/ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest.php` (which runs earlier in the Integration suite), `test_two_concurrent_entries_across_different_accounts_reject_excess_risk` executed `PositionLifecycleService::open(...)` on `$account1` (`trend_pullback`, one of the 13 canonical Bybit PRIMARY accounts).
2. `PositionLifecycleService::open` altered `$account1`'s financial metrics in `paper_accounts` (deducting execution entry fee from `realized_pnl`, lowering `current_equity` from $10,000 to $9,997.25, and setting drawdown to 0.0275%) and inserted a snapshot row in `equity_snapshots`.
3. In its `finally` block, the test deleted `virtual_executions` and `virtual_positions`, but did NOT restore `$account1`'s balance/equity/PnL metrics, did NOT delete the temporary `equity_snapshots` row, and relied on `use RefreshDatabase;` which was ineffective because `ensurePostgresTestDatabaseReady()` reconnected the PDO connection, causing queries to auto-commit directly to PostgreSQL.
4. When `LedgerReconciliationPostgresIntegrationTest` subsequently reconciled the 13 primary accounts, `trend_pullback` exhibited:
   - Stored balance: $9,997.25
   - Recalculated balance: $10,000.00 (all virtual_executions were deleted, sum = 0)
   - Diff: $2.75 > $0.05 threshold -> triggered `BALANCE_MISMATCH` and `EQUITY_MISMATCH`, setting status to `'DISCREPANCY_DETECTED'`.

### 2.3 Remediation Implemented
1. **Removed `use RefreshDatabase;`** from `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest`:
   In line with all other Integration tests in VECTOR that explicitly manage isolated test fixtures against shared PostgreSQL databases.
2. **Pristine State Capture & Restoration:**
   - Captured the exact state of all primary Bybit accounts prior to running the risk limits test.
   - In `finally`, restored each Bybit account row back to its exact pre-test values (`current_equity`, `realized_pnl`, `unrealized_pnl`, `reserved_risk`, `maximum_drawdown_percent`, `current_drawdown_percent`, `peak_equity`, `status`).
3. **Comprehensive Fixture Cleanup:**
   - Deleted all temporary `equity_snapshots` with `source_event_key != 'account:initial'`.
   - Deleted temporary `virtual_positions`, `virtual_executions`, `trading_journal_events`, `paper_signal_states`, `signals`, and provisioned `binance` accounts.
4. **Advisory Lock Safety:**
   - Wrapped advisory lock verification in `test_entry_decision_lock_serializes_concurrent_postgresql_transactions` in a `try ... finally { $db1->rollBack(); }` block.
5. **Observability Hardening:**
   - Updated `LedgerReconciliationPostgresIntegrationTest` assertion message to output the failing account ID and JSON-encoded discrepancies, making any future diagnostic immediate.

---

## 3. Verification Evidence

1. **Reproduction & Isolated Verification:**
   - Running `ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest` followed by `LedgerReconciliationPostgresIntegrationTest`:
     `OK (5 tests, 64 assertions)` in 0.98s.
   - Repeated double run to verify idempotency:
     `OK (10 tests, 128 assertions)` in 1.55s.
2. **PHP Formatting & Style:**
   - `vendor/bin/pint --test`: `PASS ................. 766 files` (0 issues).
3. **Paper Trading & Drawdown Regression:**
   - `tests/Feature/PaperTrading/ProductionDrawdownReconciliationTest.php`: `OK (4 tests, 23 assertions)`.
4. **Research Go Tests:**
   - `go test ./...`: PASSED.
   - `go test -race ./...`: PASSED with zero data races.
5. **Frontend Suite:**
   - `npm test`: 64/64 tests passed.
   - `npm run build`: Vite 5 production build succeeded with 0 errors.

---

## 4. Release Audit Disposition

**`READY_FOR_OWNER_PRODUCTION_PREFLIGHT`**
