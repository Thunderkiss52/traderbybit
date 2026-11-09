#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_STATE_DIR="${DEPLOY_STATE_DIR:-${PROJECT_ROOT}}"
source "${PROJECT_ROOT}/docker/scripts/timing.sh"
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

# Auto-derive RESEARCH_WORKER_IMAGE if missing but APP_IMAGE or WEB_IMAGE is provided
if [[ -z "${RESEARCH_WORKER_IMAGE:-}" ]]; then
    if [[ -n "${APP_IMAGE:-}" && "${APP_IMAGE}" == */app ]]; then
        RESEARCH_WORKER_IMAGE="${APP_IMAGE%/app}/research-worker"
        export RESEARCH_WORKER_IMAGE
    elif [[ -n "${WEB_IMAGE:-}" && "${WEB_IMAGE}" == */web ]]; then
        RESEARCH_WORKER_IMAGE="${WEB_IMAGE%/web}/research-worker"
        export RESEARCH_WORKER_IMAGE
    fi
fi

if [[ -z "${IMAGE_TAG:-}" ]]; then
    IMAGE_TAG="latest"
fi
export IMAGE_TAG

# Fail closed if image references are missing or use unqualified fallback names in pull mode
if [[ "${DEPLOY_MODE:-}" == "pull" && "${ALLOW_TEST_PROJECT:-0}" != "1" ]]; then
    for img_var in APP_IMAGE WEB_IMAGE RESEARCH_WORKER_IMAGE; do
        val="${!img_var:-}"
        if [[ -z "${val}" ]]; then
            echo "[FATAL] ${img_var} is required for pull deployment mode." >&2
            exit 1
        fi
        if [[ "${val}" =~ ^bybit-signals- || "${val}" != *"/"* ]]; then
            echo "[FATAL] ${img_var} must be a qualified registry image reference (e.g. ghcr.io/<owner>/<repo>/<target>), got: '${val}'." >&2
            exit 1
        fi
    done
fi
export APP_IMAGE WEB_IMAGE RESEARCH_WORKER_IMAGE

COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-bybit-signals}"
if [[ "${COMPOSE_PROJECT_NAME}" != "bybit-signals" && "${ALLOW_TEST_PROJECT:-0}" != "1" ]]; then
    echo "COMPOSE_PROJECT_NAME must be bybit-signals for this deployment." >&2
    exit 1
fi

APPLICATION_SERVICES=(
    app
    web
    queue
    queue-bybit
    queue-binance
    scheduler
    market-ws
    audit-worker
    backtest-worker
    research-worker
)

mkdir -p "${DEPLOY_STATE_DIR}"
export ENV_FILE COMPOSE_PROJECT_NAME DEPLOY_STATE_DIR APP_IMAGE WEB_IMAGE RESEARCH_WORKER_IMAGE IMAGE_TAG
COMPOSE_ARGS=(docker compose --project-name "${COMPOSE_PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${PROJECT_ROOT}/compose.yaml")
if [[ "${USE_EXTERNAL_PROXY:-0}" == "1" ]]; then
    : "${EXTERNAL_PROXY_NETWORK:?EXTERNAL_PROXY_NETWORK is required when USE_EXTERNAL_PROXY=1}"
    COMPOSE_ARGS+=(-f "${PROJECT_ROOT}/compose.proxy.yaml")
fi

compose() {
    local overlay="${DEPLOY_STATE_DIR}/release-images/${IMAGE_TAG}.json"
    if [[ -f "${overlay}" ]]; then
        "${COMPOSE_ARGS[@]}" -f "${overlay}" "$@"
    else
        "${COMPOSE_ARGS[@]}" "$@"
    fi
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
    local service="$1" timeout="${2:-180}" start id status
    start="$(monotonic_ms)"
    while (( $(monotonic_ms) - start < timeout * 1000 )); do
        id="$(compose ps -q "${service}")"
        if [[ -n "${id}" ]]; then
            status="$(docker inspect --format '{{if and .State.Running (not .State.Restarting) (not .State.Paused)}}{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}{{else}}{{.State.Status}}{{end}}' "${id}")"
            if [[ "${status}" == "healthy" || "${status}" == "running" ]]; then
                return 0
            fi
            if [[ "${status}" == "unhealthy" || "${status}" == "exited" || "${status}" == "dead" ]]; then
                compose logs --tail=100 "${service}" >&2
                return 1
            fi
        fi
        sleep 0.5
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
services = ["app", "web", "queue", "queue-bybit", "queue-binance", "scheduler", "market-ws", "audit-worker", "backtest-worker", "research-worker"]
for s in services:
    ref = data.get("services", {}).get(s, {}).get("image", "")
    if not ref:
        sys.exit(1)
    print(f"{s}={ref}")
'
}

