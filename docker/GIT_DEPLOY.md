# GitHub Actions deployment — one-time setup and checks

The workflow `.github/workflows/deploy.yml` is **manual** (`Actions → Deploy Production → Run workflow`) and is restricted to the `main` branch. It runs backend/frontend tests, publishes versioned `app`, `web`, and `research-worker` images to GHCR, and connects to the VPS over SSH. The images are **not** built on the VPS.

## Before first click (one-time server setup)

The workflow **does not provision** SSH, the server deploy wrapper, or the production secrets. Set these up once on the VPS, where the existing paper-trading deployment is already running:

1. Ensure `/srv/bybit-signals/.env.production` exists with secure permissions (`chmod 600`) and the existing Docker Compose stack is healthy. **Do not overwrite the production environment, database, or volumes.**
2. Configure `APP_IMAGE=ghcr.io/<owner>/<repo>/app`, `WEB_IMAGE=ghcr.io/<owner>/<repo>/web`, and `RESEARCH_WORKER_IMAGE=ghcr.io/<owner>/<repo>/research-worker` in `.env.production`, matching the lowercased GitHub repository name used by the workflow. Set `DEPLOY_MODE=pull`. **Do not change `IMAGE_TAG` to an unavailable GHCR tag.**
3. The server must be able to pull all image packages from GHCR. Either make the GHCR packages public, or authenticate the server Docker daemon with a token limited to `read:packages`. Test pulling a previously published SHA tag before enabling regular releases. Do not put registry tokens in the repository.
4. Create `/srv/bybit-signals/incoming`, `/srv/bybit-signals/releases`, and `/srv/bybit-signals/deploy-state`. Ensure the deploy user can upload archives to `incoming` and that the deploy wrapper can read those archives and the protected `.env.production`.
5. Install `docker/scripts/production-deploy-wrapper.sh` from the reviewed repository as `/usr/local/sbin/bybit-signals-deploy`, owned by root and executable. The workflow runs `sudo -n /usr/local/sbin/bybit-signals-deploy <40-hex-commit-sha>`. Use a narrowly scoped `sudoers` entry for this exact script, not unrestricted sudo. A Docker-capable deployment user is effectively privileged: review this security boundary carefully.
6. The wrapper expects an **already initialized** Docker Compose deployment because `update.sh` creates a PostgreSQL backup before updating. For a brand new server, bootstrap with the documented `docker/scripts/deploy.sh` process first, then enable GitHub deployment.
7. Verify free disk space before the first release. No cleanup of other projects, and no global Docker prune, is allowed.

## GitHub repository settings

Set GitHub Environment `production` and, preferably, require approval for this environment. The SSH server host key must be checked against an independently verified fingerprint.

| Environment secret | Meaning |
| --- | --- |
| `DEPLOY_HOST` | VPS DNS/IP |
| `DEPLOY_USER` | Restricted SSH deployment user |
| `DEPLOY_SSH_KEY` | Private SSH key used **only** by GitHub Actions |
| `DEPLOY_SSH_HOST_KEY` | Verified `known_hosts` entry, **not** a raw fingerprint |

Optional Environment variable: `DEPLOY_PORT` (defaults to 22).

For private GHCR packages, separately provision registry read credentials on the VPS; `GITHUB_TOKEN` used by GitHub Actions to publish images is **not transferred** to the VPS.

## One-click workflow

1. Commit and push the application updates to `main`.
2. Open GitHub → Actions → **Deploy Production** → **Run workflow**, selecting `main`.
3. The workflow tests the backend/frontend, builds/publishes `sha-<commit>` images, and transfers only a small source archive to the VPS.
4. The server deploy wrapper verifies the archive checksum, extracts into an isolated SHA release directory, backs up PostgreSQL, pulls immutable GHCR images, migrates, updates the Compose stack, runs healthchecks, and switches the `/srv/bybit-signals/current` symlink only after success.
5. Existing databases and volumes are preserved. The current and previous application image tags are retained for rollback.

The workflow can be rerun for the **same SHA**; `update.sh` recognizes the deployed image and verifies service health without inventing a non-existent image tag.

## Diagnostics if the button fails

- **`Permission denied (publickey)`**: check deploy SSH key/user and `authorized_keys`.
- **`sudo: a password is required` / command not found**: install wrapper and configure narrow passwordless sudo permission.
- **`No such file or directory: /srv/bybit-signals/incoming`**: create upload directory and permissions.
- **`pull access denied` / `unauthorized`**: check `APP_IMAGE`/`WEB_IMAGE`/`RESEARCH_WORKER_IMAGE`, GHCR visibility and server `docker login`.
- **Backup or migration failure**: ensure running PostgreSQL, stable persistent volumes, and compatible migrations. Do not auto-restore production DB.
- **`Image is already deployed`**: expected on repeated clicks for the same commit.
- **Healthcheck failure**: inspect only `bybit-signals` Compose logs; investigate before attempting another deployment.

Do not claim a working one-click deployment until a real GitHub Actions run has passed end-to-end on the VPS. This is an operational prerequisite, not something a local archive can prove.
