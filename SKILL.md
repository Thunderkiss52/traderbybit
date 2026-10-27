---
name: vector-engineering-standards
description: Authoritative engineering standards, domain invariants, architecture rules, and implementation practices for VECTOR (TraderBybit).
---

# VECTOR Engineering Standards & Implementation Manual

This document establishes the mandatory engineering standards, domain invariants, coding conventions, testing protocols, and operational safety boundaries for the **VECTOR** platform.

Every engineer and AI coding agent working on this repository **MUST** read, understand, and strictly follow these standards prior to introducing or modifying application code, database migrations, queues, API contracts, tests, or quantitative research logic.

---

## 1. System Architecture Overview

VECTOR is a quantitative research and virtual paper-trading system operating strictly in **PAPER** mode with zero live exchange order execution capabilities.

- **Exchanges:** Bybit (linear USDT-perpetual) and Binance (linear USDT-perpetual).
- **Core Strategy Portfolio:** 13 canonical `PRIMARY` strategies:
  1. `baseline`
  2. `trend_pullback`
  3. `bollinger_mean_reversion`
  4. `donchian_atr_breakout`
  5. `regime_switching`
  6. `momentum`
  7. `support_resistance_bounce`
  8. `support_resistance_breakout`
  9. `funding_open_interest_squeeze`
  10. `liquidity_sweep_reversal`
  11. `volatility_compression_breakout`
  12. `btc_eth_lead_lag`
  13. `multi_timeframe_trend_following`
- **Account Model:** Exactly **26 independent PAPER accounts** (13 Bybit + 13 Binance). Each account possesses isolated capital, dedicated ledger journals, strict drawdown tracking, and independent position lifecycle management.
- **Authoritative Ledger & Risk:** PHP 8.3+ / Laravel (event-driven trading pipeline, quantitative indicators, risk evaluation, and double-entry financial accounting).
- **Frontend Dashboard:** Vue 3, Quasar 2, Vite, TypeScript.
- **Persistence & Message Broker:** PostgreSQL 17 (or 16) with strict `NUMERIC(24, 12)` precision and Redis 7.4.
- **Research Engine:** Go (`research-go`) for deterministic parameter optimization and backtesting simulations. Real-time paper ledger authority remains strictly in Laravel.
- **Deployment:** Operator-triggered GitHub Actions (`.github/workflows/deploy.yml`) deploying Docker Compose to VPS. No manual VPS deployments.

---

## Section A: Senior Engineering Principles

1. **Identify Root Causes Before Modifying Code:** Never apply superficial band-aids or suppress warnings to make tests pass. Identify the exact failure mechanism, inspect runtime evidence, trace callers, and verify historical state before altering logic.
2. **Understand Domain Invariants:** Preserve core financial and risk rules: `equity = starting_balance + realized_pnl + unrealized_pnl`, closed-candle determinism, and account-isolated ledger entries.
3. **Small, Cohesive Changes:** Keep changesets minimal, reviewable, and focused. Avoid mixing refactorings with bug fixes.
4. **Maintainable Over Clever:** Favor explicit, readable code over magic abstractions, dynamic dispatch, or excessive metaprogramming.
5. **Avoid Speculative Abstractions:** Build for the current concrete requirements. Do not add generic frameworks or unused interfaces until multiple distinct implementations demand them.
6. **Zero Logic Duplication:** Financial formulas (fees, slippage, funding, PnL, R-multiples) and risk thresholds must have single authoritative implementations.
7. **No Fabricated Outcomes:** Never suppress exceptions, swallow errors, or mark pipeline stages as `COMPLETED` when underlying jobs or accounts fail. Surface actionable failures with structured error contexts.
8. **Measure Before Optimizing:** Do not perform premature micro-optimizations. When performance bottlenecks emerge, profile database queries (N+1 queries, indexing) and queue job contention with measurable benchmarks.
9. **Verify Against Acceptance Criteria:** A change is complete only when verified by passing targeted tests, regression tests, and observable contract adherence.

---

## Section B: PHP 8.x and Laravel Engineering Standards

1. **Strict Types & Declarations:**
   - Every PHP file must begin with `declare(strict_types=1);`.
   - Use explicit scalar and return types across all method signatures. Disallow untyped arrays where typed DTOs, value objects, or typed collections are applicable.
2. **Dependency Injection & Inversion of Control:**
   - Inject dependencies through constructors rather than using global helpers, `App::make()`, or facades inside domain classes.
   - Separate infrastructure adapters (e.g., Bybit/Binance API clients) behind explicit interfaces (`ExchangeClientInterface`, `MarketDataFeedInterface`).
