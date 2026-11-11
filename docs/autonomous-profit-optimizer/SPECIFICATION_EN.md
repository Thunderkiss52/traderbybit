# VECTOR — Autonomous Profit Optimization & Self-Learning Trading System

## Full Technical Specification for Codex

**Project:** VECTOR / traderbybit  
**Component:** Autonomous Profit Optimizer (APO)  
**Priority:** Critical  
**Implementation:** Incremental enhancement of the existing application  
**Architecture:** Go-first, Laravel API, Vue 3 + Quasar, PostgreSQL, Redis  
**Trading mode:** PAPER ONLY  
**Target:** Autonomous, evidence-driven maximization of sustainable net trading profitability across eligible exchanges, strategies, and timeframes.

---

# 1. Primary Mission

Transform VECTOR from a system that executes predefined trading strategies into an autonomous trading research and optimization platform.

The system must continuously:

1. Collect and validate market data.
2. Identify potentially profitable trading opportunities.
3. Evaluate trading signals and their predictive value.
4. Compare strategies across exchanges and market conditions.
5. Discover and test strategy improvements.
6. Optimize execution decisions and capital utilization.
7. Eliminate statistically unsupported or persistently unprofitable behavior.
8. Adapt to changing market conditions.
9. Promote validated improvements into PAPER trading automatically.
10. Measure whether each optimization actually improves net profitability.

The system should require minimal human involvement during normal PAPER operation.

**The primary optimization target is sustainable, risk-constrained NET profitability after all trading costs.**

It must not optimize win rate, trade frequency, gross returns, or backtest profitability in isolation.

Maximum profitability is a research and optimization objective, not a guaranteed outcome.

---

# 2. Mandatory Engineering Rules

Before implementing any new functionality, inspect the actual repository and identify existing implementations.

Do not assume that functionality is missing.

## 2.1 Initial repository audit

Inspect:

- Current Git HEAD, working tree, and recent commits.
- Existing strategy registry and lifecycle.
- Supported exchanges and exchange capability contracts.
- Market-data ingestion and historical dataset subsystem.
- Signal generation and signal persistence.
- PAPER execution engine.
- Portfolio accounting and risk controls.
- Strategy research and candidate optimization.
- Backtesting and walk-forward validation.
- Research automation, workers, queues, and orchestration.
- Existing ML/statistical learning components.
- Existing profitability dashboards, endpoints, and exports.
- Database migrations and existing schemas.
- Production resource constraints.
- CI and test infrastructure.

Classify each requirement as:

- IMPLEMENTED_AND_VERIFIED
- IMPLEMENTED_BUT_INCOMPLETE
- IMPLEMENTED_BUT_DEFECTIVE
- MISSING
- BLOCKED_BY_DEPENDENCY

Produce a requirement-to-code mapping before implementing.

Do not overwrite existing correct functionality or duplicate services.

Preserve existing financial history and database integrity.

## 2.2 Non-negotiable constraints

- PAPER ONLY: never send real exchange orders.
- Never enable live trading as a side effect of optimization.
- Never weaken security or bypass risk controls.
- Never automatically unblock an owner-blocked PAPER account.
- Never rewrite historical trades, balances, or financial ledger entries.
- Never modify the ECONOM / econom-group project or its Docker resources.
- Never remove or globally prune shared Docker images, volumes, or caches.
- Do not perform production deployments.
- Do not push commits.
- Commit verified changes locally at the end of each implementation stage.
- Execute stages sequentially without requiring manual confirmation unless an irreversible or explicitly owner-controlled action is required.
- Never claim success without appropriate test evidence.

Keep the current deployment policy intact: CI builds, production deployment only through the existing manually approved workflow.

---

# 3. Target System Architecture

Implement APO as a modular subsystem integrated into the existing VECTOR architecture.

## 3.1 Go — computational core

Go should own computationally expensive operations:

- Market feature extraction.
- Signal outcome labeling.
- Profitability analysis.
- Statistical analysis.
- Model training where practical.
- Strategy parameter optimization.
- Market regime classification.
- Walk-forward evaluation.
- Monte Carlo simulations.
- Portfolio optimization.
- Risk-adjusted sizing recommendations.
- Champion/challenger evaluation.
- Candidate scoring.
- Autonomous research orchestration.
- Decision and improvement analysis.

Favor bounded-memory, streaming, batch-based processing.

Do not introduce Python-based runtime services without demonstrating a clear technical requirement that Go cannot reasonably satisfy.

## 3.2 Laravel — application and API layer

Laravel should own:

- Authentication and authorization.
- Configuration management.
- Application API.
- Trading account settings.
- Research job management interfaces.
- Audit trails.
- Operational state management.
- User-facing reporting.
- Persistence interfaces where already established.

Avoid placing large statistical calculations in PHP workers.

## 3.3 Vue 3 + Quasar — user interface

Provide dashboards and administrative controls for:

- Net profitability.
- Strategy comparison.
- Exchange comparison.
- Signal quality.
- Timeframe analysis.
- Risk and capital usage.
- Learning progress.
- Research experiments.
- Optimization decisions.
- PAPER promotion history.

Respect existing project UI patterns and server-side pagination conventions.

## 3.4 Data management

