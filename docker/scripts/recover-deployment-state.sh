#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

MODE=""
REALIGN_CONTAINERS=0
SKIP_READINESS="${SKIP_READINESS:-0}"
TARGET_TAG_OVERRIDE=""
PREV_TAG_OVERRIDE=""
TARGET_RELEASE_OVERRIDE=""
PREV_RELEASE_OVERRIDE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --inspect)
            MODE="inspect"
            shift
            ;;
        --repair)
            MODE="repair"
            shift
            ;;
        --realign-metadata)
            MODE="repair"
            shift
            ;;
        --realign-containers)
            REALIGN_CONTAINERS=1
            shift
            ;;
        --skip-readiness)
            SKIP_READINESS=1
            shift
            ;;
        --target-tag)
            TARGET_TAG_OVERRIDE="$2"
            shift 2
            ;;
        --previous-tag)
            PREV_TAG_OVERRIDE="$2"
            shift 2
            ;;
        --target-release)
            TARGET_RELEASE_OVERRIDE="$2"
            shift 2
            ;;
        --previous-release)
            PREV_RELEASE_OVERRIDE="$2"
            shift 2
            ;;
        *)
            echo "Unknown option: $1" >&2
            echo "Usage: $0 [--inspect | --repair] [--realign-containers] [--skip-readiness] [--target-tag <tag>] [--previous-tag <tag>]" >&2
            exit 1
            ;;
    esac
done

if [[ -z "${MODE}" ]]; then
    MODE="inspect"
fi

STATE_FILE="${DEPLOY_STATE_DIR}/deploy-state.json"
JOURNAL_FILE="${DEPLOY_STATE_DIR}/recovery-journal.json"
DEPLOY_CURR_FILE="${DEPLOY_STATE_DIR}/.deploy-current"
DEPLOY_PREV_FILE="${DEPLOY_STATE_DIR}/.deploy-previous"
REL_CURR_FILE="${DEPLOY_STATE_DIR}/release-current"
REL_PREV_FILE="${DEPLOY_STATE_DIR}/release-previous"

CURRENT_TAG=""
PREVIOUS_TAG=""
CURRENT_RELEASE=""
PREVIOUS_RELEASE=""
STATE_STATUS=""

if [[ -f "${STATE_FILE}" ]]; then
    CURRENT_TAG="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('current_image_tag',''))" 2>/dev/null || true)"
    PREVIOUS_TAG="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('previous_image_tag',''))" 2>/dev/null || true)"
    CURRENT_RELEASE="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('current_release_sha',''))" 2>/dev/null || true)"
    PREVIOUS_RELEASE="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('previous_release_sha',''))" 2>/dev/null || true)"
    STATE_STATUS="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('status',''))" 2>/dev/null || true)"
fi

if [[ -z "${CURRENT_TAG}" && -f "${DEPLOY_CURR_FILE}" ]]; then
    CURRENT_TAG="$(<"${DEPLOY_CURR_FILE}")"
fi
if [[ -z "${PREVIOUS_TAG}" && -f "${DEPLOY_PREV_FILE}" ]]; then
    PREVIOUS_TAG="$(<"${DEPLOY_PREV_FILE}")"
fi
if [[ -z "${CURRENT_RELEASE}" && -f "${REL_CURR_FILE}" ]]; then
    CURRENT_RELEASE="$(<"${REL_CURR_FILE}")"
fi
if [[ -z "${PREVIOUS_RELEASE}" && -f "${REL_PREV_FILE}" ]]; then
    PREVIOUS_RELEASE="$(<"${REL_PREV_FILE}")"
fi

