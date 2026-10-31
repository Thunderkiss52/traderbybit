#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

echo "Filesystem:"
df -h "${PROJECT_ROOT}" "${BACKUP_DIR:-${PROJECT_ROOT}/backups/bybit-signals}"

echo "Bybit Signals images:"
for repository in "${APP_IMAGE}" "${WEB_IMAGE}" "${RESEARCH_WORKER_IMAGE}"; do
    docker image ls "${repository}" --format '{{.Repository}}:{{.Tag}} {{.Size}} {{.ID}}'
done

echo "Bybit Signals volumes:"
for volume in "${COMPOSE_PROJECT_NAME}_postgres_data" "${COMPOSE_PROJECT_NAME}_redis_data" "${COMPOSE_PROJECT_NAME}_app_storage"; do
    mountpoint="$(docker volume inspect --format '{{.Mountpoint}}' "${volume}")"
    du -sh "${mountpoint}"
done

echo "Bybit Signals container logs:"
for id in $(compose ps -q); do
    log_path="$(docker inspect --format '{{.LogPath}}' "${id}")"
    [[ -f "${log_path}" ]] && du -h "${log_path}"
done

echo "Largest PostgreSQL relations:"
compose exec -T postgres sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "SELECT relname || E'\''\t'\'' || pg_size_pretty(pg_total_relation_size(relid)) FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 15"'
