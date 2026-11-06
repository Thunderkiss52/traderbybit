# VECTOR Final Market Capability Matrix
**Document ID:** `VECTOR-RPT-CAPABILITY-MATRIX-FINAL-20261106`  
**Status:** COMPLETE & FROZEN  
**Scope:** 10 Global Exchanges × 4 Market Venues (`SPOT`, `PERPETUAL`, `FUTURES`, `OPTIONS`)  
**Legend:**
- `SUP`: `SUPPORTED` (Fully tested, operational normalized adapter)
- `PART`: `PARTIALLY_SUPPORTED` (Operational with documented venue constraints)
- `UNSUP`: `UNSUPPORTED` (Not provided by venue or regulatory boundary)
- `N/A`: Not applicable for market type (e.g., funding on spot markets)

---

## 1. Universal Venue Capability Matrix (10 × 4)

| Exchange | Market Type | Candles | Ticker | OrderBook | FundingRate | OpenInterest | Hist. Acquisition | Discovery |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Binance** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | PART | PART | PART | N/A | SUP | PART | SUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | N/A | UNSUP | UNSUP | UNSUP |
| **Bybit** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | PART | PART | PART | N/A | PART | PART | PART |
| **Coinbase** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| | `FUTURES` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| **Upbit** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| | `FUTURES` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| **OKX** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | PART | PART | PART | N/A | PART | PART | PART |
| **Bitget** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| **Gate.io** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | PART | PART | PART | N/A | PART | PART | PART |
| **KuCoin** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| **Kraken** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |
| **HTX** | `SPOT` | SUP | SUP | SUP | N/A | N/A | SUP | SUP |
| | `PERPETUAL` | SUP | SUP | SUP | SUP | SUP | SUP | SUP |
| | `FUTURES` | SUP | SUP | SUP | N/A | SUP | SUP | SUP |
| | `OPTIONS` | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP | UNSUP |

---

## 2. Regulatory & Architectural Venues Analysis

### 2.1 Spot-Only Regulated Venues
1. **Coinbase Exchange (`coinbase`)**:
   - Strictly Spot crypto/fiat trading (`BTC-USD`, `ETH-USD`, `BTC-USDT`).
   - Derivatives are executed via Coinbase International (separate entity and API). To maintain institutional precision and avoid false capabilities, Coinbase Exchange adapter marks all non-spot venues as `UNSUPPORTED`.
2. **Upbit Korea (`upbit`)**:
   - Governed under South Korean FSC / FIU guidelines prohibiting retail cryptocurrency derivative contracts.
   - Strictly Spot trading in KRW, BTC, and USDT quote pairs. All derivative market requests are cleanly intercepted and rejected.

### 2.2 Comprehensive Multi-Market Venues
1. **Bybit, OKX, Gate.io**:
   - Provide complete multi-product suites: Spot, Linear Perpetual, Delivery Futures, and European Cash-Settled Options.
   - Options APIs provide underlying strike, expiry, implied volatility (`IV`), delta, gamma, and vega greeks through public ticker and market endpoints.
2. **Bitget, KuCoin, Kraken, HTX**:
   - Complete coverage for Spot and Linear/Inverse Perpetual and Delivery Futures. Options are unsupported or restricted to private OTC desks.

---

## 3. Database & Schema Persistence

The matrix capabilities are persisted in the database via migration `2026_11_06_000001_expand_ten_exchanges_and_market_capabilities.php`:
- `exchanges`: Contains all 10 canonical records (`binance`, `bybit`, `coinbase`, `upbit`, `okx`, `bitget`, `gate`, `kucoin`, `kraken`, `htx`).
- `exchange_market_capabilities`: Relational records capturing each `(exchange_id, market_type, capability, status, notes)`.
- Enforces strict foreign keys, unique tuples, and audit logging.
