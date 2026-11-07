# GitHub Actions deployment — one-time setup and checks

The release workflow is split into two connected stages:
1. **`Build`** (`.github/workflows/ci.yml`): Runs automatically on `push` to `main` (and PRs). Executes all tests, policy verifications, static analyses, and builds/publishes immutable SHA-tagged and digest-pinned images (`app`, `web`, `research-worker`) to GHCR. Its **`CI required`** aggregate gate verifies all mandatory job groups, complete PHP case inventory, isolated service identities, and publishes a verified `release-manifest.json` with direct cross-linking to trigger deployment.
2. **`Deploy`** (`.github/workflows/deploy.yml`): Manual operator release triggered via `workflow_dispatch` (`Actions → Deploy → Run workflow`). It authorizes current `main`, verifies exact-SHA CI approval, validates GHCR registry image digests against the cryptographic manifest, transfers the release bundle to the VPS, and invokes the host deployment wrapper. Production CD executes zero tests, builds, or dependency installs. Deployment is strictly manual; automatic deployment is prohibited by project policy.

## Before first click (one-time server setup)

The workflow **does not provision** SSH, the server deploy wrapper, or the production secrets. Set these up once on the VPS, where the existing paper-trading deployment is already running:

1. Ensure `/srv/bybit-signals/.env.production` exists with secure permissions (`chmod 600`) and the existing Docker Compose stack is healthy. **Do not overwrite the production environment, database, or volumes.**
2. Configure `APP_IMAGE=ghcr.io/<owner>/<repo>/app`, `WEB_IMAGE=ghcr.io/<owner>/<repo>/web`, and `RESEARCH_WORKER_IMAGE=ghcr.io/<owner>/<repo>/research-worker` in `.env.production`, matching the lowercased GitHub repository name used by the workflow. Set `DEPLOY_MODE=pull`. **Do not change `IMAGE_TAG` to an unavailable GHCR tag.**
3. The server must be able to pull all image packages from GHCR. Either make the GHCR packages public, or authenticate the server Docker daemon with a token limited to `read:packages`. Test pulling a previously published SHA tag before enabling regular releases. Do not put registry tokens in the repository.
4. Create `/srv/bybit-signals/incoming`, `/srv/bybit-signals/releases`, and `/srv/bybit-signals/deploy-state`. Ensure the deploy user can upload archives to `incoming` and that the deploy wrapper can read those archives and the protected `.env.production`.
5. Install `docker/scripts/production-deploy-wrapper.sh` from the reviewed repository as `/usr/local/sbin/bybit-signals-deploy`, owned by root and executable. The workflow runs `sudo -n /usr/local/sbin/bybit-signals-deploy <40-hex-commit-sha>`. Use a narrowly scoped `sudoers` entry for this exact script, not unrestricted sudo. A Docker-capable deployment user is effectively privileged: review this security boundary carefully.
6. The wrapper expects an **already initialized** Docker Compose deployment because `update.sh` creates a PostgreSQL backup before updating. New-server provisioning requires a separately approved workflow task; this workflow assumes the existing initialized stack. Do not bootstrap production through a manual deployment.
7. Verify free disk space before the first release. No cleanup of other projects, and no global Docker prune, is allowed.

## GitHub repository settings

Require **CI required** in the main-branch protection/ruleset. Set GitHub Environment `production` and require the operator approval required by project policy. Repository settings and successful hosted execution still need operator verification. The SSH server host key must be checked against an independently verified fingerprint.

| Environment secret | Meaning |
| --- | --- |
| `DEPLOY_HOST` | VPS DNS/IP |
| `DEPLOY_USER` | Restricted SSH deployment user |
| `DEPLOY_SSH_KEY` | Private SSH key used **only** by GitHub Actions |
| `DEPLOY_SSH_HOST_KEY` | Verified `known_hosts` entry, **not** a raw fingerprint |

Optional Environment variable: `DEPLOY_PORT` (defaults to 22).

