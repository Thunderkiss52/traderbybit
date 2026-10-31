#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

export AUTO_RECOVER_ON_DEPLOY="${AUTO_RECOVER_ON_DEPLOY:-1}"
acquire_deployment_lock "DEPLOYMENT"
bash "${SCRIPT_DIR}/validate-deployment-state.sh" pre-deploy

CURRENT_TAG="${IMAGE_TAG:-latest}"
if [[ -f "${DEPLOY_STATE_DIR}/.deploy-current" ]]; then
    CURRENT_TAG="$(<"${DEPLOY_STATE_DIR}/.deploy-current")"
fi
PREVIOUS_TAG=""
if [[ -f "${DEPLOY_STATE_DIR}/.deploy-previous" ]]; then
    PREVIOUS_TAG="$(<"${DEPLOY_STATE_DIR}/.deploy-previous")"
fi
if [[ -z "${PREVIOUS_TAG}" && -f "${DEPLOY_STATE_DIR}/deploy-state.json" ]]; then
    PREVIOUS_TAG="$(python3 -c "import json; data=json.load(open('${DEPLOY_STATE_DIR}/deploy-state.json')); print(data.get('previous_image_tag',''))" 2>/dev/null || true)"
fi
assert_free_disk_space

if [[ -n "${NEW_IMAGE_TAG:-}" ]]; then
    NEW_TAG="${NEW_IMAGE_TAG}"
elif git -C "${PROJECT_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    NEW_TAG="$(git -C "${PROJECT_ROOT}" rev-parse --short=12 HEAD)"
else
    NEW_TAG="release-$(date -u +%Y%m%d%H%M%S)"
fi

if [[ "${DEPLOY_MODE:-pull}" == "pull" && -z "${NEW_IMAGE_TAG:-}" ]]; then
    echo "NEW_IMAGE_TAG is required for an immutable pull deployment." >&2
    exit 1
fi
IS_SAME_TAG_RECONCILE=0
if [[ "${NEW_TAG}" == "${CURRENT_TAG}" ]]; then
    if [[ "${DEPLOY_MODE:-pull}" == "pull" ]]; then
        # Immutable GHCR tags are SHA-based. A timestamp suffix does not exist in the registry.
        # Reconcile container environment configuration and service state without rebuilding images.
        echo "Release ${NEW_TAG} matches current tag; reconciling environment configuration and services without rebuilding immutable GHCR images."
        IS_SAME_TAG_RECONCILE=1
    else
        NEW_TAG="${NEW_TAG}-$(date -u +%Y%m%d%H%M%S)"
    fi
fi

bash "${PROJECT_ROOT}/docker/postgres/backup.sh"

export IMAGE_TAG="${NEW_TAG}"
rollback_application() {
    local failure_code="$?"
    trap - ERR
    echo "Deployment failed; attempting application rollback to ${CURRENT_TAG}. Database migrations are not rolled back." >&2
    export IMAGE_TAG="${CURRENT_TAG}"
    compose up -d --no-deps "${APPLICATION_SERVICES[@]}" || true
    compose restart web || true
    bash "${SCRIPT_DIR}/healthcheck.sh" || true
    exit "${failure_code}"
}
trap rollback_application ERR

if [[ "${DEPLOY_MODE:-pull}" == "pull" ]]; then
    compose pull app web research-worker
    if ! docker image inspect "${RESEARCH_WORKER_IMAGE:-bybit-signals-research-worker}:${NEW_TAG}" >/dev/null 2>&1; then
        echo "Building research-worker image for tag ${NEW_TAG}..."
        compose build research-worker
    fi
else
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

# Commit canonical state and projection files atomically only after healthcheck passes
new_rel="${NEW_TAG#sha-}"
if [[ "${IS_SAME_TAG_RECONCILE}" == "1" ]]; then
    prev_target_tag="${PREVIOUS_TAG}"
    prev_target_rel=""
    if [[ -f "${DEPLOY_STATE_DIR}/release-previous" ]]; then
        prev_target_rel="$(<"${DEPLOY_STATE_DIR}/release-previous")"
    elif [[ -n "${prev_target_tag}" ]]; then
        prev_target_rel="${prev_target_tag#sha-}"
    fi
    commit_deployment_state "${NEW_TAG}" "${prev_target_tag}" "${new_rel}" "${prev_target_rel}" "CONSISTENT"
    CURRENT_IMAGE_TAG="${NEW_TAG}" PREVIOUS_IMAGE_TAG="${prev_target_tag:-${CURRENT_TAG}}" bash "${SCRIPT_DIR}/cleanup-project-images.sh"
else
    curr_rel="${CURRENT_TAG#sha-}"
    commit_deployment_state "${NEW_TAG}" "${CURRENT_TAG}" "${new_rel}" "${curr_rel}" "CONSISTENT"
    CURRENT_IMAGE_TAG="${NEW_TAG}" PREVIOUS_IMAGE_TAG="${CURRENT_TAG}" bash "${SCRIPT_DIR}/cleanup-project-images.sh"
fi
bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy

trap - ERR

bash "${SCRIPT_DIR}/storage-report.sh"

if [[ "${IS_SAME_TAG_RECONCILE}" == "1" ]]; then
    echo "Reconciled deployment configuration for image tag ${NEW_TAG}."
else
    echo "Updated from image tag ${CURRENT_TAG} to ${NEW_TAG}."
fi
echo "Application rollback: IMAGE_TAG=${CURRENT_TAG} SKIP_BUILD=1 ENV_FILE=${ENV_FILE} ${SCRIPT_DIR}/deploy.sh"
echo "Database migrations must be backward-compatible; database rollback is intentionally not automatic."
