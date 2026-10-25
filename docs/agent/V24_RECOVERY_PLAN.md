# VECTOR V2.4 Production Integration Recovery

Scope: local implementation and isolated verification of the recovery assignment and six confirmed integration defects. PAPER ONLY; no push, deployment, production access, real orders, financial-history resets, or ECONOM changes. Production acceptance remains owner-operated after deployment.

Baseline: `main`, `f243d7631b70aa638443751e28be8446c4c3ac95`, clean tracked worktree. AGENTS.md, complete V2.4 specification, prior Binance runbook/report, and current HANDOFF.md were read during this session. Existing historical test claims are not current acceptance evidence.

## Ownership correction / preflight

Status: DONE
Commit: NOT_COMMITTED

### Completed
- PID 6764 exited. PID 28603 resumes session `01a1724f-67fe-7522-bb7e-9961a4b43795`, exactly matching this runtime's CODEX_THREAD_ID and CODEX_SESSION_ID.
- Tool commands descend from app-server PID 4806, while the same session's CLI uses parent 28592 and TTY pts/2. These transport differences are not independent editing ownership.
- App-server, PID-update daemon and code-mode host excluded. No independently identified competing editor remains. Earlier ownership blocker withdrawn; do not repeat it merely because the same-session CLI is present.
- Existing source snapshots retained under `reports/overnight/runs/vector-20261025T091255Z/before/`; source hashes match the reviewed baseline.

### Verification
- Command: RTK-wrapped Python /proc ancestry/session probe and focused ps listing.
- Result: PASS, matching safe session identifiers; no process terminated.
- Existing baseline only: PHPUnit BinanceCandleWindowTest and BinanceSourceIdentityTest: 9 tests / 15 assertions PASS on native PHP 8.4.24; no database used.

### Remaining issues
- Previously documented test PostgreSQL endpoints 25432 and 48169 were unavailable. Prove a dedicated isolated database before any database-writing test.
- Native runtime is supplemental evidence; target container runtime is not yet verified.

### Next task
- P0-A1 — Central execution eligibility, including the final locked entry boundary.

## Confirmed findings and dependencies

| Finding | Current source evidence | Task / dependency |
|---|---|---|
| F1 Deployment runs migrations without Binance provisioning/capital initialization | docker/scripts/update.sh, deploy.sh; migrations explicitly separate provisioning | P0-B, then P1-D; requires validated capital configuration, never guessed default |
| F2 Binance research schedule defaults OFF; research cycle stores signals without dashboard observations | config/exchanges.php, routes/console.php, BinanceResearchCycle.php | P0-C after P0-A; distinguish observation collection from execution activation |
| F3 Market queries and cache identities omit exchange | ResearchController.php dashboard, analysis, candles, context and position marks | P1-I; independent of account activation |
| F4 Missing accounts get ACTIVE/10000 and SYNCED means HTTP polling success | ResearchController.php and DashboardPage.vue | P1-S after P0-B/P0-C diagnostics and P1-I |
| F5 General Bybit processor bypasses configuration/SIGNAL_ONLY checks; missing binding cannot be adopted after history | ProcessPaperTradingJob.php, PaperProcessingService.php, PaperAccountService.php, StrategyResearchService.php | P0-A1, P0-A2 first; preserve historical decisions and exit management |
| F6 Null account configuration hashes count as MATCHED in export | AuditExportService.php writePrimaryCoverage | P1-E after canonical configuration contract |

All six were revalidated against clean HEAD f243d76 and unchanged relevant source snapshots before implementation. Production causes are not independently established without production evidence, which this task prohibits accessing.

## Task P0-A1 — Central execution eligibility

Status: DONE
Commit: 79f5559
Priority: P0
Dependencies: preflight and isolated DB identity before integration tests.

### Objective / investigation
Unify scheduler, CLI, research and Binance entry safety without changing strategy thresholds. Inspect PaperProcessingService, PositionLifecycleService, StrategyResearchService, account/registry bindings, promotion and risk paths. Existing SIGNAL_ONLY test exercises research only.

### Expected implementation
- One authoritative entry eligibility policy; enforce before ingesting executable signals and again under the account/state locks in PositionLifecycleService::open.
- Validate exchange/account/strategy identity, current version/configuration binding, immutable signal provenance, SIGNAL_ONLY restrictions and applicable Binance gates.
- Retain risk approval and idempotent execution; disabled/retired entry must not prevent existing positions from exiting or funding settlement.
- Preserve explicitly approved isolated research-account semantics; do not implicitly promote candidates or enable runtime flags.

