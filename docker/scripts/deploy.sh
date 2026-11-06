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
if [[ "${DEPLOY_MODE:-pull}" == "pull" ]]; then
    for img_var in APP_IMAGE WEB_IMAGE RESEARCH_WORKER_IMAGE; do
        val="${!img_var:-}"
        if [[ -z "${val}" ]]; then
            echo "[FATAL] ${img_var} is required for pull deployment mode." >&2
            echo "Existing running containers are preserved." >&2
            exit 1
        fi
        if [[ "${val}" =~ ^bybit-signals- || "${val}" != *"/"* ]]; then
            echo "[FATAL] ${img_var} must be a qualified registry image reference (e.g. ghcr.io/<owner>/<repo>/<target>), got: '${val}'." >&2
            echo "Existing running containers are preserved." >&2
            exit 1
        fi
    done
    echo "Pulling immutable images (app, web, research-worker) for tag ${IMAGE_TAG:-latest}..."
    if ! compose pull app web research-worker; then
        echo "Error: Failed to pull required immutable images for tag ${IMAGE_TAG:-latest}." >&2
        echo "Existing running containers are preserved." >&2
        exit 1
    fi
    references="$(resolve_service_image_references "${IMAGE_TAG}")"
    while IFS='=' read -r service svc_img; do
        verify_release_image "${svc_img}"
        retain_release_image "${svc_img}"
    done <<< "${references}"
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
compose up -d --no-build --no-deps "${APPLICATION_SERVICES[@]}"

for svc in "${APPLICATION_SERVICES[@]}"; do
    wait_service_ready "${svc}" 120
done

bash "${SCRIPT_DIR}/healthcheck.sh"

tag="${IMAGE_TAG:-latest}"
rel="${tag#sha-}"
VALIDATE_IMAGE_TAG="${tag}" bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy
commit_deployment_state "${tag}" "" "${rel}" "" "CONSISTENT"

echo "Deployment completed on http://${WEB_BIND_IP}:${WEB_PORT} (Basic Auth protected)."
