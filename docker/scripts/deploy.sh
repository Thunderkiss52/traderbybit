#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

for command in docker curl ss; do
    command -v "${command}" >/dev/null || { echo "Required command is missing: ${command}" >&2; exit 1; }
done
docker compose version >/dev/null
assert_free_disk_space

WEB_BIND_IP="${WEB_BIND_IP:-127.0.0.1}"
WEB_PORT="${WEB_PORT:-18080}"
if ss -ltnH | awk '{print $4}' | grep -Eq "(^|:)${WEB_PORT}$"; then
    if [[ -z "$(compose ps -q web 2>/dev/null)" ]]; then
        echo "Port ${WEB_PORT} is already occupied by another process; deployment stopped." >&2
        exit 1
    fi
fi

if [[ "${USE_EXTERNAL_PROXY:-0}" == "1" ]]; then
    docker network inspect "${EXTERNAL_PROXY_NETWORK}" >/dev/null
fi

compose config --quiet
if [[ "${DEPLOY_MODE:-build}" == "pull" ]]; then
    compose pull app web
elif [[ "${SKIP_BUILD:-0}" != "1" ]]; then
    compose build --pull app web
fi

assert_free_disk_space

compose up -d postgres redis
wait_healthy postgres 180
wait_healthy redis 120

compose run --rm --no-deps app php artisan migrate --force --no-interaction
compose up -d --remove-orphans
wait_healthy app 120
wait_healthy web 120
wait_healthy queue 120
wait_healthy scheduler 120
wait_healthy market-ws 120
wait_healthy backtest-worker 120

"${SCRIPT_DIR}/healthcheck.sh"
printf '%s\n' "${IMAGE_TAG:-latest}" >"${DEPLOY_STATE_DIR}/.deploy-current"
echo "Deployment completed on http://${WEB_BIND_IP}:${WEB_PORT} (Basic Auth protected)."