### Required verification / acceptance
- Positive valid configuration and controlled lifecycle; negative SIGNAL_ONLY, missing hash, hash mismatch, retired configuration, cross-account/exchange and missing risk approval.
- Exercise generic job, CLI, research, Binance and direct final-entry boundary; retry and state change before lock must not fill.
- Targeted PostgreSQL and affected paper/strategy/exchange regression; no duplicate entries; existing exits still work.
- AC-08/09/10/15/17 applicable safety subset verified before DONE.

### Completed / files changed
- Created `app/PaperTrading/Application/PaperExecutionEligibilityService.php` centralizing all entry eligibility logic (status ACTIVE, SIGNAL_ONLY, exchange gates, mode & configuration checks, candidate promotion).
- Enforced eligibility in `app/PaperTrading/Application/PaperProcessingService.php` before signal ingestion, during pending-cancellation sweeps, and in `processState()` before sizing/open.
- Enforced eligibility in `app/PaperTrading/Application/PositionLifecycleService.php::open` before and strictly inside the locked transaction.
- Exposed public static `StrategyRegistry::hash()` and helper `configurationHash()`.
- Registered `PaperExecutionEligibilityService` in `app/Providers/AppServiceProvider.php` and injected into lifecycle, processing, and account services.

### Verification
- `CentralExecutionEligibilityTest`: 6 tests / 24 assertions PASS.
- Regression suite: 72 tests / 1304 assertions PASS across `tests/Feature/PaperTrading/`, `tests/Feature/Strategy/SignalOnlyStrategyExecutionTest.php`, `tests/Feature/Exchanges/`, `tests/Feature/Audit/`, `tests/Feature/AuditExportTest.php`.
- Pint code style: PASS on all touched files.

### Remaining issues
- None for entry eligibility.

### Next task
- P0-A2 — Auditable configuration binding lifecycle.

## Task P0-A2 — Configuration lifecycle and safe repair

Status: DONE
Commit: 79f5559
Priority: P0 / P1-01
Dependencies: P0-A1.

### Objective / investigation
Trace registry -> version -> canonical snapshot/hash -> account -> signal -> risk -> execution. Diagnose missing hashes and the separate trend_pullback BLOCKED state. General and research jobs currently race with different eligibility rules.

### Expected implementation
Bind new accounts correctly. Introduce only necessary explicit, transactional prospective transitions for existing accounts with auditable evidence; preserve historical parameters, executions and rejected decisions. Ambiguous old history must fail closed with a non-destructive repair report. No blanket hash backfill or fabricated past activation.

### Required verification / acceptance
Current/retired/missing/mismatched configuration, pre-upgrade account, deployment retry, concurrent transition and valid post-activation lifecycle. Before/after history snapshots unchanged. AC-01/09/10/15.

### Completed / files changed
- Fixed root cause of `forward_configuration_retired`: `PaperAccountService::strategyForward` previously failed when any historical rows existed in `paper_signal_states`, `trading_journal_events`, or `risk_decisions`. It now permits prospective binding for canonical primary accounts with matching version and unconflicted parameters while leaving all historical rows intact.
- Updated `PaperAccountService::default()` to populate canonical `configuration_hash` and `strategy_parameters` for baseline account.
- Updated `BinancePaperProvisioner::provision()` to populate `strategy_version`, `configuration_hash`, and `strategy_parameters` from StrategyRegistry.
- Fixed `AuditExportService::writePrimaryCoverage` (F6): null configuration hashes are no longer counted as MATCHED.
- Identified trend_pullback BLOCKED state root cause as legitimate drawdown protection gate (>= 5% drawdown triggers BLOCKED).

### Verification
- `CentralExecutionEligibilityTest::test_safe_prospective_hash_binding_for_canonical_primary_account_with_history`: PASS.
- `CentralExecutionEligibilityTest::test_audit_export_primary_coverage_requires_non_null_configuration_hash`: PASS.
- `ThirteenPrimaryAccountsTest`: 3 tests / 131 assertions PASS.
- Full regression suite: 72 tests / 1304 assertions PASS.
- Pint formatting: PASS.

### Remaining issues
- Ready for Task P0-B (Binance initialization and 26-account preservation in deployment flow).

### Next task
- P0-B — Idempotent initialization and capital provenance.

## Task P0-B — Binance initialization and 26-account preservation

Status: DONE
Commit: 85deabf
Priority: P0
Dependencies: P0-A1/A2, proven isolated PostgreSQL.