pull_release_images() {
    local overlay="${DEPLOY_STATE_DIR}/release-images/${IMAGE_TAG}.json" policy=always
    local -a targets=(app web research-worker)
    local -a pull_targets=()
    local target_refs
    # Only the final, complete exact-SHA digest overlay authorizes cache reuse.
    # Legacy tag-only definitions retain registry verification on every pull.
    if [[ -f "${overlay}" ]] && target_refs="$(python3 - "${overlay}" "${IMAGE_TAG}" "${APPLICATION_SERVICES[@]}" <<'PYCODE'
import json, re, sys
services = json.load(open(sys.argv[1]))["services"]
valid = set(services) == set(sys.argv[3:]) and all(
    re.fullmatch(r".+:" + re.escape(sys.argv[2]) + r"@sha256:[a-f0-9]{64}", s.get("image", ""))
    for s in services.values())
if not valid:
    sys.exit(1)
for t in ("app", "web", "research-worker"):
    print(f"{t}={services.get(t, {}).get('image', '')}")
PYCODE
)"; then
        policy=missing
        while IFS='=' read -r target img; do
            [[ -n "${target}" ]] || continue
            if [[ -z "${img}" ]] || ! docker image inspect "${img}" >/dev/null 2>&1; then
                pull_targets+=("${target}")
            fi
        done <<< "${target_refs}"

        if [[ ${#pull_targets[@]} -eq 0 ]]; then
            return 0
        fi
        compose pull --policy "${policy}" "${pull_targets[@]}"
    else
        compose pull --policy "${policy}" "${targets[@]}"
    fi
}

retain_release_image() {
    local reference="$1"
    docker image inspect "${reference}" >/dev/null || return 1
    if [[ "${reference}" == *@sha256:* ]]; then
        # Keep current/previous SHA aliases visible to the existing scoped image cleanup.
        docker tag "${reference}" "${reference%@*}"
    fi
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
        queue-bybit|queue-binance)
            timeout 5 docker exec "${cid}" sh -c "tr '\\0' ' ' </proc/1/cmdline | grep -q 'queue:work.*trading-${service#queue-}'"
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
            local st
            st="$(docker inspect --format '{{.State.Status}}' "${cid}" 2>/dev/null || echo "unknown")"
            [[ "${st}" == "running" ]] || return 1
            # Operational heartbeat: Worker loop started, No queued jobs, Processed N jobs
            # Go standard library log package prints to stderr, so 2>&1 is required to pipe logs to grep
            docker logs --tail=50 "${cid}" 2>&1 | grep -Eq "Worker loop started|No queued jobs|Processed [0-9]+ jobs" || return 1
            # Negative control: active claim errors must NOT pass readiness
            if docker logs --tail=10 "${cid}" 2>&1 | grep -q "Claim error"; then
                return 1
            fi
            ;;
        *)
            return 1
            ;;
    esac
}

wait_service_ready() {
    local service="$1" timeout="${2:-120}" start
    start="$(monotonic_ms)"
    wait_healthy "${service}" "${timeout}" || return 1
    while (( $(monotonic_ms) - start < timeout * 1000 )); do
        if check_service_readiness "${service}"; then
            return 0
        fi
        sleep 0.5
    done
    echo "Timed out waiting for readiness of ${service}." >&2
    compose logs --tail=100 "${service}" >&2
    return 1
}

stop_transition_observer() {
    if [[ -n "${TRANSITION_OBSERVER_PID:-}" ]]; then
        kill -TERM "${TRANSITION_OBSERVER_PID}" 2>/dev/null || true
        wait "${TRANSITION_OBSERVER_PID}" 2>/dev/null || true
        unset TRANSITION_OBSERVER_PID
        if [[ -s "${TRANSITION_OBSERVER_FILE}" ]]; then
            printf 'VECTOR_TIMING %s\n' "$(<"${TRANSITION_OBSERVER_FILE}")"
            [[ -z "${DEPLOY_TIMING_FILE:-}" ]] || { cat "${TRANSITION_OBSERVER_FILE}" >>"${DEPLOY_TIMING_FILE}"; printf '\n' >>"${DEPLOY_TIMING_FILE}"; }
        fi
        rm -f -- "${TRANSITION_OBSERVER_FILE}"
    fi
}

