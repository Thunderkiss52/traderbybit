# VECTOR progress history

Scope baseline: `VECTOR-SCOPE-20261027-v1` (proposed weights). No comparable prior measured score exists. Informal percentages from earlier discussions are not baselines.

| Snapshot | Source assessment HEAD | Implementation | Verification | Product | Comparison |
|---|---|---:|---:|---:|---|
| [Initial, 2026-10-27](history/initial.json) | `d9a0aeb5d14233fc95e9e5cc55d8748814362f59` | 42.2882% | 0.0000% | 0.0000% | NO_COMPARABLE_SNAPSHOT |
| [Phase 0 evidence, 2026-10-27](history/phase0-evidence-20261027.json) | `c8d51156b2c72b03f009d10e6a634ff80cff77ff` | 42.2882% | 4.1270% | 0.0000% | Same scope; verification +4.1270 points |
| [Phase 0 integrity remediation, 2026-10-29](history/phase0-integrity-20261029.json) | `375d7ecc33873d21bde877f70f1e7313225c3384` | 0.0000% | 0.0000% | 0.0000% | Prior evidence stale/regenerated; local regressions add no product acceptance |

97 requirements: 45/8/10/11/10/8/5 in Phases 0–6. F01–F27 and I01–I09 are individual assurance rows, excluded from duplicate runtime implementation credit. Phase 6 is documentation only and excluded from runtime A.

## Source reconciliation

`a12bac4` → `236e5ea` (architecture documents) → `fa31ad0` (26-account pipeline fixtures and read-only diagnostic SQL) → `d9a0aeb` (statistical-bootstrap lifecycle fixtures and SQL refinement). Git ancestry was checked. Subsequent commits added tests/diagnostics, not new strategy runtime behavior. Source assessment therefore reused architecture extension points but did not inherit old verification claims.

The latest tests exercise 13×2 account provisioning, unapproved Binance entry rejection, support/resistance sample gating, forecast maturation without trading activity, and exchange population isolation. They use mutation-based fixtures and were not run in this tracking assignment. No production approval/configuration, actual forward sample count, balance or funding total was queried. The current source keeps Binance approval default false; this is not proof of deployed configuration. Current promoted-account rollback still explicitly reports unsupported.

The committed remediation report's section 10 records F09/F25 local completion and prior test totals. Earlier partial rows coexist in that report. The tracker preserves both as reference context and awards no independent closure without mapped current execution evidence. All 229 verification obligations are initially unavailable; this does not mean historical tests failed. Product acceptance is absent, not inferred from documentation or commits.

## Future entries

Never overwrite existing snapshots. After an authorized stage, append a uniquely named JSON report under `history/`, link it here and record source/registry hashes, scope ID, actual scores, delivery delta or SCOPE_CHANGED, changed requirement IDs and blockers. Compare with `python3 scripts/vector_progress.py --compare <prior-snapshot>`. Scope changes require an approved new baseline and preserve old denominators. Code/evidence invalidation may lower scores; record its reason rather than hiding the decrease.

This first snapshot measures platform requirements only. The completion tracker itself is not added to the product denominator, so its implementation cannot inflate the platform score.

## Phase 0 evidence packet finalized after session recovery

The continuation inherited the staged 2026-10-27 packet at `c8d51156b2c72b03f009d10e6a634ff80cff77ff`. It verified retained command hashes, JUnit identities, source fingerprints and unchanged scope before reusing the executed suites. The existing immutable snapshot above was preserved; recovery adds no verification credit and does not duplicate that snapshot.

All 45 Phase 0 requirements are mapped, including F01–F27 and I01–I09. Eighteen local obligations pass; one performance obligation fails; 80 remain unavailable. Production acceptance and Phase 1 remain blocked. The [closure report](PHASE0_EVIDENCE_CLOSURE_20261027.md) and [matrix](evidence/phase0-20261027/MATRIX.md) distinguish missing local assertions from operator evidence and give the remaining procedures. This records evidence review completion, not completion of all local coverage or Phase 0 acceptance.

## Phase 0 Local Acceptance Closure & Roadmap V3 Transition

On 2026-10-28 at commit `a4b82ea04a3307cbbc29e966bcc0b250e0339caf`, Phase 0 local acceptance reached closure:
- 42 of 45 V1 local obligations passed on isolated PostgreSQL 17 (939 tests, 8873 assertions).
- 9 of 9 V3 browser obligations passed with authentic headless Chromium verification screenshots.
- 1 V1 performance obligation (`VEC-P0-009-V1`, Binance Bulk Ingestion Latency P95 = 52.161 ms > 50 ms) strictly retained as FAILED without altering threshold.
- 2 V1 obligations (`VEC-P0-003-V1` live census, `VEC-P0-008-V1` gate promotion) and 45 V2 production obligations preserved as UNAVAILABLE pending operator VPS execution.
- Scorecard metrics verified: Implementation 42.2882%, Verification 9.2857%, Product 0.0000% (51 CURRENT, 1 FAILED, 177 UNAVAILABLE).

Concurrently, the canonical [VECTOR Go-First Architecture Roadmap V3](../roadmap/VECTOR_GO_FIRST_ROADMAP_V3.md) and [Go Migration Registry](VECTOR_GO_MIGRATION_REGISTRY_V3.md) were codified, establishing a 9-phase sequence and a Migration Value Gate to transition core market data, strategy evaluation, risk, and PAPER accounting to Golang while retaining Laravel as a thin control plane. Frozen baseline `VECTOR-SCOPE-20261027-v1` remains permanently preserved.


## Phase 0 Financial Integrity Remediation

On 2026-10-29 the local source at `375d7ecc33873d21bde877f70f1e7313225c3384`
(based on deployed `5b674960adc8ff6591134537763dce7a5bb0bb3c`) corrected confirmed
Phase 0 code defects: Binance bootstrap equity, Bybit funding marks and retry scope,
pipeline terminalization/recovery, late-funding snapshot time, and the training
permission boundary. The isolated suite passed 973 tests (972 pass, 1 opt-in live API
skip, 9710 assertions); Pint, Python classifier/tracker tests, frontend tests,
typecheck and build passed.

Scores remain I=0.0000%, V=0.0000%, P=0.0000% because the registry correctly treats
earlier evidence as stale and no production V2 obligations were verified. All 45
Phase 0 production obligations remain PENDING. The 379 legacy Bybit positions remain
unclassified until per-position and independently validated exchange evidence is
provided; the four PARTIAL and 316 STALE rows require row-level production
disposition. No historical financial data was repaired. See
[remediation](evidence/phase0-production-acceptance/REMEDIATION_20261029.md) and
[validation](evidence/phase0-production-acceptance/VALIDATION_20261029.md).
