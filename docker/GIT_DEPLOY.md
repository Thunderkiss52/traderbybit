# GitHub Actions production deployment

`Deploy Production` tests the selected ref, builds immutable `app` and `web`
images in GitHub Actions, pushes them to GHCR, and activates them on the VPS.
Composer, Node.js, and Docker build cache are not required on the server.

## One-time GitHub setup

1. Push this repository to GitHub and create an Environment named `production`.
2. Protect `main` and configure required reviewers for the production Environment.
3. Add Environment secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, and
   `DEPLOY_SSH_HOST_KEY`. The host-key value must come from a separately verified
   `ssh-keyscan -p 2222 HOST` result; the workflow never disables host checking.
4. Add Environment variable `DEPLOY_PORT` (`2222` on the current VPS).
5. Give the server a GHCR credential restricted to `read:packages`, or make only
   these two container packages public. Run `docker login ghcr.io` once as root;
   never put that token in the repository.
6. Configure a GHCR retention rule that preserves the current and previous image
   versions. The server also removes only unused Bybit Signals tags after a
   successful deployment.

## One-time VPS setup

Create a dedicated `deploy` user without a password or general sudo rights. Its
public key belongs in `~deploy/.ssh/authorized_keys`. It needs write access only to
`/srv/bybit-signals/incoming`. Install
`docker/scripts/production-deploy-wrapper.sh` as the root-owned executable
`/usr/local/sbin/bybit-signals-deploy`, then add exactly this sudoers rule:

```text
deploy ALL=(root) NOPASSWD: /usr/local/sbin/bybit-signals-deploy *
```

Do not add `deploy` to the Docker group. The wrapper accepts only a full 40-digit
commit SHA, verifies the archive checksum and paths, and operates only on the
`bybit-signals` Compose project. The wildcard is safe only with this immutable,
root-owned validation wrapper; do not replace it with a general shell script.
`/srv/bybit-signals/.env.production` remains
root-owned with mode `0600` and is never uploaded by Actions.

Set these non-secret values in `.env.production`:

```dotenv
APP_IMAGE=ghcr.io/OWNER/REPOSITORY/app
WEB_IMAGE=ghcr.io/OWNER/REPOSITORY/web
DEPLOY_MODE=pull
MIN_FREE_DISK_GB=10
LOG_MAX_SIZE=10m
LOG_MAX_FILES=3
BACKUP_RETENTION_COUNT=7
```

## Deployment and rollback

Push to `main`, open **Actions → Deploy Production → Run workflow**, and select
`main` or a release tag. Deployments are serialized. Tests and image publication
must succeed before the protected production job starts.

`update.sh` creates and verifies a PostgreSQL backup, pulls the commit-tagged
images, migrates, replaces only Bybit Signals containers, and runs healthchecks.
On failure it automatically restores the previous application images. It never
rolls back migrations or removes volumes. Migrations must therefore remain
backward compatible.

Manual application rollback, if required:

```bash
sudo -n /usr/local/sbin/bybit-signals-deploy rollback
```

Review Actions logs and `docker compose ... logs`. If a migration is incompatible,
stop and deploy a forward fix; never restore a database over production without a
separate approved recovery procedure.