PostgreSQL remains the authoritative durable store.

Maintain the existing single-writer guarantees where required by the current architecture.

Redis may be used for queues, coordination, and non-authoritative caching.

Every research result and promoted configuration must be reproducible using immutable configuration versions, dataset identifiers, code versions, and deterministic random seeds where applicable.

Do not use an LLM in the critical real-time trading decision path.

---

# 4. Multi-Exchange Autonomous Strategy Management

## 4.1 Exchange coverage

Support the existing Bybit and Binance integrations without breaking their isolation.

Design for at least 10 eligible PAPER exchanges without assuming every exchange has identical execution capabilities.

Supported instruments, contract specifications, fee models, minimum order sizes, funding mechanisms, and market-data intervals must be exchange-specific.

## 4.2 Strategy coverage

Preserve all existing 13 PRIMARY strategies.

Support additional strategies without a hard-coded maximum.

Each strategy must have an independent performance model for every eligible exchange.

Do not assume that a profitable strategy on one exchange is profitable on another.

## 4.3 Automatic strategy onboarding

When a new strategy is registered and activated:

1. Discover all owner-enabled, PAPER-eligible exchanges.
2. Validate strategy/exchange compatibility.
3. Verify required market data, instruments, and capabilities.
4. Create the necessary isolated PAPER strategy accounts or bindings.
5. Apply the configured initial capital and default trading notional.
6. Initialize signal and performance tracking.
7. Enable PAPER evaluation and trading only after safety and readiness checks pass.
8. Record explicit reasons for unsupported or blocked combinations.

The strategy should automatically begin PAPER operation on all compatible eligible exchanges.

Exchange onboarding must remain explicitly controlled. Adding a new exchange must not silently authorize trading or bypass its eligibility checks.

## 4.4 Isolation

Maintain independent accounting for every strategy/exchange combination.

Prevent:

- Cross-exchange balance contamination.
- Cross-strategy PnL contamination.
- Incorrect fee or funding attribution.
- Reuse of invalid evidence from another exchange.
- Accidental cross-account position execution.
- Unapproved risk-state transitions.

---

# 5. Autonomous Profit Optimization Engine

## 5.1 Optimization objective

The optimizer must maximize expected, sustainable net profitability subject to explicit constraints.

Net PnL must include:

- Realized trading PnL.
- Unrealized position PnL where appropriate for equity reporting.
- Trading commissions.
- Funding payments.
- Slippage and execution costs.
- Other supported exchange-specific costs.

Do not double-count these components.

For strategy selection, distinguish realized performance, marked-to-market equity changes, and forecasted performance.

The optimization objective should consider:

- Expected net return.
- Return uncertainty.
- Maximum drawdown.
- Tail risk.
- Probability of ruin.
- Capital utilization.
- Liquidity.
- Exposure concentration.
- Strategy correlations.
- Robustness across market regimes.
- Statistical confidence.
- Performance stability over time.

Do not collapse everything into a single raw PnL ranking.

Implement a configurable constrained optimization policy, with documented scoring methods and hard eligibility gates.

## 5.2 Profitability dimensions

Measure performance separately for:

- Exchange.
- Strategy.
- Instrument.
- Signal type.
- Signal direction.
- Source timeframe.
- Evaluation horizon.
- Market regime.
- Volatility regime.
- Liquidity conditions.
- Time of day.
- Trading cost environment.
- Strategy and model version.

Support combined analysis without losing the ability to inspect individual dimensions.

## 5.3 Dynamic strategy preference

APO must learn which strategies are most suitable for specific conditions.

For example:

- A trend strategy may perform well during directional markets.
- A mean-reversion strategy may perform better during range-bound markets.
- A specific signal may work on Binance but not on Bybit.
- A short-horizon opportunity may be profitable before costs but unprofitable after fees.

The optimizer may automatically adjust PAPER strategy preferences and allocation within authorized limits.

Never suppress all strategies solely because a single short observation window has negative returns.

Use sufficient evidence, statistical uncertainty, and exploration controls.

---

# 6. Signal Intelligence Engine

**This is a critical component.**

VECTOR must distinguish profitable signals from weak, noisy, misleading, or economically untradeable signals.

## 6.1 Required timeframes

Support at least:

- 1 minute.
- 3 minutes.
- 5 minutes.
- 15 minutes.
- 30 minutes.
- 60 minutes.

Track both:

1. **Source timeframe:** candle interval from which the signal is derived.
2. **Evaluation horizon:** elapsed time over which signal performance is measured.

These are different concepts and must not be confused.

Examples:

- 5-minute source signal evaluated after 15 minutes.
- 1-minute source signal evaluated after 60 minutes.
- 15-minute source signal evaluated after 30 minutes.

Support all valid combinations while avoiding uncontrolled Cartesian-product computation.

## 6.2 Signal quality analysis

For every signal, record:

- Unique signal identifier.
- Exchange.
- Strategy and version.
- Instrument.
- Source timeframe.
- Signal timestamp in UTC.
- Signal direction.
- Entry reference price.
- Available volatility and market-regime features.
- Prediction confidence if available.
- Whether trading was allowed.
- Whether an order was simulated or executed.
- Reason for acceptance or rejection.
- Associated position or trade identifiers.
- Evaluation horizon.
- Observed future price movement.
- Realistic hypothetical execution result.
- Actual PAPER execution result, if applicable.
- Estimated and realized costs.
- Net outcome.
- Label generation version.

