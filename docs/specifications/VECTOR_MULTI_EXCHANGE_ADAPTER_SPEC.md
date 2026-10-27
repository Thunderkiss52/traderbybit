# Multi-exchange adapter specification

Status: future standardization of existing adapters; no new exchange or real-order routing authorized.

## Current evidence and capability matrix

`app/Exchanges/Contracts/ExchangeAdapter.php` already defines `id`, `fetchCandles`, `fetchFundingHistory`, `fetchOpenInterest`, `fetchSymbols`. `app/Exchanges/ExchangeRegistry.php` resolves adapters. Keep this interface compatible while adding typed capabilities. Presence of a method is not support.

| Capability | Bybit current source | Binance current source | Target requirement |
|---|---|---|---|
| Bounded candles | `BybitAdapter` delegates to market client; requires bounded window, ≤500 | `BinancePublicAdapter` normalizes/validates candles, ≤500 | Qualified, closed, ordered, decimal-string rows and explicit pagination |
| Funding via common adapter | Explicitly throws pending parity fixture | Implemented public history normalization | Unsupported until verified; retain Bybit `FundingHistorySynchronizer` path |
| Open interest via common adapter | Explicitly throws pending parity fixture | Implemented with interval restrictions | Capability specifies intervals/history horizon; no fabricated zero |
| Symbol discovery | Explicitly throws for incomplete legacy listing | `fetchSymbols` normalizes exchange info | Point-in-time instrument registry with status and constraints |
| Context | Legacy `BybitRestClient` and context collector | `BinanceContextCapture`, adapter `fetchContext` | Snapshot with field-level source/available timestamps |
| Closed stream | Bybit parser/supervisor under `app/MarketData` | `BinanceWebSocketMessageParser`, `BinanceClosedCandleIngestor` | Conformance test before capability declared; source presence is not live health |
| Backfill/recovery | `CandleSynchronizer`, `GapDetector` | `BinanceCandleIngestion`, exchange routing in `RecoverCandleGapsJob` | Per-exchange idempotency, no cross-exchange fallback |
| Funding schedules | Existing PAPER schedule resolution | Existing PAPER schedule resolution | Effective-dated schedule evidence; unknown stays uncertified |
| Real order execution | Prohibited | Prohibited | No method, credential, endpoint or feature flag |

This matrix describes repository implementation, not current remote API guarantees. Before implementation against an exchange, verify its then-current official public API documentation, error/rate semantics and fixtures. This documentation cycle does not certify API limits from historical docs.

## Proposed compatible contract

Add approved `ExchangeCapabilities` and typed query/result DTOs alongside the existing interface. Proposed methods (specification signatures, not implementation):

```text
id(): ExchangeId
capabilities(asOf): CapabilitySet
instruments(query): Page<InstrumentSpecification>
candles(qualifiedInstrument, interval, closedWindow, cursor, limit): Page<Candle>
fundingEvents(qualifiedInstrument, window, cursor): Page<FundingEvent>
fundingSchedule(qualifiedInstrument, asOf): ScheduleEvidence
openInterest(qualifiedInstrument, interval, window): Page<OpenInterestPoint>
context(qualifiedInstrument, asOf): ContextEvidence
feeModel(qualifiedInstrument, tier, asOf): FeeAssumption
```

Do not add order submission/cancellation or balances from private exchange accounts. Use separate optional capability interfaces for funding/OI/streaming rather than forcing successful stubs. Registry resolves `SUPPORTED`, `UNSUPPORTED`, `DEGRADED`, `UNVERIFIED` with supported intervals, history horizon, source version and reason; runtime health does not change static capability identity.

Instrument ID includes exchange, market category, native symbol, base/quote/settlement assets and contract type. Store contract multiplier, linear/inverse flag, tick/quantity steps, min/max quantity/notional, rounding, listing/delisting time, availability and effective metadata timestamps. Initial PAPER engines admit only their verified linear USDT-perpetual semantics; spot/inverse/dated contracts are unavailable until valuation/funding/settlement models pass dedicated tests.

Candle DTO: identity, interval, UTC open/close, OHLC, base volume and quote turnover with units, closure proof, source event/observed/available times, provider revision/payload digest and quality flags. Normalize close-boundary conventions without discarding original times. Funding DTO: scheduled/actual settlement time, rate, mark/reference price if provided, units, source and available-at time. Missing price is missing, not substituted spot. OI requires units and conversion provenance. A fetched current context cannot masquerade as historical as-of context.

