# Phase 0 financial integrity investigation — 2026-10-29

Base/deployed source supplied by owner and verified locally: `5b674960adc8ff6591134537763dce7a5bb0bb3c`.
Initial worktree clean. Scope: Phase 0 only. V3.3 remains frozen; Phase 1 not started.
Production was not accessed. The owner's aggregate observations are historical evidence,
not row-level evidence or verification of the new code. No production SQL, Artisan,
API mutation, push, deployment, capital adjustment or history repair was performed.

## Findings and causal chains

| Finding | Confirmed source/local result | Production conclusion still requiring evidence |
| --- | --- | --- |
| A: 13 zero Binance snapshots | `BinancePaperProvisioner::provision` wrote `account:initial:binance=0`; `BinancePaperCapital::allocate` subsequently wrote `owner-paper-capital:<UUID>=10000`. Introduced by `c061ab8`; automatic initializer added in `85deabf`. The regular `CapturePaperEquityJob` could also checkpoint an unfunded blocked account as zero. `PerformanceStatisticsService` starts its peak at starting_balance, so consuming the former zero creates a false 100% drawdown. Regressions reproduce both paths and prove that funding provenance, not equity magnitude alone, separates bootstrap from a real loss. | Source keys, integer IDs and capital linkage of the 26 observed rows were not supplied. Identical timestamps do not prove their actual ordering. No claim that stored account drawdown was 100%: this defect affects snapshot-derived analytics. |
| B: 379 incomplete Bybit positions | `c259ec7` on October 27 added close-time completeness evaluation, historical exposure reconstruction, and ingestion-triggered reconciliation. `04e009b` and `ad09f65` tightened interval evidence. Current ingestion's opened-at cutoff missed positions opened before the fetched interval; duplicate ingestion did not retry reconciliation. Bybit also substituted trade-candle closes for funding marks. | The change date supports a legacy explanation, but cannot classify 379 individual positions. Missing schedules, rates, marks, settlements and deployment timestamps prevent financial reconciliation. `funding_complete=true` is local lifecycle coverage, not independent exchange certification. |
| C: legacy Bybit baseline | `PaperAccountService::default` deliberately creates `FORWARD_PAPER`; `primaryAccount('baseline','bybit')` reuses it. PRIMARY flags/strategy identity drive reporting and fan-out. The SQL mode-only filter was wrong. | Preserve the existing UUID and ledger. Query 13 must verify every exact exchange/strategy pair, including baseline. |
| D: 316 STALE, four PARTIAL records | STALE is a legitimate freshness disposition; PARTIAL means mixed account results. `2632d7e`/`bd20e6c` added acknowledgement/recovery near October 27. Current code discarded failure identities, overwrote cohort totals on targeted retries, skipped expired acknowledgements, and could overwrite synchronous terminal status with DISPATCHED. Regressions reproduce these defects. | Aggregates cannot tell whether 316 rows were real expiry, missing old acknowledgements, or which accounts failed in four rows. Four records may represent fewer underlying cycles because upstream mirrors downstream. Never infer four failed fills. |
| E: 5% entry block / 10% halt | `PaperExecutionEligibilityService` rejects non-ACTIVE accounts and drawdown >=5%; `PaperProcessingService::processState` rechecks risk under account lock immediately before `PositionLifecycleService::open`, which rechecks eligibility. Active states still reach exit/funding/risk-closure processing. Thresholds unchanged. | Owner's three blocked accounts are compatible with intended protection. Historical no-bypass and management liveness require dated decisions, fills, equity and logs, not a derived SQL boolean. |
| F: auto_promote_paper=true | `AutonomyPermissionService` merges capability with both kill switches; live trading and hard-risk changes are always false. `PromotionPolicyService::promoteCandidate` refuses SYSTEM_AUTONOMOUS promotion even if enabled because operational rollback is unsupported. `AutonomyResearchService::evaluatePromotion` records eligibility with applied=false. ML champion selection is separate and uses prediction artifacts, not PRIMARY replacements. Training itself bypassed retrain permission/kill switches: reproduced and corrected at service entry and before writes; scheduler skips denied training. | The stored boolean alone does not authorize strategy replacement. Production effective config, both authorities and approval logs remain pending. No research settings changed. |

## Snapshot implementation and downstream coverage

New unfunded provisioning does not create a trading-performance point. Scheduled
equity capture now skips accounts that have never been funded, so a blocked
zero-balance Binance account cannot create a new bootstrap zero. Approved capital
allocation creates its first snapshot with flat valuation provenance. If a legacy
capture already wrote such a zero, the same proof-based exclusion applies to any
zero snapshot preceding the linked owner allocation, not only the original
`account:initial:binance` key. Raw historical snapshots remain queryable and audit
CSV exports retain them.
`EquitySnapshotRecord::performance` excludes only a zero pre-capital observation
with matching owner capital event and funded snapshot, account/exchange, amount,
time and insertion-sequence proof. It never takes MAX(equity) or removes all zeros.
Without proof the row remains visible. Genuine capital exhaustion remains a loss.

Snapshot IDs are PostgreSQL `bigIncrements` from
`2026_10_14_000000_create_paper_trading_tables.php`, not UUIDs. Readers use
`recorded_at, id`; the scoped funding linkage establishes bootstrap order rather
than guessing from equity magnitude. The shared scope covers statistics, API equity
curves, daily PostgreSQL/lateral reads, window reconciliation, risk-capital series
and dependence alignment. Audit quality recognizes linked capital as an opening
baseline; raw exports preserve the excluded observations for audit.
`LearningFeatureStoreService` builds causal market features/labels from candles,
not account equity snapshots; backtest snapshots remain a separate model/table.
No claim is made that historical research outputs were regenerated.