Retain signals that are not executed so the system can evaluate missed opportunities and filtering quality.

Do not misrepresent hypothetical signal outcomes as actual realized PnL.

## 6.3 Quality metrics

Calculate:

- Total signals.
- Accepted signals.
- Rejected signals.
- Executed signals.
- Win rate.
- Average winner.
- Average loser.
- Expected net value.
- Profit factor.
- Net return per signal.
- Signal precision.
- False-positive rate.
- Directional accuracy.
- Maximum adverse excursion.
- Maximum favorable excursion.
- Outcome distributions.
- Statistical uncertainty.
- Performance after trading costs.

Where useful, measure prediction calibration using appropriate probabilistic scoring methods.

## 6.4 Signal classification

Classify signals as:

- PROFITABLE_CONFIRMED
- PROMISING
- NEUTRAL
- INSUFFICIENT_DATA
- UNPROFITABLE_CONFIRMED
- DRIFT_DETECTED
- INVALID_DATA

Classification must be supported by versioned, configurable statistical rules.

Never classify a signal as definitively unprofitable solely because of a small sample.

## 6.5 Signal decision engine

Before allowing a new PAPER entry, evaluate:

1. Whether the signal is valid.
2. Whether the market regime is compatible.
3. Whether predicted net expectancy is positive.
4. Whether trading costs eliminate the opportunity.
5. Whether position constraints are satisfied.
6. Whether the trading account has adequate available capital.
7. Whether current portfolio exposure allows the trade.
8. Whether risk controls permit the trade.
9. Whether model confidence is sufficient.

If conditions fail, record the rejection reason.

The optimizer must explicitly support **NO_TRADE** as a valid and potentially optimal decision.

---

# 7. Autonomous Self-Learning

Implement a continuous research and learning cycle.

## 7.1 Learning lifecycle

The intended flow is:

MARKET DATA → FEATURES → SIGNAL ANALYSIS → HYPOTHESIS → CANDIDATE → BACKTEST → WALK-FORWARD VALIDATION → MONTE CARLO → SHADOW EVALUATION → PAPER PROMOTION → CONTINUOUS MONITORING.

The system must be able to execute this cycle automatically.

## 7.2 Learning targets

The optimizer should investigate:

- Strategy parameters.
- Signal filters.
- Entry timing.
- Exit timing.
- Stop-loss placement.
- Take-profit placement.
- Volatility sensitivity.
- Market regime selection.
- Signal confidence thresholds.
- Timeframe combinations.
- Exposure limits.
- Capital utilization.
- Trading cost sensitivity.

Support controlled experiments rather than unrestricted live mutation of production strategy logic.

## 7.3 Model approaches

Start with methods appropriate to the available data:

- Statistical hypothesis testing.
- Rolling-window statistics.
- Regime-conditioned analysis.
- Robust parameter search.
- Bayesian or comparable sequential estimation.
- Contextual decision models where justified.
- Lightweight ML models when they demonstrate measurable value.

Complexity must be earned by outperforming simpler baselines.

Do not introduce deep learning, reinforcement learning, or expensive infrastructure solely to claim that VECTOR uses AI.

## 7.4 Data leakage protection

Prevent:

- Look-ahead bias.
- Future candle contamination.
- Incorrect indicator warm-up.
- Overlapping label leakage.
- Training on test data.
- Reusing holdout datasets for repeated optimization.
- Invalid cross-exchange evidence.
- Survivorship and selection bias where applicable.

Use chronological train/validation/test splits.

Implement purging or embargo where overlapping signals or holding periods would otherwise leak information across splits.

## 7.5 Overfitting prevention

Require:

- Out-of-sample testing.
- Walk-forward validation.
- Multiple market regimes.
- Realistic trading costs.
- Sensitivity analysis.
- Monte Carlo or bootstrap uncertainty analysis.
- Multiple-testing safeguards where many hypotheses are evaluated.
- Comparison against the current champion and simple baselines.
- Independent PAPER forward evidence before promotion.

A backtest with excellent results is not sufficient for promotion.

---

# 8. Champion/Challenger Optimization

Every active strategy configuration must have a clearly defined champion version.

New improvements become challengers.

## 8.1 Challenger evaluation

Compare each challenger against the existing champion using the same:

- Exchange.
- Instrument.
- Market-data interval.
- Comparable historical periods.
- Trading cost assumptions.
- Account configuration.
- Risk limits.

Use paired comparisons where feasible to reduce comparison noise.

## 8.2 Promotion requirements

A challenger may be automatically promoted into eligible PAPER operation only if:

- Data validation passes.
- All research integrity checks pass.
- Required out-of-sample tests pass.
- Statistical evidence meets configurable thresholds.
- Walk-forward evaluation passes.
- Stress testing passes.
- Execution assumptions are realistic.
- Risk limits are respected.
- Shadow/PAPER verification passes.
- Operational health checks pass.
- No owner block is active.
- Promotion is authorized by the existing policy.