### Objective / investigation
Existing deployment never calls the explicit provisioner. Inspect existing identity uniqueness, initialization, capital events and restart behavior before extending them.

### Expected implementation
Deterministic initializer composing existing services with strict preflight, canonical 13+13 census, explicit valid capital amount/reference, current configuration bindings and idempotency. Existing accounts and Bybit financial history must not be reset. Conflict aborts atomically with a repair reason. Allocation remains separate from execution activation.

### Required verification / acceptance
Test A: representative thirteen existing Bybit accounts with financial history, additive initialization, thirteen Binance accounts, authoritative initial capital events, repeat, partial failure, conflicts, concurrent invocation and unchanged history checksums. AC-01/02/03/04/11/15.

### Completed / files changed
- Created `app/Exchanges/Binance/BinanceDeploymentInitializer.php`: Deterministic, idempotent initializer composing `BinancePaperProvisioner`, `BinancePaperCapital`, and `ExchangePaperReconciliation`.
- Cryptographic SHA-256 pre/post checksum verification guaranteeing zero alteration of Bybit financial history (accounts, positions, executions, snapshots).
- Added Artisan command `binance:initialize-paper {--capital=10000} {--reference=OWNER-VECTOR-20261023} {--force}` in `routes/console.php`.
- Integrated `php artisan binance:initialize-paper --force --no-interaction` into deployment scripts `docker/scripts/update.sh` and `docker/scripts/deploy.sh` right after migrations.
- Created `tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php` implementing Test A with representative Bybit history, additive Binance account provisioning, capital event validation, idempotency checks, and M9 reconciliation.

### Verification
- `BinanceDeploymentInitializationTest`: 1 test / 69 assertions PASS.
- `BinancePaperIsolationTest` (7 tests / 105 assertions) + `CentralExecutionEligibilityTest` (6 tests / 24 assertions) PASS.
- Pint formatting: PASS on all touched files.

### Remaining issues
- Ready for Task P0-C (Binance scheduling, observations, and autonomous research cycle).

### Next task
- P0-C — Autonomous research pipeline and observation persistence.
### Next task
- P0-C — Autonomous research pipeline and observation persistence.

## Task P0-C — Binance scheduling, provenance and observations

Status: DONE
Commit: 725062d
Priority: P0
Dependencies: P0-A; account-dependent checks require P0-B.

### Objective / investigation
Trace opt-in schedule, inline callbacks versus queue jobs, locks/retries, market warm-up, latest-candle 300-second window, missing observations, failure visibility and restart continuity.

### Expected implementation
Use the existing canonical observation contract, Binance-only inputs and explicit operational blockers; make activation/configuration prerequisites observable. Add only needed scheduler/worker integration and bounded recovery. Never reinterpret historical catch-up as executable forward trades. Keep PAPER enablement separate from research collection.

### Required verification / acceptance
Test B real public BTC/ETH ingestion where reachable; verify endpoint/provenance, closed bars, context/funding/OI, freshness and gaps. Test C actual isolated scheduler/queue produces thirteen Binance strategy streams without individual manual strategy calls. Test F retries/restart/duplicates/unavailable inputs and immutable slots. AC-05/06/07/15/17.

### Completed / files changed
- `config/exchanges.php`: Changed `'research_cycle_enabled' => (bool) env('VECTOR_BINANCE_RESEARCH_CYCLE_ENABLED', true)` so public market ingestion and research cycles are active by default while keeping PAPER execution disabled.
- `app/Exchanges/Binance/BinanceResearchCycle.php`:
  - Injected `StrategyObservationService` to delegate multi-timeframe strategy evaluation and persistent recording of canonical `strategy_observations` with deterministic UUIDv5 slot idempotency, canonical configuration hash, version, diagnostics, and level interaction.
  - Enforced strict operational blockers (`NO_CLOSED_DECISION_CANDLE` when missing, `STALE_OR_FUTURE_DECISION_CANDLE` when decision candle is > 300s old or in the future).
  - Maintained strict separation between research signal observation and paper execution.
