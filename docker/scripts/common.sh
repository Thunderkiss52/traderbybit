#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_STATE_DIR="${DEPLOY_STATE_DIR:-${PROJECT_ROOT}}"
REQUESTED_ENV_FILE="${ENV_FILE:-${PROJECT_ROOT}/.env.production}"
REQUESTED_IMAGE_TAG="${IMAGE_TAG:-}"
ENV_FILE="${REQUESTED_ENV_FILE}"

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "Missing environment file: ${ENV_FILE}" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a
ENV_FILE="${REQUESTED_ENV_FILE}"
if [[ -n "${REQUESTED_IMAGE_TAG}" ]]; then
    IMAGE_TAG="${REQUESTED_IMAGE_TAG}"
fi

COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-bybit-signals}"
if [[ "${COMPOSE_PROJECT_NAME}" != "bybit-signals" && "${ALLOW_TEST_PROJECT:-0}" != "1" ]]; then
    echo "COMPOSE_PROJECT_NAME must be bybit-signals for this deployment." >&2
    exit 1
fi

mkdir -p "${DEPLOY_STATE_DIR}"
export ENV_FILE COMPOSE_PROJECT_NAME DEPLOY_STATE_DIR
COMPOSE_ARGS=(docker compose --project-name "${COMPOSE_PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${PROJECT_ROOT}/compose.yaml")
if [[ "${USE_EXTERNAL_PROXY:-0}" == "1" ]]; then
    : "${EXTERNAL_PROXY_NETWORK:?EXTERNAL_PROXY_NETWORK is required when USE_EXTERNAL_PROXY=1}"
    COMPOSE_ARGS+=(-f "${PROJECT_ROOT}/compose.proxy.yaml")
fi

compose() {
    "${COMPOSE_ARGS[@]}" "$@"
}

assert_free_disk_space() {
    local minimum_gb="${MIN_FREE_DISK_GB:-10}" available_kb required_kb
    [[ "${minimum_gb}" =~ ^[0-9]+$ ]] || { echo "MIN_FREE_DISK_GB must be an integer." >&2; return 1; }
    available_kb="$(df -Pk "${PROJECT_ROOT}" | awk 'NR == 2 {print $4}')"
    required_kb=$((minimum_gb * 1024 * 1024))
    if (( available_kb < required_kb )); then
        echo "Deployment blocked: less than ${minimum_gb} GiB is available on the project filesystem." >&2
        return 1
    fi
    echo "Disk preflight passed: $((available_kb / 1024 / 1024)) GiB available (minimum ${minimum_gb} GiB)."
}

wait_healthy() {
    local service="$1" timeout="${2:-180}" elapsed=0 id status
    while (( elapsed < timeout )); do
        id="$(compose ps -q "${service}")"
        if [[ -n "${id}" ]]; then
            status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${id}")"
            if [[ "${status}" == "healthy" || "${status}" == "running" ]]; then
                return 0
            fi
            if [[ "${status}" == "unhealthy" || "${status}" == "exited" || "${status}" == "dead" ]]; then
                compose logs --tail=100 "${service}" >&2
                return 1
            fi
        fi
        sleep 3
        elapsed=$((elapsed + 3))
    done
    echo "Timed out waiting for ${service}." >&2
    compose logs --tail=100 "${service}" >&2
    return 1
}