Thresholds must be configurable, versioned, and justified.

Do not invent a universal Sharpe-ratio or win-rate threshold and treat it as proof of profitability.

## 8.3 Rollback

After promotion, continue monitoring the new configuration.

Trigger automatic rollback or safe suspension when justified by:

- Material performance degradation.
- Statistical drift.
- Unexpected execution errors.
- Risk-limit violations.
- Dataset integrity failures.
- Accounting discrepancies.

Rollback must restore the previous valid configuration without rewriting historical financial records.

Keep a complete promotion and rollback audit trail.

---

# 9. Market Regime Intelligence

Introduce an adaptive market-regime subsystem.

At minimum, distinguish:

- Trending upward.
- Trending downward.
- Sideways/ranging.
- High volatility.
- Low volatility.
- Uncertain or transitional conditions.

Allow mixed regime attributes rather than forcing every observation into one mutually exclusive category.

Regime features may include:

- Trend strength.
- ATR and realized volatility.
- Volume.
- Volatility expansion.
- Momentum.
- Range characteristics.
- Liquidity proxies.
- Relevant cross-asset relationships.

Regime classification must use information available at decision time.

The optimizer must learn which strategies and signals perform best in each regime.

When regime confidence is insufficient, prefer conservative decisions over forced classification.

---

# 10. Position Sizing and Capital Management

## 10.1 Default trade notional

**The default trading nominal value must be 5 USDT per position.**

For example:

- Trade nominal: 5 USDT.
- Position exposure: 5 USDT.
- The value does not mean 5 USDT margin with additional leverage.

Preserve the existing user-selected fixed-notional policy unless explicitly changed by the owner.

## 10.2 Configurable notional

Allow the owner to configure notional values such as:

- 2 USDT.
- 5 USDT.
- 10 USDT.
- 100 USDT.
- Any valid exchange-compliant value.

The default remains 5 USDT.

Settings must be available per strategy/exchange account, with suitable defaults and inherited templates.

## 10.3 Additional sizing modes

Where compatible with current implementation, support:

- FIXED_NOTIONAL
- FIXED_MARGIN
- PERCENT_OF_EQUITY
- RISK_PER_STOP

These modes must be semantically distinct.

Never silently convert fixed notional into leveraged margin.

## 10.4 Adaptive sizing

The optimizer may recommend risk-adjusted position sizes based on:

- Expected net expectancy.
- Confidence.
- Volatility.
- Portfolio exposure.
- Recent drawdown.
- Available capital.
- Correlation.
- Liquidity.
- Exchange constraints.

However, automatic changes must remain inside owner-authorized sizing bounds.

If the owner selected a strict fixed nominal of 5 USDT, the optimizer must respect that value rather than silently increasing positions.

## 10.5 Exchange order constraints

Before entering PAPER positions, validate:

- Minimum notional.
- Minimum quantity.
- Quantity increment.
- Price tick.
- Available funds.
- Fees.
- Leverage restrictions.
- Instrument-specific requirements.

If 5 USDT cannot produce a valid order, skip and report the reason.

Do not automatically increase exposure to bypass minimum order restrictions.

---

# 11. Dynamic Risk Optimization

The goal is not to maximize returns through uncontrolled leverage or excessive exposure.

Implement adaptive risk management based on statistically validated behavior.

## 11.1 Adaptive risk factors

Consider:

- Strategy-specific drawdown.
- Exchange-specific drawdown.
- Volatility.
- Liquidity.
- Estimated tail risk.
- Statistical uncertainty.
- Consecutive losses.
- Portfolio correlation.
- Market regime.
- Model drift.
- Operational health.

## 11.2 Fixed drawdown policy review

Audit the existing fixed 5% drawdown entry-stop policy.

Separate:

1. Dynamic risk throttling.
2. Strategy-level entry suspension.
3. Portfolio-level risk restrictions.
4. Emergency account protection.
5. Owner-enforced hard limits.

Replace unnecessary rigid optimization restrictions with evidence-driven adaptive controls where authorized and safe.

**Do not silently remove or relax existing hard emergency protection.**

The optimizer must never override owner-defined maximum loss limits.

Previously risk-blocked accounts must remain blocked until explicitly authorized by the owner.

## 11.3 Required safety responses

Supported responses should include:

- Reduce allowed exposure.
- Reject new entries.
- Temporarily suspend an underperforming strategy.
- Switch to a safer validated configuration.
- Disable automatic promotion.
- Trigger PAPER emergency stop when applicable.
- Record all decisions in the audit log.

Do not automatically liquidate positions or modify financial state merely because a research score changes, unless an explicit existing risk policy authorizes that operation.

---

# 12. Trade Execution Cost Intelligence

Optimization must use realistic executable outcomes.

Track and model:

- Maker/taker fees.
- Slippage.
- Bid/ask spread where data permits.
- Funding.
- Order rounding.
- Partial fills.
- Exit costs.
- Execution delays.
- Rejected or unfillable orders.

Distinguish simulated cost estimates from costs actually recorded by PAPER execution.

Use exchange-specific fee schedules and effective configurations.

For small 5 USDT positions, demonstrate whether the strategy can remain profitable after all costs.