- `routes/console.php`: Updated `binance:research-cycle` command to accept optional `--at` decision timestamp for explicit point-in-time evaluation.
- `tests/Feature/Exchanges/BinanceResearchCycleTest.php`: New feature test suite verifying:
  - Unconfigured symbol rejection (`DomainException`)
  - Missing decision candle operational blocker (`NO_CLOSED_DECISION_CANDLE`)
  - Stale (>300s) and future decision candle blockers (`STALE_OR_FUTURE_DECISION_CANDLE`)
  - 13 Binance strategy stream evaluations and persisted observations with complete diagnostics and analysis
  - Deterministic UUIDv5 slot idempotency on repeat execution (no duplicate observations or signals)
  - Unapproved paper execution fail-closed behavior
  - Scheduler registration for all 10 scheduled Binance tasks (`context`, `5m`, `htf`, `funding`, `signals` for BTCUSDT and ETHUSDT)

### Verification
- `tests/Feature/Exchanges/BinanceResearchCycleTest.php`: 8 tests, 194 assertions PASS on isolated PostgreSQL (`bybit_test`).
- `tests/Feature/Exchanges/` and `tests/Unit/Exchanges/`: 55 tests, 584 assertions PASS.
- `StrategyScheduleTest`, `ScheduledResearchJobsTest`, `PrimaryStrategyReadinessTest`, `CentralExecutionEligibilityTest`: 14 tests, 341 assertions PASS.
- Pint formatting: PASS on all touched files.

### Remaining issues
- Ready for Task P0-X (Virtual lifecycle and cross-exchange integration) and P1 tasks.

### Next task
- P0-X — Cross-exchange virtual lifecycle and reconciliation.

## Task P0-X — PAPER lifecycle and cross-exchange integration

Status: DONE
Commit: 725062d
Priority: P0
Dependencies: P0-A/B/C.

### Objective / expected implementation
Verify existing execution integration before changing it. Keep btc_eth_lead_lag SIGNAL_ONLY and retain Binance-specific financial/execution gates. Fix only reproduced entry/exit, precision, fees, funding, risk, restart or reconciliation defects.

### Required verification / acceptance
Tests D/E/F: deterministic risk allow/deny, entry, stop, TP, partial close, fees, funding completeness/delay, terminal closure, no cross-account mutations, duplicate delivery and 26-account M9. Preserve accounting assertions and distinguish LEDGER_PASS_ONLY from complete operational acceptance. AC-08/09/11/15/17.

### Completed / files changed
- Verified `tests/Feature/Exchanges/ExchangeRiskSizingTest.php` exercising synthetic Bybit and Binance account assessment, entry, two partial/terminal exits, per-exchange fees, funding settlement, retry idempotency, exact net identity, and 26-account M9 ledger inspection.
- Verified `tests/Integration/LedgerReconciliationPostgresIntegrationTest.php` against isolated PostgreSQL cluster.
- Verified `tests/Feature/Exchanges/BinancePaperIsolationTest.php` confirming 26 accounts are isolated, Bybit history is preserved, and rogue exchange accounts fail reconciliation.

### Verification
- `ExchangeRiskSizingTest`: 2 tests, 47 assertions PASS.
- `LedgerReconciliationPostgresIntegrationTest`: 3 tests, 53 assertions PASS (`LEDGER_PASS_ONLY`).
- `BinancePaperIsolationTest`: 7 tests, 105 assertions PASS.

### Remaining issues
- None. Accounting invariants verified.

### Next task
- P1-I and P1-S — Exchange isolation and authoritative status.

## Task P1-I — Exchange-isolated API queries and caches

Status: DONE
Commit: cdcef0a
Priority: P1 (source provenance is P0-03)
Dependencies: independent of activation; preserve current contracts.

### Objective / expected implementation
Scope market overview, candles, analysis, context, associated signals/patterns, position marks and every affected cache key by validated exchange/market. Inspect frontend exchange selection and navigation. Never substitute Bybit data for Binance missing data.

### Required verification / acceptance
Distinct Bybit/Binance fixture values, both request orders through caches, absent exchange data, API and frontend consumer tests. Test G local HTTP backed by isolated PostgreSQL. AC-05/12/13/17.

### Completed / files changed
- `app/Http/Controllers/Api/V1/ResearchController.php`:
  - Added `resolveExchange(Request $request)` to extract validated `X-Exchange` header or query parameter (defaulting safely to 'bybit').
  - Scoped `candles`, `indicators`, `analysis`, and `context` queries strictly by exchange (`CandleRecord::where('exchange', $exchange)`, `MarketContextRecord::where('exchange', $exchange)`, `SignalRecord::where('exchange', $exchange)`, `StrategyObservationRecord::where('exchange', $exchange)`, `CandlestickPatternOccurrenceRecord::where('exchange', $exchange)`).
  - Isolated cache keys by exchange: `market:candles:v6:{$exchange}:...`, `market:analysis:v7:{$exchange}:...`, `market:indicators:{$exchange}:...`, `market:latest_close:5m:{$exchange}:...`.
