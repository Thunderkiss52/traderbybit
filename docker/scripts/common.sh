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
if [[ -n "${UI_BASIC_AUTH_PASSWORD_B64:-}" ]]; then
    if ! UI_BASIC_AUTH_PASSWORD="$(printf '%s' "${UI_BASIC_AUTH_PASSWORD_B64}" | base64 --decode)" || [[ -z "${UI_BASIC_AUTH_PASSWORD}" ]]; then
        echo "UI_BASIC_AUTH_PASSWORD_B64 must contain a non-empty base64-encoded password." >&2
        exit 1
    fi
    export UI_BASIC_AUTH_PASSWORD
fi
if [[ -z "${UI_BASIC_AUTH_USER:-}" || -z "${UI_BASIC_AUTH_PASSWORD:-}" ]]; then
    echo "UI_BASIC_AUTH_USER and either UI_BASIC_AUTH_PASSWORD or UI_BASIC_AUTH_PASSWORD_B64 are required." >&2
    exit 1
fi
ENV_FILE="${REQUESTED_ENV_FILE}"
if [[ -n "${REQUESTED_IMAGE_TAG}" ]]; then
    IMAGE_TAG="${REQUESTED_IMAGE_TAG}"
fi

COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-bybit-signals}"
if [[ "${COMPOSE_PROJECT_NAME}" != "bybit-signals" && "${ALLOW_TEST_PROJECT:-0}" != "1" ]]; then
    echo "COMPOSE_PROJECT_NAME must be bybit-signals for this deployment." >&2
    exit 1
fi

APPLICATION_SERVICES=(
    app
    web
    queue
    scheduler
    market-ws
    audit-worker
    backtest-worker
    research-worker
)

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

resolve_service_image_references() {
    local target_tag="${1:-${IMAGE_TAG:-latest}}"
    IMAGE_TAG="${target_tag}" compose config --format json 2>/dev/null | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(1)
services = ["app", "web", "queue", "scheduler", "market-ws", "audit-worker", "backtest-worker", "research-worker"]
for s in services:
    ref = data.get("services", {}).get(s, {}).get("image", "")
    if not ref:
        sys.exit(1)
    print(f"{s}={ref}")
'
}

check_service_readiness() {
    local service="$1"
    local cid
    cid="$(compose ps -q "${service}" 2>/dev/null)" || return 1
    [[ -n "${cid}" ]] || return 1

    case "${service}" in
        app)
            timeout 5 docker exec "${cid}" php -r '$s=@fsockopen("127.0.0.1",9000,$e,$m,2); exit($s ? 0 : 1);'
            ;;
        web)
            timeout 5 docker exec "${cid}" wget -qO- http://127.0.0.1/up >/dev/null
            ;;
        queue)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'queue:work'"
            ;;
        scheduler)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'schedule:work'"
            ;;
        market-ws)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'market:stream-candles'"
            ;;
        audit-worker)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'queue:work.*audit'"
            ;;
        backtest-worker)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'queue:work.*backtests'"
            ;;
        research-worker)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'research-worker'" || return 1
            local started_at
            started_at="$(docker inspect --format '{{.State.StartedAt}}' "${cid}" 2>/dev/null || true)"
            [[ -n "${started_at}" ]] || return 1
            # Operational heartbeat: Worker loop started, No queued jobs, Processed N jobs
            # CRITICAL: Claim error must NOT independently qualify a worker as ready!
            compose logs --tail=50 --since=60s research-worker 2>/dev/null | grep -Eq "Worker loop started|No queued jobs|Processed [0-9]+ jobs"
            ;;
        *)
            return 1
            ;;
    esac
}

wait_service_ready() {
    local service="$1" timeout="${2:-120}" elapsed=0
    while (( elapsed < timeout )); do
        if check_service_readiness "${service}"; then
            return 0
        fi
        sleep 3
        elapsed=$((elapsed + 3))
    done
    echo "Timed out waiting for readiness of ${service}." >&2
    compose logs --tail=100 "${service}" >&2
    return 1
}

