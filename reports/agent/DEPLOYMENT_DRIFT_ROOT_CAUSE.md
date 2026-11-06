# Deployment drift root cause

Baseline: `main`, `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`, equal to local `origin/main`; no staged/unstaged tracked changes or local commits at entry. Two inherited untracked files were preserved: `VECTOR_INDEPENDENT_AUDIT_20261031.md` and `VECTOR_RELEASE_HARDENING.patch`. No other tracked edits appeared before implementation. `rtk` was unavailable; the repository's raw-command exception was used. Docker Engine 29.7.1, Compose 5.4.0, Linux; PHP checks used the existing PHP 8.5.12 test image with no network.

## Exact failure mechanism

The original `docker/scripts/validate-deployment-state.sh`, container inspection block, executes `tag_part="${img##*:}"`, collects `UNIQUE_RUNNING_TAGS`, and sets `HAS_DRIFT=1` when the collection has more than one entry. The `post-deploy` branch then prints the incident's exact fatal message.

For `repository:sha-COMMIT@sha256:DIGEST`, that expansion returns **DIGEST**, not the SHA tag. The three application artifacts normally have three distinct registry digests. Eight app consumers share one suffix; web and research-worker supply the other two. Thus three correct component artifacts deterministically satisfy the fatal conditional. `recover-deployment-state.sh` duplicated the defect.

The original validator has been executed against ten real, correct, isolated containers. Its fatal output is retained in `deployment-remediation/original-validator-reproduction.log.gz`. The regression also independently compares every actual container image ID with Docker's resolution of its expected digest pin. This proves a defect sufficient to produce the reported failure; it does not establish whether additional drift coexisted in the historical production run.

## Execution path and identity contract

`Deploy Production` authorizes current main and the exact-SHA `CI required` aggregate; downloads the approved release artifact; verifies GHCR tag/index digests; archives `compose.yaml`, proxy configuration, `docker/`, and `ci-release.json`; transfers the archive/checksum; invokes the installed approved wrapper. The wrapper checks archive integrity, extracts the release, performs prevalidation and invokes `update.sh`. Update locks deployment, stages the approved digest overlay, prevalidates, backs up PostgreSQL, pulls three artifacts, verifies local images, migrates/initializes, transitions ten services, probes readiness, commits state and formerly cleaned images **before** final drift validation. Its error trap then attempted application rollback. The wrapper advances its release symlink only if update succeeds.

| Identity | Meaning | Fixed use |
|---|---|---|
| Container `Config.Image` | Creation reference: tag, digest pin, or image ID | Diagnostic evidence; string equality is not content identity |
| Container `.Image` | Platform-specific Docker image/config ID | Must equal Docker image inspect `.Id` for the approved reference |
| `RepoDigests` | Repository-qualified registry manifests/indexes known locally | Recorded by Docker; no cross-component or unordered-list equality assumption |
| OCI index digest | Multi-platform manifest collection | Resolve through Docker for the host platform |
| Platform manifest digest | One platform's config and layers | May differ from index digest and image ID |
| SHA tag / OCI revision | Source release identity | Manifest enforces exact SHA; pinned images must carry its OCI revision label |
| Resolved Compose image | Per-service approved target | Overlay resolves every one of the ten services; infrastructure is excluded |

The fixed validator resolves each expected image from the release's Compose overlay, resolves its local Docker ID, compares the running ID, verifies exact-SHA OCI revision for pinned releases, and rejects missing or non-ready services after deployment. Recovery uses the same identity helper. Digest parsing errors and unavailable images fail closed. Equivalent creation references pass only when their image content resolves to the approved ID.

## Ten-service real fixture evidence

These are **isolated local values**, not historical production values. Each expected reference includes the repository, exact immutable tag and digest; Compose and creation references were identical in this valid deployment. The original check fails globally because the suffix set has three values. The fixed check passes. Exact identities are retained in `deployment-remediation/isolated-identities.json`.

| Service | Expected = resolved Compose = actual Config.Image | Actual Docker image ID |
|---|---|---|
| app | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| web | `127.0.0.1:59866/vector-drift-be9e7b8649/web:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:a294aa48e8117a47a05f3a022c30bba940f8a214a9b503b1c2044c3458522d29` | `sha256:a33c28225eb3d2596f1f168ecbaa7169a8a282b76e175df0dde9ebe6a0081512` |
| queue | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| queue-bybit | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| queue-binance | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| scheduler | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| market-ws | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| audit-worker | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| backtest-worker | `127.0.0.1:59866/vector-drift-be9e7b8649/app:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:6c6623d2592cdeb6bc0b2ffb84bfc55aeb3150f2bcc47298a7c0170c89195022` | `sha256:d80b64c2fe9ce3d0e9a24051b21d5c224a3e5aae2d38ab861a7f12dcb92d4120` |
| research-worker | `127.0.0.1:59866/vector-drift-be9e7b8649/research-worker:sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca@sha256:9951ae105e2686eaffda657db94dffd9be06d619bfaedcb6921e9efc63847668` | `sha256:dd71f0481cd7042faa606ac2034c50dc2a480188e0bf2fef0296166c70a6e584` |

## Historical production evidence boundary

Owner-supplied incident: run `42110252554`, job `127535611277`, target `832de9b5c2ff5c25351c25fa2e110a78a5f574ca`, rollback `129c33c933a688cbc7a5e372d2ea41504a6f677a`, duration 335 seconds. The unauthenticated GitHub log endpoint returned HTTP 404; no authenticated GitHub connector/CLI was available. Production access was expressly prohibited and was not attempted.

Expected production repositories follow the approved CI manifest's normalized repository `ghcr.io/thunderkiss52/traderbybit/`; exact digest values, resolved runtime definitions, historical `Config.Image`, image IDs, RepoDigests and index/platform manifests were not supplied. They cannot be reconstructed honestly from the SHA alone.

| Production service | Expected repository suffix | Expected tag | Historical digest / Compose / Config.Image / image ID |
|---|---|---|---|
| app | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| web | `web` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| queue | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| queue-bybit | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| queue-binance | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| scheduler | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| market-ws | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| audit-worker | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| backtest-worker | `app` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |
| research-worker | `research-worker` | `sha-832de9b5c2ff5c25351c25fa2e110a78a5f574ca` | Unavailable; no VPS access |

Source/reproduction establish incorrect digest-suffix comparison, plus premature cleanup/state advancement. They do not prove genuine image drift, overlay mismatch, partial rollout, recreation race or cleanup-caused image loss in the original production containers. Owner-reported availability after rollback remains attributed to the owner, not independently verified financial reconciliation.

## Changed files

`common.sh`: shared identity, exact schema guard, readiness/lock interruption handling, timings and worker observer orchestration. `validate-deployment-state.sh` and `recover-deployment-state.sh`: replace suffix comparison with per-service identity. `update.sh`: acceptance ordering, guarded rollback/journal, infrastructure preservation, timing events. `deploy.sh`/`rollback.sh`: matching identity/acceptance checks. `cleanup-project-images.sh`: protected IDs and exact repository scope. Backup/workflow/timing helpers: structured stage measurements. Regression fixtures exercise actual validators and update scripts; only external CLI boundaries are injected for destructive failure scenarios.

No push, deployment, Actions trigger, VPS access, financial repair, exchange order, account funding or production mutation is authorized or performed.
