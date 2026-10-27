# Strategy analytics and audit specification

Status: proposed extension; current services and UI are reused. No financial values below are measured production outcomes.

## Sources, scopes and API contract

Authoritative inputs: PAPER accounts, executions, position funding events, positions, equity snapshots and journals under `app/PaperTrading/Infrastructure/Persistence`. Reuse `PerformanceStatisticsService`, `DailyPerformanceService`, `StrategyComparisonService`, `ExchangeComparisonService`, `MetricRegistry`, `LedgerReconciliationService` and `app/Audit/Application/AuditExportService.php`. Extend `frontend/src/pages/ProfitabilityResearchPage.vue`, `StatisticsPage.vue`, `ResearchLearningPage.vue`, `AuditPage.vue` and existing API routes, preserving `frontend/src/services/api.ts` envelope semantics.

Every response/export must identify strategy/version hash, exchange, account IDs, symbol set, UTC half-open `[from,to)` interval, regime/version, experiment/generation, active/archive filter and mode (`BACKTEST`, `HISTORICAL_REPLAY`, `FORWARD_PAPER`). All dimensions must be queryable; unsupported combinations return explicit validation errors. Regimes are causal labels, never retrospective labels silently attached to prospective performance.

Proposed metric DTO: `{name,value,unit,definition_version,population_id,accounting_scope,n,denominator,coverage,status,reason_codes,as_of_utc,source_hash}`. Monetary values are decimal strings; missing is null, not zero. States: VERIFIED, PROVISIONAL, STALE, INSUFFICIENT_DATA, NOT_APPLICABLE, UNVERIFIED. Statistical adequacy is separate from financial reconciliation. Return pagination cursor, effective filters, data watermark and scope. Export hashes tie the same filter/metric version to API and UI. Read endpoints have no mutations; report creation is an authenticated queued POST.

## Metric registry: explicit definitions

For an account let B be inception starting balance; C be documented net external capital flow (normally zero for these PAPER accounts); R be realized ledger PnL net of all booked fees and signed funding, including booked costs for still-open positions; U be unrealized price PnL at verified marks; E=B+C+R+U. A closed-trade cohort is a separate population, not interchangeable with ledger-window R. Use exact decimal arithmetic and declared rounding. No undocumented capital adjustments.