Fee assumptions are versioned exchange/category/tier maker/taker schedules; no global Bybit fee fallback for Binance. Funding intervals may vary by instrument and effective date; do not infer a permanent eight-hour schedule from sparse events. Missing schedule, settlement mark or event coverage blocks financial certification. Settlement/calendars/maintenance/listing changes determine expected slots rather than inventing candles during unavailable trading.

## Retry, persistence and isolation

Use existing HTTP transport/backoff implementations behind adapter boundaries. Categorize RATE_LIMITED, TRANSIENT, INVALID_REQUEST, UNSUPPORTED, MALFORMED_DATA and PROVENANCE_MISMATCH. Retry only transient/rate limits with finite exponential backoff/jitter and Retry-After where valid; impose per-provider request budgets and shared-host connection limits. Circuit breakers are exchange-specific; no switch to another exchange's candles. Exhaustion produces DEGRADED plus recovery cursor.

Persist unique `(exchange, category, instrument, interval, open_time)` identities; preserve immutable closed OHLCV and quarantine conflicting duplicates rather than silently overwriting. Funding uniqueness uses provider identity/settlement key plus exchange. Publish closed-candle events only after successful persistence; replays remain idempotent. Bounded backfills retain cursor and missing-range evidence across restart. Never mark a range complete from successful HTTP response alone.

Account identity is exchange-qualified at every eligibility, position, ledger, query and export boundary. Adapter onboarding cannot create/fund/rebind accounts automatically. Existing 26 accounts and their histories must remain byte-equivalent under onboarding tests except separately authorized normal processing outside fixtures.

## New-exchange integration checklist

1. Review official API/terms and supported public data; record dated source references and schema fixtures.
2. Implement allowlisted public client, identity, capabilities and error mapping; no secrets with Trade/Withdraw permissions.
3. Validate instrument/contract units, fee assumptions, effective schedules, precision and calendars.
4. Pass conformance fixtures: malformed/reversed/duplicate/out-of-order data, symbol mismatch, boundary closure, gaps, pagination, rate limits and cancellation.
5. Verify isolated PostgreSQL concurrent ingestion, retry/restart and conflict quarantine; prove no Bybit/Binance writes.
6. Build source provenance and historical availability coverage; unsupported fields remain unavailable.
7. Pass PAPER sizing, partial exits, fees, funding, mark valuation and reconciliation parity for supported contract types.
8. Run research-only comparisons with separate datasets and account scopes; record latency/coverage limitations.
9. Obtain explicit governance approval for any new independent PAPER accounts; initial blocked state and zero implied funding.
10. Operator deployment only through GitHub Actions after separate authorization; monitor and retain disabling path.

## Files, tests and acceptance

Extend `app/Exchanges/Contracts`, registry and existing Bybit/Binance implementations; proposed `app/Exchanges/Domain/ExchangeCapabilities.php`, `InstrumentSpecification.php`, `tests/Contract/Exchanges/ExchangeAdapterContractTest.php`. Add effective metadata/capability provenance only where existing market tables cannot represent it. No destructive migration or account rewrite. Expose capabilities through existing exchange/research capability views; UI explains unavailable functionality before scheduling.

Conformance includes missing funding versus zero funding, decimal precision, minimum notional, inverse rejection, variable interval schedules, expired metadata, delayed contexts, same-symbol cross-exchange collisions, duplicate events, retry exhaustion, interrupted backfill and no-network deterministic fixture replay. PAPER compatibility requires identical lifecycle financial outputs for preexisting fixtures and no new cross-account queries.

Performance: normalized batch size≤500; preserve the existing Stage B `<50 ms` 500-row ingestion P95 gate from `docs/verification/VECTOR_V26_STAGE_B.md` where applicable, measured with that benchmark's database/hardware conditions; do not claim a pass from old results. Proposed adapter overhead≤5% against direct-client fixture baseline; zero unbounded pagination/memory growth. Rollback disables the new capability/adapter and new admissions, retains existing data and services existing positions through the already-admitted model. No automatic down migrations or resource cleanup.
