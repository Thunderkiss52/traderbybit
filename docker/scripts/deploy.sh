#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

acquire_deployment_lock "DEPLOYMENT"

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
    echo "Pulling immutable images (app, web, research-worker) for tag ${IMAGE_TAG:-latest}..."
    if ! compose pull app web research-worker; then
        echo "Error: Failed to pull required immutable images for tag ${IMAGE_TAG:-latest}." >&2
        exit 1
    fi
    for svc_img in "${APP_IMAGE:-bybit-signals-app}:${IMAGE_TAG:-latest}" \
                   "${WEB_IMAGE:-bybit-signals-web}:${IMAGE_TAG:-latest}" \
                   "${RESEARCH_WORKER_IMAGE:-bybit-signals-research-worker}:${IMAGE_TAG:-latest}"; do
        if ! docker image inspect "${svc_img}" >/dev/null 2>&1; then
            echo "Error: Required immutable image '${svc_img}' is missing after pull." >&2
            echo "Silent build fallback is prohibited in production. Aborting deployment." >&2
            exit 1
        fi
    done
    echo "Verified all required immutable images are present locally."
elif [[ "${SKIP_BUILD:-0}" != "1" ]]; then
    compose build --pull app web research-worker
fi

assert_free_disk_space

compose up -d postgres redis
wait_healthy postgres 180
wait_healthy redis 120

compose run --rm --no-deps app php artisan migrate --force --no-interaction
compose run --rm --no-deps app php artisan binance:initialize-paper --force --no-interaction
compose up -d --remove-orphans
compose restart web

for svc in "${APPLICATION_SERVICES[@]}"; do
    wait_service_ready "${svc}" 120
done

bash "${SCRIPT_DIR}/healthcheck.sh"

tag="${IMAGE_TAG:-latest}"
rel="${tag#sha-}"
commit_deployment_state "${tag}" "" "${rel}" "" "CONSISTENT"
bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy

echo "Deployment completed on http://${WEB_BIND_IP}:${WEB_PORT} (Basic Auth protected)."
