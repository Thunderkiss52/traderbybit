# Mandatory post-implementation independent audit, remediation and archive handoff

This document supplements `SPECIFICATION_EN.md` and is an explicit **acceptance gate** for Codex. This is a REQUIREMENT to execute after completing all stages, not a statement that they have been completed.

## Procedure (do not stop after the first pass)

1. Verify the actual repository HEAD, staged/unstaged changes, migration history and every stage's evidence. Do not mistake this archive's historical `vector-git-history.txt` for a live Git repository. Compare against the current actual Git HEAD before applying any patch.
2. Map every numbered specification requirement, API, exchange, instrument, 13 PRIMARY strategies, source timeframe and outcome horizon to implementation and executable tests. Mark each as VERIFIED / DEFECT / MISSING / NOT_VERIFIED / BLOCKED. Reference code paths and test IDs.
3. Conduct an independent, adversarial re-audit. Check paper-only hard boundaries, account/exchange/strategy isolation, immutable ledger, 5 USDT fixed NOTIONAL (not margin), hard risk limits, owner-block persistence, data leakage and future candles, fee/funding/slippage economics, rejected vs executed signals, real 1m/3m/5m/15m/30m/60m signal generation, model training/promotions/rollback, resource bounds, error/empty response semantics, and UI/API/export reconciliation.
4. Implement fixes for newly found defects and re-run the affected tests and regression suites. Review whether fixes introduced new defects; repeat until no known CRITICAL/HIGH issues remain. Never hide a blocker, replace an unknown metric with 0, weaken tests or relax safety constraints merely to obtain a green result.
5. Required integration validation in a completely isolated environment: full PHPUnit on a disposable PostgreSQL database, migrations, Redis and concurrency recovery, full Go tests/race/build, frontend unit/typecheck/build and browser E2E, 10-exchange readiness, multi-instrument ledger reconciliation and performance benchmarks. Use read-only production checks only if explicitly authorized. Do not assume production tests succeeded because fixture-level checks passed.
6. Audit all actual HTTP endpoints, pagination, input validation, access control, errors, security boundaries, malformed datasets, network retries and exhausted resources; verify that linked results have consistent provenance.
7. Produce evidence-backed `FINAL_POST_IMPLEMENTATION_AUDIT.md` with numbered findings, severity, reproduction, root cause, patch, tests run and outcome. Record all exact commands/versions, failing output and unresolved issues. Separate SOURCE_FIXED from INTEGRATION_VERIFIED and FORWARD_PROVEN.
8. Prepare a **ready-to-apply archive** named `VECTOR-APO-AUDITED-<version>.tar.gz`. Include the full updated source tree (without build caches, secrets, vendor, node_modules), a relative V7→final unified patch (or live-repo base commit→final patch), file SHA-256 manifest, reproducible test results, requirements matrix, stage commit list, migration/rollback notes, and audit report. Verify archive SHA-256, member path safety, extraction and patch applicability before handing it to the owner.
9. Create local commits after verified implementation stages when a real Git checkout is available. Do not invent commits if only a snapshot without `.git` is supplied. Do not push, deploy, send real exchange orders, touch ECONOM or alter historical PAPER balances. Wait for a separate explicitly authorized manual deployment after green CI.

## Release condition

Only label the final archive `READY_FOR_MANUAL_DEPLOY_REVIEW` if every critical feature and safety requirement is integration-verified and no unaccepted HIGH/CRITICAL defect remains. Otherwise label it `NOT_RELEASE_READY`, list every blocker and still deliver the inspected archive and patch.

Commercial profitability is *not* a software acceptance criterion unless supported by independently pre-registered, long-horizon PAPER forward evidence. Never present research/backtest PnL as executed PAPER PnL.
