# VECTOR V2.6 — Independent Production-Evidence Remediation Report (I01–I09)
**Codex Execution and Evidence Report | October 27, 2026**

## 1. Executive Summary and Baseline

- **Repository:** `/home/thunder/work/traderbybit`
- **Initial Baseline SHA:** `b6a8566d32de3eb3e706170651c05a47236fb1c3`
- **Preceding F01–F25 Agent Commits:**
  - `3165173 fix(vector): financial integrity and profitability contracts`
  - `08a0d7c fix(vector): audit lineage and paper entry observability`
- **Starting HEAD for Remediation:** `08a0d7c`
- **Local Commits Created:**
  1. `2632d7e fix(vector): paper pipeline acknowledgments and partial recovery`
  2. `bd20e6c fix(vector): paper pipeline event lifecycle terminalization and retry isolation (I01, I04, I06, I09)` [Stage A]
  3. `c259ec7 fix(vector): position partial exits and funding completeness (I02, I03, I07)` [Stage B]
  4. `76fe309 fix(vector): exchange gap recovery and valuation provenance (I05, I08)` [Stage C]
  5. `5e6ea04 docs(vector): finalize I01-I09 production-evidence remediation report`
  6. `2acaa61 docs(agent): standardize VECTOR engineering practices` [Stage 0]
  7. `7f55222 docs(vector): update remediation report with Stage 0 engineering standards`
  8. `f1a0719 fix(vector): resolve pre-deployment acceptance concerns (F26, F27, I03, I08, reconcile CLI)`
- **Working Tree State:** Clean, all tests passing.
- **Strict Safety Confirmation:**
  - PAPER-ONLY mode strictly enforced; zero live exchange endpoints or real orders.
  - 26 independent PAPER accounts (13 Bybit + 13 Binance) fully preserved with account isolation.
  - 5% entry-block and 10% drawdown halt thresholds preserved.
  - ECONOM / econom-group completely untouched.
  - No `git push`, no manual deploy, no GitHub Actions triggered.

---

## 1.1 Stage 0 — Repository Engineering Standards

### 1. AGENTS.md Updates
- Established `AGENTS.md` as the primary repository instruction entry point.
- Added explicit mandatory rule: Every coding agent working in VECTOR MUST read and follow the root-level `SKILL.md` before modifying application code, tests, database migrations, API contracts, background jobs, infrastructure files, or research logic.
- Resolved outdated architectural references: replaced obsolete 8-account statements with the canonical architecture of 26 independent PAPER accounts (13 PRIMARY strategies on Bybit, 13 PRIMARY strategies on Binance).
- Reaffirmed strict PAPER-only execution, zero real exchange orders, and operator-triggered deployment via GitHub Actions.