A strategy that is profitable before fees but loses money after fees must not be categorized as economically profitable.

---

# 13. Portfolio-Level Profit Optimization

Individual profitable strategies may produce poor combined results.

Implement portfolio-level analysis across strategies and exchanges.

## 13.1 Required analytics

- Total portfolio equity.
- Realized and unrealized PnL.
- Net return after costs.
- Strategy contribution.
- Exchange contribution.
- Open exposure.
- Capital utilization.
- Maximum drawdown.
- Correlation between strategies.
- Correlated positions.
- Risk concentration.
- Exposure by instrument.
- Portfolio profit factor.
- Risk-adjusted performance.
- Equity curve stability.

## 13.2 Allocation optimization

Determine whether capital should preferentially support some strategies or exchanges.

Allocation decisions must:

- Respect independent account balances.
- Respect hard exposure and sizing limits.
- Avoid fictional transfers between isolated accounts.
- Avoid double-counting shared capital.
- Preserve owner-defined funding allocations.
- Provide explicit recommendations when actual capital reassignment requires owner approval.

If capital cannot legally or operationally be transferred, record the recommended allocation separately rather than mutating account balances.

---

# 14. Autonomous Research Scheduler

Develop or extend the existing autonomous research workflow.

## 14.1 Research triggers

Research may be scheduled or triggered by:

- New validated market data.
- Newly completed trades.
- Signal outcome maturity.
- Performance deterioration.
- Regime changes.
- Detected model drift.
- Availability of new historical datasets.
- Scheduled optimization cycles.
- New strategies.
- New owner-enabled exchanges.

## 14.2 Resource-aware scheduling

Respect VPS limitations.

Use:

- Bounded concurrency.
- Chunked processing.
- Streaming datasets.
- Memory-aware batch sizes.
- Backpressure.
- Job prioritization.
- Retry limits.
- Cancellation.
- Idempotent jobs.

Respect established research memory and dataset safety contracts.

Do not allow optimization workers to starve PAPER execution, ingestion, or health monitoring.

## 14.3 Research prioritization

Prioritize research opportunities using:

- Potential net improvement.
- Evidence strength.
- Expected computational cost.
- Data availability.
- Current strategy deficiencies.
- Portfolio impact.
- Exploration value.

Avoid wasting resources repeatedly testing known-invalid configurations without new evidence.

---

# 15. Profitability Dashboard

Implement or extend the existing **Доходность / Profitability** section.

It must expose what the optimizer learns and whether the learning improves results.

## 15.1 Core filters

Provide filters for:

- Exchange.
- Strategy.
- Instrument.
- Signal type.
- Signal direction.
- Source timeframe: 1/3/5/15/30/60 minutes.
- Evaluation horizon: 1/3/5/15/30/60 minutes.
- Market regime.
- Strategy version.
- Research/forward/PAPER data source.
- Date range.

Default date presets:

- Today.
- Yesterday.
- 7 days.
- 30 days.
- All time.
- Custom range.

## 15.2 Profitability overview

Show:

- Net PnL.
- Total return.
- Realized PnL.
- Unrealized PnL.
- Trading fees.
- Funding.
- Maximum drawdown.
- Open positions.
- Closed trades.
- Win rate.
- Profit factor.
- Current portfolio equity.
- Capital in active positions.
- Available capital.

Provide daily PnL charts, equity curves, and a daily performance calendar.

## 15.3 Signal quality table

Example columns:

| Field | Description |
|---|---|
| Exchange | Where the signal originated |
| Strategy | Strategy and version |
| Instrument | Trading pair |
| Timeframe | Signal source timeframe |
| Horizon | Outcome evaluation window |
| Signals | Total observed |
| Executed | Actual PAPER entries |
| Win rate | Outcome success ratio |
| Net expectancy | Expected result after costs |
| Profit factor | Gross winning/gross losing PnL |
| Confidence | Evidence quality |
| Classification | Profitable/weak/unknown |
| Status | Active/rejected/research |

Use server-side pagination, default 10 rows per page.

Every aggregate must be traceable to the underlying signals, trades, and calculations.

## 15.4 Strategy/exchange comparison

Allow side-by-side comparisons of:

- The same strategy across multiple exchanges.
- Different strategies on the same exchange.
- One strategy across different timeframes.
- Different signal types under identical conditions.
- Current champion against challengers.

Do not rank incomparable experiments as if they share the same execution assumptions.

## 15.5 Optimization history

Display:

- Current champion configuration.
- Candidate configurations.
- Research status.
- Training period.
- Validation period.
- Forward verification period.
- Previous versus candidate net performance.
- Confidence and uncertainty.
- Promotion decision.
- Rejection reasons.
- Rollback history.
- Last optimization timestamp.

Clearly label hypothetical, backtested, shadow, and actual PAPER results.

---

# 16. Capital and Position Visibility

For every strategy/exchange account, display:

- Initial capital.
- Current equity.
- Available capital.
- Reserved capital.
- Configured nominal per trade.
- Current exposure.
- Maximum permitted exposure.
- Number of open positions.
- Average position nominal.
- Total net PnL.
- Return percentage.
- Current drawdown.

Initial capital must be configurable, with presets:

- 1,000 USDT.
- 5,000 USDT.
- 10,000 USDT.
- 50,000 USDT.

Default initial capital: 10,000 USDT for newly created accounts, unless explicitly overridden.

Changing the configuration must not rewrite existing balances or historical accounting.

Existing account history and balances are authoritative.

---

# 17. Database and Persistence

Inspect existing tables before proposing migrations.

Reuse established schemas where possible.

Introduce only necessary additional entities, conceptually including:

- Profit optimization policies.
- Signal outcome evaluations.
- Signal quality aggregates.
- Market regime observations.
- Strategy performance profiles.
- Optimization experiments.
- Candidate comparisons.
- Champion configuration versions.
- Promotion decisions.
- Portfolio allocation recommendations.
- Optimization audit events.
- Model drift observations.

Names should follow existing repository conventions.

## 17.1 Data integrity

Require:

- Immutable event identifiers.
- Explicit UTC timestamps.
- Exchange and strategy scope.
- Versioned research configurations.
- Dataset provenance.
- Idempotent signal outcome updates.
- Consistent accounting.
- Transaction-safe state transitions.
- Appropriate indexes and retention policies.

Preserve existing PAPER financial history.

Do not backfill artificial trades to produce attractive performance charts.

Historical candle imports should remain a research-data operation, not a mechanism for inserting fictitious realized PAPER trades.

---

# 18. API Requirements

Audit and extend existing endpoints.

Do not create parallel APIs when equivalent endpoints already exist.

The system must provide capabilities for:

1. Profitability overview.
2. Exchange profitability.
3. Strategy profitability.
4. Signal quality by timeframe and horizon.
5. Historical signal outcome drill-down.
6. Strategy/exchange comparison.
7. Active optimizer configuration.
8. Optimization experiment history.
9. Champion/challenger status.
10. Promotion and rollback history.
11. Research scheduling and status.
12. Capital configuration.
13. Trading nominal configuration.
14. Risk policy configuration.
15. Audit export.
16. Optimizer health and diagnostic status.

Implement appropriate authentication, authorization, input validation, and pagination.

Use the existing Basic Auth and research-token boundaries where applicable.

Read-only analytics endpoints must not trigger unexpected trading state changes.

---

# 19. Data Export and Auditing

Extend existing audit and export infrastructure.

Required export formats:

- CSV.
- JSON.
- Existing project-supported formats where relevant.

Export:

- Strategy profitability.
- Exchange profitability.
- Signal quality metrics.
- Timeframe comparisons.
- Research experiments.
- Model decisions.
- Champion/challenger evaluations.
- PAPER promotion history.
- Risk decisions.
- Capital and sizing configurations.
- Source dataset and version identifiers.

Support date presets including day, week, two weeks, month, and custom range.

Every automated change must be auditable.

A human operator must be able to answer:

**Why did VECTOR accept this trade, reject this signal, promote this model, reduce exposure, or stop a strategy?**

---

# 20. Reliability and Failure Recovery

The optimizer must tolerate:

- Worker restarts.
- Queue retries.
- Duplicate events.
- Partial job failures.
- Missing market data.
- Temporarily unavailable exchanges.
- Delayed signal outcomes.
- Database connection interruptions.
- Research timeouts.
- Invalid candidate models.
- Disk-space restrictions.
- Memory pressure.

Use safe, observable recovery mechanisms.

A research subsystem failure must not compromise existing account balances or PAPER execution safety.

Never promote a partially validated model.

When data is stale or invalid, prefer suspension or NO_TRADE over unsupported decisions.

---

# 21. Observability

Expose system-level metrics for:

- Market-data freshness.
- Signal generation rates.
- Evaluated signal counts.
- Optimizer research job rates.
- Job failure rates.
- Candidate acceptance/rejection.
- Champion promotions.
- Rollbacks.
- Regime distribution.
- Model drift.
- Realized versus expected performance.
- Worker CPU and memory usage.
- Queue latency.
- Trade decision latency.
- Data integrity failures.

Provide clear diagnostic reasons for every blocked research or trading operation.

Do not confuse infrastructure health with trading profitability.

A fully healthy deployment can still have unprofitable strategies.

---

# 22. Security and Financial Safety

Maintain the existing security baseline.

Requirements:

- PAPER-only capability enforcement at every execution boundary.
- No live exchange order submission.
- No secrets in audit exports.
- No arbitrary code execution from generated strategy parameters.
- Validated and bounded optimizer inputs.
- No untrusted serialized model execution.
- Authorized configuration mutation.
- Immutable financial event history.
- Controlled research resource consumption.
- Protection against malformed historical datasets.
- No cross-exchange financial state leakage.

All promoted configurations must remain compatible with these controls.

---

# 23. Testing Requirements

Testing is mandatory.

## 23.1 Go tests

Cover:

- Signal labeling.
- Feature calculations.
- Statistical scoring.
- Regime detection.
- Parameter optimization.
- Candidate ranking.
- Walk-forward validation.
- Data leakage safeguards.
- Portfolio constraints.
- Risk calculations.
- Reproducibility.
- Memory and concurrency behavior.

## 23.2 Laravel tests

Cover:

