# VECTOR — V7 Remediation and Re-audit

**Date:** 2026-11-11  
**Input:** VECTOR-AUDIT-CLOSED-V6.tar.gz, plus the 24-finding independent V6 audit  
**Output:** VECTOR-AUDIT-CLOSED-V7.tar.gz  
**Source state:** V6 packaged `payload/` tree; no Git repository in archive. The original reported HEAD was `9615a62`, not a new V7 Git commit.

## Executive summary

V7 includes real source modifications for high-severity fail-open PAPER eligibility, venue/instrument mixing in pre-entry evidence, point-in-time ML features, direction-aware historical proxy labels, cross-version ML cohort isolation, research provenance, queue consistency, truthful incomplete UI statuses, historical-candle readiness, and backtest venue costs.

**This is NOT a full production release signoff.** Source-level fixes are distinguishable from integration verification, which remains blocked by missing Laravel vendor dependencies, PostgreSQL, full Go dependencies, and a running browser/server environment. Do not deploy or enable real orders based only on this archive.

## Defect-by-defect resolution vs original V6 findings

| V6 finding | V7 status | Source correction / outstanding limitation |
|---|---|---|
| F01 PAPER eligibility without edge | SOURCE_FIXED; standalone verified | Mandatory positive source-specific validated edge, >=10 samples, matching status; no unchecked override. |
| F02 mismatched fee/cost drag dimensions | SOURCE_FIXED; integration unverified | Round-trip entry+TP1 notional × (fee+slippage), divided by TP1 gain for percent; same dimension in risk classification. |
| F03 cross-venue/symbol evidence pollution | SOURCE_FIXED; DB unverified | Exact exchange/account strategy, symbol, side and pre-decision closure; no fallback into other venues; stale/negative evidence blocks. |
| F04 future candles in features | SOURCE_FIXED; historical fixtures not executed | Open timestamp bound by decision minus interval, same market category; versioned feature snapshots. Original database-ingestion provenance is not independently verified. |
| F05 LONG-only synthetic labels | PARTIAL_SOURCE_FIXED | Direction-aware signal TP1/SL and 4h proxy labels, invalid without actual strategy plan, source-specific fee/slippage; this remains a *proxy*, not executed ledger profitability. |
| F06 continuous 10-exchange ingestion | OPEN | Still lacks independently proven scheduled immutable ingestion and event publishing for all 10 markets. |
| F07 Bybit/Binance-only research | PARTIAL_SOURCE_FIXED | Go job scope and PHP job/evolution checks accept dynamically identified exchanges; research runner/lifecycle still requires independent capability validation. |
| F08 inconsistent label horizons | SOURCE_FIXED; DB unverified | 4h event-time horizon with contiguous bars, not fixed 16 bars; ambiguous bars not labeled wins. |
| F09 RSI monotone-gain defect | FIXED; standalone verified | 100 for rising/no losses, 0 falling/no gains, 50 flat. |
| F10 mixed ML cohorts | SOURCE_FIXED; DB unverified | Scope by exchange, category, symbol, strategy, **strategy version**, timeframe, BUY/SELL and policy version; training produces CANDIDATE until independent executed-trade validation. |
| F11 spot PAPER execution unsupported | OPEN | Coinbase/Upbit research-only until an audited spot execution contract exists; never simulate perp funding/shorts on spot. |
| F12 derivative funding schedules | OPEN | Verified exchange-native effective-dated funding schedules absent for several derivatives venues. |
| F13 8-venue candle recovery | OPEN | Generic immutable candle recovery not implemented for all exchanges; fail-closed behavior retained. |
| F14 inconsistent exchange queue routing | SOURCE_FIXED; deployment unverified | Shared resolver for publishing, recovery and candle event dispatch; generic venues use default queue. Verify default worker in real Compose. |
| F15 observation count masks missing strategies | SOURCE_FIXED; database unverified | Compare canonical strategy ID+version set, not raw number of observation rows. |
| F16 market context after decision | OPEN | Source-specific pre-close bid/ask contextual capture still not certified across 10 venues. Do not backdate context. |
| F17 false P-1 verification badge | SOURCE_FIXED; DB/UI unverified | Uses generation status scoped by exchange/symbol; no class-existence claim. |
| F18 unknown venue silently becomes Bybit | SOURCE_FIXED; API unverified | Validate registry and return error, never substitute Bybit. |
| F19 unbounded analytics memory | OPEN | Full SQL aggregate/chunked pagination and benchmark still required. |
| F20 1m/3m UI claims | PARTIAL_SOURCE_FIXED | Explicit no-independent-signal status for unsupported timeframe cohorts; genuine per-timeframe strategy execution not implemented. |
| F21 insufficient candle readiness | SOURCE_FIXED; integration unverified | Require fresh contiguous per BTC/ETH + required intervals; not one arbitrary candle. |
| F22 false 0% from no samples | SOURCE_FIXED; frontend-unit verified | Nullable undefined metrics and UI placeholder, do not market empty cohorts as losses. |
| F23 backtest fee-source divergence | SOURCE_FIXED; cost E2E unverified | Use exchange-specific fee/slippage contract instead of Binance env-only/Bybit default. |
| F24 fixed drawdown risk cap | POLICY_UNCHANGED | Preserve hard safety limit; changing adaptive-risk safety needs explicit owner policy and testing. |

