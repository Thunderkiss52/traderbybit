# Deployment remediation acceptance

Status: core local remediation verified; production acceptance is not claimed.

Baseline: `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`, main equal to origin/main. Inherited untracked audit/patch files were preserved. A concurrent external edit to AGENTS.md was reviewed and preserved outside task commits; it introduces trailing whitespace, so the global diff check is not clean. Task-owned diff checks pass.

Root cause, identity evidence and limits: [DEPLOYMENT_DRIFT_ROOT_CAUSE.md](DEPLOYMENT_DRIFT_ROOT_CAUSE.md). Rollback, retention and migration contract: [DEPLOYMENT_ROLLBACK_SAFETY.md](DEPLOYMENT_ROLLBACK_SAFETY.md). Timings, observed optimizations and unmeasured production bottlenecks: [DEPLOYMENT_PERFORMANCE_ANALYSIS.md](DEPLOYMENT_PERFORMANCE_ANALYSIS.md).

Local evidence is retained under deployment-remediation/. The original validator rejects ten valid real digest-pinned containers; the fixed validator accepts them and rejects each of ten wrong-image injections. Equivalent tag/image-ID references and OCI index/platform manifest identity are verified with real Docker. Real shared-layer image retention/rollback mapping, exact schema negatives and backup creation are included. Failure-injection tests execute the real deployment scripts with explicit Docker/curl boundary fixtures; they do not execute production Laravel processes. No assertion claims those CLI fixtures are full production integration.

Application source, financial rules, migrations, account history, risk limits and real-order restrictions were not changed. The isolated recovery matrix contains 26 synthetic account rows, 13 per exchange, including three BLOCKED rows. Their hashes are preserved; no actual forward-PAPER returns, funding completeness, SKIP statistics or financial reconciliation were measured. Full application PHP/Go suites and live browser/production verification are outside this deployment-only regression run; frontend tests/build and deployment PHP tests were run.

The required three iterations consist of (1) original-validator reproduction and identity fix, (2) update/recovery failure injection and schema/retention/timing checks, (3) combined regressions and build/lint review. Early failed fixture attempts are retained, including an empty REDIS_PASSWORD shadowing the Compose example and a test overlay masking the intended worker transition; both fixture errors were corrected and specifically retested.

Progress registry VEC-P0-007 / VEC-P0-008 notes and the immutable history snapshot are updated without awarding frozen production obligations. Before/after implementation, verification and product scores remain 0/0/0 because broader evidence is stale/unavailable; local deployment tests do not fabricate platform acceptance.

Production restrictions remain explicit: no push, deploy, Actions trigger, VPS access, financial repair, account funding, promotion bypass, live trading or ECONOM modification. Owner-reported rollback availability is historical input only. Production digest/image-ID evidence, real GHCR/VPS timings, schema compatibility approval and full financial acceptance remain unverified.

Core verification: Python discovery 81/81 passed (396.381 seconds), including ten real Docker integration tests and twelve update-script failure tests. Existing shell regressions passed: Compose resolution 9 cases, immutable pull 5, workflow gate 7, recovery matrix 12 scenarios, real same-SHA reconciliation. PostgreSQL dump/schema controls passed. Deployment PHPUnit 12 tests / 46 assertions passed; Pint 855 files passed; PHP lint and frontend 76 tests/typecheck/build passed. actionlint 1.7.7 and task-owned diff checks passed. Commit hashes are appended after local commits.
