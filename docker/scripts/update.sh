#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

export AUTO_RECOVER_ON_DEPLOY="${AUTO_RECOVER_ON_DEPLOY:-1}"
acquire_deployment_lock "DEPLOYMENT"
"${SCRIPT_DIR}/validate-deployment-state.sh" pre-deploy

CURRENT_TAG="${IMAGE_TAG:-latest}"
if [[ -f "${DEPLOY_STATE_DIR}/.deploy-current" ]]; then
    CURRENT_TAG="$(<"${DEPLOY_STATE_DIR}/.deploy-current")"
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
if [[ "${NEW_TAG}" == "${CURRENT_TAG}" ]]; then
    if [[ "${DEPLOY_MODE:-pull}" == "pull" ]]; then
        # Immutable GHCR tags are SHA-based. A timestamp suffix does not exist in the registry.
        echo "Release ${NEW_TAG} is already deployed; checking service health without rebuilding."
        "${SCRIPT_DIR}/healthcheck.sh"
        exit 0
    fi
    NEW_TAG="${NEW_TAG}-$(date -u +%Y%m%d%H%M%S)"
fi

"${PROJECT_ROOT}/docker/postgres/backup.sh"

export IMAGE_TAG="${NEW_TAG}"
rollback_application() {
    local failure_code="$?"
    trap - ERR
    echo "Deployment failed; attempting application rollback to ${CURRENT_TAG}. Database migrations are not rolled back." >&2
    export IMAGE_TAG="${CURRENT_TAG}"
    compose up -d --no-deps "${APPLICATION_SERVICES[@]}" || true
    "${SCRIPT_DIR}/healthcheck.sh" || true
    exit "${failure_code}"
}
trap rollback_application ERR

if [[ "${DEPLOY_MODE:-pull}" == "pull" ]]; then
    compose pull app web research-worker
else
    compose build --pull app web
fi
assert_free_disk_space
compose up -d postgres redis
wait_healthy postgres 180
wait_healthy redis 120
compose run --rm --no-deps app php artisan migrate --force --no-interaction
compose up -d --remove-orphans

for svc in "${APPLICATION_SERVICES[@]}"; do
    wait_service_ready "${svc}" 120
done

"${SCRIPT_DIR}/healthcheck.sh"

# Commit canonical state and projection files atomically only after healthcheck passes
new_rel="${NEW_TAG#sha-}"
curr_rel="${CURRENT_TAG#sha-}"
commit_deployment_state "${NEW_TAG}" "${CURRENT_TAG}" "${new_rel}" "${curr_rel}" "CONSISTENT"
"${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy

trap - ERR

CURRENT_IMAGE_TAG="${NEW_TAG}" PREVIOUS_IMAGE_TAG="${CURRENT_TAG}" "${SCRIPT_DIR}/cleanup-project-images.sh"
"${SCRIPT_DIR}/storage-report.sh"

echo "Updated from image tag ${CURRENT_TAG} to ${NEW_TAG}."
echo "Application rollback: IMAGE_TAG=${CURRENT_TAG} SKIP_BUILD=1 ENV_FILE=${ENV_FILE} ${SCRIPT_DIR}/deploy.sh"
echo "Database migrations must be backward-compatible; database rollback is intentionally not automatic."
