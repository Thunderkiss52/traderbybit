# VECTOR — Exchange × Strategy Compatibility Matrix

## 1. Executive Summary

This report documents the compatibility evaluation of the VECTOR quantitative strategy portfolio against registered exchanges under `ExchangeStrategyCompatibilityService` (Evaluation Engine `v1`).

Total Discovered Strategies: **13 Canonical PRIMARY + Custom Visual DSL Strategies**  
Registered Exchanges: **Bybit** (`bybit`), **Binance** (`binance`), plus dynamic onboarding support for offline data sources.

---

## 2. Core Strategy Requirements Reference

| Strategy ID | Canonical Name | Target Symbols | Required Intervals | Requires Funding | Requires OI | Minimum History Bars |
|---|---|---|---|---|---|---|
| `baseline` | Baseline Trend Following | BTCUSDT | 5m, 15m, 1h | No | No | 200 |
| `trend_pullback` | Trend Pullback (EMA/RSI) | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 200 |
| `bollinger_mean_reversion` | Bollinger Mean Reversion | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 100 |
| `donchian_atr_breakout` | Donchian Channel ATR Breakout | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 120 |
| `regime_switching` | Volatility Regime Switching | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 250 |
| `momentum` | Multi-Timeframe Momentum | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 150 |
| `support_resistance_bounce` | S/R Structural Bounce | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 200 |
| `support_resistance_breakout`| S/R Volume Breakout | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 200 |
| `funding_open_interest_squeeze`| Funding & OI Squeeze | BTCUSDT, ETHUSDT | 5m, 15m, 1h | **Yes** | **Yes** | 200 |
| `liquidity_sweep_reversal` | Liquidity Sweep & Stop Run | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 200 |
| `volatility_compression_breakout` | Squeeze / Volatility Expansion | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 180 |
| `btc_eth_lead_lag` | BTC/ETH Cross-Asset Lead-Lag | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 200 |
| `multi_timeframe_trend_following`| Multi-Timeframe Trend Invariant | BTCUSDT, ETHUSDT | 5m, 15m, 1h | No | No | 300 |

---

## 3. Compatibility Matrix by Exchange

### 3.1 Bybit (`bybit`) — Production Status: `ACTIVE_PAPER`
- **Exchange Capabilities:** `linear_perpetual`, Live Adapter COMPILED, Public Data Fresh, Owner Approved for PAPER.
- **Funding & Open Interest Ingestion:** Verified active.

| Strategy ID | Status | Research Eligible | Shadow Eligible | Paper Eligible | Diagnostic Notes |
|---|---|---|---|---|---|
| `baseline` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `trend_pullback` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `bollinger_mean_reversion` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `donchian_atr_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `regime_switching` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `momentum` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `support_resistance_bounce` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `support_resistance_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `funding_open_interest_squeeze` | `PAPER_ELIGIBLE` | YES | YES | YES | Bybit funding and OI feeds active. |
| `liquidity_sweep_reversal` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `volatility_compression_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `btc_eth_lead_lag` | `PAPER_ELIGIBLE` | YES | YES | YES | Both BTC and ETH linear contracts active. |
| `multi_timeframe_trend_following` | `PAPER_ELIGIBLE` | YES | YES | YES | 5m, 15m, 1h candle hierarchy verified. |

### 3.2 Binance (`binance`) — Production Status: `ACTIVE_PAPER`
- **Exchange Capabilities:** `linear_perpetual` (USD-M Futures), Live Adapter COMPILED, Public Data Fresh, Owner Approved for PAPER.
- **Funding Ingestion:** Verified active through Binance REST and historical Vision downloader.

| Strategy ID | Status | Research Eligible | Shadow Eligible | Paper Eligible | Diagnostic Notes |
|---|---|---|---|---|---|
| `baseline` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `trend_pullback` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `bollinger_mean_reversion` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `donchian_atr_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `regime_switching` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `momentum` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `support_resistance_bounce` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `support_resistance_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `funding_open_interest_squeeze` | `PAPER_ELIGIBLE` | YES | YES | YES | Binance funding rates verified. |
| `liquidity_sweep_reversal` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `volatility_compression_breakout` | `PAPER_ELIGIBLE` | YES | YES | YES | All invariants met. 13 accounts provisioned. |
| `btc_eth_lead_lag` | `PAPER_ELIGIBLE` | YES | YES | YES | Both BTC and ETH linear contracts active. |
| `multi_timeframe_trend_following` | `PAPER_ELIGIBLE` | YES | YES | YES | Multi-timeframe bar ingestion verified. |

### 3.3 Offline Historical Data Sources (e.g. `historical_csv_archive`)
- **Lifecycle Status:** `OFFLINE_DATA_SOURCE` or `RESEARCH_ENABLED`.
- **Live Adapter:** NONE (Generic/CSV).
- **Owner PAPER Approval:** FALSE.

| Strategy Type | Evaluation Outcome | Status | Eligibility |
|---|---|---|---|
| Strategies with candle coverage | `RESEARCH_ELIGIBLE` | `RESEARCH_ELIGIBLE` | Research: **YES**, Shadow: **NO**, Paper: **NO** |
| Strategies requiring funding (if funding CSV missing) | `INSUFFICIENT_DATA` | `INSUFFICIENT_DATA` | Research: **NO**, Shadow: **NO**, Paper: **NO** (Blocker: funding dataset missing) |
| Non-linear market types | `INCOMPATIBLE` | `INCOMPATIBLE` | Research: **NO**, Shadow: **NO**, Paper: **NO** (Blocker: market type mismatch) |

---

## 4. Operational Invariant Verification

1. **Risk Isolation Invariant:** Under no circumstances does registering an exchange or marking a strategy `RESEARCH_ELIGIBLE` allocate virtual paper trading capital or alter the 26 canonical paper accounts.
2. **Deterministic Re-Evaluation:** Changing any strategy timeframe in the Strategy Builder DSL immediately flips unsupported exchange records to `INSUFFICIENT_DATA` or `INCOMPATIBLE` without manual engineering intervention.
