# Local APO research baseline — not release acceptance

The local baseline commit captures the frozen source tested in full iteration 3,
before the subsequent canonical sizing hardening and descriptive-metric extension.
Those later working-tree changes require their own verification. This commit does
not mark Stage 1, the remaining APO stages, production readiness or profitability
complete.

The full isolated PHP 8.5.12/PostgreSQL 17/Redis run executed all **1,677** discovered
test IDs: **22,233 assertions, zero errors, one failure, three warnings, one optional
public-API skip; exit 1**. The failure and warnings came from the test snapshot
omitting the tracked public `.env.production.example`. The corrected snapshot's
Compose control passed **6 tests / 23 assertions**. This follow-up does not turn
the failed full run into a green run. PHP lint passed 994 files and release-scope
Pint passed 990 files. The inherited untracked `AUDIT_CLOSURE` archive is preserved
outside the release source set.

Source manifest: `c6c9b878c91e1564e16a21a3fad14e4c5df8f62a66f66b8d2d6ec934250dcc2d`.
JUnit SHA-256: `e279e177096f45692e87951b351745b1f67332c17ef7fe18a8557416e49b1ea4`.
Raw results, commands, environment, source inventory and limitations are retained
under `evidence/stage1-20261112/timeframe/full-phpunit-3.*`.

The source includes bounded snapshot analytics, hypothetical horizon evidence,
fenced research jobs, scoped learning/slippage/pipeline corrections and conservative
account discovery. New accounts cannot inherit capital or activation solely from
venue registration. Historical financial records remain authoritative.

Known release blockers include the independently reproduced supplied-position-size
bypass and unset-sizing/API-default discrepancy, now being corrected in a separate
increment. Further research integrity, regime, promotion/rollback, sizing, dashboard,
export and end-to-end work remains. No new signal outcome grants entry authority.

No push, deployment, production migration, real exchange order, owner unblock or
ECONOM operation occurred. All test returns are synthetic; no forward profitability
is demonstrated. Tracker admission remains provisional I/V/P = 0/0/0 under unchanged
weights and stale prior fingerprints.