For private GHCR packages, separately provision registry read credentials on the VPS; `GITHUB_TOKEN` used by GitHub Actions to publish images is **not transferred** to the VPS.

## One-click workflow

1. After review and explicit push authorization, publish the reviewed commit to `main`. The **`Build`** workflow runs automatically. Wait for **`CI required`** and all mandatory checks to succeed for that exact SHA. The step summary on the Build run page will display verified image digests and a direct link to trigger deployment. A push never deploys automatically.
2. After explicit deployment approval, open GitHub → Actions → **Deploy** → **Run workflow**, selecting `main` (or click the link directly from the Build step summary). An optional full `commit_sha` must equal current main HEAD.
3. CD verifies the latest exact-SHA trusted `Build` run, its successful aggregate, artifact provenance and all published image digests. It rechecks current main before transfer and before invoking the wrapper. The checksummed archive contains only Compose files, `docker/` deployment assets and `ci-release.json`; runtime code arrives in prebuilt images.
4. The server deploy wrapper verifies the archive checksum, extracts into an isolated SHA release directory, backs up PostgreSQL, pulls immutable GHCR images, migrates, updates the Compose stack, runs healthchecks, and switches the `/srv/bybit-signals/current` symlink only after success.
5. Existing databases and volumes are preserved. All ten application/worker services use digest-pinned Compose overlays stored under `deploy-state/release-images/`. Current/previous local SHA aliases are retained for the existing scoped rollback-image cleanup. Backups, safe migrations, readiness checks, server locking and application rollback remain enabled; database migrations are not automatically reversed.

### Same-SHA redeployment and verified safe fast-path

The workflow can be rerun for the **same SHA** with the same approved image bits. `update.sh` reads the current dispatch manifest from its checksummed incoming archive even if the SHA release directory already exists.
- If running containers already match the exact image IDs, runtime health passes with zero drift, and configuration (`.env.sha256`) is unchanged, `update.sh` executes a **verified safe no-op fast path**: verifying container health and release consistency without unnecessary container downtime, image pulls, or redundant backups.
- If configuration (`.env`) has changed or drift is detected, `update.sh` falls through to reconcile the running container environment while preserving existing database state.
- Conflicting image bits for an already pinned SHA fail closed before pull/transition. A new CI approval of identical bits does not rewrite the original immutable pin record. A changed registry tag is rejected; use a reviewed new commit rather than overwriting release records.

The execution timer covers archive transfer and the approved remote deployment path. The 30-second target is conditional on image pulls, backup/migration costs, startup and graceful worker shutdown; it has not been verified in production. Full workflow time also includes queueing, environment approval and CI/image preflight. See [the performance report](../reports/agent/CICD_PERFORMANCE_COMPARISON.md).

## Diagnostics if the button fails

- **`Permission denied (publickey)`**: check deploy SSH key/user and `authorized_keys`.
- **`sudo: a password is required` / command not found**: install wrapper and configure narrow passwordless sudo permission.
- **`No such file or directory: /srv/bybit-signals/incoming`**: create upload directory and permissions.
- **`pull access denied` / `unauthorized`**: check `APP_IMAGE`/`WEB_IMAGE`/`RESEARCH_WORKER_IMAGE`, GHCR visibility and server `docker login`.
- **Backup or migration failure**: ensure running PostgreSQL, stable persistent volumes, and compatible migrations. Do not auto-restore production DB.
- **Missing/failed CI, missing aggregate or stale SHA**: CD rejects before connecting to production; obtain a successful complete CI run for current main.
- **Missing digest, retagged image or conflicting release pin**: do not overwrite pins or bypass verification; investigate and publish a reviewed new SHA when bits change.
- **Healthcheck failure**: inspect only `bybit-signals` Compose logs; investigate before attempting another deployment.

Do not claim a working one-click deployment until a real GitHub Actions run has passed end-to-end on the VPS. This is an operational prerequisite, not something a local archive can prove.
