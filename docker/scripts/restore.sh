#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

BACKUP="${1:-}"
TARGET_DB="${RESTORE_TARGET_DB:-${POSTGRES_DB}_restore}"
CONFIRM="${2:-}"
if [[ ! -f "${BACKUP}" || "${CONFIRM}" != "--confirm" ]]; then
    echo "Usage: RESTORE_TARGET_DB=bybit_signals_restore $0 /path/backup.dump --confirm" >&2
    exit 1
fi
[[ "${TARGET_DB}" =~ ^[a-zA-Z0-9_]+$ ]] || { echo "Unsafe target database name." >&2; exit 1; }
if [[ "${TARGET_DB}" == "${POSTGRES_DB}" && "${ALLOW_PRODUCTION_RESTORE:-0}" != "1" ]]; then
    echo "Refusing to overwrite the production database. Restore to a separate test database first." >&2
    exit 1
fi

compose exec -T postgres pg_restore --list <"${BACKUP}" >/dev/null
if [[ -f "${BACKUP}.sha256" ]]; then
    (cd "$(dirname "${BACKUP}")" && sha256sum --check "$(basename "${BACKUP}").sha256")
fi
if compose exec -T postgres psql -U "${POSTGRES_USER}" -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname='${TARGET_DB}'" | grep -q 1; then
    echo "Target database ${TARGET_DB} already exists; refusing to modify it." >&2
    exit 1
fi

compose exec -T postgres createdb -U "${POSTGRES_USER}" "${TARGET_DB}"
if ! compose exec -T postgres pg_restore -U "${POSTGRES_USER}" -d "${TARGET_DB}" --exit-on-error --no-owner --no-acl <"${BACKUP}"; then
    compose exec -T postgres dropdb -U "${POSTGRES_USER}" "${TARGET_DB}" || true
    exit 1
fi
compose exec -T postgres psql -U "${POSTGRES_USER}" -d "${TARGET_DB}" -c 'SELECT COUNT(*) AS migrations FROM migrations;'
echo "Restore verified in isolated database ${TARGET_DB}. Production was not changed."