transition_application_services() {
    local status
    TRANSITION_OBSERVER_FILE="$(mktemp "${DEPLOY_STATE_DIR}/transition.XXXXXX")"
    python3 "${PROJECT_ROOT}/docker/scripts/observe-transition.py" "${COMPOSE_PROJECT_NAME}" "${TRANSITION_OBSERVER_FILE}" 200>&- &
    TRANSITION_OBSERVER_PID=$!
    if compose up -d --no-build --no-deps "${APPLICATION_SERVICES[@]}"; then status=0; else status=$?; fi
    stop_transition_observer
    return "${status}"
}

wait_for_application_services() {
    local timeout="${1:-120}" start
    start="$(monotonic_ms)"
    local -a pending=("${APPLICATION_SERVICES[@]}")
    while (( ${#pending[@]} > 0 )); do
        if (( $(monotonic_ms) - start >= timeout * 1000 )); then
            echo "Timed out waiting for application services: ${pending[*]}." >&2
            return 1
        fi
        local -a still_pending=()
        for service in "${pending[@]}"; do
            local cid
            cid="$(compose ps -q "${service}" 2>/dev/null || true)"
            if [[ -z "${cid}" ]]; then
                still_pending+=("${service}")
                continue
            fi
            local st
            st="$(docker inspect --format '{{if and .State.Running (not .State.Restarting) (not .State.Paused)}}{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}{{else}}{{.State.Status}}{{end}}' "${cid}" 2>/dev/null || true)"
            if [[ "${st}" == "unhealthy" || "${st}" == "exited" || "${st}" == "dead" ]]; then
                compose logs --tail=100 "${service}" >&2
                return 1
            fi
            if [[ "${st}" == "healthy" || "${st}" == "running" ]]; then
                if check_service_readiness "${service}"; then
                    continue
                fi
            fi
            still_pending+=("${service}")
        done
        pending=("${still_pending[@]}")
        if (( ${#pending[@]} > 0 )); then
            sleep 0.5
        fi
    done
    return 0
}

is_ancestor_pid() {
    local target_pid="$1"
    [[ "${target_pid}" =~ ^[0-9]+$ ]] || return 1
    local cur_pid="$$"
    while [[ -n "${cur_pid}" && "${cur_pid}" -ne 0 && "${cur_pid}" -ne 1 ]]; do
        local ppid
        ppid="$(awk '{print $4}' "/proc/${cur_pid}/stat" 2>/dev/null || echo "")"
        [[ "${ppid}" =~ ^[0-9]+$ ]] || break
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
        trap release_deployment_lock EXIT
        trap 'exit 130' INT
        trap 'exit 143' TERM
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

declare -gA _EXPECTED_IMAGE_IDS=()
declare -gA _VERIFIED_RELEASE_IMAGES=()

verify_image_identity() {
    local service="$1"
    local expected_ref="$2"
    local cid="${3:-}"
    if [[ -z "${cid}" ]]; then
        cid="$(compose ps -q "${service}" 2>/dev/null || true)"
    fi
    [[ -n "${cid}" ]] || { echo "[FATAL] Service '${service}' container not found." >&2; return 1; }

    local actual_img actual_id
    actual_img="$(docker inspect --format '{{.Config.Image}}' "${cid}" 2>/dev/null || true)"
    actual_id="$(docker inspect --format '{{.Image}}' "${cid}" 2>/dev/null || true)"

    local expected_id="${_EXPECTED_IMAGE_IDS["${expected_ref}"]:-}"
    if [[ -z "${expected_id}" ]]; then
        expected_id="$(docker image inspect --format '{{.Id}}' "${expected_ref}" 2>/dev/null || true)"
        [[ -n "${expected_id}" ]] || { echo "[FATAL] Expected image '${expected_ref}' not found in Docker cache." >&2; return 1; }
        _EXPECTED_IMAGE_IDS["${expected_ref}"]="${expected_id}"
    fi

    # A registry index/manifest digest is not a Docker image config ID. Resolve the
    # approved reference through Docker for this host platform, then compare IDs.
    [[ "${actual_id}" =~ ^sha256:[0-9a-f]{64}$ && "${expected_id}" =~ ^sha256:[0-9a-f]{64}$ ]] || {
        echo "[FATAL] Invalid Docker image identity for '${service}'." >&2; return 1;
    }
    if [[ "${actual_id}" != "${expected_id}" ]]; then
        echo "[FATAL] Service '${service}' image ID mismatch! Running: ${actual_id} (${actual_img}), Expected: ${expected_id} (${expected_ref})" >&2
        return 1
    fi
    verify_release_image "${expected_ref}" || return 1
    echo "[IMAGE] service=${service} expected=${expected_ref} creation_ref=${actual_img} image_id=${actual_id} result=PASS"

    return 0
}

verify_release_image() {
    local reference="$1" revision expected_sha
    if [[ -n "${_VERIFIED_RELEASE_IMAGES["${reference}"]:-}" ]]; then
        return 0
    fi
    docker image inspect "${reference}" >/dev/null || return 1
    if [[ "${reference}" == *@* ]]; then
        [[ "${reference}" =~ :sha-([0-9a-f]{40})@sha256:[0-9a-f]{64}$ ]] || {
            echo "[FATAL] Invalid immutable release reference: ${reference}" >&2; return 1;
        }
        expected_sha="${BASH_REMATCH[1]}"
        revision="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${reference}")" || return 1
        [[ "${revision}" == "${expected_sha}" ]] || {
            echo "[FATAL] OCI revision mismatch for ${reference}: ${revision}" >&2; return 1;
        }
    fi
    _VERIFIED_RELEASE_IMAGES["${reference}"]=1
    return 0
}

# Snapshot the migration ledger of a verified known-good release. Changed or
# uncertain migration state never authorizes application rollback.
migration_state() {
    compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -Atc \
        'SELECT migration FROM migrations ORDER BY migration;'
}

schema_state() {
    # pg_dump 17 emits random psql restriction keys. Exclude only those transport
    # guards and comments; retain all executable schema DDL in the fingerprint.
    compose exec -T postgres pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" \
        --schema-only --no-owner --no-acl --no-comments --quote-all-identifiers \
        | sed -E '/^\\(un)?restrict /d; /^--/d' | sha256sum | cut -d' ' -f1
}

assert_rollback_migration_state() {
    local target="$1" actual actual_schema snapshot="${DEPLOY_STATE_DIR}/release-schemas/${1}.migrations"
    [[ -s "${snapshot}" ]] || { echo "[BLOCKED] No known-good migration snapshot for ${target}." >&2; return 1; }
    if [[ -f "${DEPLOY_STATE_DIR}/update-in-progress" ]] &&
       grep -Eq 'MIGRATING|BLOCKED_SCHEMA_REVIEW|ROLLBACK_FAILED' "${DEPLOY_STATE_DIR}/update-in-progress"; then
        echo '[BLOCKED] Incomplete migration/recovery journal; schema state is uncertain.' >&2
        return 1
    fi
    [[ -s "${snapshot%.migrations}.schema.sha256" ]] || {
        echo "[BLOCKED] No exact schema fingerprint for ${target}." >&2; return 1;
    }
    actual_schema="$(schema_state)" || return 1
    [[ "${actual_schema}" == "$(<"${snapshot%.migrations}.schema.sha256")" ]] || {
        echo '[BLOCKED] PostgreSQL schema differs from known-good release; no application rollback.' >&2; return 1;
    }
    actual="$(migration_state)" || return 1
    [[ "${actual}" == "$(<"${snapshot}")" ]] || {
        echo "[BLOCKED] Migration state changed since ${target}; backward compatibility requires explicit review. No automatic database reversal." >&2
        return 1
    }
}

report_postgres_lock_diagnostics() {
    echo "[DIAGNOSTIC] Checking active PostgreSQL queries and transactions:" >&2
    compose exec -T postgres psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -c "
        SELECT pid, now() - xact_start AS xact_duration, state, wait_event_type, wait_event, query
        FROM pg_stat_activity
        WHERE state != 'idle' AND pid != pg_backend_pid();
    " >&2 || true

    echo "[DIAGNOSTIC] Checking locks on application tables:" >&2
    compose exec -T postgres psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -c "
        SELECT l.pid, c.relname, l.mode, l.granted
        FROM pg_locks l
        JOIN pg_class c ON l.relation = c.oid
        JOIN pg_namespace n ON c.relnamespace = n.oid
        WHERE n.nspname = 'public' AND c.relname IN ('signals', 'migrations', 'market_candles');
    " >&2 || true
}

run_safe_database_migration() {
    local timeout_secs="${MIGRATION_TIMEOUT_SECONDS:-300}"
    local mig_exit=0
    echo "[MIGRATION] Running database migrations with timeout ceiling of ${timeout_secs}s..."

    compose run --rm --no-deps app php artisan migrate --force --no-interaction &
    local mig_pid=$!
    local elapsed=0

    while kill -0 "${mig_pid}" 2>/dev/null; do
        sleep 1
        elapsed=$((elapsed + 1))
        if [[ "${elapsed}" -ge "${timeout_secs}" ]]; then
            echo "[ERROR] Database migration timed out after ${timeout_secs}s. Terminating container PID ${mig_pid}..." >&2
            kill -TERM "${mig_pid}" 2>/dev/null || true
            sleep 2
            kill -KILL "${mig_pid}" 2>/dev/null || true
            wait "${mig_pid}" 2>/dev/null || true
            report_postgres_lock_diagnostics
            return 124
        fi
    done

    wait "${mig_pid}" || mig_exit=$?

    if [[ "${mig_exit}" -ne 0 ]]; then
        echo "[ERROR] Database migration failed (exit code: ${mig_exit})." >&2
        report_postgres_lock_diagnostics
        return "${mig_exit}"
    fi

    echo "[MIGRATION] Database migrations completed successfully."
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

    local resolved_images=""
    resolved_images="$(resolve_service_image_references "${curr_tag}" 2>/dev/null || true)"

    local state_json
    state_json="$(python3 -c '
import json, sys

status = sys.argv[1]
curr_rel = sys.argv[2]
curr_tag = sys.argv[3]
prev_rel = sys.argv[4]
prev_tag = sys.argv[5]
now = sys.argv[6]
journal_id = sys.argv[7]
resolved_raw = sys.argv[8]

services = ["app", "web", "queue", "queue-bybit", "queue-binance", "scheduler", "market-ws", "audit-worker", "backtest-worker", "research-worker"]
resolved_map = {}
if resolved_raw:
    for line in resolved_raw.strip().splitlines():
        if "=" in line:
            svc, ref = line.split("=", 1)
            resolved_map[svc.strip()] = ref.strip()

services_dict = {}
for s in services:
    ref = resolved_map.get(s, "")
    c_status = "running" if status in ("CONSISTENT", "ROLLED_BACK") else "configured"
    services_dict[s] = {
        "image_reference": ref,
        "container_status": c_status
    }

state = {
    "schema_version": "1.0.0",
    "status": status,
    "current_release_sha": curr_rel,
    "current_image_tag": curr_tag,
    "previous_release_sha": prev_rel,
    "previous_image_tag": prev_tag,
    "services": services_dict,
    "updated_at": now,
    "last_journal_id": journal_id
}
print(json.dumps(state, indent=2))
' "${status}" "${curr_rel}" "${curr_tag}" "${prev_rel}" "${prev_tag}" "${now}" "${journal_id}" "${resolved_images}")"

    atomic_write "${DEPLOY_STATE_DIR}/deploy-state.json" "${state_json}"

    # In DEGRADED_UNVERIFIED or ROLLBACK_FAILED mode, DO NOT update canonical successful release projections
    if [[ "${status}" == "DEGRADED_UNVERIFIED" || "${status}" == "ROLLBACK_FAILED" ]]; then
        echo "[WARN] Deployment state committed as ${status}. Canonical successful release metadata was NOT modified." >&2
        return 0
    fi

    atomic_write "${DEPLOY_STATE_DIR}/.deploy-current" "${curr_tag}"
    if [[ -n "${prev_tag}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/.deploy-previous" "${prev_tag}"
    elif [[ -f "${DEPLOY_STATE_DIR}/.deploy-previous" && "$(<"${DEPLOY_STATE_DIR}/.deploy-previous")" == "${curr_tag}" ]]; then
        rm -f -- "${DEPLOY_STATE_DIR}/.deploy-previous"
    fi
    if [[ -n "${curr_rel}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/release-current" "${curr_rel}"
    fi
    if [[ -n "${prev_rel}" ]]; then
        atomic_write "${DEPLOY_STATE_DIR}/release-previous" "${prev_rel}"
    elif [[ -f "${DEPLOY_STATE_DIR}/release-previous" && "$(<"${DEPLOY_STATE_DIR}/release-previous")" == "${curr_rel}" ]]; then
        rm -f -- "${DEPLOY_STATE_DIR}/release-previous"
    fi
}
