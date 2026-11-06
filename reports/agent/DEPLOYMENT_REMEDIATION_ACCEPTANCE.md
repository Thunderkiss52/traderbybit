# Deployment remediation acceptance

Status: local remediation and performance follow-up verified and committed. Production acceptance remains unverified.

Baseline: `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`, main equal to origin/main. Inherited untracked audit/patch files were preserved. A concurrent external edit to AGENTS.md was reviewed and preserved outside task commits; it introduces trailing whitespace, so the global diff check is not clean. Task-owned diff checks pass.

Root cause, identity evidence and limits: [DEPLOYMENT_DRIFT_ROOT_CAUSE.md](DEPLOYMENT_DRIFT_ROOT_CAUSE.md). Rollback, retention and migration contract: [DEPLOYMENT_ROLLBACK_SAFETY.md](DEPLOYMENT_ROLLBACK_SAFETY.md). Timings, observed optimizations and unmeasured production bottlenecks: [DEPLOYMENT_PERFORMANCE_ANALYSIS.md](DEPLOYMENT_PERFORMANCE_ANALYSIS.md).

Local evidence is retained under deployment-remediation/. The original validator rejects ten valid real digest-pinned containers; the fixed validator accepts them and rejects each of ten wrong-image injections. Equivalent tag/image-ID references and OCI index/platform manifest identity are verified with real Docker. Real shared-layer image retention/rollback mapping, exact schema negatives and backup creation are included. Failure-injection tests execute the real deployment scripts with explicit Docker/curl boundary fixtures; they do not execute production Laravel processes. No assertion claims those CLI fixtures are full production integration.

Application source, financial rules, migrations, account history, risk limits and real-order restrictions were not changed. The isolated recovery matrix contains 26 synthetic account rows, 13 per exchange, including three BLOCKED rows. Their hashes are preserved; no actual forward-PAPER returns, funding completeness, SKIP statistics or financial reconciliation were measured. Full application PHP/Go suites and live browser/production verification are outside this deployment-only regression run; frontend tests/build and deployment PHP tests were run.

The required three iterations consist of (1) original-validator reproduction and identity fix, (2) update/recovery failure injection and schema/retention/timing checks, (3) combined regressions and build/lint review. Early failed fixture attempts are retained, including an empty REDIS_PASSWORD shadowing the Compose example and a test overlay masking the intended worker transition; both fixture errors were corrected and specifically retested.

Progress registry VEC-P0-007 / VEC-P0-008 notes and the immutable history snapshot are updated without awarding frozen production obligations. Before/after implementation, verification and product scores remain 0/0/0 because broader evidence is stale/unavailable; local deployment tests do not fabricate platform acceptance.

Production restrictions remain explicit: no push, deploy, Actions trigger, VPS access, financial repair, account funding, promotion bypass, live trading or ECONOM modification. Owner-reported rollback availability is historical input only. Production digest/image-ID evidence, real GHCR/VPS timings, schema compatibility approval and full financial acceptance remain unverified.

Core verification: Python discovery 81/81 passed (396.381 seconds), including ten real Docker integration tests and twelve update-script failure tests. Existing shell regressions passed: Compose resolution 9 cases, immutable pull 5, workflow gate 7, recovery matrix 12 scenarios, real same-SHA reconciliation. PostgreSQL dump/schema controls passed. Deployment PHPUnit 12 tests / 46 assertions passed; Pint 855 files passed; PHP lint and frontend 76 tests/typecheck/build passed. actionlint 1.7.7 and task-owned diff checks passed. Commit hashes are appended after local commits.

Local tooling: Docker Engine 29.7.1 / Compose 5.4.0; PHP 8.5.12 in the existing network-isolated test image; Node 20.20.0 for the unchanged frontend (project CI targets Node 22, so this local build does not certify the Node 22 lane). PHP lint covered 835 files; Pint covered 855.

Performance fixture follow-up: restarting a random-published-port registry changed its port and broke later registry-dependent checks. The final fixture uses a reserved loopback port, a task-owned directory and explicit readiness after restart. Earlier failing output remains retained. Raw test logs are compressed without content changes to preserve diagnostics while allowing source diff whitespace checks.

## Final execution results

| Executed command | Result |
|---|---|
| `VECTOR_DEPLOY_EVIDENCE=reports/agent/deployment-remediation python3 -m unittest discover -s scripts/tests -p 'test_*.py'` | 82 passed, 369.448 seconds, no skips; includes 11 real Docker integration and 12 update-script test methods |
| `python3 scripts/tests/run_deployment_regressions.py` | Exit 0; three real PostgreSQL backup/schema controls plus five shell suites all passed; individual exit codes retained in shell-regressions.json |
| PHP 8.5.12 test container: `vendor/bin/phpunit tests/Unit/DeploymentStateValidationAndRecoveryTest.php` | Exit 0 / OK, 12 tests, 46 assertions |
| PHP 8.5.12 test container: `vendor/bin/pint --test` | Exit 0, 855 files |
| PHP 8.5.12 test container: PHP lint | 835 files, no syntax errors |
| `cd frontend && npm test` / `npm run build` | Exit 0, 76 tests; typecheck and production build passed on Node 20.20.0 |
| actionlint 1.7.7 / all deployment and PostgreSQL shell syntax | Exit 0 |
| Progress generation / strict against starting SHA and core fix | Exit 0; snapshots remain append-only and scores stay 0/0/0 |
| Task-owned staged and baseline diff checks | Passed after byte-preserving log compression |

Raw stdout/stderr is retained as `.log.gz`; checksums are listed in deployment-remediation/SHA256SUMS. Early failed fixture runs remain available and are not counted as passing final runs. No application source, Dockerfile, Compose runtime configuration, migrations or financial rules changed.

## Acceptance criteria

| Criterion | Local outcome and boundary |
|---|---|
| Original failure | Exact original validator reproduces the fatal suffix comparison on correct real containers |
| Valid pins / wrong images | Valid release passes; wrong image fails separately for all ten services |
| All ten mappings | Overlay, identity, readiness, retention and rollback mapping covered |
| Previous known-good release | Actual shared-layer artifact retention plus real ten-container rollback mapping; full update trap tested with CLI failure injection |
| PostgreSQL / Redis preserved | Existing production services never accessed; update cannot recreate them; isolated fixture hashes preserved |
| Cleanup safe | After acceptance only; current/previous IDs and aliases protected; unknown dangling artifacts not deleted |
| Timing | Structured monotonic stage events, distinct runner/remote/transition durations, observed worker grace |
| Performance | Unconditional restart removed; digest-cache reuse verified online/offline; only local sample timings claimed |
| Regression | Final local tests above pass |
| Production changes | None; no VPS access, push, deploy, workflow trigger, account repair/funding or ECONOM changes |
| Local commits | Core fix and separate performance commit listed below |

## Local commits

- `13dc72f0018522e3297b312a41b45033bb07143c` — correct immutable image drift validation, rollback safety and timing foundation.
- `d8211c75fce3ef1578b6fab0087d68e496b080c8` — reuse verified digest cache, retain strict tag-only pulls and complete performance regression coverage.

The final evidence/report commit contains these reports and byte-preserving compressed logs. It does not change deployment behavior. The externally modified AGENTS.md and the two inherited untracked files remain outside task commits.

Before any later owner-authorized production deployment: obtain the historical release manifest/runtime identity packet if incident-level attribution is required, verify canonical deployment metadata against the known-good rollback release, review any changed-schema rollback constraints, and use only the established manual GitHub Actions workflow. Financial reconciliation and actual VPS timing require separately authorized evidence; basic HTTP/Docker availability is insufficient.
