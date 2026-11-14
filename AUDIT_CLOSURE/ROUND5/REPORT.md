# VECTOR — ROUND 5 independent audit, repairs and re-audit

**UTC:** 2026-11-11T07:28:12.232357+00:00  
**Source:** `VECTOR-AUDIT-CLOSED-V4.tar.gz`  
**Source SHA-256:** `d763d39ef5441b487d4c075c10ea9561471ed8f000dc43e4f30aa0e009b63ce2`  
**Represented original HEAD:** `9615a62` (original upload does not contain `.git`).  
**Status:** **SOURCE PATCHED; INTEGRATION AND PRODUCTION ACCEPTANCE NOT VERIFIED**.

## Confirmed defects / repairs

1. **R5-01 / P1: N×M paper topology broken for declarative exchanges.** The prior strategy-exchange assignment service returned no strategies when a new declarative venue had no assignment rows. The topology synchronizer now explicitly enumerates all registered executable strategies for every registered venue. `StrategyExchangeAssignmentService` defaults to compatible assignment where there is no explicit operator veto. **Provisioning and eligibility are separated**: account creation does not authorize trading.
2. **R5-02 / P1: Operator veto / account-preservation conflict.** The previous implementation used assignment state to omit PAPER accounts. The topology synchronizer and comparison endpoint now enumerate the complete strategy × exchange matrix even when entries are vetoed. Explicit `is_active=false` rows still prevent entries; existing accounts and ledger history are not removed. The real execution gate continues to check exchange approval, strategy compatibility, risk, capital and technical model.
3. **R5-03 / P1: Invented historical equity snapshots.** Synchronization previously inserted an `account:initial` snapshot for any old primary account lacking a snapshot, using its mutable account fields and historical creation timestamp. This could hide incomplete historical evidence. It now writes an opening snapshot only when the new account is provisioned. Missing historical snapshots remain unresolved for reconciliation; **no history is fabricated**.
4. **R5-04 / P1: Spreadsheet formula injection in Signal Explorer export.** User- or system-controlled strings (including rejected trade reasons and strategy identifiers) were written directly to CSV. They are now passed through the existing safe `BacktestAuditCsv::cell` encoder. Both header and data use explicit empty CSV escape characters.
5. **R5-05 / P1: Spreadsheet formula injection in Exchange Comparison CSV.** Apply the same safe cell encoder across dynamic exchange, strategy, account and inactivity fields; preserve numeric values and `null` as empty cells.
6. **R5-06 / P2: Unicode control characters bypassed formula detection.** Strengthen shared CSV formula escaping for leading Unicode format/spacing characters, including BOM and zero-width spaces, while preserving signed decimal amounts as machine-readable values.
7. **R5-07 / P1: False PAPER readiness on paused/unapproved venues.** `ExchangeComparisonService` formerly inspected `paper_approved` alone. It now requires persisted `ACTIVE_PAPER` lifecycle plus approval and flags active accounts as execution-blocked if the venue lacks active approval. No new exchange is approved automatically.
8. **R5-08 / P1: One candle falsely marked entire exchange fresh.** Previously the maximum `market_candles.open_time` across all symbols/intervals was treated as exchange readiness. Now only closed linear candles count, and BTC/ETH × required timeframes are checked individually. Missing, stale and future-dated series receive explicit diagnostics. This is **market-data diagnostic evidence, not certification of execution model or continuous candle history**.
9. **R5-09 / P2: Non-ACTIVE and unfunded accounts displayed as waiting for trade signals.** The comparison report now flags any non-ACTIVE account as blocked, and ACTIVE accounts with nonpositive equity as unfunded, before claiming normal signal evaluation.
10. **R5-10 / P2: Exchange-comparison N×M query amplification.** Resolve `hasAdapter` once per venue instead of per strategy/venue pair; replace repeated per-pair linear `in_array` membership tests with precomputed strategy sets.

## New or revised regression coverage

