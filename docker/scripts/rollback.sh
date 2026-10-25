#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

acquire_deployment_lock "ROLLBACK"
bash "${SCRIPT_DIR}/validate-deployment-state.sh" pre-rollback

[[ -f "${DEPLOY_STATE_DIR}/.deploy-current" && -f "${DEPLOY_STATE_DIR}/.deploy-previous" ]] || {
    echo "Current and previous deployment state are required for rollback." >&2
    exit 1
}
current="$(<"${DEPLOY_STATE_DIR}/.deploy-current")"
previous="$(<"${DEPLOY_STATE_DIR}/.deploy-previous")"
[[ -n "${previous}" && "${previous}" != "${current}" ]] || { echo "No distinct previous image tag is available." >&2; exit 1; }

export IMAGE_TAG="${previous}"
compose up -d --no-deps "${APPLICATION_SERVICES[@]}"
compose restart web

for svc in "${APPLICATION_SERVICES[@]}"; do
    wait_service_ready "${svc}" 120
done

bash "${SCRIPT_DIR}/healthcheck.sh"

prev_rel="${previous#sha-}"
curr_rel="${current#sha-}"
commit_deployment_state "${previous}" "${current}" "${prev_rel}" "${curr_rel}" "CONSISTENT"
bash "${SCRIPT_DIR}/validate-deployment-state.sh" post-deploy

echo "Application rolled back from ${current} to ${previous}. Database migrations were not changed."
