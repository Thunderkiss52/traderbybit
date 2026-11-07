#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

current="${CURRENT_IMAGE_TAG:-}"
if [[ -z "${current}" && -f "${DEPLOY_STATE_DIR}/.deploy-current" ]]; then
    current="$(<"${DEPLOY_STATE_DIR}/.deploy-current")"
fi
[[ -n "${current}" ]] || { echo "Current image tag is unknown; refusing cleanup." >&2; exit 1; }
previous="${PREVIOUS_IMAGE_TAG:-}"
if [[ -z "${previous}" && -f "${DEPLOY_STATE_DIR}/.deploy-previous" ]]; then
    previous="$(<"${DEPLOY_STATE_DIR}/.deploy-previous")"
fi

acquire_deployment_lock "IMAGE_RETENTION"
declare -A protected_ids
declare -A checked_refs
for tag in "${current}" "${previous}"; do
    [[ -n "${tag}" ]] || continue
    references="$(resolve_service_image_references "${tag}")" || exit 1
    while IFS='=' read -r service reference; do
        [[ -n "${reference}" ]] || continue
        if [[ -n "${checked_refs["${reference}"]:-}" ]]; then
            continue
        fi
        checked_refs["${reference}"]=1
        id="$(docker image inspect --format '{{.Id}}' "${reference}")" || {
            echo "Required release image missing; refusing cleanup: ${reference}" >&2; exit 1;
        }
        [[ "${id}" =~ ^sha256:[0-9a-f]{64}$ ]] || exit 1
        protected_ids["${id}"]=1
    done <<< "${references}"
done

for repository in "${APP_IMAGE}" "${WEB_IMAGE}" "${RESEARCH_WORKER_IMAGE}"; do
    # Exact repository references only. Never remove image IDs, unknown dangling
    # images, shared layers directly, or artifacts protected under another alias.
    candidates="$(docker image ls "${repository}" --format '{{.Repository}}:{{.Tag}}')" || exit 1
    while read -r reference; do
        [[ "${reference}" == "${repository}:"* && "${reference}" != *':<none>' ]] || continue
        tag="${reference#"${repository}:"}"
        [[ "${tag}" == "${current}" || "${tag}" == "${previous}" ]] && continue
        id="$(docker image inspect --format '{{.Id}}' "${reference}")" || exit 1
        [[ -n "${protected_ids[${id}]:-}" ]] && continue
        containers="$(docker ps -aq --filter "ancestor=${reference}")" || exit 1
        [[ -z "${containers}" ]] || continue
        docker image rm "${reference}"
    done <<< "${candidates}"
done