is_ancestor_pid() {
    local target_pid="$1"
    local cur_pid="$$"
    while [[ -n "${cur_pid}" && "${cur_pid}" -ne 0 && "${cur_pid}" -ne 1 ]]; do
        local ppid
        ppid="$(awk '{print $4}' "/proc/${cur_pid}/stat" 2>/dev/null || echo "")"
        [[ -n "${ppid}" ]] || break
        if [[ "${ppid}" -eq "${target_pid}" ]]; then
            return 0
        fi
        cur_pid="${ppid}"
    done
    return 1
}

acquire_deployment_lock() {
    local op="${1:-DEPLOYMENT}"
    local lockfile="${DEPLOY_STATE_DIR}/deployment.lock"
    mkdir -p "${DEPLOY_STATE_DIR}"

    exec 200>>"${lockfile}"
    if flock -n 200; then
        echo "pid=$$" >"${lockfile}"
        echo "operation=${op}" >>"${lockfile}"
        echo "locked_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"${lockfile}"
        export DEPLOYMENT_LOCK_OWNER_PID="$$"
        trap release_deployment_lock EXIT INT TERM
        return 0
    fi

    # flock failed - check if lock owner is an ancestor of this process (reentrancy)
    local locked_pid
    locked_pid="$(grep -oE '^pid=[0-9]+' "${lockfile}" 2>/dev/null | head -n 1 | cut -d= -f2 || true)"
    if [[ -n "${locked_pid}" ]] && is_ancestor_pid "${locked_pid}"; then
        echo "[INFO] Deployment lock reentrancy verified (lock owned by ancestor PID ${locked_pid} for operation '${op}')." >&2
        export DEPLOYMENT_LOCK_REENTRANT=1
        return 0
    fi

    echo "[FATAL] Deployment lock is held by another process (PID ${locked_pid:-unknown}). Operation '${op}' blocked." >&2
    return 1
}

release_deployment_lock() {
    if [[ "${DEPLOYMENT_LOCK_REENTRANT:-0}" == "1" ]]; then
        return 0
    fi
    if [[ "${DEPLOYMENT_LOCK_OWNER_PID:-}" == "$$" ]]; then
        local lockfile="${DEPLOY_STATE_DIR}/deployment.lock"
        flock -u 200 2>/dev/null || true
        exec 200>&- 2>/dev/null || true
        # NEVER unlink lockfile to prevent flock race condition
        unset DEPLOYMENT_LOCK_OWNER_PID
    fi
}

verify_image_identity() {
    local service="$1"
    local expected_ref="$2"
    local cid
    cid="$(compose ps -q "${service}" 2>/dev/null || true)"
    [[ -n "${cid}" ]] || { echo "[FATAL] Service '${service}' container not found." >&2; return 1; }

    local actual_img actual_id repo_digests
    actual_img="$(docker inspect --format '{{.Config.Image}}' "${cid}" 2>/dev/null || true)"
    actual_id="$(docker inspect --format '{{.Id}}' "${cid}" 2>/dev/null || true)"
    repo_digests="$(docker inspect --format '{{json .RepoDigests}}' "${cid}" 2>/dev/null || echo "[]")"

    local expected_id
    expected_id="$(docker image inspect --format '{{.Id}}' "${expected_ref}" 2>/dev/null || true)"
    [[ -n "${expected_id}" ]] || { echo "[FATAL] Expected image '${expected_ref}' not found in Docker cache." >&2; return 1; }

    # 1. Verify image ID matches expected release artifact image ID
    if [[ "${actual_id}" != "${expected_id}" ]]; then
        echo "[FATAL] Service '${service}' image ID mismatch! Running: ${actual_id}, Expected: ${expected_id}" >&2
        return 1
    fi

    # 2. Verify Config.Image matches expected reference tag
    if [[ "${actual_img}" != "${expected_ref}" ]]; then
        echo "[FATAL] Service '${service}' Config.Image mismatch! Running: ${actual_img}, Expected: ${expected_ref}" >&2
        return 1
    fi

    # 3. Compare RepoDigests when non-empty
    if [[ "${repo_digests}" != "[]" && "${repo_digests}" != "null" && -n "${repo_digests}" ]]; then
        local expected_digests
        expected_digests="$(docker image inspect --format '{{json .RepoDigests}}' "${expected_ref}" 2>/dev/null || echo "[]")"
        if [[ "${expected_digests}" != "[]" && "${expected_digests}" != "null" && -n "${expected_digests}" ]]; then
            if [[ "${repo_digests}" != "${expected_digests}" ]]; then
                echo "[FATAL] Service '${service}' RepoDigests mismatch! Running: ${repo_digests}, Expected: ${expected_digests}" >&2
                return 1
            fi
        fi
    fi

    return 0
}

