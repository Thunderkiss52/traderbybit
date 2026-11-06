# Deployment performance analysis

## Measurement boundary

The owner reports 335 seconds for failed Actions run `42110252554`. Stage-level production timings and GHCR compressed bytes were unavailable. The log API returned 404 and VPS access was prohibited. No claim attributes the 335 seconds to pulls, nor claims a new production deployment duration.

Deployment logs now emit `VECTOR_TIMING` JSON events and `VECTOR_TIMING_SUMMARY`. Linux `/proc/uptime` / CLOCK_BOOTTIME provides monotonic elapsed time. Separate events cover runner checkout/preparation, GitHub release validation, artifact download, registry verification, archive/SSH preparation, SCP transfer, SSH remote total, remote lock acquisition, prevalidation, disk preflights, PostgreSQL dump creation, dump verification, image pulls, local image verification, migrations, financial initialization, container transition, health/readiness, final image validation, cleanup and rollback. Failed commands retain their nonzero timing status. Backup timing never enters the dump stream.

Worker shutdown/grace is observed separately through Docker kill/die events filtered to the exact Compose project, timestamped on receipt with a single host's monotonic clock. It records service-specific durations and a maximum, not a sum of overlapping waits. `NO_SHUTDOWN_OBSERVED` means the observer has no shutdown measurement (elapsed time is null); observer failure is `UNAVAILABLE`; it is not proof that a failed observer saw every event. The complete Compose transition timer remains authoritative for total transition time.

`runner_wall_to_final_reporting` includes tests of release eligibility, artifact verification, transfer, remote work and reporting after runner allocation. GitHub start/queue metadata is logged separately; Actions supplies the final completed workflow duration. `service_transition_to_acceptance` covers only application transition through health/identity acceptance. Neither is presented as the other. VM provisioning before the first runnable step is not locally observable. The installed wrapper's archive/prevalidation overhead is included in SSH remote total; individual internal update stages are separately measured.

## Verified local improvements

Compose already reconciled changed image/configuration identities without `--force-recreate`. It was followed by an unconditional `compose restart web`, forcing a web interruption even for a repeated unchanged SHA. That restart was removed from update, bootstrap and rollback. Existing nginx uses Docker DNS with `resolver 127.0.0.11 valid=5s`, so an app address change does not require a forced web restart. Full operational readiness checks remain mandatory.

Real isolated Compose tests demonstrate unchanged container IDs for the same configuration; a research image change recreates only research-worker; an app artifact change recreates all eight app consumers while preserving web and research-worker. A separate real same-SHA configuration-refresh regression changes the affected service while retaining the unaffected database fixture. No filename-based change classifier was added. All ten service identities are still checked.

The retained local Alpine sample measured 336 ms for no-op Compose reconciliation and an additional 1,513 ms for the redundant web restart. These are local synthetic-service measurements, not VPS or full-workflow timings; `deployment-remediation/transition-benchmark.json` is the authoritative current sample. Production graceful-stop limits were not reduced. Health polling uses actual running/healthy state and monotonic deadlines; restarting, paused, unhealthy and stopped containers do not pass readiness.

## Digest-cache pull optimization

A complete exact-SHA overlay for precisely the ten application services authorizes `compose pull --policy missing`. Locally cached digest-addressed artifacts do not need another registry request; missing pins still pull and fail on registry/pull errors. Mutable/legacy tag-only definitions retain `--policy always`. Local image inspection, OCI revision checks, alias retention and post-deployment ID checks still run; no image is trusted merely because a tag exists.

The real registry test proves cached pins succeed while the registry is stopped, while an absent digest and tag-only references still fail. Reading the already-staged complete final overlay avoids redundant Compose resolution. The latest sample measured 301 ms for always-pull and 268 ms for digest-cache reuse; values are retained in `deployment-remediation/pull-benchmark.json`. These use local cached Alpine artifacts, not GHCR or the VPS. They prove local behavior and sample timing, not a production speed guarantee.

## Images, layers and remaining bottlenecks

CI already uses per-target BuildKit GHA caches and dependency-first COPY steps. Pulling only app/web/research-worker reuses shared layers across the eight app consumers. Exact-SHA revision labels change image/config identity on every commit even when filesystem layers are unchanged. Consequently a documentation-only SHA can legitimately change all artifact digests; exact-SHA provenance prevents classifying those services as unchanged merely from filenames. No provenance labels, immutable digests or release gates were removed to obtain fewer restarts.

The app target bundles frontend assets as well as PHP and Go helper binaries. A frontend change can therefore legitimately affect both app and web artifacts. Vendor optimization consumes application/database sources, so invalidation merits future measured build analysis, not an unverified Dockerfile restructuring here. Actual GHCR compressed artifact sizes, transfer throughput, layer-cache hit rates and VPS disk bandwidth remain unmeasured. Backup compression, worker drain time and serial readiness can be significant, but their production contribution is unknown until an authorized instrumented deployment.

## Optional pre-pull proposal; not implemented

An independently approved, manually dispatched preparation workflow could validate exact-main CI provenance, pull digest-pinned artifacts under the deployment lock, verify OCI revision and cache identity, and retain at most current/previous plus one pending release subject to disk bounds. It must not execute those images, recreate containers, mutate the database, activate a release, bypass the final manual deployment gate, or clean other projects. Cleanup would use explicit release ownership. Pre-pulling moves network work earlier; it does not erase its cost or prove lower total workflow time. No autonomous VPS mutation or new workflow was implemented.

No push/deployment/Actions trigger/VPS access was performed. Local commit hashes and final regression results are recorded in DEPLOYMENT_REMEDIATION_ACCEPTANCE.md.