# Inspect running container versions
declare -A RUNNING_IMAGES
declare -A SERVICE_STATUS
declare -A EXPECTED_IMAGES
declare -A DETECTED_TAGS
HAS_DRIFT=0
UNIFORM_RUNNING_TAG=""
UNIFORM_RUNNING_RELEASE=""
RUNNING_COUNT=0
TOTAL_SERVICES=${#APPLICATION_SERVICES[@]}

for svc in "${APPLICATION_SERVICES[@]}"; do
    RUNNING_IMAGES["${svc}"]="NOT_CHECKED"
    SERVICE_STATUS["${svc}"]="NOT_CHECKED"
done

if [[ "${SKIP_DOCKER_INSPECT:-0}" != "1" ]]; then
    validation_tag="${VALIDATE_IMAGE_TAG:-${CURRENT_TAG:-${IMAGE_TAG}}}"
    resolved_images="$(resolve_service_image_references "${validation_tag}")" || {
        echo '[FATAL] Cannot resolve all ten application service images.' >&2; exit 1;
    }
    while IFS='=' read -r svc ref; do EXPECTED_IMAGES["${svc}"]="${ref}"; done <<< "${resolved_images}"
    for svc in "${APPLICATION_SERVICES[@]}"; do
        cid="$(compose ps -q "${svc}" 2>/dev/null || true)"
        if [[ -n "${cid}" ]]; then
            img="$(docker inspect --format '{{.Config.Image}}' "${cid}" 2>/dev/null || true)"
            st="$(docker inspect --format '{{if and .State.Running (not .State.Restarting) (not .State.Paused)}}{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}{{else}}{{.State.Status}}{{end}}' "${cid}" 2>/dev/null || true)"
            RUNNING_IMAGES["${svc}"]="${img}"
            SERVICE_STATUS["${svc}"]="${st}"
            if ! verify_image_identity "${svc}" "${EXPECTED_IMAGES[${svc}]}"; then HAS_DRIFT=1; fi
            if [[ "${MODE}" == "post-deploy" && "${st}" != healthy && "${st}" != running ]]; then HAS_DRIFT=1; fi
            if [[ "${st}" == "healthy" || "${st}" == "running" ]]; then
                RUNNING_COUNT=$((RUNNING_COUNT + 1))
            fi
            svc_tag=""
            if [[ "${img}" =~ :([a-zA-Z0-9_.-]+)(@|$) ]]; then
                svc_tag="${BASH_REMATCH[1]}"
            fi
            if [[ -n "${svc_tag}" ]]; then
                DETECTED_TAGS["${svc_tag}"]=1
            fi
        else
            RUNNING_IMAGES["${svc}"]="NOT_RUNNING"
            SERVICE_STATUS["${svc}"]="STOPPED"
            if [[ -n "${CURRENT_TAG}${VALIDATE_IMAGE_TAG:-}" ]]; then HAS_DRIFT=1; fi
        fi
    done

    if [[ "${RUNNING_COUNT}" -eq "${TOTAL_SERVICES}" ]] && [[ "${#DETECTED_TAGS[@]}" -eq 1 ]]; then
        candidate_tag="${!DETECTED_TAGS[@]}"
        candidate_resolved="$(resolve_service_image_references "${candidate_tag}" 2>/dev/null || true)"
        if [[ -n "${candidate_resolved}" ]]; then
            uniform_matches=1
            while IFS='=' read -r c_svc c_ref; do
                [[ -n "${c_svc}" ]] || continue
                if ! verify_image_identity "${c_svc}" "${c_ref}" >/dev/null 2>&1; then
                    uniform_matches=0
                    break
                fi
            done <<< "${candidate_resolved}"
            if (( uniform_matches )); then
                UNIFORM_RUNNING_TAG="${candidate_tag}"
                if [[ "${UNIFORM_RUNNING_TAG}" =~ ^sha-([0-9a-f]{40})$ ]]; then
                    UNIFORM_RUNNING_RELEASE="${BASH_REMATCH[1]}"
                else
                    UNIFORM_RUNNING_RELEASE="${UNIFORM_RUNNING_TAG#sha-}"
                fi
            fi
        fi
    fi
fi

if [[ "${MODE}" == "inspect" ]]; then
    echo "=== Production Deployment State Inspector ==="
    echo "State Directory      : ${DEPLOY_STATE_DIR}"
    echo "Current Tag          : ${CURRENT_TAG:-<unset>}"
    echo "Previous Tag         : ${PREVIOUS_TAG:-<unset>}"
    echo "Current Release SHA  : ${CURRENT_RELEASE:-<unset>}"
    echo "Previous Release SHA : ${PREVIOUS_RELEASE:-<unset>}"
    echo "Status               : ${STATE_STATUS:-<unknown>}"
    if [[ -n "${UNIFORM_RUNNING_TAG}" && "${UNIFORM_RUNNING_TAG}" != "${CURRENT_TAG}" ]]; then
        echo "Container Drift      : YES (Uniform Release Mismatch: containers=${UNIFORM_RUNNING_TAG} vs metadata=${CURRENT_TAG})"
    elif (( HAS_DRIFT )); then
        echo "Container Drift      : YES (Mixed Versions Detected)"
    else
        echo "Container Drift      : NO"
    fi
    echo "Services:"
    for svc in "${APPLICATION_SERVICES[@]}"; do
        printf "  %-16s %-10s %s\n" "${svc}" "[${SERVICE_STATUS["${svc}"]:-UNKNOWN}]" "${RUNNING_IMAGES["${svc}"]:-UNKNOWN}"
    done
    if [[ -f "${JOURNAL_FILE}" ]]; then
        j_state_val="$(python3 -c "import json; data=json.load(open('${JOURNAL_FILE}')); print(data.get('state',''))" 2>/dev/null || true)"
        if [[ "${j_state_val}" == "COMMITTED" ]]; then
            echo "Completed Journal Found: ${JOURNAL_FILE}"
        else
            echo "Active Journal Found: ${JOURNAL_FILE}"
        fi
        python3 -c "import json; data=json.load(open('${JOURNAL_FILE}')); print(f'  Recovery ID: {data.get(\"recovery_id\")} | State: {data.get(\"state\")} | Target: {data.get(\"target_tag\")}')" 2>/dev/null || true
    fi
    exit 0
fi

# Production Safety Guardrail
if [[ "${APP_ENV:-}" == "production" ]]; then
    if [[ "${SKIP_DOCKER_INSPECT:-0}" == "1" || "${SKIP_READINESS:-0}" == "1" ]]; then
        if [[ "${ALLOW_EMERGENCY_SKIP:-0}" != "1" ]]; then
            echo "[FATAL] --skip-readiness and SKIP_DOCKER_INSPECT are strictly prohibited in production without ALLOW_EMERGENCY_SKIP=1." >&2
            exit 1
        fi
    fi
fi

# ==================== REPAIR MODE ====================
acquire_deployment_lock "RECOVERY"

# Discrepancy Detection: check if state is corrupted
IS_CONFLICTING=0
if [[ -n "${CURRENT_TAG}" && -n "${PREVIOUS_TAG}" && "${CURRENT_TAG}" == "${PREVIOUS_TAG}" ]]; then
    if [[ -n "${CURRENT_RELEASE}" && -n "${PREVIOUS_RELEASE}" && "${CURRENT_RELEASE}" != "${PREVIOUS_RELEASE}" ]]; then
        IS_CONFLICTING=1
    fi
fi

if (( IS_CONFLICTING )); then
    if [[ -z "${TARGET_TAG_OVERRIDE}" || -z "${PREV_TAG_OVERRIDE}" ]]; then
        echo "[FATAL] Ambiguous recovery state: state files conflict (.deploy-current == .deploy-previous while releases differ)." >&2
        echo "Automatic inference is prohibited. Explicit targets are required:" >&2
        echo "  $0 --repair --target-tag <tag> --previous-tag <tag> [--target-release <sha>] [--previous-release <sha>]" >&2
        exit 1
    fi
fi

# Determine target tags and releases
TARGET_TAG="${TARGET_TAG_OVERRIDE}"
if [[ -z "${TARGET_TAG}" && -n "${UNIFORM_RUNNING_TAG}" ]]; then
    echo "[INFO] Running containers are uniformly on ${UNIFORM_RUNNING_TAG}; adopting as recovery target tag."
    TARGET_TAG="${UNIFORM_RUNNING_TAG}"
fi
if [[ -z "${TARGET_TAG}" ]]; then
    TARGET_TAG="${CURRENT_TAG}"
fi
if [[ -z "${TARGET_TAG}" && -n "${CURRENT_RELEASE}" ]]; then
    TARGET_TAG="sha-${CURRENT_RELEASE}"
fi
if [[ -z "${TARGET_TAG}" && -n "${REQUESTED_IMAGE_TAG}" ]]; then
    TARGET_TAG="${REQUESTED_IMAGE_TAG}"
fi

TARGET_PREV_TAG="${PREV_TAG_OVERRIDE}"
if [[ -z "${TARGET_PREV_TAG}" ]]; then
    if [[ -n "${PREVIOUS_TAG}" && "${PREVIOUS_TAG}" != "${TARGET_TAG}" ]]; then
        TARGET_PREV_TAG="${PREVIOUS_TAG}"
    elif [[ -n "${CURRENT_TAG}" && "${CURRENT_TAG}" != "${TARGET_TAG}" ]]; then
        TARGET_PREV_TAG=""
    elif [[ -n "${PREVIOUS_RELEASE}" && "${PREVIOUS_RELEASE}" != "${CURRENT_RELEASE}" ]]; then
        TARGET_PREV_TAG="sha-${PREVIOUS_RELEASE}"
    fi
fi
if [[ -n "${TARGET_PREV_TAG}" && "${TARGET_PREV_TAG}" == "${TARGET_TAG}" ]]; then
    TARGET_PREV_TAG=""
fi

# Deterministic resolution of omitted release SHAs
TARGET_RELEASE="${TARGET_RELEASE_OVERRIDE:-}"
if [[ -z "${TARGET_RELEASE}" ]]; then
    if [[ "${TARGET_TAG}" =~ ^sha-([0-9a-f]{40})$ ]]; then
        TARGET_RELEASE="${BASH_REMATCH[1]}"
    elif [[ -n "${CURRENT_RELEASE}" ]]; then
        TARGET_RELEASE="${CURRENT_RELEASE}"
    else
        echo "[FATAL] Unable to deterministically resolve target release SHA from '${TARGET_TAG}'. Provide --target-release explicitly." >&2
        exit 1
    fi
fi

TARGET_PREV_RELEASE="${PREV_RELEASE_OVERRIDE:-}"
if [[ -z "${TARGET_PREV_RELEASE}" && -n "${TARGET_PREV_TAG}" ]]; then
    if [[ "${TARGET_PREV_TAG}" =~ ^sha-([0-9a-f]{40})$ ]]; then
        TARGET_PREV_RELEASE="${BASH_REMATCH[1]}"
    elif [[ -n "${PREVIOUS_RELEASE}" ]]; then
        TARGET_PREV_RELEASE="${PREVIOUS_RELEASE}"
    else
        echo "[FATAL] Unable to deterministically resolve previous release SHA from '${TARGET_PREV_TAG}'. Provide --previous-release explicitly." >&2
        exit 1
    fi
fi
if [[ -n "${TARGET_PREV_RELEASE}" && "${TARGET_PREV_RELEASE}" == "${TARGET_RELEASE}" ]]; then
    TARGET_PREV_RELEASE=""
    TARGET_PREV_TAG=""
fi

if [[ -z "${TARGET_TAG}" ]]; then
    echo "[FATAL] Unable to determine target image tag for recovery. Provide --target-tag explicitly." >&2
    exit 1
fi

echo "=== Beginning Deployment State Recovery ==="
echo "Target Tag             : ${TARGET_TAG}"
echo "Target Previous Tag    : ${TARGET_PREV_TAG:-<none>}"
echo "Target Current Release : ${TARGET_RELEASE:-<none>}"
echo "Target Prev Release    : ${TARGET_PREV_RELEASE:-<none>}"
echo "Realign Containers     : $(( REALIGN_CONTAINERS || HAS_DRIFT ))"

# Verify local docker images for target tag if inspect enabled
if [[ "${SKIP_DOCKER_INSPECT:-0}" != "1" ]]; then
    echo "Verifying local images for target tag ${TARGET_TAG}..."
    resolved=$(resolve_service_image_references "${TARGET_TAG}") || {
        echo "[FATAL] Failed to resolve service images for ${TARGET_TAG}." >&2
        exit 1
    }
    while read -r line; do
        [[ -n "${line}" ]] || continue
        svc="${line%%=*}"
        img="${line#*=}"
        if ! docker image inspect "${img}" >/dev/null 2>&1; then
            echo "[FATAL] Target image missing for service '${svc}': ${img}" >&2
            exit 1
        fi
    done <<< "${resolved}"
fi

# Journal Validation & Recovery ID Preservation
RECOVERY_ID=""
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

if [[ -f "${JOURNAL_FILE}" ]]; then
    echo "Found existing recovery journal. Inspecting for safe resume..."
    journal_valid="$(python3 -c "
import json, sys
try:
    with open('${JOURNAL_FILE}') as f:
        d = json.load(f)
    if 'recovery_id' in d and 'state' in d and 'target_tag' in d:
        print(f\"{d['recovery_id']}|{d['state']}|{d['target_tag']}\")
    else:
        sys.exit(1)
except Exception:
    sys.exit(1)
" 2>/dev/null || echo "INVALID")"

    if [[ "${journal_valid}" == "INVALID" ]]; then
        echo "[FATAL] Malformed or corrupt recovery-journal.json detected. Aborting fail-closed." >&2
        exit 1
    fi

    IFS='|' read -r j_id j_state j_target <<< "${journal_valid}"
    if [[ "${j_state}" == "COMMITTED" ]]; then
        echo "Found completed historical recovery journal (session ${j_id}). Archiving to recovery-journal-${j_id}.json..."
        mv -f "${JOURNAL_FILE}" "${DEPLOY_STATE_DIR}/recovery-journal-${j_id}.json"
    else
        if [[ -n "${j_target}" && "${j_target}" != "${TARGET_TAG}" ]]; then
            echo "[FATAL] Active journal target '${j_target}' conflicts with requested target '${TARGET_TAG}'." >&2
            exit 1
        fi
        RECOVERY_ID="${j_id}"
        echo "Resuming recovery session ID: ${RECOVERY_ID} (prior state: ${j_state})"
    fi
fi

if [[ -z "${RECOVERY_ID}" ]]; then
    RECOVERY_ID="$(python3 -c 'import uuid; print(uuid.uuid4())' 2>/dev/null || echo "$$-$(date +%s)")"
fi

# Step 1: Write journal INIT -> PREPARED
update_journal() {
    local jstate="$1"
    local jextra="${2:-}"
    [[ -n "${jextra}" ]] || jextra="{}"
    python3 -c "
import json, sys
data = {
    'recovery_id': sys.argv[1],
    'updated_at': sys.argv[2],
    'state': sys.argv[3],
    'target_tag': sys.argv[4],
    'target_previous_tag': sys.argv[5],
    'target_release': sys.argv[6],
    'target_previous_release': sys.argv[7],
    'details': json.loads(sys.argv[8])
}
with open('${JOURNAL_FILE}.tmp', 'w') as f:
    json.dump(data, f, indent=2)
" "${RECOVERY_ID}" "${NOW}" "${jstate}" "${TARGET_TAG}" "${TARGET_PREV_TAG}" "${TARGET_RELEASE}" "${TARGET_PREV_RELEASE}" "${jextra}"
    sync "${JOURNAL_FILE}.tmp" 2>/dev/null || true
    mv -Tf "${JOURNAL_FILE}.tmp" "${JOURNAL_FILE}"
}

update_journal "PREPARED" "{}"

# Step 2: Realign Containers if requested or drifted
if (( REALIGN_CONTAINERS || HAS_DRIFT )); then
    if [[ "${SKIP_DOCKER_INSPECT:-0}" != "1" ]]; then
        echo "Aligning all application services to ${TARGET_TAG}..."
        for svc in "${APPLICATION_SERVICES[@]}"; do
            # Live re-inspection of container
            cid="$(compose ps -q "${svc}" 2>/dev/null || true)"
            actual_img=""
            if [[ -n "${cid}" ]]; then
                actual_img="$(docker inspect --format '{{.Config.Image}}' "${cid}" 2>/dev/null || true)"
            fi

            expected_ref="$(resolve_service_image_references "${TARGET_TAG}" | sed -n "s/^${svc}=//p")"
            if verify_image_identity "${svc}" "${expected_ref}"; then
                echo "Service '${svc}' is already running ${TARGET_TAG}. Skipping restart."
                update_journal "ALIGNING_SERVICES" "{\"service\": \"${svc}\", \"action\": \"SKIPPED_ALREADY_MATCHED\"}"
                continue
            fi

            echo "Restarting service '${svc}' with IMAGE_TAG=${TARGET_TAG}..."
            update_journal "ALIGNING_SERVICES" "{\"service\": \"${svc}\", \"action\": \"RESTARTING\"}"
            IMAGE_TAG="${TARGET_TAG}" compose up -d --no-build --no-deps "${svc}"
            update_journal "ALIGNING_SERVICES" "{\"service\": \"${svc}\", \"action\": \"ALIGNED\"}"
        done
    fi
fi

update_journal "CONTAINERS_ALIGNED" "{}"

# Step 3: Readiness Probes & Image Identity Verification
if (( ! SKIP_READINESS )); then
    echo "Verifying image identity and probing readiness across all application services..."
    resolved_expected=$(resolve_service_image_references "${TARGET_TAG}" 2>/dev/null || true)

    for svc in "${APPLICATION_SERVICES[@]}"; do
        update_journal "PROBING_READINESS" "{\"service\": \"${svc}\"}"

        # Verify image identity if docker available
        if [[ "${SKIP_DOCKER_INSPECT:-0}" != "1" && -n "${resolved_expected}" ]]; then
            exp_ref="$(echo "${resolved_expected}" | grep "^${svc}=" | cut -d= -f2 || true)"
            if [[ -n "${exp_ref}" ]]; then
                verify_image_identity "${svc}" "${exp_ref}" || {
                    echo "[FATAL] Image identity verification failed for service '${svc}'." >&2
                    exit 1
                }
            fi
        fi

        wait_service_ready "${svc}" 60
    done

    # Run healthcheck
    if [[ -f "${SCRIPT_DIR}/healthcheck.sh" ]]; then
        echo "Running full healthcheck..."
        bash "${SCRIPT_DIR}/healthcheck.sh"
    fi
fi

update_journal "HEALTHY" "{}"

# Step 4: Atomic Commit
FINAL_STATUS="CONSISTENT"
if [[ ("${SKIP_READINESS:-0}" == "1" || "${SKIP_DOCKER_INSPECT:-0}" == "1") && "${FORCE_CONSISTENT:-0}" != "1" ]]; then
    FINAL_STATUS="DEGRADED_UNVERIFIED"
fi

echo "Committing deployment state with status ${FINAL_STATUS}..."
commit_deployment_state \
    "${TARGET_TAG}" \
    "${TARGET_PREV_TAG}" \
    "${TARGET_RELEASE}" \
    "${TARGET_PREV_RELEASE}" \
    "${FINAL_STATUS}" \
    "${RECOVERY_ID}"

update_journal "COMMITTED" "{\"status\": \"${FINAL_STATUS}\"}"

echo "=== Deployment State Recovery Successfully Completed ==="
echo "Canonical deploy-state.json status : ${FINAL_STATUS}"
echo "Current image tag                  : ${TARGET_TAG}"
echo "Previous image tag                 : ${TARGET_PREV_TAG}"
echo "Current release SHA                : ${TARGET_RELEASE}"
echo "Previous release SHA               : ${TARGET_PREV_RELEASE}"
