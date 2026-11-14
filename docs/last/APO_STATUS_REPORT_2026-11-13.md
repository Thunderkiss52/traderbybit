# VECTOR APO: implementation status

**Report time:** 2026-11-13 09:03 UTC
**Repository:** `/home/thunder/work/traderbybit`
**Current HEAD when assessed:** `1c4fe1f247045e1bc71c1c3a21d65eb2fb3cb37a`
**Release decision:** NOT READY FOR PRODUCTION

**Later implementation update:** additional changes are in progress; assess the
separately frozen current regression independently of the earlier baseline below.

This report records work completed, work in progress, evidence, and remaining
requirements. It does not certify the current working tree, actual PAPER
profitability, or any production environment.

## Local commits and working tree

- `4846221` — Stage 0 repository audit, requirement mapping, prior-finding
  recheck, and isolated baseline evidence.
- `1c4fe1f` — scoped hypothetical signal research, profitability attribution,
  research and PAPER safety corrections, and baseline evidence. This is an
  incremental baseline; it does not close the APO specification.
- Subsequent signal-metric and PAPER sizing changes are **uncommitted**. The
  user's pre-existing staged `.gitignore` change remains separate. Existing
  untracked archive materials remain in the working tree.

The implementation checkpoint predates the second commit and some later changes;
use this report and the actual Git status when resuming work.

## Completed work

### Audit and mapping

The repository and the requested V8 and post-implementation audit documents were
reviewed. Stage 0 maps the numbered specification requirements to existing code,
identifies missing or defective behavior, and rechecks the 24 earlier findings.
The audit does not treat an archive's own reports as production acceptance.

Primary documents:

- [Stage 0 audit](../autonomous-profit-optimizer/STAGE_0_AUDIT.md)
- [Requirement matrix](../autonomous-profit-optimizer/REQUIREMENT_MATRIX.csv)
- [Signal intelligence design and definitions](../autonomous-profit-optimizer/SIGNAL_INTELLIGENCE.md)
- [Baseline verification report](../autonomous-profit-optimizer/BASELINE_3_VERIFICATION.md)

### Signal intelligence and reporting

- Added Go research-only fixed-horizon signal markouts and separate source
  timeframe and evaluation horizon dimensions. Outcomes include configured
  costs, funding assumptions, provenance, and explicit unavailable states.
- Added immutable PostgreSQL outcome and profile records, fenced research jobs,
  checksums, scope checks, idempotent imports, and bounded scheduling/recovery.
- Added read-only paginated horizon and quality-profile endpoints under the
  existing profitability API. Results are labeled `HYPOTHETICAL` and do not
  authorize entry or promotion.
- Reworked existing profitability analytics to use a bounded PostgreSQL statement
  snapshot, preserve decimal accounting, and report invalid cross-scope links.
- Added descriptive net-return metrics, direction and excursion coverage in the
  working tree. Precision, false-positive rate and probability calibration remain
  unavailable when their required inputs are absent; no proxy is presented as proof.
- Corrected empty diagnostics, undefined success percentages, and signal-list
  pagination inherited from the V8 work.

The markouts are research outcomes, not realized PAPER PnL. Six accepted timeframe
values do not establish six independently operating signal-generation engines.

### Safety and research corrections

- Tightened PAPER entry evidence to require scoped, timely historical outcomes and
  cost evidence; incomplete funding cannot authorize an entry.
- Changed future topology discovery so additional accounts start `BLOCKED`,
  zero-funded and pending independent readiness. Existing accounts and historical
  balances remain authoritative.
- Scoped slippage generation and research jobs by exchange and instrument; added
  registered-exchange checks and preserved worker cutoff timestamps.
- Required current strategy version and configuration hash when reusing canonical
  pipeline observations. Added concurrency and negative controls.
- Hardened ML training and advisory behavior for incomplete, future, unavailable
  and nonfinite evidence. Descriptive sample comparisons no longer claim verified
  profitability merely from favorable supplied rows.
- Added the new integration CLI to the existing CI build and configured isolated
  PostgreSQL for its Go worker tests. The manually approved deployment policy remains.

## Verification performed

The results below cover different, overlapping snapshots and must not be added
together or treated as acceptance of the current working tree.

| Check | Result and scope |
|---|---|
| Full PHP baseline 3 | 1,677 tests, 22,233 assertions, zero errors, **one failure**, three warnings, one optional public-API skip; exit 1. The snapshot omitted the tracked public `.env.production.example`. A corrected Compose control passed 6 tests / 23 assertions. The full suite has not been rerun green on the corrected current tree. |
| Research regression | 555 tests / 4,589 assertions passed on its recorded isolated snapshot. |
| Trading regression | 73 tests / 951 assertions passed on its recorded isolated snapshot. |
| Backtest/exchange regression | 311 tests / 4,058 assertions passed on its recorded isolated snapshot. |
| Signal analytics and descriptive metrics | 40 tests / 453 assertions passed for the recorded PHP/Go integration snapshot. |
| Go baseline | Race suite passed 110 top-level tests and 100 subtests across 16 packages; vet/build passed. Later descriptive metrics separately passed 30 tests and 51 subtests under race detection. |
| Frontend | 99 tests passed; Node 22 typecheck and production build passed for the recorded snapshot. |
| Static checks | PHP lint passed 994 files and release-scope Pint passed 990 files on baseline 3. A whole-workspace Pint check also found issues in three preserved untracked archive scripts; these are outside the release source set. |

