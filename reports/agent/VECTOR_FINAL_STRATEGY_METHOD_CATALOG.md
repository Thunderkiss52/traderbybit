# VECTOR Final Strategy Method Catalog
**Document ID:** `VECTOR-RPT-METHOD-CATALOG-FINAL-20261106`  
**Status:** COMPLETE & FROZEN  
**Total Methods Catalogued:** 50 Quantitative Methods  
**Classification:** 6 Method Families (Families A through F)  
**Safety Invariant:** Virtual PAPER Simulation Only — Blocked Methods Enforced at Model Layer

---

## 1. Executive Summary & Catalog Taxonomy

The VECTOR Strategy Method Library organizes 50 industry-standard quantitative trading methods into 6 foundational families. Every method possesses a strict metadata schema detailing parameters, bounds, input data feeds, required indicators, supported market types, and honest implementation statuses.

### Status Breakdown Across 50 Methods:
- **`PAPER_READY` / `RESEARCH_READY` / `IMPLEMENTED`**: 42 Methods. Fully executable within the Go Strategy DSL and virtual PAPER trading pipeline.
- **`BLOCKED_BY_DATA`**: Exactly 8 Methods. These methods depend on multi-exchange order routing, live level-3 orderbooks, external Bloomberg/Reuters news feeds, on-chain RPC nodes, or dedicated exchange reserve wallets. In accordance with VECTOR engineering standards, these are truthfully marked `BLOCKED_BY_DATA` and blocked from active paper promotion.

---

## 2. Complete 50-Method Catalog by Family

### Family A: Trading Horizons and Direction (10 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 1 | `scalping_momentum` | Scalping Momentum | Signal | `PAPER_READY` | 1m/5m Candles, Ticker |
| 2 | `day_trading_breakout` | Day Trading Breakout | Signal | `PAPER_READY` | 15m/1h Candles, Volume |
| 3 | `swing_trend_following` | Swing Trend Following | Signal | `PAPER_READY` | 1h/4h/1d Candles, EMA |
| 4 | `position_macro_trend` | Position Macro Trend | Style | `RESEARCH_READY` | 1d Candles, Multi-month Series |
| 5 | `trend_following_dual_ema` | Dual EMA Trend Following | Signal | `PAPER_READY` | 15m Candles, EMA 50/200 |
| 6 | `mean_reversion_bollinger` | Bollinger Mean Reversion | Signal | `PAPER_READY` | 15m Candles, BB Bands |
| 7 | `momentum_relative_strength` | Relative Strength Momentum | Signal | `PAPER_READY` | 15m/1h Candles, RSI/ROC |
| 8 | `breakout_donchian_atr` | Donchian ATR Breakout | Signal | `PAPER_READY` | 15m Candles, Donchian, ATR |
| 9 | `range_trading_envelope` | Range Trading Envelope | Signal | `PAPER_READY` | 15m Candles, SMA, Bands |
| 10 | `counter_trend_exhaustion` | Counter-Trend Exhaustion | Signal | `PAPER_READY` | 15m Candles, RSI, Z-Score |

### Family B: Price and Technical Analysis (10 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 11 | `moving_average_cross` | Moving Average Crossover | Signal | `PAPER_READY` | Closed Candles, Fast/Slow MA |
| 12 | `rsi_divergence` | RSI Divergence | Signal | `PAPER_READY` | Closed Candles, RSI |
| 13 | `macd_histogram_momentum`| MACD Histogram Momentum | Signal | `PAPER_READY` | Closed Candles, MACD |
| 14 | `bollinger_bandwidth_squeeze`| Bollinger Bandwidth Squeeze| Signal | `PAPER_READY` | Closed Candles, BB, ATR |
| 15 | `donchian_channel_breakout`| Donchian Channel Breakout | Signal | `PAPER_READY` | Closed Candles, High/Low Bands |
| 16 | `supertrend_direction` | Supertrend Direction | Filter | `PAPER_READY` | Closed Candles, ATR, Multiplier |
| 17 | `ichimoku_cloud_filter` | Ichimoku Kinko Hyo Filter | Filter | `PAPER_READY` | Closed Candles, Tenkan/Kijun/Span |
| 18 | `candlestick_pattern_recognition`| Candlestick Pattern Engine| Signal | `PAPER_READY` | OHLC Bar Ratios, Body/Wick |
| 19 | `support_resistance_zones`| Support & Resistance Zones | Filter | `PAPER_READY` | Swing Pivots, Touch Counts |
| 20 | `pivot_points_standard` | Standard Pivot Points | Filter | `PAPER_READY` | Daily High/Low/Close Pivots |

### Family C: Volatility and Volume (8 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 21 | `atr_volatility_breakout`| ATR Volatility Breakout | Signal | `PAPER_READY` | 15m Candles, ATR |
| 22 | `keltner_channel_breakout`| Keltner Channel Breakout | Signal | `PAPER_READY` | 15m Candles, EMA, ATR |
| 23 | `historical_volatility_rank`| Historical Volatility Rank | Regime Filter | `PAPER_READY` | Rolling Log Returns Variance |
| 24 | `vwap_mean_reversion` | VWAP Mean Reversion | Signal | `PAPER_READY` | Intraday Price & Turnover |
| 25 | `obv_trend_confirmation` | On-Balance Volume Trend | Filter | `PAPER_READY` | Close Changes & Volume |
| 26 | `volume_profile_poc` | Volume Profile (POC / VAH / VAL)| Filter | `PAPER_READY` | Price-Bucket Aggregated Volume |
| 27 | `order_flow_imbalance` | Order Flow Delta Imbalance | Signal | `RESEARCH_READY` | Tick Trades with Aggressor Flag |
| 28 | `depth_pressure_ratio` | Order Book Depth Ratio | Filter | `RESEARCH_READY` | L2 Bids/Asks Depth within 1-2% |