- API endpoints.
- Account isolation.
- Configuration persistence.
- Access control.
- Strategy onboarding.
- Exchange eligibility.
- PAP​ER account initialization.
- Audit trails.
- Research job lifecycle.
- Promotion transitions.
- Rollbacks.

## 23.3 Frontend tests

Cover:

- Profitability filters.
- Signal timeframe selection.
- Evaluation horizon selection.
- Strategy/exchange comparison.
- Nominal configuration.
- Research status.
- Error states.
- Empty datasets.
- Pagination.

## 23.4 End-to-end scenarios

At minimum:

**Scenario A — Profitable signal**

A statistically supported signal passes validation and produces an authorized 5 USDT PAPER entry.

**Scenario B — Negative expectancy**

A signal with negative expected net value after costs is rejected with an auditable reason.

**Scenario C — Exchange differences**

The same strategy is profitable on one exchange and unprofitable on another. Decisions remain exchange-specific.

**Scenario D — Timeframe differences**

A strategy performs differently on 1-minute and 15-minute intervals. Aggregates and decisions preserve correct timeframe attribution.

**Scenario E — Valid candidate promotion**

A challenger passes all required gates and becomes the new PAPER champion.

**Scenario F — Invalid candidate**

An impressive in-sample backtest fails out-of-sample validation and is rejected.

**Scenario G — Model degradation**

A promoted model degrades, triggering the configured rollback or safe suspension process.

**Scenario H — Risk block**

An owner-blocked account remains blocked regardless of optimizer recommendations.

**Scenario I — Small notional**

A configured 5 USDT position violates exchange minimum order constraints and is safely skipped.

**Scenario J — Historical accounting**

Optimization changes do not alter existing realized PnL, balances, or ledger history.

**Scenario K — New strategy**

A newly registered strategy is initialized across all compatible, owner-enabled PAPER exchanges without breaking account isolation.

**Scenario L — New exchange**

A newly connected exchange cannot initiate PAPER trading without the required eligibility and approval state.

**Scenario M — Worker restart**

Interrupted optimization jobs recover without duplicate trade events, duplicate promotions, or corrupted accounting.

**Scenario N — Research data integrity**

Missing candles, overlapping labels, look-ahead features, and invalid historical datasets are detected and cannot silently produce validated candidates.

**Scenario O — Data-source separation**

Backtest and shadow results cannot be incorrectly reported as actual PAPER profits.

**Scenario P — Capital controls**

Adaptive sizing cannot exceed the owner's configured notional or risk limits.

**Scenario Q — Full system integration**

Validate ingestion → signals → evaluation → research → promotion → PAPER execution → accounting → dashboard → export.

Test every affected API endpoint.

Run relevant existing CI suites, static checks, formatting checks, and regression tests.

Do not weaken tests simply to obtain a green CI run.

---

# 24. Performance and Infrastructure Constraints

Target the existing VPS environment:

- 4 vCPU.
- Approximately 8 GiB RAM.
- Shared Docker infrastructure.
- Existing PostgreSQL and Redis services.
- Multiple independent application containers.

Respect the established research worker resource contracts, including bounded dataset processing and memory limits.

New subsystems must not assume dedicated GPU infrastructure.

Optimize throughput without introducing unsafe parallel writes.

Benchmark:

- Signal evaluations per second.
- Historical candles processed per second.
- Optimization throughput.
- Memory consumption.
- Research job latency.
- Query performance.
- Dashboard response times.

Provide benchmark results for realistic datasets.

Where a required target is not achieved, document the measured limitation and cause.

---

# 25. Implementation Plan

Perform stages sequentially.

## Stage 0 — Repository audit and gap analysis

Tasks:

1. Inspect actual repository state.
2. Identify current and missing optimization capabilities.
3. Map all requirements to existing modules.
4. Identify relevant defects and technical debt.
5. Verify financial and safety invariants.
6. Produce implementation and regression-test plans.

Deliverable:

`docs/autonomous-profit-optimizer/STAGE_0_AUDIT.md`

Do not rewrite already-completed functionality.

## Stage 1 — Signal intelligence and profitability attribution

Implement missing:

- Immutable signal tracking.
- Future outcome evaluation.
- Net expectancy calculations.
- Signal quality classification.
- Timeframe/horizon analysis.
- Strategy/exchange attribution.
- Profitability APIs.

Validate data integrity and accounting.

Commit verified Stage 1 changes locally.

## Stage 2 — Market regime and research optimization

Implement missing:

- Regime classification.
- Feature pipelines.
- Candidate generation.
- Configurable optimization objective.
- Experiment tracking.
- Out-of-sample analysis.
- Walk-forward evaluation.
- Overfitting prevention.

Commit verified Stage 2 changes locally.

## Stage 3 — Autonomous learning and promotion

Implement missing:

- Champion/challenger lifecycle.
- Research orchestration.
- Statistical comparison.
- Shadow evaluation.
- Automated PAPER promotion.
- Drift detection.
- Safe rollback.

Commit verified Stage 3 changes locally.

## Stage 4 — Capital, sizing, and risk intelligence

Implement missing:

- 5 USDT fixed-notional default.
- Per-account sizing configuration.
- Capital visibility.
- Portfolio exposure analysis.
- Adaptive risk recommendations.
- Owner-authorized sizing constraints.
- Portfolio-level optimization.