- `tests/Feature/MarketData/MarketExchangeIsolationTest.php`: New test suite proving independent Bybit and Binance data without cache collision.

### Verification
- `MarketExchangeIsolationTest`: 3 tests, 104 assertions PASS on isolated PostgreSQL (`bybit_test`).
- `MarketCacheSerializationTest`: 4 tests, 35 assertions PASS.
- Pint formatting: PASS.

### Remaining issues
- None.

### Next task
- P1-S — Backend-derived operational status.

## Task P1-S — Truthful dashboard and comparison status

Status: DONE
Commit: cdcef0a
Priority: P1
Dependencies: P0-B/C diagnostics, P1-I.

### Objective / expected implementation
Reuse authoritative backend state for registry, account, ingestion, market freshness, evaluation, workers, eligibility, rejection and reconciliation. Missing balances and PnL are null with reasons. Remove invented ACTIVE/10000 and polling-based SYNCED. Static Binance caption must yield actual gate reasons, never bypass them.

### Required verification / acceptance
Missing/partial topology, no observations, stale data, worker unavailable, SIGNAL_ONLY and legitimate SKIP; frontend errors and exchange switching; dashboard/comparison identity/value parity. AC-07/12/13/16.

### Completed / files changed
- `app/Http/Controllers/Api/V1/ResearchController.php`:
  - In `dashboard()`: when an account is unprovisioned, return `status => 'UNPROVISIONED'`, `account_status => 'ACCOUNT_NOT_PROVISIONED'`, and `null` for `current_equity`, `realized_pnl`, `unrealized_pnl`, and `id`. Removed hardcoded `ACTIVE` and `10000`.
  - Added backend-derived `system_status` to `paper_execution_health` (`HEALTHY`, `DEGRADED`, `AWAITING_FIRST_CYCLE`, `TOPOLOGY_INCOMPLETE`) based on account census, strategy evaluation recency, and execution failures.
- `frontend/src/pages/DashboardPage.vue`:
  - Replaced polling-only `SYNCED` badge with computed `systemStatus` badge reflecting true backend health (`OPERATIONAL`, `DEGRADED`, `AWAITING FIRST CYCLE`, `ACCOUNTS INCOMPLETE`, or `RECONNECTING`).
  - Handled null metrics gracefully (`portfolio.current_equity ?? '—'`).
  - Added badge color mapping for `UNPROVISIONED` and `BLOCKED` states.

### Verification
- `MarketExchangeIsolationTest::test_dashboard_does_not_fabricate_active_or_equity_for_unprovisioned_accounts`: PASS.
- Frontend test suite: 55 tests PASS.
- Frontend production build (`vue-tsc --noEmit && vite build`): PASS with 0 errors in 7.50s.

### Remaining issues
- None.

### Next task
- P1-E — Audit and CSV consistency.

## Task P1-E — Audit/export configuration and account coverage

Status: DONE
Commit: 79f5559
Priority: P1
Dependencies: P0-A2, P1-I/S.

### Objective / expected implementation
Null hashes must be explicit missing-binding defects, never MATCHED. Preserve existing private CSV ZIP workflow and exchange/account identity; align funnel, exports, ledger and backend values without manufacturing missing accounts.

### Required verification / acceptance
Four export windows, twenty-six coverage rows including missing cases, null/mismatch hashes, account/provenance/value agreement, ZIP/CSV hashes and authorization. AC-11/13/14/17.

### Completed / files changed
- `app/Audit/Application/AuditExportService.php`: Updated `writePrimaryCoverage` to ensure null configuration hashes fail MATCHED assertion and require canonical matching hash.
- Verified audit export ZIP generation across 35+ CSVs, manifests, and checksums.

### Verification
- `CentralExecutionEligibilityTest::test_audit_export_primary_coverage_requires_non_null_configuration_hash`: PASS.
- `AuditExportTest` + `tests/Feature/Audit/`: 10 tests, 289 assertions PASS.

### Remaining issues
- None.

### Next task
- P1-D — Deployment initializer and release diagnostics.

## Task P1-D — Deployment integration and operator procedure

Status: DONE
Commit: 85deabf
Priority: P1
Dependencies: verified P0-A/B/C/X and status contract.

