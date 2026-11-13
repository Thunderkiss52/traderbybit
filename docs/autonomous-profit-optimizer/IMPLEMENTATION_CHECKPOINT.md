# APO implementation checkpoint

Updated: 2026-11-12. Current committed baseline: `4846221` (Stage 0 audit).
Stage 1 runtime changes remain local and uncommitted while coordinated regression
is completed. This is not release or profitability acceptance. Continue the
authorized work; do not reapply archive patches or discard inherited changes.

## Current implementation

- Existing profitability analytics now use a bounded, single-statement PostgreSQL
  snapshot, exact decimal totals, valid account/exchange/strategy attribution,
  explicit invalid-link diagnostics and ten-row drill-down pagination.
- New Go signal intelligence and fenced `SIGNAL_MARKOUT` jobs evaluate immutable,
  hypothetical fixed-horizon outcomes; source timeframe and horizon stay separate.
  Laravel verifies complete provenance, request/assumption/policy hashes and worker
  manifests before append-only persistence. Cohorts preserve label-policy identity.
- Read-only horizon/profile APIs and bounded scheduler/recovery were added under
  existing boundaries. No new results authorize trading or promotion.
- ML training/inference/drift reject incomplete, immature, future or nonfinite
  evidence. Positive tests use explicit, scoped synthetic evidence.
- Final PAPER entry gates now recompute actual scoped/versioned historical evidence
  and TP1 execution costs. Incomplete funding cannot authorize an entry.
- Pipeline completeness requires exchange-assigned primary identities, current
  versions and parameter hashes; candidates/stale versions cannot substitute.
- Slippage generation is exchange/symbol scoped, immutable-retry safe and UTC
  normalized. Empty or unrelated generations cannot claim selected-scope verification.
- New portfolios outside the original explicitly approved Bybit cohort start
  `BLOCKED`, zero-funded and `READINESS_PENDING`. Discovery neither activates nor
  funds them. Existing accounts and historical rows are preserved. Tests distinguish
  these placeholders from explicitly funded original Binance accounts.
- Policy comparison reports descriptive results, never scientifically verified
  profitability from caller-supplied sample rows. Known negative expectancy remains
  denied with a precise reason; unavailable evidence remains a separate denial.
- Imported style defects were formatted. CI builds the new integration CLI and
  explicitly runs its PostgreSQL worker tests; mandatory checks/deployment approval
  remain intact.

See `SIGNAL_INTELLIGENCE.md` for contract, configuration, migration and limitations.

## Executed local evidence

Durable working artifacts are outside the repository under
`/home/thunder/work/.vector-apo-evidence-20261112/`. Selected redacted raw evidence
must be copied into version control before the implementation commit. Earlier
uncommitted `/tmp` evidence was lost on environment restart; it must not be the
only basis of acceptance. The committed Stage 0 evidence remains available.

Current authentic results (overlapping suites; do not add totals):

| Evidence | Result |
|---|---|
| `root/stage1-final-focused2.*` | 40 tests / 442 assertions passed |
| `root/quality-policy-iteration3.*` | 58 / 2,470 passed |
| `root/discovery-safety-iteration2.*` | 7 / 238 passed |
| `ml/research-regression-final.*` | 555 / 4,589 passed, zero skips |
| `ml/trading-regressions/final-trading-regression.*` | 73 / 951 passed, zero skips |
| `quant/php-regressions/expanded-current-regression.*` | 311 / 4,058 passed, zero skips |
| `quant/registry-final-20261112T0910/` | Full Go race: 110 top-level tests + 100 subtests, 16 packages; vet/build passed; no skips |
| `root/frontend-unit.*`, `frontend-build.*` | 99 frontend tests, typecheck and build passed (Node 22.23.4) |
| `root/ci-python.*` | 21 CI regression tests passed |
| `timeframe/acquisition-preservation-verification.json` | Exact existing pipeline-event preservation; append/mutation controls detected injected defects |

PHP runs use PHP 8.5.12 with isolated PostgreSQL 17/Redis 7.4. Synthetic fixtures
are not actual PAPER profitability. Standalone Go benchmarks are local CPU
measurements, not VPS or end-to-end performance acceptance.

## Coordinated full regression in progress

`timeframe/full-phpunit-1.*` captured 1,664 tests / 20,280 assertions with
21 errors, 41 failures and one preexisting optional public-API skip. Subsequent
focused suites repaired those failures; the frozen failing evidence is retained.

Agent `/root/apo_paper_finish` is running `timeframe/full-phpunit-2.*` against a
new frozen source and current static Go binaries on owned PostgreSQL port 50083
and Redis 44723. Inventory: 1,677 tests, no exclusions. Source manifest digest:
`730b828f6bbbd598036903064425b90674581a732202649fcdf34a8b9b8f47fe`.
Two first failures were stale unit capital expectations; root corrected only
`R8CapitalPolicyTest` and `GlobalPaperCapitalGuardCanonicalRiskTest`, preserving
the funded-BLOCKED risk-budget negative control. Their focused verification is
`root/discovery-unit-closure.*`. The frozen full run must continue unchanged.

Root task harness: `root/harness.py`, isolated source `root/source`, owned
containers `vector-apo-root-pg-20261112` / `vector-apo-root-redis-20261112`.
Read current dynamic ports from Docker inspection. Never share a mutable test DB
between concurrent suites. Go 1.27.3 toolchain: `quant/toolchain/go`.

## Work still required

1. Complete full regression; resolve every actual failure without weakening tests.
   Finish PHP syntax/Pint/diff checks, collect current source hashes and retain
   evidence. Update tracker metadata/history without inventing acceptance credit.
   Commit verified Stage 1 changes locally, preserving the user's staged `.gitignore`.
2. Finish remaining Stage 1 metric/reporting gaps against the full specification;
   observed horizon markouts are not new live signal engines or full UI integration.
3. Stage 2 remains incomplete: chronological purge/embargo across existing research
   splits, mixed regime attributes, constrained research objectives and the runtime
   consumer for the bounded exchange-specific timeframe analyzer need integration.
4. Stage 3 operational champion promotion/rollback is still deliberately refused
   where reversible configuration restoration and complete independent evidence are
   absent. Implement safe lifecycle integration; do not enable policy flags or
   override owner blocks as a shortcut.
5. Stages 4–6 still require final sizing-boundary review, capital/portfolio controls,
   dashboard/export integration, end-to-end recovery/security/performance validation
   and readiness reporting. Registration of ten venues does not establish data or
   PAPER execution readiness on them.
6. No push, production deployment/migration, real order, owner-account unblock or
   ECONOM change is authorized. Forward PAPER validation and demonstrated profitability
   remain separate, currently unproven milestones.