Preserve hard risk protection.

Commit verified Stage 4 changes locally.

## Stage 5 — Profitability UI, audit, and end-to-end validation

Implement missing:

- Profitability dashboard.
- Signal-quality dashboard.
- Timeframe comparison.
- Exchange/strategy comparison.
- Optimizer history.
- Capital and position visibility.
- Audit exports.
- Operational diagnostics.

Execute the complete relevant test suite.

Commit verified Stage 5 changes locally.

## Stage 6 — Integration hardening and final readiness assessment

Perform:

- Cross-service integration validation.
- API endpoint verification.
- Research worker restart/recovery tests.
- Database consistency checks.
- Performance benchmarks.
- Security regression checks.
- PAPER-only enforcement verification.
- CI compatibility checks.
- Documentation and operational handoff.

Commit verified hardening changes locally.

No push or deployment.

---

# 26. Definition of Done

The component is considered functionally implemented when all of the following are satisfied:

- Existing functionality has been audited and reused where possible.
- Signals are evaluated by exchange, strategy, timeframe, and horizon.
- Net expectancy includes realistic costs.
- Unprofitable or uncertain signals can be rejected.
- Market regime information affects decisions where validated.
- New research candidates are generated and evaluated automatically.
- Robust out-of-sample validation is enforced.
- Candidates cannot bypass promotion safety gates.
- Validated configurations can be promoted automatically into eligible PAPER operation.
- Regression and drift can trigger rollback or safe suspension.
- The default nominal is 5 USDT and is configurable.
- Independent strategy/exchange accounts remain isolated.
- Owner-defined risk and capital constraints are preserved.
- Dashboard results reconcile with authoritative accounting data.
- All automatic decisions have audit trails.
- Existing relevant tests pass.
- Required new tests pass.
- Benchmarks and limitations are documented.
- All changes remain local and committed.
- No production deployment has occurred.
- No real exchange orders have been submitted.
- ECONOM remains completely untouched.

**Important:** Functional completion does not mean that trading profitability has been scientifically demonstrated.

That requires a separate evidence-based validation milestone.

---

# 27. Profitability Validation Milestone

Once implementation is functionally complete, begin continuous controlled PAPER evaluation.

Compare the optimizer against:

- Existing unoptimized strategy baselines.
- Existing champion configurations.
- Simple reference strategies where applicable.
- A no-trade baseline.
- Relevant cost-adjusted benchmarks.

Evaluate:

- Net PnL improvement.
- Drawdown differences.
- Risk-adjusted return.
- Statistical uncertainty.
- Forward performance.
- Consistency across exchanges.
- Stability across market regimes.
- Capital efficiency.
- Implementation and execution costs.

Pre-register evaluation windows and decision criteria before comparing models.

Do not choose validation periods retrospectively because they show favorable outcomes.

If the optimizer fails to improve results, report that result explicitly.

The system must preserve the distinction between:

1. Operationally functional.
2. Research validated.
3. PAPER forward validated.
4. Demonstrated net profitable over the specified evaluation period.
5. Demonstrated robust across multiple independent market regimes.

Never report unproven profitability as verified.

---

# 28. Final Engineering Deliverables

Provide:

1. Stage 0 repository audit.
2. Requirement implementation matrix.
3. Architecture documentation.
4. Database migration documentation.
5. Optimizer configuration documentation.
6. Signal quality metric definitions.
7. Research and validation methodology.
8. Risk and sizing policy documentation.
9. API documentation.
10. Dashboard implementation.
11. Unit, integration, and E2E tests.
12. Performance benchmark results.
13. Security and financial integrity verification.
14. Local commit list by stage.
15. Remaining defects and known limitations.
16. Final readiness report.

The final report must explicitly distinguish:

- IMPLEMENTED
- VERIFIED
- NOT_VERIFIED
- BLOCKED
- NEEDS_PRODUCTION_EVIDENCE
- NEEDS_PAPER_FORWARD_EVIDENCE

Never invent test results, performance figures, completed tasks, or profitability evidence.

---

# 29. Final Instruction to Codex

Implement this specification against the actual VECTOR/traderbybit repository.

Treat the repository, its current configuration, established safety policies, and historical financial data as authoritative.

Start by inspecting Git status and identifying the first genuinely incomplete requirement.

Continue sequentially through the stages, avoiding duplicate implementation.

For every stage:

1. Audit current implementation.
2. Define the smallest compatible change set.
3. Implement the missing functionality.
4. Run focused tests.
5. Run relevant regression checks.
6. Fix detected defects.
7. Review security and financial invariants.
8. Document verified outcomes.
9. Create a local Git commit.
10. Continue to the next stage.

If a stage is already implemented and verified, document the evidence and proceed without duplicating it.

Do not push, deploy, execute real trades, modify ECONOM, relax owner-defined safety limits, or fabricate test evidence.

Prioritize measurable net profitability improvement, scientific validity, operational safety, autonomous research, and long-term maintainability.

**The intended end state is not merely a trading bot that executes strategies. It is an autonomous PAPER trading research system that continuously discovers, evaluates, and adopts statistically supported improvements while preserving owner-defined financial safety boundaries.**