# Locked dependency remediation — local stage

The initial lockfile scans failed: `composer audit --locked --no-interaction` reported one medium `symfony/mime` CVE-2026-45070 finding, and `npm audit --omit=dev --audit-level=high` reported six high findings through Vue I18n and `follow-redirects`. The patched versions were checked against the Symfony advisory and GitHub package advisories in `SECURITY_REVIEW.md`.

Only `composer.lock` and `frontend/package-lock.json` changed. Composer resolved `symfony/mime` 8.1.7 to 8.1.10 without changing `composer.json`; npm resolved `vue-i18n` and its `@intlify/*` dependencies from 11.4.2 to 11.4.18, and `follow-redirects` 1.16.1 to 1.16.2. The app's Node 22 Docker build stage and CI setup satisfy the newer dependency engine requirement.

After lockfile updates, `composer install --no-scripts --no-interaction --no-progress` installed the lockfile in the local PHP test environment, and Node 22 `npm install --ignore-scripts --no-fund` installed the frontend lockfile. Both commands exited 0. `composer audit --locked --no-interaction`, `composer validate --strict`, and `npm audit --omit=dev --audit-level=high` exited 0 with zero advisories. Node 22 `npm test` passed 64 tests and `npm run build` passed, including TypeScript checking. These are local dependency checks, not deployed image or production acceptance.