atomic_write() {
    local target="$1"
    local content="$2"
    local tmp="${target}.tmp.$$"
    mkdir -p "$(dirname "${target}")"
    printf '%s\n' "${content}" >"${tmp}"
    sync "${tmp}" 2>/dev/null || true
    mv -Tf "${tmp}" "${target}"
}

commit_deployment_state() {
    local curr_tag="$1"
    local prev_tag="${2:-}"
    local curr_rel="${3:-}"
    local prev_rel="${4:-}"
    local status="${5:-CONSISTENT}"
    local journal_id="${6:-$(python3 -c 'import uuid; print(uuid.uuid4())' 2>/dev/null || echo '00000000-0000-0000-0000-000000000000')}"
    local now
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if [[ -z "${curr_rel}" && -f "${DEPLOY_STATE_DIR}/release-current" ]]; then
        curr_rel="$(<"${DEPLOY_STATE_DIR}/release-current")"
    fi
    if [[ -z "${prev_rel}" && -f "${DEPLOY_STATE_DIR}/release-previous" ]]; then
        prev_rel="$(<"${DEPLOY_STATE_DIR}/release-previous")"
    fi

    local state_json
    state_json="$(python3 -c '
import json, sys

services = ["app", "web", "queue", "scheduler", "market-ws", "audit-worker", "backtest-worker", "research-worker"]
services_dict = {s: {"image_reference": "", "container_status": "configured"} for s in services}

state = {
    "schema_version": "1.0.0",
    "status": sys.argv[1],
    "current_release_sha": sys.argv[2],
    "current_image_tag": sys.argv[3],
    "previous_release_sha": sys.argv[4],
    "previous_image_tag": sys.argv[5],
    "services": services_dict,
    "updated_at": sys.argv[6],
    "last_journal_id": sys.argv[7]
}
print(json.dumps(state, indent=2))
' "${status}" "${curr_rel}" "${curr_tag}" "${prev_rel}" "${prev_tag}" "${now}" "${journal_id}")"

    atomic_write "${DEPLOY_STATE_DIR}/deploy-state.json" "${state_json}"

    # In DEGRADED_UNVERIFIED mode, DO NOT update canonical successful release projections
    if [[ "${status}" == "DEGRADED_UNVERIFIED" ]]; then
        echo "[WARN] Deployment state committed as DEGRADED_UNVERIFIED. Canonical successful release metadata was NOT modified." >&2
        return 0
    fi

    atomic_write "${DEPLOY_STATE_DIR}/.deploy-current" "${curr_tag}"
    if [[ -n "${prev_tag}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/.deploy-previous" "${prev_tag}"
    fi
    if [[ -n "${curr_rel}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/release-current" "${curr_rel}"
    fi
    if [[ -n "${prev_rel}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/release-previous" "${prev_rel}"
    fi
}
