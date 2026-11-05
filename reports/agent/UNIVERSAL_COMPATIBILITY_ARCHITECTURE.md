# VECTOR — Universal Exchange & Strategy Compatibility Architecture

## 1. Architectural Overview & Design Objectives

Historically, the VECTOR quantitative platform operated with a static, hardcoded pairing of Bybit and Binance against 13 fixed canonical strategies. Adding another exchange or strategy required extensive code modifications across controllers, frontend components, and risk evaluators.

The **Universal Exchange & Strategy Compatibility Architecture** decouples exchanges and quantitative strategies into an open, extensible, data-driven topology. Any registered exchange (including public REST/WS connected exchanges and offline historical CSV data sources) is dynamically evaluated against all registered strategies (including both 13 canonical `PRIMARY` strategies and visual `DSL` strategies) across three distinct eligibility tiers.

```
                      +-----------------------------------+
                      |   Strategy Definition Registry    |
                      |   (13 Canonical + Custom DSL)     |
                      +-----------------+-----------------+
                                        |
+--------------------------+            |
|   Exchange Catalog       |            |
| (Bybit, Binance, Custom) |            |
+------------+-------------+            |
             |                          |
             +------------+-------------+
                          |
                          v
         +----------------------------------+
         | ExchangeStrategyCompatibility    |
         | Service (Multi-Tier Evaluation)  |
         +----------------+-----------------+
                          |
       +------------------+------------------+
       |                  |                  |
       v                  v                  v
 [ Tier 1: RESEARCH ] [ Tier 2: SHADOW ] [ Tier 3: PAPER ]
  - Offline CSV/Zip    - Live adapter     - Full adapter
  - Historical bars    - Fresh data       - Cost model
  - Zero live code     - No trade orders  - 0 conflicts
                                          - Owner approval
```

---

## 2. Multi-Tier Eligibility Model

To support quantitative research without compromising financial paper-trading integrity, the compatibility engine evaluates pairs across three distinct operational tiers:

### Tier 1: Historical Research Eligibility (`research_eligible`)
- **Purpose:** Enables high-performance backtesting, parameter sweeps, and machine learning feature generation in Go.
- **Requirements:**
  1. Market type compatibility (linear perpetual / spot / inverse).
  2. Instrument symbol overlap between strategy requirement and available historical series.
  3. Historical candle coverage for all strategy timeframes (e.g. 1m, 5m, 15m, 1h).
  4. Specialized dataset presence if required by the strategy (e.g. funding rates, open interest).
- **Key Invariant:** Does **NOT** require a compiled live exchange adapter or live network credentials. Offline historical CSV datasets (e.g. from Binance Vision or external archives) qualify immediately once candles are validated.

### Tier 2: Shadow Forward Evaluation Eligibility (`shadow_eligible`)
- **Purpose:** Evaluates live signals in forward real-time without paper balance commitment, logging counterfactual executions and tracking hypothetical slippage.
- **Requirements:**
  1. All Tier 1 (Research) requirements satisfied.
  2. Exchange lifecycle is at least `CONNECTED_PUBLIC_DATA`.
  3. Live REST/WebSocket adapter compiled and healthy.
  4. Market data feed is verified fresh (lag < 300 seconds).

### Tier 3: Virtual Paper Trading Eligibility (`paper_eligible`)
- **Purpose:** Allows execution in virtual paper trading across isolated, double-entry ledger accounts.
- **Requirements:**
  1. All Tier 2 (Shadow) requirements satisfied.
  2. Explicit fee and slippage cost model configured (`maker_rate`, `taker_rate`, `slippage_rate`).
  3. Zero unresolved market candle conflicts or timestamp gaps.
  4. Minimum required historical depth for indicator warm-up (e.g. 200 EMA requires >= 200 closed bars).
  5. Explicit owner approval reference recorded (`paper_approved = true`).

---

## 3. Database Schema & Persistence

The compatibility state is stored in PostgreSQL table `exchange_strategy_compatibilities`:

```sql
CREATE TABLE exchange_strategy_compatibilities (
    id BIGSERIAL PRIMARY KEY,
    exchange_id VARCHAR(32) NOT NULL,
    strategy_id VARCHAR(64) NOT NULL,
    strategy_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    status VARCHAR(32) NOT NULL,
    research_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    shadow_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    paper_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    market_type_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    instruments_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    timeframes_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    history_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    features_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    execution_model_compatible BOOLEAN NOT NULL DEFAULT FALSE,
    diagnostics JSONB NULL,
    capability_snapshot JSONB NULL,
    dataset_coverage JSONB NULL,
    evaluated_at TIMESTAMPTZ NOT NULL,
    evaluation_version VARCHAR(16) NOT NULL DEFAULT 'v1',
    created_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NULL,
    CONSTRAINT exchange_strategy_unique UNIQUE (exchange_id, strategy_id, strategy_version),
    CONSTRAINT fk_exchange_strat_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges(id) ON DELETE CASCADE
);

CREATE INDEX idx_compat_lookup ON exchange_strategy_compatibilities(exchange_id, strategy_id);
CREATE INDEX idx_compat_status ON exchange_strategy_compatibilities(status);
CREATE INDEX idx_compat_research ON exchange_strategy_compatibilities(research_eligible);
CREATE INDEX idx_compat_paper ON exchange_strategy_compatibilities(paper_eligible);
```

---

## 4. Lifecycle Hooks & Automated Re-Evaluation

Compatibility is not evaluated on manual polling loops; it is deterministically triggered on domain state transitions:

1. **Exchange Registration:** When an exchange is added (`ExchangeCatalogService::register()`), all discovered strategies are immediately reconciled against the new exchange.
2. **Exchange Lifecycle Transition:** When an exchange transitions from `OFFLINE_DATA_SOURCE` to `RESEARCH_ENABLED` or `ACTIVE_PAPER`, compatibility is re-evaluated.
3. **Owner PAPER Approval:** When an owner grants PAPER approval (`ExchangeCatalogService::approvePaper()`), compatibility updates to unlock Tier 3 eligibility.
4. **Strategy Definition Creation/Update:** When a strategy definition is saved in the Strategy Builder (`DefinitionStore::save()`), all registered exchanges are re-evaluated against the new strategy version.
5. **CSV Dataset Import:** When historical candles are imported (`HistoricalCandleCsvImporter::import()`), the target exchange is re-evaluated to reflect the newly available symbol/interval coverage.
6. **Binance Coverage Pin Validation:** When historical Binance coverage is validated (`CoverageValidator::validate()`), Binance is re-evaluated to refresh research eligibility.

---

## 5. REST API & CLI Operations

### REST Endpoints
- `GET /api/v1/exchanges/matrix` — Full matrix of all registered exchanges × all registered strategies.
- `GET /api/v1/exchanges/{id}/strategies` — Evaluated compatibility for a specific exchange against all strategies.
- `POST /api/v1/exchanges/{id}/reconcile` — Force immediate re-evaluation of compatibility for an exchange.
- `GET /api/v1/strategies/{id}/exchanges` — Evaluated compatibility for a specific strategy across all exchanges.

### CLI Management Command
```bash
php artisan exchanges:reconcile-compatibility [--exchange=] [--strategy=] [--force]
```
Outputs a formatted table of all evaluated pairs, their status tier, and missing blocker requirements.