3. **Thin Controllers & Explicit Boundaries:**
   - Controllers must only handle HTTP parameter extraction, Form Request validation, dispatching application service calls, and returning typed API resources or JSON responses.
   - Controllers must contain zero financial, risk, or trading business logic.
4. **Application Services & Domain Services:**
   - Coordinate cross-cutting concerns (database transactions, event publishing, audit logs) inside Application Services.
   - Place pure business rules (position sizing, fee calculation, signal scoring) inside Domain Services or Value Objects.
5. **Database Transactions & Concurrency Control:**
   - Wrap state-mutating multi-entity operations in `DB::transaction(callback, attempts: 3)`.
   - Acquire pessimistic locks (`lockForUpdate()`) when updating balances, positions, executions, or pipeline state records to prevent race conditions across parallel queue workers.
6. **Queues, Retries, and Timeouts:**
   - Queue jobs must define explicit `$timeout`, `$tries`, and `$backoff` properties.
   - Background jobs must be idempotent: executing the same job multiple times must never duplicate balance adjustments, fills, or event logs.
   - Handle SIGTERM gracefully in queue workers; never leave active database transactions hanging.
7. **Query Performance & N+1 Prevention:**
   - Always eager-load relationships (`with(['executions', 'account', 'fundingEvents'])`) when processing collections.
   - Avoid querying inside loops; batch database reads and writes. Use `chunk()` or cursor-based pagination for large data volumes.
8. **Logging & Observability:**
   - Log via structured contextual channels: `Log::info('Paper order executed', ['account_id' => $id, 'symbol' => $symbol, 'qty' => $qty, 'price' => $price])`.
   - Never log API credentials, authorization headers, or sensitive secrets.
9. **Code Style & Tooling:**
   - Maintain PSR-12 and Laravel Pint formatting. Run `./vendor/bin/pint --test` before every commit.
   - Use PHPUnit with strict assertions. Mock only external boundary dependencies (network, clock); use the isolated test database for repository and transactional integration tests.

---

## Section C: PostgreSQL and Financial Accounting

1. **Arbitrary Precision for Financial Computations:**
   - Never use native PHP floating-point arithmetic (`float`) for monetary amounts, prices, fees, funding, or quantities.
   - Always use `brick/math` (`BigDecimal`) or bcmath for financial arithmetic.
   - Database schema must store monetary and quantity fields as `NUMERIC(24, 12)` or `NUMERIC(18, 8)`.
2. **Account and Exchange Scoping:**
   - Every financial record (`virtual_positions`, `virtual_executions`, `virtual_account_journal_entries`, `equity_snapshots`) must explicitly reference `account_id` and `exchange`.
   - Global queries across accounts without explicit scoping are strictly forbidden.
3. **Idempotency Keys & Uniqueness Constraints:**
   - Prevent duplicate trade fills, funding credits, or ledger entries via unique database constraints (e.g., `unique(['account_id', 'signal_id', 'execution_type'])` or `unique(['position_id', 'market_funding_event_id'])`).
   - Every financial state change must check idempotency prior to executing ledger mutations.
4. **Authoritative Double-Entry & Financial Identities:**
   - `Equity = Initial_Balance + Realized_PnL + Unrealized_PnL`.
   - Unrealized PnL is computed strictly from verified mark prices. If mark price is unavailable or stale (> 300s), unrealized PnL must be flagged as unverified/stale.
   - Realized PnL must reconcile with closed position records and execution fee deductions: `Net_PnL = Gross_PnL - Total_Fees + Total_Funding`.
5. **No Historical Mutation:**
   - Never alter historical ledger entries, execution records, or closed positions merely to force an audit balance to reconcile.
   - Adjustments must be recorded as distinct compensating journal entries with documented audit provenance.
6. **Safe Migrations:**
   - Migrations must be purely additive or safely staged. Adding columns must use nullable types or sensible defaults.
   - Do not drop columns or rename existing tables without documented deprecation cycles.
   - Never rollback production migrations automatically.

---

## Section D: PAPER Trading and Risk Safety

1. **Strict PAPER Boundary:**
   - Trading is 100% simulated. API keys are strictly read-only for public market data. No trade endpoints or live routing may exist in the codebase.
2. **Account Independence:**
   - All 26 accounts (13 Bybit + 13 Binance) are strictly isolated. A failure, halt, or drawdown event on one account must never disrupt, block, or corrupt another account.
