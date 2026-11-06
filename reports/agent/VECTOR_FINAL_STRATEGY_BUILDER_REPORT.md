# VECTOR Final Strategy Builder Report
**Document ID:** `VECTOR-RPT-STRATEGY-BUILDER-FINAL-20261106`  
**Status:** COMPLETE & FROZEN  
**Components:** Frontend Visual Editor, Backend REST APIs, Go Research DSL  
**Safety Invariant:** Virtual PAPER & Research Mode — Strict Deterministic Validation Prior to Persistence

---

## 1. Executive Summary

The VECTOR Strategy Builder provides a complete visual and programmatic authoring pipeline that allows the platform owner to:
1. Browse and inspect the complete catalog of 50 quantitative methods across Families A through F.
2. Dynamically parameterize indicators, triggers, filters, and exit rules.
3. Explicitly assign custom strategies to any of the 10 target exchanges (`binance`, `bybit`, `coinbase`, `upbit`, `okx`, `bitget`, `gate`, `kucoin`, `kraken`, `htx`) and 4 market types (`spot`, `perpetual`, `futures`, `options`).
4. Generate deterministic, versioned JSON definitions evaluated bit-for-bit by the Go computational research engine.
5. Validate method composability, market type compatibility, and financial risk boundaries before creating durable drafts or immutable custom versions.

---

## 2. Visual Builder & UI Architecture

### 2.1 Component Structure
- `frontend/src/pages/StrategiesPage.vue`:
  - Primary navigation hub with tabbed interface:
    - Tab 1: **Strategy Method Library**: 50-method catalog with Family A-F and Method Type filters, search bar, honest status badges, parameter schemas, and data dependency callouts.
    - Tab 2: **Visual Strategy Builder**: Interactive visual rule builder (`VisualDefinitionEditor.vue`), parameter configuration, and explicit exchange assignment.
    - Tab 3: **Drafts & Versions**: Durable authoring drafts, schema validation, and immutable version lifecycle management.
    - Tab 4: **Language Authoring**: English and Russian natural-language description parser with deterministic grammar support.
    - Tab 5: **Runs & Research**: Pinned Go backtesting, walk-forward analysis, and bounded autonomous optimization.
- `frontend/src/components/strategyBuilder/VisualDefinitionEditor.vue`:
  - **Quantitative Method Selector**: Dropdown populated from `GET /api/v1/strategy-methods` with automatic parameter form generation.
  - **Explicit Exchange Assignment**: Venue selection across all 10 target exchanges with market type toggles (`perpetual`, `spot`, `futures`, `options`).
  - **Indicator & Condition Trees**: Visual nodes for EMA, SMA, RSI, ATR, ADX, MACD, Bollinger Bands, Donchian Channels, Volume SMA, ROC, and Z-Score.
  - **Exit & Risk Management**: Dynamic ATR trailing stop configuration (`activation_atr`, `distance_atr`), take-profit multiples, and fixed fractional risk %.

---

## 3. Backend Strategy Orchestration & REST Endpoints

### 3.1 Catalog & Inspection Endpoints
- `GET /api/v1/strategy-methods`: Returns full list of 50 methods with summary counters (`total_methods`, `executable_methods`, `paper_ready_methods`, `blocked_by_data_methods`). Filterable by `category`, `type`, and `status`.
- `GET /api/v1/strategy-methods/{id}`: Returns single method definition with complete parameter schemas and diagnostic blocker reasons.

### 3.2 Strategy-to-Exchange Assignment Endpoints
- `POST /api/v1/strategies/{id}/assign-exchange`:
  - Assigns target exchange and market type to a strategy version.
  - Validates market compatibility via `ExchangeStrategyCompatibilityService`.
  - Rejects assignment if the exchange does not support the requested market type (e.g. attempting to assign perpetual contracts to Coinbase Exchange or Upbit).
- `GET /api/v1/strategies/{id}/assigned-exchanges`: Returns list of currently assigned exchanges, market types, and compatibility statuses.

### 3.3 Exchange Discovery & Capabilities Endpoints
- `GET /api/v1/exchanges/{id}/capabilities`: Returns matrix of supported capabilities for the exchange.
- `GET /api/v1/exchanges/{id}/instruments?market_type={type}`: Returns discovered tradable symbols, price precision, and min lot sizes directly from the exchange adapter.

---

## 4. Go Research Engine Integration (`research-go`)

### 4.1 Extended Indicator Support
The Go Strategy DSL in `research-go/internal/strategydsl/` evaluates:
- Price moving averages: `EMA`, `SMA`.
- Oscillators: `RSI` (Wilder's smoothing with half-even rounding matching PHP), `MACD`, `MACD_SIGNAL`, `MACD_HISTOGRAM`.
- Volatility: `ATR` (Wilder's true range), `BB_UPPER`, `BB_LOWER` (population standard deviation).
- Channels: `DONCHIAN_HIGH`, `DONCHIAN_LOW`.
- Momentum & Relative Value: `ROC` (Rate of Change %), `ZSCORE` (Rolling Z-Score from SMA).
- Trend & Strength: `ADX` (Wilder's directional movement index).
- Volume: `VOLUME_SMA`.

### 4.2 Causal Trailing Stop & Partial Exit Simulation
`research-go/internal/backtest/signal_runner.go` was extended to simulate dynamic trailing stop loss ratchets without look-ahead bias:
- Stop loss evaluated strictly against the stop price established at the *close* of the prior candle.
- When candle close extends into profit past `activation_atr`, stop loss ratchets in the direction of the trade by `distance_atr`.
- Never moves backwards; protects accumulated gains.
- Tested and verified in `TestCompositeStrategyTrendRSIWithATRTrailingStop`, yielding positive net PnL and strictly outperforming fixed stops in high-volatility reversals.