Late funding previously snapshotted today's cumulative account equity at the
historical funding timestamp. The failing test proves this temporal error. New
funding snapshots use application time; settlement/journal economic timestamps,
amounts and existing snapshots remain intact. Historical curves already containing
those observations require a separate classification; they are not rewritten here.

## Funding semantics and limits

A new position starts incomplete. `executeExit` evaluates after closing;
`applyFunding` applies exchange/symbol-scoped events within `(opened_at, closed_at]`,
reconstructing exposure from partial and terminal executions. Under the existing
paper model, exits at a boundary occur before that boundary's funding; opening
exactly at the boundary is excluded. Zero payment is complete only when schedule
and coverage establish that no payment is due. Missing data is not zero funding.
A closed position may remain incomplete and later settle; unique position/event
identity and a position lock prevent duplicate cashflows.

Bybit now uses the OPEN of the exact, closed 1-minute public mark-price candle at
the boundary, never its future close or an ordinary trade candle. This is an
explicit PAPER valuation convention, not proof of a real account's exchange charge.
The new nullable `market_funding_events.mark_price_source` records
`BYBIT_MARK_KLINE_OPEN` or `BINANCE_FUNDING_HISTORY`; legacy rows remain NULL.
Conflicting existing Bybit values are rejected rather than rewritten. A legacy
proxy conflict can block ingestion and requires operator classification; this is
an explicit release risk, not permission to repair or silently ignore the conflict.
Reconciliation retries include positions held across the fetched interval and
repeat verified ingestion safely. UTC boundary calculation now normalizes offsets.

Official API contracts checked during this task:
[Bybit funding history](https://bybit-exchange.github.io/docs/v5/market/history-fund-rate)
returns rates and timestamps, not settlement marks;
[mark-price kline](https://bybit-exchange.github.io/docs/v5/market/mark-kline)
provides timestamped mark OHLC. Mocked transport tests verify path, exact boundary,
closed candle and OPEN selection. No live exchange requests or rate-limit load test
were performed. Historical schedule validity and independently sourced historical
marks remain production acceptance blockers.

## Historical funding classification and repair boundary

No per-position export was supplied. Of the reported 379 incomplete positions,
**379 remain unclassified in this investigation**; no counts in the five substantive
classes can be asserted. The 72 complete Bybit and 63 complete Binance flags also
remain historical observations, not independent source reconciliation.

Use Query 16 JSON with the offline, standard-library-only tool:

```bash
rtk proxy python3 scripts/diagnostics/classify_phase0_funding.py positions.json \
  --exchange-evidence independently-validated-exchange-evidence.json
```

It makes no connections or writes and reports input hashes. Each external record
must identify exchange/symbol, full lifetime coverage, independently validated
schedule-derived boundaries/rates/marks, and a review reference. The tool does not
perform or substitute for that independent validation. Categories:

1. `PROVEN_FULLY_RECONCILED`: independent coverage, reconstructed exposure,
   amounts and ledger identities match and lifecycle flag is already complete.
2. `MISSING_EXPECTED_FUNDING_INTERVALS`: validated boundaries absent locally.
3. `INSUFFICIENT_SOURCE_DATA`: coverage/provenance/executions unavailable or conflicting.
4. `LEGACY_STATE_REQUIRES_MIGRATION_DECISION`: all money/coverage reconciles but
   incomplete metadata requires an explicit owner decision; no flag is changed.
5. `CONFIRMED_FINANCIAL_DISCREPANCY`: local identity or independently calculated
   settlement disagrees. Missing settlement with proved exposure is a discrepancy.

Any later repair requires owner-approved exact IDs, immutable input hashes,
effective historical exchange schedules, deterministic decimal calculations,
idempotency keys, account-isolated transaction/lock design, before/after independent
ledger reconciliation and compensating entries where appropriate. No mass UPDATE,
normalization, zero-row deletion, forced funding flags or production backfill is
part of this work. Replaying old pipeline events is prohibited.

## Pipeline recovery contract

Each account's effects and its acknowledgement now commit together; rollback
removes both if processing fails. The durable cohort retains eligible/successful/
failed identities, so a retry skips completed peers and resumes unfinished ones.
Failure diagnostics persist in payload. Expired deliveries acknowledge upstream
without executing, preserving an already recorded downstream terminal disposition.
Fresh partial/failed batches can retry known missing peers; expired partial states
remain historical evidence. Legacy aggregates without identities are not used to
infer safe retry targets. Exchange-qualified keys, account locks and existing fill
idempotency remain in force. Regression covers simulated crash checkpoints and
retry; an OS-level worker kill during a real multi-worker fan-out was not performed.

## Acceptance and operator handoff

All **45 V2 production obligations remain PENDING**. Checklist mapping is tested
against the frozen registry; SQL success is not PASS. Current-source local tests
are evidence for their specific paths, not wholesale replacement of older V1,
browser V3 or product acceptance. Baseline scorecard was I=0%, V=0%, P=0% because
prior evidence is stale; no score is inflated by this remediation.

The next owner-run read-only round needs the revised SQL output (especially Q13–16),
release SHA and timestamps, independent funding evidence, and existing processing/
permission logs. Q9 threshold predicates require matching risk/exit evidence.
Do not run mutation-based audit-export API examples as part of this read-only round.
Production migration/deployment is not authorized by this report. The additive
funding-source migration was exercised only on isolated test PostgreSQL.

**Release acceptance verdict: NOT READY** until historical funding/source conflicts,
individual pipeline dispositions and the mandatory production obligations are
verified. Local code verification results and artifact hashes are recorded alongside
this report; production acceptance is a separate gate.