3. **Closed-Candle Execution Invariant:**
   - Trading signals and paper executions are evaluated **strictly on closed candles**.
   - No signals or entry decisions may be calculated on open, forming, or incomplete candles.
4. **Signal Provenance & Strategy Versioning:**
   - Every generated signal must capture its strategy identifier, strategy configuration version, indicator snapshot, timeframe, entry boundaries, stop loss, and take profit targets.
   - Signals with missing indicator data or unverified exchange context must be emitted as `SKIP` with documented reasons.
5. **Strict Risk Circuit Breakers:**
   - **5% Drawdown Entry Block:** When an account reaches 5% drawdown from peak equity, new position entries are blocked immediately.
   - **10% Drawdown Halt:** When an account reaches 10% drawdown, all trading on that account is halted.
   - **Servicing Existing Positions:** When new entries are blocked due to drawdown or cooldown, active open positions **MUST** continue to be fully serviced (SL, TP1, TP2, timeouts, and funding).
   - **Losing Streak Cooldown:** 3 consecutive losses impose a 60-minute entry cooldown for that specific account.
   - Risk gates must never be bypassed or loosened to artificially inflate trade counts.
6. **Execution Modeling (Slippage, Fees, Lot Sizes):**
   - Apply realistic taker/maker fee schedules (`taker_rate = 0.00055`, `maker_rate = 0.00020`).
   - Calculate price slippage per contract according to `config/paper.php`.
   - Respect instrument lot-size steps and minimum order quantities. If a partial exit (TP1 at 50%) calculates to less than the minimum lot step (`rawQty <= 0`), the partial exit must be safely skipped without creating invalid zero-quantity fills.

---

## Section E: Vue 3, Quasar and TypeScript Frontend

1. **Typed API Contracts:**
   - Define TypeScript interfaces matching backend DTOs and API responses. Disallow `any` where structured schemas exist.
2. **Explicit Operational States:**
   - Operational components must explicitly render `loading`, `empty`, `error`, `stale`, and `ready` states.
   - Never display placeholder zeros for missing financial metrics; display `N/A`, `Pending`, or `Unverified`.
3. **Exchange and Account Filtering:**
   - Account selectors must clearly separate Bybit and Binance accounts and allow deterministic filtering by strategy and symbol.
4. **Race-Condition Protection:**
   - Debounce search and filter inputs. Cancel pending in-flight requests (via `AbortController`) when switching accounts, dates, or symbols.
5. **Timezone & Monetary Formatting:**
   - Display timestamps in UTC by default with explicit format indicators (`YYYY-MM-DD HH:mm:ss UTC`).
   - Format currency with explicit decimal precision (e.g., 2 decimals for USDT equity, 4-8 decimals for crypto quantities).
6. **Data Provenance & Audit Transparency:**
   - Surface valuation freshness indicators (`FRESH`, `STALE`, `FLAT_VERIFIED`) on account equity cards.
   - Prominently display the system disclaimer: **RESEARCH / PAPER MODE ONLY — NOT A FINANCIAL ADVICE / REAL EXCHANGE ORDERING DISABLED**.

---

## Section F: Go Research Engineering

1. **Role of Go:**
   - Go (`research-go`) is dedicated to high-performance batch simulations, genetic algorithms, brute-force parameter sweeps, and quantitative modeling.
   - Live paper execution, queue orchestration, and authoritative ledgers remain strictly in Laravel. Do not re-implement core Laravel accounting in Go without approved architectural justification.
2. **Determinism & Numerical Precision:**
   - Research simulations must be 100% deterministic given identical inputs and random seeds.
   - Handle IEEE 754 float precision carefully; use fixed-point or bounded comparisons for price ticks and fee rounding.
3. **Concurrency & Resource Limits:**
   - Use goroutines with bounded worker pools; never spawn unbounded goroutines per candle or symbol.
   - Pass `context.Context` through all computational loops to allow clean cancellation and timeouts.
4. **Go Tooling & Standards:**
   - Format with `gofmt -s`.
   - Pass `go vet ./...` and `go test -race ./...` before committing changes to `research-go`.

---

## Section G: Quantitative Research & Look-Ahead Prevention

1. **Zero Look-Ahead Bias:**
   - Analysis at time $T$ must consume strictly data available at or before $T$.
   - Candle indicators, support/resistance levels, and order book snapshots must never reference future timestamps or the closing price of the current unclosed candle.
