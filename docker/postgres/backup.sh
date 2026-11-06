#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../scripts" && pwd)"
# shellcheck source=../scripts/common.sh
source "${SCRIPT_DIR}/common.sh"

BACKUP_DIR="${BACKUP_DIR:-${PROJECT_ROOT}/backups/bybit-signals}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
RETENTION_COUNT="${BACKUP_RETENTION_COUNT:-7}"
case "${BACKUP_DIR}" in /|"" ) echo "Unsafe BACKUP_DIR." >&2; exit 1;; esac
[[ "${RETENTION_DAYS}" =~ ^[0-9]+$ ]] || { echo "BACKUP_RETENTION_DAYS must be numeric." >&2; exit 1; }
[[ "${RETENTION_COUNT}" =~ ^[1-9][0-9]*$ ]] || { echo "BACKUP_RETENTION_COUNT must be a positive integer." >&2; exit 1; }

mkdir -p "${BACKUP_DIR}"
chmod 0700 "${BACKUP_DIR}"
umask 077
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/${COMPOSE_PROJECT_NAME}-${POSTGRES_DB}-${STAMP}.dump"
TMP="${TARGET}.partial"
trap 'rm -f "${TMP}"' EXIT

backup_start="$(monotonic_ms)"
if compose exec -T postgres pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --format=custom --compress=9 --no-owner --no-acl >"${TMP}"; then
    timing_record postgres_backup_creation "${backup_start}"
else
    status=$?
    timing_record postgres_backup_creation "${backup_start}" "${status}"
    exit "${status}"
fi
backup_verify_start="$(monotonic_ms)"
if compose exec -T postgres pg_restore --list <"${TMP}" >/dev/null; then
    timing_record postgres_backup_verification "${backup_verify_start}"
else
    status=$?
    timing_record postgres_backup_verification "${backup_verify_start}" "${status}"
    exit "${status}"
fi
mv "${TMP}" "${TARGET}"
(cd "${BACKUP_DIR}" && sha256sum "$(basename "${TARGET}")" >"$(basename "${TARGET}").sha256")
trap - EXIT

if [[ -n "${BACKUP_EXTERNAL_DIR:-}" ]]; then
    case "${BACKUP_EXTERNAL_DIR}" in /|"" ) echo "Unsafe BACKUP_EXTERNAL_DIR." >&2; exit 1;; esac
    mkdir -p "${BACKUP_EXTERNAL_DIR}"
    install -m 0600 "${TARGET}" "${BACKUP_EXTERNAL_DIR}/$(basename "${TARGET}")"
    install -m 0600 "${TARGET}.sha256" "${BACKUP_EXTERNAL_DIR}/$(basename "${TARGET}.sha256")"
fi

mapfile -t backups < <(find "${BACKUP_DIR}" -maxdepth 1 -type f -name '*.dump' -printf '%T@ %p\n' | sort -nr | awk '{print $2}')
for ((index = RETENTION_COUNT; index < ${#backups[@]}; index++)); do
    candidate="${backups[index]}"
    if [[ -f "${candidate}.sha256" ]] && (cd "${BACKUP_DIR}" && sha256sum -c "$(basename "${candidate}").sha256" >/dev/null); then
        rm -f -- "${candidate}" "${candidate}.sha256"
    fi
done

find "${BACKUP_DIR}" -maxdepth 1 -type f -name '*.dump' -mtime "+${RETENTION_DAYS}" -print0 \
    | while IFS= read -r -d '' candidate; do
        newest="${backups[0]:-}"
        if [[ "${candidate}" != "${newest}" && -f "${candidate}.sha256" ]] && (cd "${BACKUP_DIR}" && sha256sum -c "$(basename "${candidate}").sha256" >/dev/null); then
            rm -f -- "${candidate}" "${candidate}.sha256"
        fi
    done
echo "Verified PostgreSQL backup: ${TARGET}"