Committed raw baseline evidence is under
[stage1 evidence](../autonomous-profit-optimizer/evidence/stage1-20261112/).
Additional local artifacts, including later sizing and metrics runs, are under
`/home/thunder/work/.vector-apo-evidence-20261112/` and are not all committed.

Synthetic standalone Go benchmarks measured roughly 1.04–1.07 ms for a 60-candle,
six-horizon evaluation and roughly 30–32 ms for a 4,096-observation classification
on the recorded local CPU. These are not VPS, dashboard, or end-to-end benchmarks.

## In progress: sizing safety

An isolated test reproduced a serious boundary defect: a 2,000 USDT supplied
position could be opened for an account configured for a strict 5 USDT notional.
The runtime also interpreted unset sizing differently from the API's fixed-5 default.

Uncommitted changes now attempt to normalize runtime settings, recompute and
validate the supplied size at the final entry boundary, enforce leverage and price
tick constraints, and include outstanding reservations in global risk accounting.
The current focused sizing, tick-precision and lifecycle checks pass in isolated
PostgreSQL: **75 tests / 499 assertions** (`current-sizing-check-2`, PHP 8.5.12).
An earlier run of those checks found a duplicate synthetic signal timestamp; the
fixture was corrected before this rerun. This focused result does not establish
full integration or release readiness. An earlier broader run had eight errors
and one failure in legacy fixtures; their intended owner sizing, causal market
context and independent accounting assertions need to be reconciled and rerun.
Exit handling and existing financial history must remain protected. Evidence is
stored under `/home/thunder/work/.vector-apo-evidence-20261112/root/`.
An independent code review also identified potential gaps in allocated-margin
availability and concurrent revalidation of correlation and signal-quality gates.
These findings require reproduction and resolution before capital controls can be
accepted. The existing test named for concurrent entries serializes its two entry
calls; it does not prove competing full entry transactions are safe.

The review also identified two unverified research-metric issues: the current
descriptive profit factor uses normalized returns and can differ from the ratio
of winning to losing PnL when notionals differ; cohort provenance does not
independently establish which classifier binary produced the profile. Resolve
both before treating the metric or classifier reproducibility as verified.

Subsequent work withholds the unsupported profit factor, versions the updated
classifier policy, rejects malformed source intervals, maps `1h` to 60 minutes,
and quarantines inconsistent or subsequently changed source-signal attribution.
Legacy profile responses mark their effective classification invalid while
preserving the historical source. Focused PHP research checks passed 28 tests /
244 assertions; the full Go race suite passed 124 tests / 117 subtests, including
isolated PostgreSQL worker tests, and Go vet passed. Classifier binary attestation
is still not established. A complete PHP regression was started on a separately
frozen snapshot; no result is claimed while it runs.

The sizing work adds account-locked margin, entry-fee and same-symbol checks.
Until causal correlation can be revalidated, same-direction exposure in another
instrument is rejected conservatively; exits remain permitted. Per-position mark
freshness, concurrent account-equity changes and actual competing-worker entry
transactions remain outstanding safety investigations.

## Remaining work

| Stage | Status and next work |
|---|---|
| 0 — Audit | Completed as an audit; production and forward evidence remain outstanding. |
| 1 — Signal intelligence | In progress. Finish and verify the current descriptive metrics and sizing integration, complete missing quality metrics where valid denominators exist, finish diagnostics/export coverage, and run the complete current-tree regression. |
| 2 — Regime and research | Incomplete. Complete mixed regime attributes and their validated decision use; integrate exchange-scoped timeframe research; enforce purging/embargo consistently across splitters and walk-forward paths; verify cost assumptions, candidate objectives and out-of-sample safeguards. |
| 3 — Learning and promotion | Incomplete. Integrate the complete champion/challenger, shadow, authorization, monitoring and rollback lifecycle. Operational promotion is currently refused where safe restoration is unsupported. |
| 4 — Capital and risk | Incomplete. Finish the strict-notional fix and regression, verify all owner sizing modes and exchange minimums, reconcile portfolio risk/exposure, and preserve hard limits and blocked accounts. |
| 5 — UI and exports | Incomplete. Integrate horizon/profile metrics, strategy/exchange comparison, optimization history, capital visibility, pagination, error states and auditable exports into existing screens. |
| 6 — Hardening | Incomplete. Run full current-source PHP, Go, frontend, security and integration checks; verify worker restart and database consistency; measure realistic dataset, memory, query and dashboard performance; complete the independent final audit and handoff. |

Ten exchange registrations do not prove that all ten have validated data,
instrument, funding, cost and PAPER execution support. Automatic promotion must
remain subject to the existing policy and complete safety gates. No optimization
may reset an owner block or change financial history to improve an audit result.

## Evidence still needed

- **NOT VERIFIED:** complete current-tree regression, all affected endpoints,
  browser/end-to-end flow, final security and accounting reconciliation, and
  complete local commits for the remaining stages.
- **BLOCKED BY DEPENDENCY:** features needing validated venue-specific data,
  costs, execution contracts, independently authorized accounts, or a safe
  operational rollback implementation.
- **NEEDS PRODUCTION EVIDENCE:** deployment/workflow and production runtime
  behavior. A production deployment requires the existing explicit approval.
- **NEEDS PAPER FORWARD EVIDENCE:** pre-registered, cost-adjusted comparisons
  against champions, simple baselines and no-trade across independent regimes.
  Neither operational health nor synthetic tests establish profitability.

No Git push, production deployment or migration, real exchange order, owner
account unblock, or ECONOM operation was performed as part of this work.
