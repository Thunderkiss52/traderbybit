# VECTOR — Audit Closure Round 2 (source-only remediation)

**UTC:** 2026-11-11T06:54:45.643597+00:00  
**Source archive:** `VECTOR-AUDIT-CLOSED.tar.gz` / SHA-256 `909067e2bedf6e25c18cd649e35e2a219c2e5af100772f93d5793168381cc557`  
**HEAD represented in the source:** `9615a62` (not a live Git checkout).  
**Acceptance:** **NOT PRODUCTION READY** — isolated source changes; full PHP 8.5/PostgreSQL/browser/live-paper verification unavailable.

## Fixes made (relative to the previous CLOSED archive)

1. **P1 — operator PAPER approval deadlock.** `ExchangeCapabilityValidator` no longer uses the already-approved config flag to determine whether an execution *model* is implemented. Technical support requires a linear-perpetual next-candle fill contract, valid instrument minima/precision and explicit cost rates. Spot/inverse remain unsupported by this existing linear engine; no fake readiness.
2. **P1 — conflicting entry authorization.** A shared `ExchangePaperApprovalPolicy` uses persisted `paper_approved=true` plus `ACTIVE_PAPER`. `PaperExecutionEligibilityService`, `ProcessPaperPipelineJob` and new-account provisioning consult the catalog state rather than conflicting static approval defaults. This blocks paused/deactivated/unapproved venues despite stale config flags.
3. **P1 — unfunded account activation.** New accounts cannot start ACTIVE with zero opening capital; exchange approval cannot reactivate BLOCKED zero-equity accounts. Existing risk/unknown-provenance blocks are retained.
4. **P1 — unverified exchange settlement model.** Linear-contract technical checks are explicit; unsupported spot and inverse markets fail closed rather than being treated as interchangeable.
5. **P1 — generic exchange context fabricated tick/lot/minimum constraints.** `UniversalMarketContextCollector` now requires exchange-derived instrument/ticker constraints and checks exchange/symbol source identity. It refuses missing metadata instead of inventing executable order sizes.
6. **P1 — wrong-exchange gap recovery.** `RecoverCandleGapsJob` rejects unknown exchange recovery rather than routing it through the Bybit-specific `SyncCandlesJob`. This is containment, **not yet a working dynamic immutable recovery pipeline**.
7. **P1 — overclaiming instrument support.** Strategy compatibility cannot assume a connected venue offers BTCUSDT/ETHUSDT without actual venue-scoped candle evidence.
8. **P1 — autonomous learning excluded newly added exchanges.** Training discovery uses the dynamic active exchange registry and requires at least 12 mature, exchange-scoped labels per symbol; missing evidence skips training.
9. **P1 — declarative endpoint SSRF DNS rebind window.** HTTPS-only dynamic manifests, no URL credentials, fragments or path traversal. `AbstractExchangeAdapter` pins the validated DNS IP with `CURLOPT_RESOLVE` for declarative GETs, disallows proxies and redirects, and fails closed without cURL. This reduces risk but **not independently penetration-tested**.
10. **P2 — legitimate zero-cost rate rejected.** PAPER cost resolution now accepts explicit decimal zero (with upper bound <0.1) while retaining exchange-specific no-Bybit-fallback behavior.

## Evidence

- PHP syntax: **937/937 PASS**.
- Existing native exchange safety checks: **28 PASS**.
- New standalone PAPER-contract checks: **4 PASS**.
- Frontend: **53 tests PASS; 2 modules cannot load** because `typescript`/`node_modules` are missing.
- Python syntax: **44 files PASS**. Shell syntax: **PASS**.
- PHPUnit/PostgreSQL/Laravel Pint: **NOT RUN** (required PHP ~8.5.12, Composer and isolated PostgreSQL unavailable).
- Go race: **BLOCKED** (offline missing `github.com/lib/pq`).
- cURL API/Playwright: **NOT RUN** (Laravel cannot start in this runtime).
- Added PHPUnit regressions for approval policy and technical model, but **not executed**.
- Git commits: **NONE** (user provided archive does not contain `.git`).

## Remaining blockers requiring actual implementation and environment verification

- **Ten-exchange PAPER execution not proven.** There is no independent full lifecycle/ledger evidence for every exchange, symbol, market type and strategy.
- **Spot, inverse, USD/USDC/KRW settlement and genuine native-contract multipliers** are not implemented in the existing linear USDT PAPER engine. No automatic activation is authorized for those markets.
- **Dynamic immutable market-candle recovery/warmup** is still Bybit/Binance-specific; unknown exchanges now fail safely instead of being misrouted. Implement a proper exchange-scoped, conflict-audited writer and recovery scheduler before claiming universal recovery.
- **Funding/model metadata** remains venue-specific and requires official instrument discovery and validated conversion. The current generic market adapter cannot prove all real exchange formats or execution constraints.
- **Owner-controlled capital allocation:** newly approved accounts with zero capital stay BLOCKED until a safe, explicitly authorized capital event; approval must not auto-fund them.
- **SSRF egress security:** verify cURL DNS pinning and Guzzle CurlHandler with native HTTP integration tests. Add independent network-egress controls for production.
- **Historical finance/UI proof:** the 26 existing original accounts, funding gaps, 786 model artifacts, Risk LONG/SHORT and P-1 were not rechecked on production data in this round.
- **Risk/pipeline:** run complete PHPUnit, feature, queue/idempotency, migration, Go race, frontend, API and UI tests on actual current HEAD before deployment. Some tests may require adjustment after stricter fail-closed approval semantics.

## Files and reproducibility

`AUDIT_CLOSURE/ROUND2/CHANGED_FILES.csv` contains every changed/new source file with hashes; `VERIFICATION.json` records executed and unavailable checks. The previous `AUDIT_CLOSURE` evidence is preserved.

**No production database write, no deployment, no push, no live orders, no ECONOM modifications.**
