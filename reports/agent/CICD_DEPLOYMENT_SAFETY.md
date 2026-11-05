# CI/CD deployment safety

Production was not accessed or changed. No Git push, workflow trigger, GHCR publication, account reset, real order or ECONOM operation was performed. All database mutations and failure scenarios used task-owned local PostgreSQL/Redis or explicit test doubles. Hosted and production acceptance remain unverified.

The manual `.github/workflows/deploy.yml` retains the production environment, pinned SSH host key verification and `bybit-signals-production` mutex with cancellation disabled. It requires a full commit SHA equal to current main HEAD, selects a successful trusted main CI run for that SHA, checks its latest attempt for a successful **CI required** job, downloads that run's `verified-release`, validates SHA/run/repository provenance, and checks each registry tag against its recorded digest. API failures, missing jobs/artifacts and non-success conclusions fail closed. Main HEAD is checked again before transfer and immediately before invoking the approved server wrapper.

The archive contains only `compose.yaml`, `compose.proxy.yaml`, `docker/` and the verified manifest. Runtime PHP, frontend and Go artifacts come from prebuilt images. The existing installed wrapper verifies the checksum and invokes the extracted `update.sh` with `DEPLOY_MODE=pull`; no wrapper replacement or manual server patch is needed. The update script reads this dispatch's manifest from the checksummed incoming archive, including when its SHA release directory already exists. It validates every image repository against the existing production environment and stages an immutable per-SHA Compose overlay. A conflicting pin record stops before backup/pull/container transition; an approval of identical bits preserves the original record.

All ten application services are pinned: app, web, queue, queue-bybit, queue-binance, scheduler, market-ws, audit-worker, backtest-worker and research-worker. PostgreSQL and Redis definitions remain unchanged. The existing service inventory previously omitted the two exchange queues; both are now included in readiness, image identity, state metadata and rollback. Compose transitions explicitly name these services and use `--no-build --no-deps`; they do not remove orphans or operate on another project. Verified digest images retain local SHA tag aliases so the existing scoped cleanup preserves current and previous rollback images. Docker documents that digest-pulled images can be untagged, which is why this retention step is necessary. [Docker image removal reference](https://docs.docker.com/reference/cli/docker/image/rm/)

Backup verification, disk checks, the server deployment lock, safe forward migrations, Binance initialization's existing safety gate, readiness probes, dashboard/database/Redis/queue health checks, state validation, application rollback and current/previous retention remain enabled. Database migrations are never reversed automatically. Existing financial history and balances are not repaired or rewritten. Failed pulls cannot trigger a build fallback. Deployment records include the SHA and all three digests; the runner retains the verified manifest after successful deployment.

| Safety scenario | Local evidence | Hosted/production boundary |
|---|---|---|
| Failed/skipped/cancelled/missing verification job | Actual gate helper rejects every result and missing dependency; workflow contract checks all jobs are in `needs` | Real Actions scheduling not run |
| Missing/failed/wrong-SHA CI | Actual release guard rejects synthetic API responses | Authenticated repository API unavailable |
| Successful workflow missing aggregate | Rejected by actual guard | No legacy aggregate assumed |
| Stale main or untrusted branch/event | Rejected by guard; workflow rejects dispatch outside main | Production never contacted |
| Latest rerun attempt and paginated jobs | Guard tests follow the latest attempt and page through job results | Real repository API pending |
| Missing image, bad tag/digest, registry retag | Actual image script with Docker test double rejects them | GHCR network checks not executed |
| Wrong manifest run/SHA/repository | Actual provenance guard rejects them | Verified-release artifact transfer not exercised against GitHub |
| Cached release manifest drift or changed bits for same SHA | Real tar/checksum/staging test rejects changed pins and corrupt archive; same-bit approval is idempotent | No production archive processed |
| Pin selection during rollback | Real `docker compose config` resolves ten services for each selected SHA | No production rollback performed |
| Current/previous image retention | Actual helper test plus local digest-to-tag alias check | No production image cleanup performed |
| Concurrent deployment/state corruption | Existing recovery matrix and lock tests pass locally | Workflow mutex configured; production concurrency not exercised |
| Same-SHA configuration refresh | Existing isolated real Compose scenario passes; unrelated fixture DB container unchanged | Production environment not changed |
| No CD testing/install/build | Actual workflow contract test plus complete script/entrypoint trace | Manual build branch remains unreachable from wrapper's forced pull mode |

`CI required` uses `always()` and explicitly rejects every non-success dependency; this avoids treating a skipped matrix group as success. The eligibility artifact is insufficient by itself: CD also requires a completed successful workflow and successful aggregate in its latest attempt. [GitHub workflow dependency semantics](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)

Repository branch protection/rulesets must require **CI required**. Production environment approval, existing wrapper/sudo permissions and server read-only GHCR authentication must be verified by the operator. These settings were not changed. SHA tags are lookup aliases; deployment authority is the approved immutable digest manifest. A retagged or differently rebuilt already pinned SHA is rejected rather than silently replacing approved bits.

The 30-second target has not been measured. The timer covers SCP and the approved remote deployment path, including pull, backup, migrations, transition and health checks; full workflow latency also includes approval, scheduling and preflight. Image size/network, the existing compressed PostgreSQL backup, recursive storage ownership checks, worker stop grace periods and startup can exceed that target. None of these safeguards was removed to improve a timing claim.