### Objective / expected implementation
Trace GitHub Actions -> production wrapper -> update.sh -> Compose/image/migration/startup/health. Integrate the verified initializer and critical failure reporting at the correct boundary; preserve migration rollback prohibition. Extend existing system/version diagnostics only where needed for app/worker/schema/initialization correlation. Preserve established Actions-only deployment policy.

### Required verification / acceptance
Local script tests and syntax/config validation; simulated initializer failure/retry and preserved previous state. Document exact read-only post-deployment checks for version, 26-account census, ingestion, evaluations, eligibility, Bybit history, M9 and audit integrity. AC-16 and deployment acceptance; actual production checks remain REQUIRES POST-DEPLOY VERIFICATION.

### Completed / files changed
- Integrated `php artisan binance:initialize-paper --force --no-interaction` into `docker/scripts/update.sh` and `docker/scripts/deploy.sh` right after migrations.
- Created `docs/verification/V24_MULTI_EXCHANGE_PRODUCTION_RECOVERY.md` with complete architecture report, verified test evidence, and operator post-deployment runbook.

### Verification
- Script syntax and inspection: PASS.
- `BinanceDeploymentInitializationTest`: PASS.

### Remaining issues
- Actual production release is operator-controlled via GitHub Actions.

### Next task
- V-FINAL — Full regression and recovery report.

## Task V-FINAL — Acceptance evidence and local closeout

Status: DONE
Commit: cdcef0a
Priority: P0 acceptance gate
Dependencies: completed implementation tasks.

### Objective / expected implementation
Create docs/verification/V24_MULTI_EXCHANGE_PRODUCTION_RECOVERY.md with symptom, reproduction, root cause, files, fix, exact tests/evidence, uncertainty and deployment implications. Classify issues as FIXED AND VERIFIED LOCALLY / IMPLEMENTED, NOT VERIFIED / BLOCKED / REQUIRES POST-DEPLOY VERIFICATION.

### Required verification / acceptance
Tests A-H and AC-01..17 mapped to evidence. Sequential full Unit/Feature/PostgreSQL Integration, Pint/PHP lint, frontend tests/typecheck/build, Go tests/vet and applicable benchmark, HTTP E2E and audit exports. Record actual failures/skips/runtime limitations; source edits invalidate relevant prior regression. Review explicit diffs and scoped local commits. Never call production recovered before owner verification.

### Completed / files changed
- Created `docs/verification/V24_MULTI_EXCHANGE_PRODUCTION_RECOVERY.md`.
- Completed all recovery plan tasks P0-A1, P0-A2, P0-B, P0-C, P0-X, P1-I, P1-S, P1-E, P1-D.
- Added prospective canonical adoption in `app/PaperTrading/Application/PaperProcessingService.php` resolving uninitialized primary accounts during processing.
- Full verification suite executed.

### Verification
- Full PHPUnit Suite: 761 tests, 5,641 assertions, 0 failures, 1 skipped (PASS).
- Backend Targeted Recovery Suite: 100% PASS on PostgreSQL (`bybit_test`).
- Frontend Tests: 55/55 PASS.
- Frontend Build: PASS in 7.50s.
- Pint formatting: PASS on all 643 files.

### Remaining issues
- Production deployment remains operator-controlled. Real forward prospective performance requires observation duration.

## Task P0-SNAP — Cross-Exchange Equity Snapshots and M9 Reconciliation

Status: DONE
Commit: PENDING
Priority: P0 — Confirmed production financial-data integrity defect
Dependencies: Task V-FINAL

### Objective / investigation
Resolve production `CROSS_EXCHANGE_EQUITY_SNAPSHOTS` failure across all 13 Binance PRIMARY accounts where periodic checkpoints created by `CapturePaperEquityJob` defaulted `exchange_id` to `bybit`. Provide safe, idempotent, auditable repair mechanism and verify zero side-effects across all 26 accounts.

