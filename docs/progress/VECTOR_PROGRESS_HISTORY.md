# VECTOR progress history

Scope baseline: `VECTOR-SCOPE-20261027-v1` (proposed weights). No comparable prior measured score exists. Informal percentages from earlier discussions are not baselines.

| Snapshot | Source assessment HEAD | Implementation | Verification | Product | Comparison |
|---|---|---:|---:|---:|---|
| [Initial, 2026-10-27](history/initial.json) | `d9a0aeb5d14233fc95e9e5cc55d8748814362f59` | 42.2882% | 0.0000% | 0.0000% | NO_COMPARABLE_SNAPSHOT |

97 requirements: 45/8/10/11/10/8/5 in Phases 0–6. F01–F27 and I01–I09 are individual assurance rows, excluded from duplicate runtime implementation credit. Phase 6 is documentation only and excluded from runtime A.

## Source reconciliation

`a12bac4` → `236e5ea` (architecture documents) → `fa31ad0` (26-account pipeline fixtures and read-only diagnostic SQL) → `d9a0aeb` (statistical-bootstrap lifecycle fixtures and SQL refinement). Git ancestry was checked. Subsequent commits added tests/diagnostics, not new strategy runtime behavior. Source assessment therefore reused architecture extension points but did not inherit old verification claims.

The latest tests exercise 13×2 account provisioning, unapproved Binance entry rejection, support/resistance sample gating, forecast maturation without trading activity, and exchange population isolation. They use mutation-based fixtures and were not run in this tracking assignment. No production approval/configuration, actual forward sample count, balance or funding total was queried. The current source keeps Binance approval default false; this is not proof of deployed configuration. Current promoted-account rollback still explicitly reports unsupported.

The committed remediation report's section 10 records F09/F25 local completion and prior test totals. Earlier partial rows coexist in that report. The tracker preserves both as reference context and awards no independent closure without mapped current execution evidence. All 229 verification obligations are initially unavailable; this does not mean historical tests failed. Product acceptance is absent, not inferred from documentation or commits.

## Future entries

Never overwrite existing snapshots. After an authorized stage, append a uniquely named JSON report under `history/`, link it here and record source/registry hashes, scope ID, actual scores, delivery delta or SCOPE_CHANGED, changed requirement IDs and blockers. Compare with `python3 scripts/vector_progress.py --compare <prior-snapshot>`. Scope changes require an approved new baseline and preserve old denominators. Code/evidence invalidation may lower scores; record its reason rather than hiding the decrease.

This first snapshot measures platform requirements only. The completion tracker itself is not added to the product denominator, so its implementation cannot inflate the platform score.
