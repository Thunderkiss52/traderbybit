# QIM Source-Capability Matrix (Stage Q0)

**Date:** 2026-10-20  
**Commit SHA:** `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55`  
**Reviewer:** Technical Lead & Senior Full-Stack Engineer  
**Status:** Q0 Gate Evaluation Completed  

## 1. Candidate Source Evaluation Matrix

| Field | Bybit REST v5 | Bybit WebSocket v5 | TradingView | IQ Option |
|---|---|---|---|---|
| **Source** | Bybit REST API v5 (`/v5/market/kline`) | Bybit Public WebSocket v5 (`kline.{interval}.{symbol}`) | TradingView Chart/Data | IQ Option Trading Platform |
| **Admission status** | **`ADMITTED`** | **`ADMITTED`** | **`UNDETERMINED`** | **`UNDETERMINED`** |
| **Retrieval method** | Official Public REST API | Official Public WebSocket Stream | None currently integrated. Webhooks / alerts only in public platform; no authorized automated quote feed without commercial licensing | Proprietary Web/Desktop client only. No documented public market data REST/WS quote API |
| **Timestamp precision** | Millisecond (`start` Unix epoch ms, `time` server timestamp ms) | Millisecond (`ts` provider event time ms, `start` candle open ms) | Undetermined (chart bars display minute/second; feed timestamp precision unverified) | Undetermined (client UI displays server time; raw timestamp precision unverified) |
| **Price semantics** | Open, High, Low, Close, Volume, Turnover (linear perpetual) | Open, High, Low, Close, Volume, Turnover (`confirm=true` closed candle) | Real-time exchange composite or broker-specific feed | Synthetic / OTC composite binary option quote feed |
| **Historical data** | Available via `/v5/market/kline` pagination (limit up to 1000/req) | Real-time streaming only (`confirm=true` on close) | Available via UI/PineScript export; programmatic bulk export restricted | Historical charts only in UI; no official historical export API documented |
| **Rate limits** | 10 req/s linear market, backoff on HTTP 429 / retCode 10006 | Max 500 connections per IP, heartbeat ping/pong 20s | Strict anti-scraping and rate limiting on web endpoints | Strict Cloudflare / anti-automation protections |
| **Legal status (ToS)** | Permitted: Public market data API governed by Bybit API Terms | Permitted: Public market data API governed by Bybit API Terms | **Undetermined / Prohibited**: Scraping without commercial agreement violates ToS §3.2 | **Undetermined / Prohibited**: Reverse engineering / scraping violates ToS §7 |
| **Provenance** | Verified in repo: `App\MarketData\Infrastructure\Bybit\BybitRestClient` | Verified in repo: `App\MarketData\Application\MarketStreamSupervisor` | No integration or authorized subscription credentials in repository | No integration or authorized subscription credentials in repository |

## 2. Gate Decisions & Admission Verdicts

### Bybit REST v5: `ADMITTED`
- **Verdict:** Admitted for Level 1 Internal Monitoring.
- **Rationale:** Fully integrated and active in codebase (`BybitRestClient.php`). Supports reverse pagination, millisecond timestamps, and closed candle semantics.

### Bybit WebSocket v5: `ADMITTED`
- **Verdict:** Admitted for Level 1 Internal Monitoring.
- **Rationale:** Fully integrated and active in codebase (`MarketStreamSupervisor.php`, `BybitWebSocketMessageParser.php`). Provides real-time candle completion events with `confirm=true` and `ts` event timestamps.

### TradingView: `UNDETERMINED`
- **Verdict:** `UNDETERMINED` — Level 2 connection **prohibited** under §2 gate rules.
- **Rationale:** No official quote data API exists in the application; scraping is legally prohibited by TradingView Terms of Service. L2 cross-source comparison cannot proceed without a commercial licensing contract or authorized export pipeline. Retrospective evidence may only be imported via manual evidence files (§5).

### IQ Option: `UNDETERMINED`
- **Verdict:** `UNDETERMINED` — Level 2 connection **prohibited** under §2 gate rules.
- **Rationale:** No documented public API for market quote feeds. Connecting private/scraped endpoints is expressly forbidden. Retrospective evidence regarding the operator's external observation may be preserved exclusively via manual evidence upload (§5).

## 3. Q0 Acceptance Statement
The source-capability matrix is complete. Level 1 proceeds with Bybit REST and Bybit WebSocket. External sources TradingView and IQ Option remain `UNDETERMINED`, ensuring no premature external integrations are attempted and the UI correctly displays `CROSS_SOURCE_UNAVAILABLE`.
