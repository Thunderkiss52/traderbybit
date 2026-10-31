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

for repository in "${APP_IMAGE}" "${WEB_IMAGE}" "${RESEARCH_WORKER_IMAGE}"; do
    while read -r tag image_id; do
        [[ -n "${tag}" && "${tag}" != "<none>" ]] || continue
        [[ "${tag}" == "${current}" || "${tag}" == "${previous}" ]] && continue
        reference="${repository}:${tag}"
        if [[ -z "$(docker ps -aq --filter "ancestor=${reference}")" ]]; then
            docker image rm "${reference}"
        fi
    done < <(docker image ls "${repository}" --format '{{.Tag}} {{.ID}}')
done

for component in app web research-worker; do
    while read -r image_id; do
        [[ -n "${image_id}" ]] || continue
        if [[ -z "$(docker ps -aq --filter "ancestor=${image_id}")" ]]; then
            docker image rm "${image_id}"
        fi
    done < <(docker image ls --filter "label=io.bybit-signals.component=${component}" --filter dangling=true -q)
done
