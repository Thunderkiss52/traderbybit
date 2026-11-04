# 13 × 2 Strategy Execution Verification Matrix

**Date:** 2026-11-04T19:18:00+03:00  
**Authority:** Independent Release Verification Lead  
**Scope:** 13 PRIMARY Strategy Families × 2 Exchanges (Bybit and Binance) = 26 Canonical PAPER Portfolios  

---

## 1. 13 × 2 Strategy Matrix

| # | Strategy ID | Version | Timeframe Hierarchy | Bybit Status | Binance Status | Test Evidence & Assertions |
|---|---|---|---|---|---|---|
| 1 | `baseline` | 1.2.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 2 | `trend_pullback` | 1.3.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `FullPaperTradingPipelineE2ETest` |
| 3 | `bollinger_mean_reversion` | 1.3.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `StrategyPipelineFunnelVerificationTest` |
| 4 | `donchian_atr_breakout` | 1.3.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 5 | `regime_switching` | 1.3.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 6 | `momentum` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 7 | `support_resistance_bounce` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `StrategyPipelineFunnelVerificationTest` |
| 8 | `support_resistance_breakout` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `StrategyPipelineFunnelVerificationTest` |
| 9 | `funding_open_interest_squeeze` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 10 | `liquidity_sweep_reversal` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 11 | `volatility_compression_breakout` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 12 | `btc_eth_lead_lag` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |
| 13 | `multi_timeframe_trend_following` | 1.0.0 | 1h trend / 30m setup / 15m entry / 5m confirm | ACTIVE | PROVISIONED (BLOCKED) | `AllThirteenStrategiesExecutionTest` |

---

## 2. Execution & Isolation Findings

1. **Portfolio Isolation:**
   - Each strategy maintains an independent, isolated `PaperAccountRecord`.
   - Bybit portfolios operate with real-time public market data ingestion and virtual paper fills.
   - Binance portfolios are provisioned as blocked placeholders by default until explicit operator activation (`BinancePaperActivation::activate()`), satisfying strict safety constraints.
2. **Deterministic Inputs & Filtering:**
   - Verified that strategies receive 4 closed-candle timeframe inputs (5m, 15m, 30m, 60m).
   - Signals are generated only on closed bars; open bars are rejected at the domain boundary.
   - Filtering produces explicit, explainable SKIP reasons (e.g. `trending_adx`, `sample_size_insufficient`, `insufficient_warmup`) rather than silent errors.
3. **Dynamic Topology Expansion:**
   - Verified that adding a 14th strategy (`volatility_arbitrage_v1`) creates 2 new accounts (total 28) without modifying or invalidating existing account records or risk allocations (`TwentySixPaperAccountsVerificationTest`).
