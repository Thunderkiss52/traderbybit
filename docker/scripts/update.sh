#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

export AUTO_RECOVER_ON_DEPLOY="${AUTO_RECOVER_ON_DEPLOY:-1}"
update_start="$(monotonic_ms)"
export DEPLOY_TIMING_FILE="${DEPLOY_TIMING_FILE:-${DEPLOY_STATE_DIR}/timing-$(date -u +%Y%m%dT%H%M%S)-$$.jsonl}"
timing_run remote_deployment_lock acquire_deployment_lock "DEPLOYMENT"
finish_update() {
    local status=$?
    timing_record remote_update_total "${update_start}" "${status}"
    timing_summary
    release_deployment_lock
}
trap finish_update EXIT
if [[ -f "${DEPLOY_STATE_DIR}/update-in-progress" ]] &&
   ! grep -q ': ROLLED_BACK$' "${DEPLOY_STATE_DIR}/update-in-progress"; then
    echo '[BLOCKED] An interrupted or schema-blocked update requires explicit recovery review.' >&2
    cat "${DEPLOY_STATE_DIR}/update-in-progress" >&2
    exit 1
fi
if [[ "${DEPLOY_MODE:-pull}" == pull && "${ALLOW_TEST_PROJECT:-0}" != 1 ]]; then
    [[ "${NEW_IMAGE_TAG:-}" =~ ^sha-[0-9a-f]{40}$ ]] || { echo 'Exact release SHA required.' >&2; exit 1; }
    incoming="$(dirname "${DEPLOY_STATE_DIR}")/incoming/${NEW_IMAGE_TAG#sha-}.tar.gz"
    bash "${SCRIPT_DIR}/stage-release-manifest.sh" "${incoming}" "${DEPLOY_STATE_DIR}/release-images" "${NEW_IMAGE_TAG#sha-}"
elif [[ -f "${PROJECT_ROOT}/ci-release.json" ]]; then
    [[ "${NEW_IMAGE_TAG:-}" =~ ^sha-[0-9a-f]{40}$ ]] || { echo 'Exact release SHA required.' >&2; exit 1; }
    python3 "${SCRIPT_DIR}/release-images.py" "${PROJECT_ROOT}/ci-release.json" "${DEPLOY_STATE_DIR}/release-images" "${NEW_IMAGE_TAG#sha-}"
fi
timing_run pre_deployment_state bash "${SCRIPT_DIR}/validate-deployment-state.sh" pre-deploy

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
timing_run disk_preflight assert_free_disk_space

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

timing_run postgres_backup_total bash "${PROJECT_ROOT}/docker/postgres/backup.sh"

export IMAGE_TAG="${NEW_TAG}"

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
    echo "Pulling immutable images (app, web, research-worker) for tag ${NEW_TAG}..."
    if ! timing_run ghcr_image_pull pull_release_images; then
        echo "Error: Failed to pull required immutable images for tag ${NEW_TAG}." >&2
        echo "Existing running containers are preserved." >&2
        exit 1
    fi
    mapfile -t image_references < <(resolve_service_image_references "${NEW_TAG}" | cut -d= -f2 | sort -u)
    [[ "${#image_references[@]}" == 3 ]] || { echo 'Expected three resolved release images.' >&2; exit 1; }
    image_verify_start="$(monotonic_ms)"
    for svc_img in "${image_references[@]}"; do
        if ! verify_release_image "${svc_img}" || ! retain_release_image "${svc_img}"; then
            echo "Error: Required immutable image '${svc_img}' is missing after pull." >&2
            echo "Silent build fallback is prohibited in production. Existing running containers are preserved. Aborting deployment." >&2
            exit 1
        fi
    done
    timing_record local_image_verification "${image_verify_start}"
    echo "Verified all required immutable images are present locally."
else
    compose build --pull app web research-worker
fi
timing_run disk_preflight assert_free_disk_space

# PostgreSQL and Redis are existing persistent services. Never reconcile/recreate
# them as part of an application update.
wait_healthy postgres 180
wait_healthy redis 120
baseline_migrations="$(migration_state)"
baseline_schema="$(schema_state)"
[[ -n "${baseline_migrations}" ]] || { echo 'Missing migration baseline; update blocked.' >&2; exit 1; }
mkdir -p "${DEPLOY_STATE_DIR}/release-schemas"
if [[ ! -f "${DEPLOY_STATE_DIR}/release-schemas/${CURRENT_TAG}.migrations" ]]; then
    atomic_write "${DEPLOY_STATE_DIR}/release-schemas/${CURRENT_TAG}.migrations" "${baseline_migrations}"
    atomic_write "${DEPLOY_STATE_DIR}/release-schemas/${CURRENT_TAG}.schema.sha256" "${baseline_schema}"