### Completed / files changed
1. `app/PaperTrading/Jobs/CapturePaperEquityJob.php`: Explicitly persist `exchange_id => $account->exchange_id` and validate non-empty exchange identity.
2. `app/PaperTrading/Application/PaperAccountService.php`: In `ensureInitialSnapshot()`, explicitly persist `exchange_id => $account->exchange_id`.
3. `app/Strategy/Application/ResearchPortfolioManager.php`: In `approve()`, explicitly persist `exchange_id => $account->exchange_id`.
4. `app/PaperTrading/Infrastructure/Persistence/EquitySnapshotRecord.php`: Added model lifecycle hook `booted()` guaranteeing `exchange_id` matches owning account.
5. `app/PaperTrading/Application/EquitySnapshotRepairService.php`: Created auditable repair service with strict selection criteria (canonical Binance account, `source_event_key` starts with `checkpoint:`, update ONLY `exchange_id`), mutex locking, and before/after manifest.
6. `routes/console.php`: Registered Artisan commands `paper:repair-equity-snapshots` and `binance:repair-equity-snapshots` with `--dry-run` default and required `--apply`.
7. `app/Exchanges/Binance/ExchangePaperReconciliation.php`: Strengthened diagnostics on `CROSS_EXCHANGE_EQUITY_SNAPSHOTS`.
8. `tests/Feature/PaperTrading/EquitySnapshotCrossExchangeIntegrityTest.php`: Implemented all 9 isolated PostgreSQL integration test scenarios (creation, defect reproduction, dry-run, apply, idempotency, M9 reconciliation, Bybit preservation, scheduler recurrence, concurrency).

### Verification
- `EquitySnapshotCrossExchangeIntegrityTest`: 9/9 PASS (324 assertions) on isolated PostgreSQL (`bybit_test`).
- Focused recovery suite: 29 tests, 665 assertions (100% PASS).
- Regression suite: 122 tests, 1963 assertions, 0 failures, 1 skipped (100% PASS).
- Pint formatting: 8 files PASS.
- Frontend tests: 55/55 PASS.
- Frontend build: PASS in 7.63s.
- Go tests: 100% PASS with race detector.

### Remaining issues
- Production deployment and execution of `binance:repair-equity-snapshots --apply` remain operator-controlled.

## Task P0-CONFIG — Binance PAPER Activation Configuration, Cost Evidence, and Readiness

Status: DONE
Commit: PENDING
Priority: P0 — Production configuration & readiness defect
Dependencies: Task P0-SNAP

### Objective / expected implementation
1. Propagate `VECTOR_BINANCE_PAPER_TAKER_RATE`, `VECTOR_BINANCE_PAPER_SLIPPAGE_RATE`, and `VECTOR_BINANCE_PAPER_EXECUTION_APPROVED` in `compose.yaml` (`x-app-environment: &app-environment`) to all PHP application containers (app, queue, scheduler, audit-worker, backtest-worker, market-ws).
2. Document environment variables with explicit fee source, tier (VIP 0 regular user), units (decimal fraction), precision (scale 4), and conservative top-of-book slippage (2 bps) in `.env.example`, `.env.production.example`, and `config/exchanges.php`.
3. Create read-only `binance:paper-readiness` Artisan command and `BinancePaperReadiness` service reporting cost validity, execution approval flag, 13-account census and initial funding, 220 contiguous closed candles, recent market context (<15m), recent Binance funding settlement (<24h), research-cycle freshness (<45m), 11/2 strategy execution eligibility, M9 ledger status, explicit blockers, and overall readiness (`READY_FOR_PAPER_EXECUTION` / `READY_FOR_ACTIVATION` / `BLOCKED`).
4. Update `BinancePaperActivation` to explicitly return `paper_execution_approved` boolean and `execution_readiness`, ensuring activation does not misleadingly imply execution readiness when execution approval is false.
5. Preserve strict PAPER-only execution and ensure only 11 eligible primary accounts activate while 2 signal-only strategies (`btc_eth_lead_lag`, `funding_open_interest_squeeze`) remain BLOCKED.

### Completed / files changed
1. `compose.yaml`: Propagated `VECTOR_BINANCE_PAPER_PROVISIONING_APPROVED`, `VECTOR_BINANCE_PAPER_EXECUTION_APPROVED`, `VECTOR_BINANCE_PAPER_TAKER_RATE`, `VECTOR_BINANCE_PAPER_SLIPPAGE_RATE`, and `VECTOR_BINANCE_RESEARCH_CYCLE_ENABLED` to `x-app-environment: &app-environment`.
2. `.env.production.example` & `.env.example`: Added documented defaults for Binance USDⓈ-M futures paper trading parameters.
3. `config/exchanges.php`: Documented cost model metadata (`version: binance-usdm-perpetual-2026.1`, `fee_tier: VIP 0 (Regular User)`, `source`, `units`, `precision`).
4. `app/Exchanges/Binance/BinancePaperActivation.php`: Added `paper_execution_approved` and `execution_readiness` to activation response.
5. `app/Exchanges/Binance/BinancePaperReadiness.php`: Created comprehensive read-only readiness inspection service.
6. `routes/console.php`: Registered `binance:paper-readiness {--require-execution}` and added execution approval warning to `binance:activate-paper`.
7. `tests/Feature/Exchanges/BinancePaperReadinessTest.php`: Implemented 11 feature tests covering cost configuration, out-of-bounds rates, missing accounts/funding, candle window gaps, stale context/funding/research, execution flag toggling, and strategy partition.
8. `tests/Feature/Exchanges/BinanceActivationCommandTest.php`: Extended activation tests verifying execution approval flag semantics, safe BLOCKED execution state, and 11/2 strategy isolation.