### Family D: Automation, Multi-Instrument and Relative Value (10 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 29 | `cross_exchange_arbitrage`| Cross-Exchange Arbitrage | Signal | **`BLOCKED_BY_DATA`** | Simultaneous L2 depth on multiple venues |
| 30 | `triangular_arbitrage` | Triangular Arbitrage | Signal | **`BLOCKED_BY_DATA`** | Atomic 3-leg orderbook latency & quotes |
| 31 | `market_making` | Limit Order Market Making | Execution | **`BLOCKED_BY_DATA`** | Microsecond queue position & maker routing |
| 32 | `grid_trading` | Deterministic Grid Trading | Execution | `PAPER_READY` | Arithmetic/Geometric Price Grids |
| 33 | `statistical_arbitrage_pairs`| Pairs Trading (Cointegration)| Signal | `PAPER_READY` | Synchronized Pair Returns, ADF/Spread |
| 34 | `dca_volatility_adjusted` | Volatility-Adjusted DCA | Execution | `PAPER_READY` | ATR-Scaled Virtual Capital Sizing |
| 35 | `rebalancing_portfolio` | Dynamic Portfolio Rebalance | Allocation | `PAPER_READY` | Multi-asset Target Weight Allocations |
| 36 | `basis_trading_cash_carry`| Cash-and-Carry Basis Trading | Signal | `PAPER_READY` | Spot vs Quarterly Futures Spread |
| 37 | `calendar_spreads` | Futures Calendar Spreads | Signal | `PAPER_READY` | Near vs Far Futures Term Structure |
| 38 | `options_strategies` | Option Delta/Theta Hedging | Signal | **`BLOCKED_BY_DATA`** | Continuous IV Surface & Option Greeks Feed |

### Family E: Events, News and Alternative Data (9 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 39 | `funding_rate_arbitrage` | Funding Rate Squeeze / Farm | Signal | `PAPER_READY` | 8h Predicted/Settled Funding Rates |
| 40 | `open_interest_divergence`| Open Interest Divergence | Signal | `PAPER_READY` | Continuous Open Interest & Price Delta |
| 41 | `liquidation_cascade` | Liquidation Hunt / Cascade | Signal | `RESEARCH_READY` | Exchange Liquidation Trade Stream |
| 42 | `options_expiry_pinning` | Max Pain & Expiry Pinning | Filter | `RESEARCH_READY` | Open Interest by Strike across Expiries |
| 43 | `news_trading` | Real-Time News Event Trading | Signal | **`BLOCKED_BY_DATA`** | Low-latency Bloomberg/Reuters wire feed |
| 44 | `on_chain_analysis` | On-Chain Whale & Miner Moves | Filter | **`BLOCKED_BY_DATA`** | Full node RPC / Mempool transaction feed |
| 45 | `exchange_flow_analysis`| Exchange Net Deposit/Withdrawal| Filter | **`BLOCKED_BY_DATA`** | Tagged Exchange Cold/Hot Wallet Flows |
| 46 | `sentiment_analysis` | Social Media / Crypto Fear Index| Filter | **`BLOCKED_BY_DATA`** | Normalized NLP Stream from Twitter/Reddit |
| 47 | `seasonality_hour_of_day`| Hour-of-Day / Day-of-Week | Filter | `PAPER_READY` | UTC Time-of-Day Calendar Matrix |

### Family F: Trade Management, Position Sizing and Risk (3 Methods)
| # | ID | Name | Type | Status | Required Inputs |
| :---: | :--- | :--- | :--- | :--- | :--- |
| 48 | `fixed_fractional_risk` | Fixed Fractional Capital Sizing| Sizing | `PAPER_READY` | Account Equity, SL Distance, Max Risk % |
| 49 | `volatility_targeted_sizing`| Volatility-Targeted Position Sizing| Sizing | `PAPER_READY` | Rolling Realized Volatility, Equity |
| 50 | `trailing_stop_atr` | ATR Trailing Stop & Partial Exit| Management | `PAPER_READY` | High/Low Prices, Dynamic Ratchet ATR |

---

## 3. Explicit Data Dependency Diagnostics for Blocked Methods

The following 8 methods have genuine external data prerequisites that cannot be satisfied solely by historical OHLCV or standard public REST/WebSocket feeds. They are strictly blocked from active PAPER promotion:

1. **`cross_exchange_arbitrage`**: Requires simultaneous, synchronized Level-2 orderbook streams with sub-millisecond timestamps across multiple distinct exchanges and cross-venue latency arbitrage execution infrastructure.
2. **`triangular_arbitrage`**: Requires zero-latency 3-leg orderbook snapshot feeds with maker/taker fee schedules and immediate triangular settlement.
3. **`market_making`**: Requires full Level-3 order-by-order book feeds with queue priority estimation and microsecond quote-cancellation loop.
4. **`options_strategies`**: Requires institutional continuous implied volatility (IV) surfaces, Black-Scholes greeks streaming, and multi-strike options tick data.
5. **`news_trading`**: Requires sub-second enterprise news-wire API (e.g. Bloomberg Terminal, Refinitiv, or Benzinga Pro) with NLP tokenization.
6. **`on_chain_analysis`**: Requires archive Bitcoin/Ethereum full node RPC access, mempool transaction monitors, and entity-attributed wallet graphs.
7. **`exchange_flow_analysis`**: Requires real-time exchange cluster attribution (Glassnode/CryptoQuant enterprise feed) tracking net exchange reserves.
8. **`sentiment_analysis`**: Requires enterprise social media firehose (Twitter/X API enterprise tier) with sentiment NLP scoring.