fi
atomic_write "${DEPLOY_STATE_DIR}/update-in-progress" "${CURRENT_TAG} -> ${NEW_TAG}: MIGRATING"
# A failed migration may have partial DDL without a migration-ledger record.
# Do not arm application rollback until migration and initialization succeed.
timing_run migrations compose run --rm --no-deps app php artisan migrate --force --no-interaction
timing_run financial_initialization compose run --rm --no-deps app php artisan binance:initialize-paper --force --no-interaction

rollback_application() {
    local failure_code="${1:-$?}"
    trap - ERR INT TERM
    stop_transition_observer
    rollback_start="$(monotonic_ms)"
    echo "Deployment failed; evaluating application rollback to ${CURRENT_TAG}. Database migrations are never reversed." >&2
    if ! assert_rollback_migration_state "${CURRENT_TAG}"; then
        atomic_write "${DEPLOY_STATE_DIR}/update-in-progress" "${CURRENT_TAG} -> ${NEW_TAG}: BLOCKED_SCHEMA_REVIEW"
        timing_record rollback "${rollback_start}" "${failure_code}"
        echo '[BLOCKED] Automatic application rollback refused. Preserve images and obtain schema compatibility review.' >&2
        exit "${failure_code}"
    fi
    export IMAGE_TAG="${CURRENT_TAG}"
    if compose up -d --no-build --no-deps "${APPLICATION_SERVICES[@]}" &&
       wait_for_application_services &&
       bash "${SCRIPT_DIR}/healthcheck.sh" &&
       VALIDATE_IMAGE_TAG="${CURRENT_TAG}" bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy; then
        atomic_write "${DEPLOY_STATE_DIR}/update-in-progress" "${CURRENT_TAG}: ROLLED_BACK"
        commit_deployment_state "${CURRENT_TAG}" "${PREVIOUS_TAG:-}" "${CURRENT_TAG#sha-}" "${PREVIOUS_TAG#sha-}" "ROLLED_BACK"
    else
        atomic_write "${DEPLOY_STATE_DIR}/update-in-progress" "${CURRENT_TAG}: ROLLBACK_FAILED"
        commit_deployment_state "${CURRENT_TAG}" "${PREVIOUS_TAG:-}" "${CURRENT_TAG#sha-}" "${PREVIOUS_TAG#sha-}" "ROLLBACK_FAILED"
        echo '[FATAL] Application rollback did not pass verification.' >&2
    fi
    timing_record rollback "${rollback_start}" "${failure_code}"
    exit "${failure_code}"
}
trap 'rollback_application $?' ERR
trap 'rollback_application 130' INT
trap 'rollback_application 143' TERM
atomic_write "${DEPLOY_STATE_DIR}/update-in-progress" "${CURRENT_TAG} -> ${NEW_TAG}: TRANSITIONING"
transition_start="$(monotonic_ms)"
timing_run container_transition_including_worker_grace transition_application_services
health_start="$(monotonic_ms)"

wait_for_application_services

timing_run application_health bash "${SCRIPT_DIR}/healthcheck.sh"
timing_record service_health_and_readiness "${health_start}"

# Validate candidate images before advancing any successful release metadata.
VALIDATE_IMAGE_TAG="${NEW_TAG}" timing_run post_deployment_validation bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy
timing_record service_transition_to_acceptance "${transition_start}"
candidate_migrations="$(migration_state)"
candidate_schema="$(schema_state)"
atomic_write "${DEPLOY_STATE_DIR}/release-schemas/${NEW_TAG}.migrations" "${candidate_migrations}"
atomic_write "${DEPLOY_STATE_DIR}/release-schemas/${NEW_TAG}.schema.sha256" "${candidate_schema}"
# Commit successful release metadata only after every acceptance gate passes.
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

else
    curr_rel="${CURRENT_TAG#sha-}"
    commit_deployment_state "${NEW_TAG}" "${CURRENT_TAG}" "${new_rel}" "${curr_rel}" "CONSISTENT"

fi
rm -f -- "${DEPLOY_STATE_DIR}/update-in-progress"
trap - ERR
trap 'exit 130' INT
trap 'exit 143' TERM
# Cleanup failure cannot undo an accepted release. Retention remains fail-closed.
CURRENT_IMAGE_TAG="${NEW_TAG}" PREVIOUS_IMAGE_TAG="${prev_target_tag:-${CURRENT_TAG}}" \
    timing_run image_cleanup bash "${SCRIPT_DIR}/cleanup-project-images.sh" || echo '[WARN] Image cleanup failed; release retained.' >&2

bash "${SCRIPT_DIR}/storage-report.sh" || echo '[WARN] Storage report unavailable; accepted release retained.' >&2

if [[ "${IS_SAME_TAG_RECONCILE}" == "1" ]]; then
    echo "Reconciled deployment configuration for image tag ${NEW_TAG}."
else
    echo "Updated from image tag ${CURRENT_TAG} to ${NEW_TAG}."
fi
echo "Application rollback requires pre-rollback schema validation through ${SCRIPT_DIR}/rollback.sh."
echo "Database migrations must be backward-compatible; database rollback is intentionally not automatic."
