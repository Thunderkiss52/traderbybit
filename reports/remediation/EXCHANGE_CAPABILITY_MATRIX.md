# VECTOR — Exchange Capability, Fee & Execution Model Matrix

**Operating Mode:** Virtual PAPER Trading (`PAPER_TRADING=true`)  
**Order Execution:** Zero Live Trade Credentials; Read-Only Market Ingestion  

---

## 1. Supported Exchanges & Capability Registry

| Exchange ID | Venue Name | Market Type | Adapter Class | Execution Model | Cost Model Version | Taker Fee | Maker Fee | Slippage (bps) | Market Context Collector |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **`bybit`** | Bybit Linear Perps | `linear_perpetual` | `BybitAdapter` | `NEXT_CANDLE_OPEN` | `bybit-linear-2026.1` | 0.055% | 0.020% | 2.0 bps | Dedicated & Universal |
| **`binance`** | Binance USD(S)-M | `linear_perpetual` | `BinancePublicAdapter` | `NEXT_CANDLE_OPEN` | `binance-usdm-perpetual-2026.1` | 0.050% | 0.020% | 2.0 bps | Dedicated & Universal |
| **`coinbase`** | Coinbase Perps | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `coinbase-perps-2026.1` | 0.060% | 0.020% | 2.5 bps | Universal Collector |
| **`upbit`** | Upbit Spot/Futures | `spot` / `perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `upbit-2026.1` | 0.050% | 0.050% | 3.0 bps | Universal Collector |
| **`okx`** | OKX Linear Perps | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `okx-perps-2026.1` | 0.050% | 0.020% | 2.0 bps | Universal Collector |
| **`bitget`** | Bitget USDT Perps | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `bitget-perps-2026.1` | 0.060% | 0.020% | 2.0 bps | Universal Collector |
| **`gate`** | Gate.io USDT Perps | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `gate-perps-2026.1` | 0.050% | 0.015% | 2.5 bps | Universal Collector |
| **`kucoin`** | KuCoin Futures | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `kucoin-futures-2026.1` | 0.060% | 0.020% | 2.5 bps | Universal Collector |
| **`kraken`** | Kraken Futures | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `kraken-futures-2026.1` | 0.050% | 0.020% | 2.0 bps | Universal Collector |
| **`htx`** | HTX Linear Perps | `linear_perpetual` | `DeclarativeExchangeAdapter` | `NEXT_CANDLE_OPEN` | `htx-perps-2026.1` | 0.050% | 0.020% | 3.0 bps | Universal Collector |

---

## 2. Dynamic Declarative Onboarding Capability

Arbitrary new exchanges ($N+1$) can be onboarded via versioned manifest without modifying platform source code:

1. **Manifest Validation:** [`ExchangeManifestValidator`](file:///home/thunder/work/traderbybit/app/Exchanges/Domain/ExchangeManifestValidator.php) strictly validates:
   - Manifest schema version (`>= 1.0`).
   - Base URL with anti-SSRF enforcement ([`ExchangeUrlSecurityValidator`](file:///home/thunder/work/traderbybit/app/Security/ExchangeUrlSecurityValidator.php)).
   - Supported timeframes, price/quantity precision, minimum order sizes, and notional thresholds.
   - Cost models and execution assumptions.
2. **Strategy Scoping:**
   - Optional `assigned_strategies` list. If omitted, zero accounts are created by default until explicitly assigned by the operator.
   - If provided (or canonical baseline), accounts are provisioned via [`PaperTopologySynchronizer`](file:///home/thunder/work/traderbybit/app/PaperTrading/Application/PaperTopologySynchronizer.php) in `BLOCKED` status.
3. **Execution Approval Gate:**
   - Independent operator approval (`exchanges.paper_approved = true`) is required before virtual PAPER trades can be evaluated or executed.
