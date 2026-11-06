# Deployment rollback safety

## Defects and changes

Previously update committed canonical release metadata and ran `cleanup-project-images.sh` before final drift validation. Its `docker image rm` calls produced the `Untagged` / `Deleted: sha256:...` output. Scoped tag cleanup preserved two tag names but not every image identity reachable through aliases; the dangling-image branch identified only component labels. The incident's literal deletion list is unavailable, so no particular production deletion is asserted.

Update now validates the candidate before advancing successful state. Cleanup runs only after acceptance, outside the rollback trap; cleanup/reporting failures retain the accepted release. The cleanup lock is reentrant under the deployment lock. It resolves all ten current and previous image mappings, protects their full Docker IDs, preserves any aliases of those IDs, refuses cleanup if required images cannot be inspected, and removes only obsolete exact repository tags unused by any container. It never removes by image ID, prunes globally, or deletes unknown dangling images. Docker's non-forced reference removal manages shared-layer reference counts. Unattributable dangling images remain for separately scoped operator review.

Application updates never run `compose up postgres redis`; they require existing infrastructure readiness and transition only the ten application services with `--no-build --no-deps`. Bootstrap deployment retains its explicit infrastructure creation path.

## Schema and interruption contract

Before migration, record the migration ledger and SHA-256 of exact schema-only pg_dump DDL for the current known-good release. Owner/ACL/comment output is excluded by pg_dump options; randomized psql restriction guards are excluded from the remaining schema DDL. After acceptance, record the candidate snapshot. Automatic application rollback requires both unchanged migration ledger and identical schema DDL. A real isolated DDL change with unchanged migration rows is rejected. A failed migration never arms application rollback: partial DDL is uncertain even without a new migration row. An unfinished migration/schema-blocked journal prevents automatic retry; explicit operator recovery review is required. No database migrations are reversed.

The previous heuristic `dropTable|dropColumn` scan was removed. Legacy releases without known-good snapshots fail closed for explicit rollback. Additive migrations can also block application rollback: this conservative change requires schema compatibility review instead of assuming backward compatibility. It preserves the forward-only database; it does not claim all old/new application versions are compatible.

On genuine drift, health failure, partial transition or TERM after migration without schema changes, rollback restores all ten app services, waits for readiness, checks operational health, and validates their identities. Canonical successful release metadata stays unchanged. Failed rollback is explicitly journaled; successful rollback does not hide the failed update exit status. SIGKILL cannot execute traps; the durable in-progress journal and subsequent fail-closed preflight require recovery review.

## Verification matrix

| Scenario | Evidence |
|---|---|
| Successful update / same SHA | Actual update script with Docker CLI boundary fixture; real Compose unchanged container-ID checks |
| Wrong image in any of ten services | Real registry/Compose containers, actual validator rejects each injection |
| Invalid digest manifest / OCI revision | Actual manifest parser / identity helper rejects before transition |
| Failed image pull / missing image | Actual update script preserves running release, no build fallback |
| Failed health | Actual readiness/update path rejects and rolls back; real unhealthy container rejected |
| Failed migration / unsafe retry | No app transition or automatic rollback; durable journal blocks retry |
| Interrupted / partial transition | TERM/failure injection into actual update restores known-good images |
| Shared layers / retention | Real current and previous artifacts share Alpine base; cleanup retains pins and aliases, removes obsolete tag; ten-service rollback mapping verified |
| Forward-only migrations | Schema or migration change prevents automatic application rollback; no reverse migration command |
| Financial records / PostgreSQL / Redis | Isolated matrix hashes 26 synthetic account rows (13/13, three BLOCKED), related fixture tables and Redis key count; update CLI fixture rejects infrastructure recreation |
| Backup | Actual PostgreSQL custom dump, pg_restore --list and checksum generation on isolated synthetic data |

Deployment PHP tests: 12 tests, 46 assertions passed under PHP 8.5.12. The 12-scenario existing recovery matrix passed against dedicated PostgreSQL 17 and Redis fixtures. These tests do not prove production account balances/history, funding completeness, financial reconciliation or actual VPS rollback readiness. No production PostgreSQL/Redis or ECONOM resources were modified.

Full production failure injection and migration compatibility sign-off are unverified and remain operator-controlled. No push/deploy/Actions trigger or production access is authorized.

The original update advanced canonical metadata before rollback. Owner verification did not establish deployment metadata/release-pointer coherence. An operator must verify these against the actual known-good release before a future deployment. The fixed validator rejects unrepaired mismatches; this session did not mutate production state.