2. **Train / Validation / Test Splitting:**
   - Parameter optimization must strictly divide historical datasets chronologically: Train (in-sample), Validation (hyperparameter tuning), and Test (out-of-sample).
   - Use purging and embargo windows around train/test splits to eliminate serial correlation leakage.
3. **Mature Outcome Labeling:**
   - Do not label training samples or evaluate signal performance on incomplete trades or unexpired signal horizons.
4. **Honest Insufficient-Data States:**
   - When indicator lookback requirements (e.g., 200 EMA) are unsatisfied, output `INSUFFICIENT_HISTORY` or `SKIP`, not a default or interpolated value.

---

## Section H: Event-Driven Pipeline Reliability

1. **Explicit Lifecycle Ownership:**
   - Pipeline stages (`STAGE_CANDLE_CLOSED` -> `STAGE_SIGNALS_EVALUATED` -> `STAGE_PAPER_PROCESSED`) must transition through explicit lifecycle statuses: `STATUS_DISPATCHED` -> `STATUS_COMPLETED`, `STATUS_PARTIAL`, or `STATUS_FAILED`.
   - **Never declare a stage completed merely because a downstream job was dispatched.**
2. **Terminalization & Downstream Acknowledgment:**
   - Downstream completion of `STAGE_PAPER_PROCESSED` must explicitly acknowledge and terminalize upstream `SIGNALS_EVALUATED` and 5m `CANDLE_CLOSED` records.
3. **Partial Failure Semantics:**
   - When processing a multi-account batch:
     - All eligible accounts succeed with 0 errors -> `STATUS_COMPLETED`.
     - Some accounts fail, but others succeed -> `STATUS_PARTIAL` with recorded `failures_count` and account error diagnostics.
     - All accounts fail -> `STATUS_FAILED`.
4. **Safe Retries & Recovery:**
   - `TradingPipelineRecoveryService` must check whether downstream stages already completed before re-dispatching stuck upstream events.
   - Support targeted retries (`onlyAccountIds`) to re-process failed accounts without re-executing successful accounts.

---

## Section I: Testing and Quality Assurance Protocols

For every functional change or defect correction:

1. **Create/Update Targeted Tests:** Write specific unit or feature tests reproducing the defect and verifying the fix.
2. **Test Negative & Edge Cases:** Test zero quantities, stale data feeds, network timeouts, database lock contention, and boundary conditions.
3. **Verify Financial Invariants:** Assert that balances, positions, executions, and journal entries reconcile exactly without floating-point drift.
4. **Isolated Infrastructure:** Execute tests against local isolated databases (`bybit_test` on test PostgreSQL / Redis containers). **Never run tests against production databases or services.**
5. **Run Suite Checks:**
   - PHP Unit & Feature tests: `DB_HOST=172.21.0.2 DB_PORT=5432 php -dxdebug.mode=off artisan test <target_paths>`
   - Frontend tests: `cd frontend && node --test tests/*.test.js`
   - Go tests: `cd research-go && go test -v ./...`
   - Code Style: `./vendor/bin/pint --test`
6. **Inspect Git Diff:** Review `git diff` to ensure no stray files, unintended modifications, or debug statements remain.

---

## Section J: Git, DevOps, and Operational Safety

1. **Atomic Local Commits:** Commit code in logical units with descriptive conventional commit messages (e.g., `fix(vector): ...`, `test(vector): ...`, `docs(vector): ...`).
2. **No Autonomous Remote Operations:**
   - **`git push` is strictly prohibited** without explicit operator authorization.
   - Manual SSH deployments or direct source editing on production VPS are prohibited.
   - Never trigger GitHub Actions workflows autonomously.
3. **Zero Resource Intrusion:**
   - **ECONOM / econom-group:** Strictly out of scope. Never inspect, modify, stop, or remove containers, volumes, or networks belonging to `econom-group`.
   - Never run global Docker pruning (`docker system prune -a` or `docker volume prune`).
4. **Zero Live Trading Risk:**
   - Never store, request, or configure live trading API keys. All keys must be read-only public market data keys.

---

## Section K: Definition of Done (DoD)

A task, defect fix, or feature is considered **DONE** only when:

1. Root cause is identified, documented, and addressed in code.
2. Relevant unit, feature, and integration tests have been run and recorded as passing.
3. Core safety, financial, and risk invariants are verified intact.
4. Operational interfaces truthfully reflect real data state without fabrication.
5. All code adheres to formatting, linting, and typecheck requirements.
6. A reviewed, dedicated local Git commit is created.
7. Documentation and acceptance reports truthfully record tested outcomes, limitations, and remaining operator steps.