| Metric | Definition and denominator / missing semantics |
|---|---|
| Starting equity | E at window opening, verified checkpoint at boundary or causally reconstructed from earlier checkpoint plus complete events; inception B only when window starts at account creation; otherwise missing opening is null |
| Current equity | E at common as-of time; mark timestamp/age must be exposed; stale mark means uncertified E, even with a fresh snapshot timestamp |
| Gross trading PnL | Sum side × (exit fill − entry fill) × matched quantity for realized slices; multiplier explicitly from contract spec; fill prices already include slippage |
| Commissions | Positive fee cost across executions in the stated scope; negative rebates separately identified if supported; missing fee is not zero |
| Funding | Signed settled cashflows (received positive), scoped by settlement time for window flows or full holding life for trade cohorts; unresolved schedules/events mark incomplete |
| Realized PnL | Ledger-window gross realized slices − booked fees + booked funding; closed-trade net separately sums full lifecycle gross − all lifecycle fees + funding |
| Unrealized PnL | Remaining quantity × side × (verified mark − entry basis) × contract multiplier; fee/funding already booked must not be deducted again |
| Net PnL | Window total E_end − E_start − C_window; equals ΔR + ΔU; closed-trade net uses the closed-cohort formula and a distinct label |
| Slippage | Existing stored `virtual_executions.slippage` is absolute price delta, not bps or total cash; diagnostic drag = Σ quantity × delta × multiplier; never subtract it again from fill-based net PnL |
| Net return % | 100 × window net / verified E_start if E_start>0 and C_window=0; with flows use separately named chain-linked time-weighted return split at flow times; missing valuations → unavailable |
| Win rate | Closed trades with net>0 / all fully valued closed trades, including zero trades in denominator; show wins/losses/breakevens and excluded incomplete count |
| Profit factor | Σ positive closed-trade net / abs(Σ negative closed-trade net); no losses → value null with NO_LOSSES, not arbitrary infinity; zero trades → INSUFFICIENT_DATA |
| Expectancy | Σ closed-trade net / complete closed-trade count, in quote currency; also report mean R separately; n=0 → null |
| Average R / distribution | Per-trade full net / immutable initial entry risk capital; freeze denominator at entry, never shrink after TP1; missing/nonpositive risk → excluded with count; mean, quantiles and histogram over same valid cohort |
| Maximum drawdown | Max over ordered equity checkpoints of (running peak − equity)/running peak; peak uses declared inception/window scope and positive denominator; gaps/stale marks make lower-confidence estimate explicit |
| Current drawdown | (declared peak − current equity)/peak; percentage ×100; unknown peak/current mark → null |
| Sharpe | Mean daily excess time-weighted return / sample SD × sqrt(365) for declared 24/7 daily sampling; risk-free assumption recorded; zero SD → null |
| Sortino | Mean daily excess return / sqrt(mean(min(return−target,0)^2)) × sqrt(365), denominator uses ALL valid days; target recorded; zero downside deviation → null |
| Trade frequency | Complete entries / valid observed days, with elapsed calendar days also reported; incomplete days cannot be silently dropped |
| Holding time | Mean final close−first fill duration over complete closed positions; open-position ages shown separately |
| Exposure | Gross Σ abs(remaining qty × mark × multiplier)/equity; signed net exposure separately; average is time weighted across valid intervals, not average of trade rows |
| Concentration | Symbol/strategy gross-notional share of total gross notional; HHI=Σ share²; zero exposure → NOT_APPLICABLE; correlation uses aligned return intervals |
| Trade count | Positions/trades counted once per declared entry/closed cohort; partial exits are executions, not extra trades |
| Coverage | Received valid closed slots / expected slots for qualified instrument calendar and interval; report duplicate, missing, late and rejected counts; funding coverage separately uses expected effective schedule boundaries |
| Sample adequacy | Raw n, elapsed/valid days, regime coverage, effective n, interval method and policy threshold; adequate only if all preregistered thresholds pass |

Sharpe/Sortino require a proposed minimum 60 complete daily observations and ESS≥20 plus uncertainty intervals before a ranked comparison; otherwise show insufficient sample. These are reporting floors, not profitability proof or replacements for governance gates. Annualization assumptions and serial dependence must be visible. Implement formulas once by extending `app/Research/Domain/MetricRegistry.php`; existing metric names/definitions are versioned, never silently redefined.

## Independent accounts and aggregate comparison

Default rows are strategy × version × exchange × account. Preserve the intended 26 independent PRIMARY accounts; archived and research accounts are opt-in populations. No fictional shared capital pool or transferred margin. A total may sum same-currency account equities only at a common certified timestamp with complete named membership. Label it “sum of independent accounts”; show member IDs/count and capital weighting. Missing member valuation invalidates the total rather than omitting that member silently.

Aggregate return = sum member net PnL / sum verified opening equities for a fixed population and identical window without flows; never average percentages or sum account Sharpes. Aggregate drawdown requires aligned summed equity curves, not summed drawdowns. Portfolio interpretation must state that independent simulated capital is being analytically combined. Different currencies require explicit conversion series/as-of provenance; initially reject mixed-currency totals. Version changes split attribution, not account balances; open positions remain attributed to entry version.

## UI and exports

Strategy comparison exposes net, costs, drawdown, n and adequacy together; default no ranking of uncertified rows. Exchange comparison aligns strategy/version/time/symbol scope and discloses unmatched populations. Equity curves show gaps and freshness, never interpolate missing financial truth. Daily calendar uses configurable display timezone with DST-aware UTC boundaries; default UTC. Calendar net equals Δequity−flows, with realized and unrealized changes separately shown. Drill-down preserves filters through decision → risk → fills → funding → journal.