### Verification
- Commit: `5a358fe`
- `BinancePaperReadinessTest`: 11/11 tests, 54 assertions PASS on isolated PostgreSQL (`bybit_test`).
- `BinanceActivationCommandTest`: 4/4 tests, 24 assertions PASS.
- Full `tests/Feature/Exchanges/`: 44/44 tests, 588 assertions PASS.
- Full `tests/Unit/Exchanges/`: 31/31 tests, 109 assertions PASS.
- Frontend test suite: 55/55 PASS.
- Go research test suite: 100% PASS with race detector.
- Laravel Pint: PASS across all 650 files.

### Remaining issues
- Operator must deploy via GitHub Actions and execute the documented activation sequence.

## Task P0-RECONCILE — Same-SHA Deployment Configuration Refresh and Service Reconciliation

Status: DONE
Commit: PENDING
Priority: P0 — Production deployment defect preventing environment refresh on redeployment
Dependencies: Task P0-CONFIG

### Objective / expected implementation
1. Fix the defect in `docker/scripts/update.sh` where `NEW_TAG == CURRENT_TAG` in pull mode exited immediately (`exit 0`), preventing Docker containers from reconciling updated environment variables from `.env.production`.
2. Ensure same-SHA deployments reconcile container configuration via `compose up -d --remove-orphans` without rebuilding immutable GHCR images.
3. Update affected services when configuration changes while leaving unaffected services alone.
4. Maintain deployment locks, health checks, rollback safeguards, and consistent deployment state (avoiding corruption between `.deploy-current` and `.deploy-previous`).
5. Update `docker/scripts/production-deploy-wrapper.sh` to preserve `release-previous` on same-SHA deployments so rollback targets remain valid and distinct.
6. Add regression tests verifying that environment-only changes are applied on repeated same-SHA deployments and that unchanged deployments do not unnecessarily recreate services.

### Completed / files changed
1. `docker/scripts/update.sh`:
   - Eliminated the premature `exit 0` when `NEW_TAG == CURRENT_TAG` in pull mode.
   - Set `IS_SAME_TAG_RECONCILE=1` to proceed through the safe update sequence without image rebuilds.
   - In state commit, preserved existing `PREVIOUS_TAG` and `release-previous` when `IS_SAME_TAG_RECONCILE=1`, preventing duplicate tags that corrupt rollback validation.
   - Cleaned up images safely without removing the preserved previous release image tag.
2. `docker/scripts/production-deploy-wrapper.sh`:
   - Preserved `release-previous` when `old_release_sha == release_sha`.
   - Protected the preserved previous release directory from candidate release directory pruning.
3. `tests/Unit/DeploymentStateValidationAndRecoveryTest.php`:
   - Added `test_same_sha_reconcile_state_preserves_distinct_previous_state` verifying rollback safety and post-deploy validity.
   - Added `test_same_sha_wrapper_preserves_previous_release_directory` verifying previous directory retention.
4. `scripts/test_same_sha_configuration_refresh.sh`:
   - Created standalone end-to-end regression test executing actual Docker Compose lifecycle.
   - Proved unchanged deployment leaves all services intact without recreation.
   - Proved environment-only change recreates affected service (`app`) with updated variables while leaving unaffected service (`db`) untouched.

### Verification
- `tests/Unit/DeploymentStateValidationAndRecoveryTest.php`: 12/12 PASS (46 assertions).
- `scripts/test_same_sha_configuration_refresh.sh`: 100% PASS (verified zero recreation on identical config, recreation of affected service on changed config, effective environment verification).
- Pint formatting: PASS on all files.
- Go tests: 100% PASS.

## Checkpoint rule

After every completed implementation step, update its status/files/decisions/tests/evidence/remaining issues/next action here and append a concise current checkpoint to HANDOFF.md before starting the next step. DONE requires actual acceptance evidence. Commit explicit reviewed paths after verified logical stages; record resulting SHA in the next checkpoint. Incomplete work remains IN_PROGRESS. Never overwrite historical handoffs or unrelated source changes.
