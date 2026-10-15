#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/common.sh"

[[ -f "${DEPLOY_STATE_DIR}/.deploy-current" && -f "${DEPLOY_STATE_DIR}/.deploy-previous" ]] || {
    echo "Current and previous deployment state are required for rollback." >&2
    exit 1
}
current="$(<"${DEPLOY_STATE_DIR}/.deploy-current")"
previous="$(<"${DEPLOY_STATE_DIR}/.deploy-previous")"
[[ -n "${previous}" && "${previous}" != "${current}" ]] || { echo "No distinct previous image tag is available." >&2; exit 1; }

export IMAGE_TAG="${previous}"
compose up -d --no-deps app web queue scheduler market-ws backtest-worker
"${SCRIPT_DIR}/healthcheck.sh"
printf '%s\n' "${current}" >"${DEPLOY_STATE_DIR}/.deploy-previous"
printf '%s\n' "${previous}" >"${DEPLOY_STATE_DIR}/.deploy-current"
echo "Application rolled back from ${current} to ${previous}. Database migrations were not changed."