## Patch verification

`git apply --check` of `PATCH_FROM_V6.diff` against the untouched V6 `payload/` directory: **PASS**. The patch includes the 30 changed/new source files, not the report artifacts.

## Provenance and data restrictions

- All changes are isolated within the supplied archive. No Production server, Git push, workflow dispatch, real exchange order, VPS, or ECONOM resources were touched.
- Historical PAPER balances/ledger are not rewritten. No automatic unblocking of risk-blocked existing accounts.
- Missing evidence blocks new PAPER entry, potentially reducing activity until >=10 verified relevant historical outcomes are present. This may create a cold-start *no trades* condition; do not bypass by mixing other venues or inventing history. Safe bootstrap requires a separately reviewed observation-only process.
- Models based on historical directional proxy outcomes are CANDIDATE, not ACTIVE or valid proof of executed-trade profitability. This intentionally prevents unverified auto-promotion.
- Previous PHPUnit tests assuming synthetic labels and immediate ML model ACTIVE states require alignment with new safety semantics. Selected source tests were updated but cannot be run here; CI is **not** green by evidence.

## Checks actually performed

| Check | Result |
|---|---|
| PHP source syntax | **981 / 981 PASS** (local PHP 8.4.24; target 8.5.12 not available) |
| Risk/ML/queue/provenance standalone PHP assertions | **26 / 26 PASS** |
| Frontend Node unit tests | **98 / 98 PASS** (temporary external TypeScript resolution, removed before packaging) |
| Go `internal/jobscope` | **PASS**, standalone `GO111MODULE=off go test ./internal/jobscope` |
| Python syntax and shell syntax | **PASS** for source files examined |
| Full PHPUnit + isolated PostgreSQL + migrations | **BLOCKED**: vendor/, PostgreSQL missing |
| Full Go test -race across packages | **BLOCKED**: offline dependency github.com/lib/pq |
| Vue/Quasar build, Playwright browser, 10-venue E2E | **NOT VERIFIED**: missing complete frontend deps/running app |
| Real Bybit/Binance ledger / 130-pair topology / historical learning data | **NOT VERIFIED**: no production DB and no authorised read-only runtime |

## Mandatory pre-release actions

1. In an isolated WSL2 clone, install pinned Composer/Node/Go deps, run Laravel Pint, static analysis, full PHPUnit and PostgreSQL migrations on a cloned non-production database, full Go tests/race, frontend typecheck/build and Playwright.
2. Run deterministic multi-venue, instrument, direction, TP1/TP2/SL/funding/fee/slippage economic reconciliation with nonzero trades and compare each entry against SQL aggregates; verify preservation of the historical 26 accounts read-only.
3. Verify each venue's legal market type, adapter metadata, funding schedule, per-instrument constraints, immutable candles, market context before decisions, recovery and queue consumers. Produce an executable 10-venue readiness matrix; research-only venues must remain PAPER-blocked.
4. Resolve full market-data ingestion/recovery, time-frame-specific signal support and analytics paging before declaring 10/10 operational.
5. Independently certify candidate training/promotion using genuinely executed forward outcomes, time-purged validation and no future-available data. Never permit legacy unlabeled or proxy-only models to auto-trade.
6. Deploy only via the owner's explicitly approved manual GitHub Actions workflow, after green CI. Never send LIVE orders and never touch ECONOM.

## Files

- `CHANGED_FILES.csv` — SHA-256 for each V6→V7 changed/new file.
- `PATCH_FROM_V6.diff` — unified source patch relative to V6 payload root; **not a Git commit**.
- `verify_fail_closed.php` — executable standalone fail-closed verifier.
- `TEST_RESULTS.md` — exact checks and limits.