Version comparison includes code/parameter diff and unequal observation windows. Regime view includes classifier version and sample count. Reports contain manifests, metrics, limitations, CSV and ZIP audit links. Downloads require scoped authorization, expiring access, size limits, CSV formula-injection escaping and hashes. Avoid logging credentials. Browser states include loading, empty, failed, stale and partial; cancel superseded requests and reject late responses. No placeholder totals, embedded starting balances or fabricated sample sizes.

## Full audit lineage and replay claims

Trace strategy manifest → parameters/indicator versions → source timestamps and completeness → signal → per-account eligibility → risk decision → PAPER execution → position lifecycle → fees/funding/slippage → equity → reconciliation → experiment → candidate → governance/admission. Include deterministic IDs, transaction/attempt correlation, UTC evaluation and available-at times, dataset/artifact hashes, immutable rejection reasons and policy versions. Pre-risk rejection must remain visible even when RiskEngine is never reached (F27).

Audit page shows strategy health, open findings, missing/stale evidence, financial mismatches, risk violations, research validity, experiment/version histories and admission/deployment references. A deployed image hash is separate from approval and current account binding. Account-specific halt and system research kill switch are separate states. Health cannot be green if required evidence is unknown.

Replay statuses: STORED_EVIDENCE_ONLY (retrieved historical decision), REPLAY_ATTEMPTED, VERIFIED_RECOMPUTATION (independent execution of pinned implementation/input with matching output), MISMATCH, UNVERIFIABLE. Verification requires artifact availability, input temporal provenance, deterministic environment and field-level comparison of decisions/reasons/prices. A matching stored hash alone does not prove recomputation. Historical rejected-signal counterfactual execution remains quarantined under `config/research.php`; no new historical fills are written to PAPER.

Exports state consistency mode. Preserve current bounded best-effort limitations until a dedicated consistent snapshot/export design is accepted; a ZIP checksum proves artifact integrity, not database snapshot consistency. Manifest lists every file, rows, schema, checksum, filter, generation boundaries, missing lineage and snapshot watermark. Partial exports are labelled partial and cannot certify admission. Reconciliation writes use explicit commands, never audit GETs. Corrective financial actions require separate authorization and compensating evidence, never history edits for appearance.

## Implementation, tests and limits

Proposed `app/Research/Application/StrategyAnalyticsQuery.php` coordinates existing calculators; `StrategyAuditReadModel.php` joins lineage. Add version/manifest references and indexed read projections only after inspecting existing migrations. Index by exchange/account/version/time; use cursor pagination (100 rows default, 500 maximum), bounded date windows and queued full exports. Cache keys include population, mode, definition version and watermark; invalidation never substitutes stale values as fresh.

Tests: ledger/API/UI/CSV equality on identical cohorts; partial exits and open-position costs; late funding and absent schedules; missing opening checkpoint; zero denominators; stale marks; timezone/DST boundaries; mixed versions/exchanges; canceled browser requests; archived population; incomplete ZIP; stored-only replay; deterministic recomputation/mismatch. PostgreSQL tests verify aggregation SQL and query counts. Read endpoints must produce zero database writes. Synthetic fixtures cannot become forward evidence.

Proposed acceptance: 26-account/90-day summary P95≤500 ms warm and ≤1 s cold in isolated baseline-equivalent environment, ≤20 SQL queries independent of account count; no >5% PAPER P95 degradation under concurrent reads; export memory≤192 MiB PHP and existing 1800-second timeout. Benchmark fixture cardinality, hardware, repetitions and cache state must be recorded. Failed limits block acceptance, not justify deleting evidence. Rollback disables new views/read projections; financial source records and metric versions remain intact.