- `tests/Feature/PaperTrading/StrategyTopologyIsolationAndAssignmentTest.php`: automatic new-venue accounts, auto-provisioning for a newly registered strategy, operator veto preserving history, veto preceding new-venue onboarding, and refusing to synthesize snapshots for historical accounts.
- `tests/Feature/Exchanges/MultiExchangeComparisonTest.php`: paused PAPER venue cannot be represented as waiting for signals; one BTC candle cannot certify BTC/ETH multi-timeframe market readiness.
- `tests/Feature/Research/SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest.php`: CSV export protects spreadsheet formulas and retains numeric PnL.
- `tests/Unit/Audit/BacktestAuditCsvR8Test.php`: BOM/zero-width Unicode formula cases.
- `scripts/tests/strategy_exchange_topology_contract_smoke.php`: 11 independently executable in-memory contract checks on the actual assignment service, requiring neither vendor nor PostgreSQL.

## Verification actually executed

| Verification | Result |
|---|---|
| Source archive SHA-256 | PASS |
| PHP syntax | **974 / 974 PASS** |
| Python AST syntax | **44 / 44 PASS** |
| Bash syntax | **11 / 11 PASS** |
| Standalone strategy assignment policy | **11 / 11 PASS** (in-memory stub persistence) |
| Native CSV formula cases | **5 / 5 PASS** |
| Targeted Python unit tests | **18 / 18 PASS** |
| Frontend Node tests | **53 PASS; 2 modules cannot load** (missing `typescript`) |
| Clean V4 patch validation (`git apply --check`) | PASS |
| Go tests | BLOCKED: offline missing `github.com/lib/pq` |
| Complete PHPUnit + real PostgreSQL | **NOT RUN**: PHP 8.4.24 vs required ~8.5.12, no `vendor/` or PostgreSQL |
| Full frontend typecheck/build/browser | **NOT RUN**: missing dependencies/runtime |
| Historical Bybit/Binance financial evidence | **NOT VERIFIED** |

**Caution:** Feature tests have been added/updated and syntax-checked but were **not** executed against Laravel/PostgreSQL. This archive must NOT be treated as an integration-tested release.

## Remaining risks for next round

- `SignalQualityAnalyticsService::analyze` still materializes all filtered signal/position models in memory; large cohorts need bounded aggregation, PostgreSQL tests and realistic benchmarks.
- PAPER-market readiness and LIVE-quote provenance for all ten venues have not been verified. At present `PaperExecutionModelContract` only supports linear USDT-settled perpetuals; spot, inverse and other settlement types require separate audited engines.
- Historical 26 Bybit/Binance accounts and new N×M account consistency on real PostgreSQL remain unverified. Missing opening snapshots must be investigated, never invented.
- Arbitrary exchange candle gap recovery and research path independence are incomplete/unverified.
- Full model-artifact pagination (786 historical records), 29 browser screenshot verification, UI filters and latency under genuine data volume remain unverified.
- Strategy-level execution targeting vs `market_type` (perpetual/spot) needs explicit contract validation when adding spot trading support. Do not automatically activate incompatible venues.
- Without a real Laravel/PostgreSQL environment, additional integration defects may remain. Do not disable safety gates or claim readiness on the strength of static checks.

## Reproduction / handoff

1. Use `PATCH_FROM_V4.diff` to review exact changes against a clean V4 archive (`git apply --check`).
2. Run `php scripts/tests/strategy_exchange_topology_contract_smoke.php` on any supported PHP runtime.
3. On the operator's supported PHP 8.5.12 environment with dependencies and a **separate test database**, run targeted feature suites for strategy topology, exchange comparison, and signal analytics; then all PHP tests.
4. Install frontend dependencies and run `npm run test` / `npm run build` from `frontend`, then Playwright route/table audit against the **local test server**.
5. Run offline/local Go unit and race tests after restoring pinned toolchain/module dependencies; do not touch production services, ECONOM or real orders.

## Operational boundaries

- PAPER-only scope, **no live orders**.
- No Production DB writes, migrations, API mutations, deployment, Git push, or ECONOM changes.
- Input has no Git repository metadata, so changes are files plus a reviewable patch, **not a Git commit**.
- No silent risk unblock, no account deletion, no financial-history rewriting.