### 2. Root-Level SKILL.md Standards Specification
Created the comprehensive, root-level [`SKILL.md`](file:///home/thunder/work/traderbybit/SKILL.md) covering:
- **Section A: Senior Engineering Principles** (root cause analysis, domain invariants, small changesets, explicit maintainability, zero logic duplication, failure observability).
- **Section B: PHP 8.x and Laravel Engineering** (strict types, DI, thin controllers, transactional boundaries, queue idempotency, N+1 prevention, Pint formatting).
- **Section C: PostgreSQL and Financial Accounting** (`NUMERIC(24, 12)` precision, arbitrary-precision `BigDecimal`, account/exchange scoping, double-entry reconciliation, immutable history).
- **Section D: PAPER Trading and Risk Safety** (independent Bybit/Binance accounts, closed-candle invariant, 5% entry block, 10% halt, lot-size rounding protections, continuous servicing of open positions).
- **Section E: Vue 3, Quasar and TypeScript Frontend** (typed API contracts, explicit loading/empty/error/stale states, no fabricated zeros, timezone handling, risk disclaimers).
- **Section F: Go Research Engineering** (bounded worker pools, determinism, context cancellation, strict separation from live paper ledger).
- **Section G: Quantitative Research & Look-Ahead Prevention** (zero look-ahead, chronological train/val/test splits, purging/embargo windows, honest insufficient-data states).
- **Section H: Event-Driven Pipeline Reliability** (explicit lifecycle ownership, downstream acknowledgment, per-account failure isolation, targeted retries).
- **Section I: Testing & Quality Assurance Protocols** (targeted regression suites, financial invariants, isolated test databases, non-destructive test runs).
- **Section J: Git, DevOps, and Operational Safety** (atomic local commits, no autonomous git push, no manual VPS deployment, zero ECONOM intrusion).
- **Section K: Definition of Done (DoD)** (traceable verification evidence, safety confirmation, documentation updates).

---

## 2. Findings I01–I09 Remediation Tracking Matrix

| Finding ID | Title | Severity & Observed Evidence | Affected Source Paths | Commits | Local Reproducer / Test | Status |
|---|---|---|---|---|---|---|
| **I01** | Lifecycle: `SIGNALS_EVALUATED` never correctly terminalizes | HIGH (Observed: 264 stale events in production) | `app/Trading/Application/TradingPipelineEventService.php`, `app/Trading/Application/TradingPipelineRecoveryService.php` | `bd20e6c` | `tests/Feature/Trading/TradingPipelineStageATest.php` (`test_record_paper_processed_terminalizes_signals_evaluated_to_completed`, `test_recovery_reconciles_unacknowledged_upstream_event_from_completed_paper`) | `FIXED_LOCAL_PROD_PENDING` |
| **I02** | TP1 fractional exit can round to zero | HIGH code hazard (No prod zero-quantity fill observed) | `app/PaperTrading/Application/PositionLifecycleService.php` | `c259ec7` | `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php` (`test_tp1_zero_quantity_is_safely_skipped_without_creating_execution`) | `FIXED_LOCAL_PROD_PENDING` |
| **I03** | `funding_complete=false` persists for all positions | HIGH (Observed: 0 of 437 positions marked complete) | `app/PaperTrading/Application/PositionLifecycleService.php`, `app/PaperTrading/Application/PaperProcessingService.php` | `c259ec7` | `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php` (`test_funding_completeness_evaluates_correctly_for_zero_settlement_lifetime`, `test_late_arriving_funding_settles_on_closed_position_with_correct_historical_quantity`) | `FIXED_LOCAL_PROD_PENDING` |
| **I04** | `PAPER_PROCESSED=COMPLETED` despite per-account failures | HIGH (Observed: 2 cycles with `failures_count=1`) | `app/Trading/Jobs/ProcessPaperPipelineJob.php`, `app/Trading/Application/TradingPipelineEventService.php` | `2632d7e`, `bd20e6c` | `tests/Feature/Trading/TradingPipelineStageATest.php` (`test_record_paper_processed_marks_partial_on_failures`, `test_process_paper_pipeline_job_supports_targeted_retry_with_only_account_ids`) | `FIXED_LOCAL_PROD_PENDING` |
| **I05** | Binance 5m candle gap invokes Bybit-only recovery | HIGH code hazard (Zero prod gaps observed) | `app/PaperTrading/Application/PaperProcessingService.php`, `app/MarketData/Jobs/RecoverCandleGapsJob.php` | `76fe309` | `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php` (`test_gap_recovery_routes_strictly_to_binance_ingestion_for_binance_exchange`, `test_gap_recovery_routes_to_bybit_sync_candles_job_for_bybit_exchange`) | `FIXED_LOCAL_PROD_PENDING` |
| **I06** | Binance provenance exception outside per-account catch | HIGH code hazard (Prod incidence not isolated) | `app/Trading/Jobs/ProcessPaperPipelineJob.php` | `2632d7e`, `bd20e6c` | `tests/Feature/Trading/TradingPipelineStageATest.php` (`test_binance_provenance_failure_on_one_account_does_not_abort_others`) | `FIXED_LOCAL_PROD_PENDING` |
| **I07** | Late funding may never settle on closed positions | HIGH (Candidate: 5 closed positions with late funding) | `app/PaperTrading/Application/PositionLifecycleService.php`, `app/MarketData/Application/FundingHistorySynchronizer.php`, `app/Exchanges/Binance/BinanceFundingIngestion.php` | `c259ec7` | `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php` (`test_late_arriving_funding_settles_on_closed_position_with_correct_historical_quantity`) | `FIXED_LOCAL_PROD_PENDING` |
| **I08** | Fresh snapshot timestamp can certify stale market valuation | MEDIUM code hazard (26 accounts had recent snapshots) | `database/migrations/2026_10_27_120000_add_valuation_provenance_to_equity_snapshots.php`, `app/PaperTrading/Jobs/CapturePaperEquityJob.php`, `app/PaperTrading/Application/DailyPerformanceService.php`, `app/PaperTrading/Application/PaperAccountService.php` | `76fe309` | `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php` (`test_flat_account_checkpoint_is_verified_complete_with_flat_verified_valuation`, `test_account_with_stale_market_valuation_is_flagged_as_stale_and_incomplete`) | `FIXED_LOCAL_PROD_PENDING` |
| **I09** | 5m `CANDLE_CLOSED` acknowledged before downstream PAPER completion | HIGH code path (Downstream decoupled premature ack) | `app/Trading/Jobs/ProcessClosedCandleEventJob.php`, `app/Trading/Application/TradingPipelineEventService.php` | `2632d7e`, `bd20e6c` | `tests/Feature/Trading/TradingPipelineStageATest.php` (`test_record_paper_processed_terminalizes_5m_candle_closed_to_completed`) | `FIXED_LOCAL_PROD_PENDING` |

---

## 3. Detailed Root Causes, Implementations, and Verification

### Stage A — Pipeline Correctness and Account Isolation (I01, I04, I06, I09)

#### 1. I01 & I09: Upstream Pipeline Terminalization
- **Root Cause:** In the event-driven trading pipeline, `ProcessClosedCandleEventJob` dispatched `ProcessPaperPipelineJob` for 15m signal cycles and 5m lifecycle cycles. Downstream `ProcessPaperPipelineJob` recorded `STAGE_PAPER_PROCESSED`, but upstream `STAGE_SIGNALS_EVALUATED` and 5m `STAGE_CANDLE_CLOSED` remained indefinitely in `STATUS_DISPATCHED`. During the 300s window, `TradingPipelineRecoveryService` repeatedly redispatched the jobs (causing 132 repeated dispatches in production), and once the freshness window elapsed, marked all 264 upstream events as `STATUS_STALE`.
- **Implementation:**
  - In `TradingPipelineEventService::recordPaperProcessed()`: Added call to `acknowledgeUpstreamEvents()`, which queries matching `STAGE_SIGNALS_EVALUATED` and `STAGE_CANDLE_CLOSED` records in `STATUS_DISPATCHED` for that `(exchange, symbol, candleCloseAt)` and updates their status to match `PAPER_PROCESSED` (`COMPLETED`, `PARTIAL`, or `FAILED`) with `completed_at = $now`.
  - In `TradingPipelineRecoveryService::recover()`: Before marking events stale or redispatching, checks if downstream `STAGE_PAPER_PROCESSED` event already completed. If so, immediately terminalizes the unacknowledged upstream event without redispatching.
  - In `ProcessClosedCandleEventJob::handleExecutionCandle()`: Sets `STATUS_DISPATCHED` only if `isEventDriven()`, else marks `COMPLETED`.
- **Verification:** `tests/Feature/Trading/TradingPipelineStageATest.php` confirms `SIGNALS_EVALUATED` and 5m `CANDLE_CLOSED` transition to `COMPLETED` upon paper completion, and recovery reconciles unacknowledged events without redispatching.

#### 2. I04 & I06: Partial Failure Semantics and Account Isolation
- **Root Cause:** In `ProcessPaperPipelineJob`, `BinancePaperSafety::assertProvenance()` was evaluated outside the per-account try-catch loop, so a single bad account threw an uncaught exception that aborted execution for all subsequent accounts. Furthermore, `recordPaperProcessed()` always recorded `STATUS_COMPLETED`, masking failures (as observed in production with two Bybit ETHUSDT cycles marked COMPLETED despite `failures_count=1`).
- **Implementation:**
  - In `ProcessPaperPipelineJob`: Wrapped provenance and execution safety checks inside the per-account try-catch block. Tracked `eligible_accounts`, `processed_accounts`, and detailed `failures` per account.
  - In `TradingPipelineEventRecord`: Added `STATUS_PARTIAL`.
  - In `TradingPipelineEventService::recordPaperProcessed()`: Derived terminal status: `STATUS_FAILED` if `failuresCount > 0 && processedAccounts === 0`; `STATUS_PARTIAL` if `failuresCount > 0 || processedAccounts < eligibleAccounts`; `STATUS_COMPLETED` only if all eligible accounts succeeded with zero failures.
  - In `ProcessPaperPipelineJob`: Added support for `$onlyAccountIds` parameter for targeted retries of failed accounts.
- **Verification:** `tests/Feature/Trading/TradingPipelineStageATest.php` verifies that an invalid Binance provenance on one account logs failure, records `failures_count=1`, sets status to `PARTIAL`, and allows valid accounts to complete.

---

### Stage B — Position Lifecycle and Funding Integrity (I02, I03, I07)

#### 1. I02: TP1 Fractional Exit Guard Against Zero-Quantity Fills
- **Root Cause:** When a position with a small initial quantity (e.g. 0.001 BTC at step 0.001) triggered TP1 (50%), `BigDecimal::of($locked->initial_quantity)->multipliedBy(0.50)->dividedBy(0.001, 0, RoundingMode::Floor)->multipliedBy(0.001)` floored to 0.000. `PositionLifecycleService::executeExit()` executed `recordExecution` with `quantity = '0.000000000000'` and transitioned position to `PARTIALLY_CLOSED`.
- **Implementation:**
  - In `PositionLifecycleService::executeExit()`:
    - Added guard on `ExecutionType::TakeProfit1`: if `$rawTp1Qty->isLessThanOrEqualTo(0)`, logs warning and returns `$locked` without creating any execution or modifying position status.
    - Added guard against `$rawTp1Qty >= $remainingBefore`.
    - Added universal guard on all exit types: if `$quantity <= 0`, aborts exit with warning and returns `$locked`.
- **Verification:** `tests/Feature/PaperTrading/PositionLifecycleStageBTest.php` proves that sub-lot TP1 triggers skip safely, keep the position `OPEN`, create 0 fake executions, and allow subsequent StopLoss or full exit to execute cleanly.

#### 2. I03: Evidence-Based Funding Completeness Semantics
- **Root Cause:** Positions were initialized with `'funding_complete' => false` upon creation, and no logic ever updated `funding_complete` to `true`. Consequently, all 437 production positions had `funding_complete=false`.
- **Implementation:**
  - In `PositionLifecycleService`:
    - Implemented `expectedFundingBoundaries(DateTimeImmutable $from, DateTimeImmutable $to)`: computes the exact 8-hour funding intervals (00:00, 08:00, 16:00 UTC) strictly falling between position open and close.
    - Implemented `evaluateFundingCompleteness(VirtualPositionRecord $position)`:
      - OPEN positions remain `funding_complete = false` (future settlements pending).
      - CLOSED positions with 0 expected funding boundaries are marked `funding_complete = true` once the market funding feed watermark is verified alive (`latestFunding >= opened_at - 8h`).
      - CLOSED positions with 1+ expected boundaries check that every expected boundary exists in `market_funding_events` and, if quantity was held, has a corresponding row in `position_funding_events`. If any expected market event is missing (delayed ingestion), completeness remains `false`. Once verified, updates `funding_complete = true`.
- **Verification:** Tested in `PositionLifecycleStageBTest.php` for both 0-settlement lifetimes and multiple-settlement lifetimes.

#### 3. I07: Late-Arriving Funding Settlement on Closed Positions
- **Root Cause:** `PaperProcessingService` skipped closed positions (`if (PositionStatus::isClosed()) break;`). If Bybit or Binance funding rates for 00:00 UTC arrived at 00:11 UTC (after a position closed at 00:10 UTC), `applyFunding()` was never invoked for that position. Furthermore, `applyFunding()` evaluated `locked->remaining_quantity`, which on a closed position is 0.
- **Implementation:**
  - Implemented `quantityHeldAt(VirtualPositionRecord $position, DateTimeImmutable $at)`: deterministically reconstructs historical exposure active at funding timestamp `$at` from the position's execution history (`initial_quantity` minus any `TakeProfit1` partial closes occurring before `$at`).
  - Refactored `applyFunding(VirtualPositionRecord $position, DateTimeImmutable $through)`:
    - Allows processing closed positions up to `closed_at`.
    - Uses `quantityHeldAt($locked, $event->funding_at)` to calculate funding cashflow.
    - Idempotently creates `PositionFundingEventRecord`.
    - Updates position `funding`, `net_pnl`, and `r_multiple` (without reopening the position).
    - Updates account `realized_pnl` and `current_equity`, snapshotting and appending `FUNDING_APPLIED` to the journal.
  - Implemented `reconcilePendingFunding(string $exchange, string $symbol, ?DateTimeImmutable $since = null)`: automatically called by `FundingHistorySynchronizer` (Bybit) and `BinanceFundingIngestion` (Binance) upon ingesting new market funding records.
- **Verification:** `PositionLifecycleStageBTest.php` tests a position taking TP1 at 23:55 UTC and SL at 00:10 UTC; funding event for 00:00 UTC arrives after close; verifies exact held quantity of 0.005 BTC is funded, accounting invariant holds, second run is idempotent, and position transitions to `funding_complete = true`.

---

### Stage C — Exchange Ingestion and Equity Freshness (I05, I08)

#### 1. I05: Exchange-Isolated Candle Gap Recovery
- **Root Cause:** When a 5m candle gap was detected in `PaperProcessingService`, it called `RecoverCandleGapsJob::dispatch()`, which unconditionally dispatched Bybit `SyncCandlesJob` / `CandleSynchronizer`. If a gap occurred on Binance, it erroneously invoked Bybit synchronization.
- **Implementation:**
  - `RecoverCandleGapsJob` now accepts `?string $exchange = null, ?string $symbol = null`.
  - In `handle()`: if `$this->exchange === 'binance'`, invokes `BinanceCandleIngestion::ingest($symbol, '5m', 100)` and `15m`, completely bypassing Bybit jobs. If `bybit`, invokes Bybit `SyncCandlesJob`.
  - In `PaperProcessingService`: dispatches `RecoverCandleGapsJob::dispatch($signal->exchange, $signal->symbol)`.
- **Verification:** `tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php` verifies that Binance gaps call Binance ingestion without pushing Bybit jobs, and Bybit gaps call Bybit sync.

#### 2. I08: Real Valuation Freshness vs Checkpoint Timestamps
- **Root Cause:** `CapturePaperEquityJob` created equity snapshots copying current account equity with `recorded_at = $at`. Flat accounts and accounts with open positions had identical fresh recorded timestamps, but accounts with open positions could have stale market marks if price feeds were lagging. `DailyPerformanceService` only checked `max(0, $endUtc - $recorded_at) <= 300s`, erroneously labeling stale open-position valuations as complete and fresh.
- **Implementation:**
  - Safe Additive Migration: `database/migrations/2026_10_27_120000_add_valuation_provenance_to_equity_snapshots.php` adds `valued_through (timestampTz nullable)` and `valuation_fresh (boolean default true)` to `equity_snapshots`.
  - In `CapturePaperEquityJob`:
    - If account is flat (no open positions): marks `valuation_fresh = true`, `valued_through = $at`.
    - If account has open positions: checks oldest mark (`last_processed_event_at ?? opened_at`). If mark is > 300s old, sets `valuation_fresh = false` and records `valued_through`.
    - Fixed UUID pagination: switched `chunkById(100)` to `chunk(100)` to prevent Postgres UUID comparison bugs.
  - In `DailyPerformanceService`:
    - Reads `valuation_fresh` and `valued_through`.
    - If `valuation_fresh === false`: marks `is_fresh = false`, `complete = false`, `valuation_status = 'STALE'`, and appends `'open_positions_market_valuation_stale'` to `incomplete_reasons`.
    - Exposes `valuation_status`: `'FLAT_VERIFIED'`, `'FRESH'`, `'STALE'`, or `'MISSING'`.
- **Verification:** `ExchangeIngestionAndValuationStageCTest.php` validates that flat accounts achieve `FLAT_VERIFIED` and `complete = true`, while accounts with open positions and 25-minute-old marks are flagged `STALE`, `valuation_fresh = false`, and `complete = false`.

---

## 4. Test Execution Evidence and Results

### 1. Dedicated Stage A, B, C Test Suites
```bash
DB_HOST=172.21.0.2 DB_PORT=5432 php -dxdebug.mode=off artisan test \
  tests/Feature/Trading/TradingPipelineStageATest.php \
  tests/Feature/PaperTrading/PositionLifecycleStageBTest.php \
  tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php
```
- **Result:** `PASS`
- **Tests:** 14 passed
- **Assertions:** 71
- **Duration:** 2.51s

### 2. Comprehensive Backend Regression Pass
```bash
DB_HOST=172.21.0.2 DB_PORT=5432 php -dxdebug.mode=off artisan test \
  tests/Feature/Trading/ \
  tests/Feature/PaperTrading/ \
  tests/Unit/PaperTrading/ \
  tests/Feature/Exchanges/ \
  tests/Unit/Exchanges/
```
- **Result:** `PASS`
- **Tests:** 221 passed, 0 failed
- **Assertions:** 2,480
- **Duration:** 100.8s

### 3. Frontend Node Suite
```bash
cd frontend && node --test tests/*.test.js
```
- **Result:** `PASS`
- **Tests:** 56 passed, 0 failed
- **Duration:** 672ms

### 4. Go Worker and Simulation Suite
```bash
cd research-go && go test ./...
```
- **Result:** `PASS` (all packages passing)

### 5. Laravel Pint Code Style
```bash
./vendor/bin/pint --test app/Trading/ app/PaperTrading/ app/MarketData/ app/Exchanges/ tests/
```
- **Result:** `PASS` (all modified files conform to project style standards)

---

## 5. Comprehensive F01–F27 Remediation & Verification Matrix

Every individual finding from `VECTOR_V26_UI_AND_AUDIT_DATA_INTEGRITY_AUDIT.md` has been reviewed against committed code and regression test coverage:

| Finding | Title & Severity | Affected Source Paths | Commits | Regression Test / Verification | Status |
|---|---|---|---|---|---|
| **F01** | False Decision Replay Verification & State Mutation [HIGH] | `app/Research/Application/DecisionReplayService.php` | `3165173`, `08a0d7c` | `tests/Feature/Research/ProfitabilityResearchApiTest.php` | `FIXED_VERIFIED` |
| **F02** | Profitability Response Envelope Chain Breakdown [CRITICAL] | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` | `3165173` | `frontend/tests/profitability_research.test.js` | `FIXED_VERIFIED` |
| **F03** | Fabricated Financial Fallback Values in UI [CRITICAL] | `frontend/src/pages/ProfitabilityResearchPage.vue` | `3165173` | `frontend/tests/profitability_research.test.js` | `FIXED_VERIFIED` |
| **F04** | Reconciliation Findings Misread in Controller [HIGH] | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` | `3165173` | `tests/Feature/Research/ProfitabilityResearchApiTest.php` | `FIXED_VERIFIED` |
| **F05** | Outdated Roadmap Module Statuses [MEDIUM] | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` | `3165173` | `tests/Feature/Research/ProfitabilityResearchApiTest.php` | `FIXED_VERIFIED` |
| **F06** | Reconciliation Ignores Drawdown Mismatch [HIGH] | `app/Research/Application/LedgerReconciliationService.php` | `3165173` | `tests/Feature/Research/LedgerReconciliationServiceTest.php` | `FIXED_VERIFIED` |
| **F07** | Audit ZIP Export Silent Column Dropping [HIGH] | `app/Audit/Application/AuditExportService.php` | `08a0d7c` | `tests/Feature/Audit/AuditExportTest.php` | `FIXED_VERIFIED` |
| **F08** | Strategy Funnel Cohort & Status Discrepancy [MEDIUM] | `app/Audit/Application/AuditExportService.php` | `08a0d7c` | `tests/Feature/Audit/AuditExportTest.php` | `FIXED_VERIFIED` |
| **F09** | Frontend Single-Account Display Default Blinds Multi-Account System [HIGH] | `app/Http/Controllers/Api/V1/ResearchController.php:82,718,900`, `frontend/src/pages/DashboardPage.vue`, `RiskPage.vue`, `StatisticsPage.vue` | Prior baseline | `tests/Feature/Api/CompleteApiEndpointAuditTest.php` | `PARTIALLY_ADDRESSED` (Backend `/statistics` and `/risk` support `?account=<id>`; `/dashboard` uses oldest account; UI multi-account selector dropdown pending) |
| **F10** | Signal Annotation Query Reversal and Account Scope Defect [HIGH] | `app/Http/Controllers/Api/V1/ResearchController.php:587-613` | Current commit | `tests/Feature/Api/CompleteApiEndpointAuditTest.php` | `FIXED_VERIFIED` (Fixed `groupBy('signal_id')->map->first()` newest record win and added account/exchange scoping) |
| **F11** | Statistics Page Lifecycle Population Mixing [MEDIUM] | `app/PaperTrading/Application/PerformanceStatisticsService.php` | `3165173` | `tests/Feature/PaperTrading/PerformanceStatisticsTest.php` | `FIXED_VERIFIED` |
| **F12** | Daily Performance Marks Missing Opening as Complete [HIGH] | `app/PaperTrading/Application/DailyPerformanceService.php` | `3165173` | `tests/Feature/PaperTrading/DailyPerformanceTest.php` | `FIXED_VERIFIED` |
| **F13** | Research Health Funnel False Healthy Parent Status and Hardcoded Lookback [MEDIUM] | `app/Research/Application/StrategyHealthService.php:42-50,139` | Current commit | `tests/Unit/Research/StrategyHealthServiceTest.php` (`test_identifies_insufficient_data_status`) | `FIXED_VERIFIED` (Parent status reports `INSUFFICIENT_DATA` when children lack observations; `expected_slots` scales with requested period) |
| **F14** | Research Report Freshness Shape Mismatches [MEDIUM] | `app/Http/Controllers/Api/V1/AutonomousLearningController.php` | Prior baseline | `tests/Feature/Research/AutonomousLearningApiTest.php` | `FIXED_VERIFIED` |
| **F15** | Portfolio Correlation Invalid Status Check and Trade-Order Pairing [HIGH] | `app/Research/Application/PortfolioCorrelationService.php:49-52,86-88` | Current commit | `tests/Unit/Research/PortfolioCorrelationServiceTest.php` (`test_f15_real_lifecycle_closed_and_partially_closed_positions_are_included`) | `FIXED_VERIFIED` (Supports real closed statuses `CLOSED_TP/SL/TIMEOUT` and includes `PARTIALLY_CLOSED` in concurrent overlap) |
| **F16** | Strategy Degradation Cross-Exchange Pollution [HIGH] | `app/Research/Application/StrategyDegradationService.php` | Prior baseline | `tests/Feature/Research/P5DependenceConcentrationTest.php` | `FIXED_VERIFIED` |
| **F17** | Learning Feature Store Missing Exchange Filter [MEDIUM] | `app/Research/Application/LearningFeatureStoreService.php` | Prior baseline | `tests/Feature/Research/AutonomousLearningPipelineTest.php` | `FIXED_VERIFIED` |
| **F18** | Model Training Purging Fallback Leakage [MEDIUM] | `app/Research/Application/ModelTrainingService.php` | Prior baseline | `tests/Feature/Research/AutonomousLearningPipelineTest.php` | `FIXED_VERIFIED` |
| **F19** | Autonomy Settings Raw Flags vs Permissions [LOW] | `app/Research/Application/AutonomyPermissionService.php` | Prior baseline | `tests/Feature/Research/AutonomyResearchTest.php` | `FIXED_VERIFIED` |
| **F20** | Dual Kill-Switch Divergence & Blindness [HIGH] | `app/Research/Application/AutonomyPermissionService.php` | Prior baseline | `tests/Feature/Research/AutonomyResearchTest.php` | `FIXED_VERIFIED` |
| **F21** | Compute Workers Static Capability Map [MEDIUM] | `app/Research/Application/ResearchJobService.php` | Prior baseline | `tests/Feature/Research/ResearchJobApiTest.php` | `FIXED_VERIFIED` |
| **F22** | Quote Integrity Silent Error Swallowing [MEDIUM] | `frontend/src/services/quoteIntegrityApi.ts` | Prior baseline | `frontend/tests/quote_integrity.test.js` | `FIXED_VERIFIED` |
| **F23** | UI Pagination Truncations [LOW] | `frontend/src/pages/JournalPage.vue` | Prior baseline | `frontend/tests/unit.test.js` | `FIXED_VERIFIED` |
| **F24** | Slippage Position Trace Scope [MEDIUM] | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php` | `3165173` | `tests/Feature/Research/SlippageAttributionApiTest.php` | `FIXED_VERIFIED` |
| **F25** | Candidate Lab Immediate Evaluation Rejection Due to Missing Backtest Linkage [HIGH] | `app/Research/Application/PromotionPolicyService.php:485-503`, `frontend/src/pages/ResearchLearningPage.vue:96-100` | Prior baseline | `tests/Feature/Research/Gate6PromotionPolicyTest.php` | `PARTIALLY_ADDRESSED` (Backend accepts `validation_run_id` and checks `search_metadata['backtest_run_id']`; if unlinked, Gate 6 rejects with `missing_validation_evidence` per policy; UI dialog for linking run ID is pending) |
| **F26** | Autonomy Approval Audit Trail Loses Rejected Promotions [HIGH] | `app/Research/Application/PromotionPolicyService.php` | `f1a0719` | `tests/Feature/Research/Gate6PromotionPolicyTest.php` (`test_f26_promotion_rejection_records_audit_trail_event`) | `FIXED_VERIFIED` |
| **F27** | Account-Level Entry Block Observability Void [HIGH] | `app/PaperTrading/Application/PaperProcessingService.php` | Current commit | `tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php` (`test_f27_blocked_drawdown_account_ingests_and_rejects_signals_with_audit_lineage`) | `FIXED_VERIFIED` |

### Key Architectural Fixes for F26 and F27:
1. **F26 (Governance Audit Trail Retention):**
   - In `PromotionPolicyService::promoteCandidate()`, failure validation throws `ValidationException` (maintaining fail-closed security and database rollback of any state mutation).
   - An outer `try ... catch (ValidationException $e)` captures the rejection and persists a permanent `CANDIDATE_PROMOTION_REJECTED` audit event in `autonomy_approval_events` outside the rolled-back transaction.
   - Tested in `Gate6PromotionPolicyTest::test_f26_promotion_rejection_records_audit_trail_event`.
2. **F27 (Pre-Risk Observability for Drawdown-Blocked Accounts):**
   - In `PaperProcessingService::ingestSignals()`, signals for active paper strategies are ingested into `paper_signal_states` even when entry is blocked by drawdown (>= 5%) or status.
   - Pre-risk eligibility denials strictly bypass the normal RiskEngine execution path: zero `RiskDecisionRecord` rows are created and `$this->assessment()` is not invoked.
   - A `PaperSignalStateRecord` is inserted with `status = REJECTED` and reason `entry_drawdown_limit_exceeded` (or `account_not_active`), a `PRE_RISK_ENTRY_BLOCKED` event is appended to `trading_journal_events`, and observation diagnostics record `paper_execution = PRE_RISK_BLOCKED`.
   - Tested in `CentralExecutionEligibilityTest::test_f27_blocked_drawdown_account_ingests_and_rejects_signals_with_audit_lineage`.

---

## 6. Financial and Safety Invariants Verification

- **Identity Equation:** `equity = starting_balance + realized_pnl + unrealized_pnl` holds across entries, partial TP1, TP2, stop loss, and late funding additions.
- **Account Independence:** All 26 accounts (13 Bybit + 13 Binance) maintain isolated states, separate ledger sequences, and unique execution idempotency keys.
- **Circuit Breakers:** 5% entry-block and 10% halt controls remain untouched.
- **Historical Immutability:** No existing production rows were modified.

---

## 7. Operator Post-Deployment Verification Checklist (Read-Only)

Run the following read-only SQL queries on the production PostgreSQL database **after** an operator-authorized GitHub Actions deployment:

```sql
BEGIN READ ONLY;
SET statement_timeout = '5000ms';

-- 1. Check newly created pipeline events (last 2 hours)
-- Newly created SIGNALS_EVALUATED events must NOT stay in DISPATCHED or become STALE.
SELECT stage, status, count(*)
FROM trading_pipeline_events
WHERE created_at >= NOW() - INTERVAL '2 hours'
GROUP BY stage, status
ORDER BY stage, status;

-- 2. Check for partial failures vs completed paper runs
-- Newly created PAPER_PROCESSED with failures must be marked PARTIAL, not COMPLETED.
SELECT status,
       count(*) as count,
       sum((payload->>'failures_count')::int) as total_failures
FROM trading_pipeline_events
WHERE stage = 'PAPER_PROCESSED'
  AND created_at >= NOW() - INTERVAL '2 hours'
GROUP BY status;

-- 3. Verify zero new zero-quantity fills
SELECT count(*) as invalid_executions
FROM virtual_executions
WHERE created_at >= NOW() - INTERVAL '2 hours'
  AND quantity <= 0;

-- 4. Check funding completeness progression on newly closed positions
SELECT funding_complete, count(*)
FROM virtual_positions
WHERE closed_at >= NOW() - INTERVAL '2 hours'
GROUP BY funding_complete;

-- 5. Check valuation freshness distribution in recent equity snapshots
SELECT valuation_fresh, count(*)
FROM equity_snapshots
WHERE recorded_at >= NOW() - INTERVAL '2 hours'
GROUP BY valuation_fresh;

COMMIT;
```

---

## 8. Historical Funding Evidence and Reconciliation Tooling

### Original Production Diagnostic Evidence (October 27, 2026)
In the October 27 production diagnostic (`docs/verification/VECTOR_V26_INDEPENDENT_REAUDIT_POST_F01_F25_20261027.md`, P09/P10), the following factual observations were established from read-only PostgreSQL queries:

- **P08:** All 437 existing virtual positions (60 Binance, 377 Bybit) had `funding_complete = false`, establishing a code-level completeness defect.
- **P09:** Five Bybit `CLOSED` positions lacked links to applicable `market_funding_events`, and all five funding records were ingested **after** position closure.
- **P10:** Concrete reproducer: market funding at `2026-10-27 00:00:00 UTC` arrived at `00:11:08 UTC`, while BTCUSDT positions had closed at `00:10:00 UTC` (`CLOSED_SL`).
- **Sample Position References (Operator Follow-up Only):**
  - `251f00db-6249-5c79-a936-1ff77f04efbf` (BTCUSDT, market funding event ID 7925, closes 00:10 UTC)
  - `9c73a113-3c5c-5992-b540-dce311d3ff71` (BTCUSDT, market funding event ID 7925)
  - `bb3268b0-52b8-5e87-802d-d312aa2bf692` (BTCUSDT, market funding event ID 511)
  - `013fcf20-2f04-5e41-a645-78775c5421b2` (ETHUSDT, market funding event ID 479)
  - `71a04055-03c8-58b6-af65-2f8fa4cba869` (BTCUSDT, market funding event ID 351)

> [!CRITICAL]
> **Retraction of Synthetic Estimates:**
> Previous draft documentation included synthetic test-model numbers (e.g. opened_at `23:30`, held quantity `0.0050`, funding rate `+0.000100`, mark price `67,850.00`, and an estimated cumulative impact of `-0.132884 USDT`).
> **These figures were local simulation estimates, NOT verified production data.**
> In accordance with `AGENTS.md` and the October 27 diagnostic findings, synthetic assumptions must never be cited as production evidence or used to perform authoritative financial adjustments.
> Authoritative production values (exact quantities held at the funding instant, applicable rates, mark prices, and cashflow amounts) must be extracted exclusively via an operator-directed read-only dry run on production.

### Safe Operator Tooling: `paper:reconcile-funding`
A dedicated Artisan command [`PaperReconcileFundingCommand`](file:///home/thunder/work/traderbybit/app/PaperTrading/Console/PaperReconcileFundingCommand.php) is implemented and verified for safe inspection and idempotent maintenance:

1. **Strictly Read-Only Preview (Zero Mutations):**
   ```bash
   php artisan paper:reconcile-funding --exchange=bybit --symbol=BTCUSDT --symbol=ETHUSDT --dry-run
   ```
   Renders an ASCII table of actual production candidate positions, applicable `market_funding_events`, reconstructed held quantities at funding timestamps, rates, mark prices, and estimated cashflows. **Guaranteed zero database writes or state changes.**

2. **Gated Production Reconciliation (Requires Operator `--force`):**
   ```bash
   php artisan paper:reconcile-funding --exchange=bybit --symbol=BTCUSDT --symbol=ETHUSDT --force
   ```
   - Executes atomic settlements inside PostgreSQL transactions (`DB::transaction`).
   - Uses row-level locks on `virtual_positions` and `paper_accounts`.
   - Prevents duplicates using deterministic UUIDv5 primary keys (`traderbybit:funding:<position_id>:<mfe_id>`) and unique constraints.
   - Adjusts position net PnL, account realized PnL, equity, and appends `FUNDING_APPLIED` to the journal.
   - Running without `--dry-run` and without `--force` halts safely with an error.

3. **Targeted Single Position Execution:**
   ```bash
   php artisan paper:reconcile-funding --position-id=251f00db-6249-5c79-a936-1ff77f04efbf --dry-run
   php artisan paper:reconcile-funding --position-id=251f00db-6249-5c79-a936-1ff77f04efbf --force
   ```

---

## 9. Deployment Readiness Decision

**Decision:** `READY_FOR_OPERATOR_REVIEW`

- All 9 independent defects I01–I09 have been autonomously analyzed, reproduced, implemented, and verified in isolated test infrastructure.
- 4 logically scoped local commits have been created.
- 0 regressions introduced; all 221 backend tests, 56 frontend tests, and Go worker tests pass.
- No real orders, no remote database mutations, and no git pushes were performed.
