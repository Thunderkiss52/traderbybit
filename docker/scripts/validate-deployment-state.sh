#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

MODE="${1:-pre-deploy}"
OUTPUT_JSON="${VALIDATE_OUTPUT_JSON:-0}"

# Production Safety Guardrail
if [[ "${APP_ENV:-}" == "production" ]]; then
    if [[ "${SKIP_DOCKER_INSPECT:-0}" == "1" && "${ALLOW_EMERGENCY_SKIP:-0}" != "1" ]]; then
        echo "[FATAL] SKIP_DOCKER_INSPECT is strictly prohibited in production without ALLOW_EMERGENCY_SKIP=1." >&2
        exit 1
    fi
fi

verify_database_compatibility() {
    local target_release="${1:-}"
    local psql_cmd=(compose exec -T postgres psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}")

    if [[ -n "${PG_PORT:-}" ]]; then
        psql_cmd=(psql -h "${PG_HOST:-127.0.0.1}" -p "${PG_PORT}" -U "${PG_USER:-postgres}" -d "${PG_DB:-bybit_test}")
    fi

    # 1. Check PostgreSQL connectivity (read-only)
    if ! "${psql_cmd[@]}" -Atc "SELECT 1;" >/dev/null 2>&1; then
        echo "[BLOCKED] Database is not reachable for schema compatibility preflight." >&2
        return 2
    fi

    # 2. Check applied migration record
    local has_mig
    has_mig="$("${psql_cmd[@]}" -Atc \
        "SELECT COUNT(*) FROM migrations WHERE migration = '2026_10_20_000000_add_slot_idempotency_to_strategy_observations';" 2>/dev/null || echo "0")"

    # 3. Check exact schema, table, columns, uniqueness, validity, and predicate in pg_catalog
    local index_check
    index_check="$("${psql_cmd[@]}" -Atc "
SELECT
    ix.indisunique AS is_unique,
    ix.indisvalid AS is_valid,
    pg_get_expr(ix.indpred, ix.indrelid) AS index_predicate,
    array_to_string(array_agg(a.attname ORDER BY array_position(ix.indkey, a.attnum)), ',') AS index_columns
FROM pg_index ix
JOIN pg_class c ON c.oid = ix.indrelid
JOIN pg_class i ON i.oid = ix.indexrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(ix.indkey)
WHERE n.nspname = 'public'
  AND c.relname = 'strategy_observations'
  AND i.relname = 'strategy_observations_slot_unique'
GROUP BY ix.indisunique, ix.indisvalid, ix.indpred, ix.indrelid;
" 2>/dev/null || true)"

    local expected_cols="exchange,category,symbol,strategy_id,strategy_version,decision_time"
    local expected_pred="(superseded_by IS NULL)"

    IFS='|' read -r is_unique is_valid actual_pred actual_cols <<< "${index_check}"

    if [[ "${has_mig}" != "1" || "${is_unique}" != "t" || "${is_valid}" != "t" || "${actual_pred}" != "${expected_pred}" || "${actual_cols}" != "${expected_cols}" ]]; then
        echo "[BLOCKED] Database schema compatibility contract violation for candidate release ${target_release}:" >&2
        echo "  Migration in DB     : ${has_mig} (expected: 1)" >&2
        echo "  Index is_unique     : ${is_unique:-<missing>} (expected: t)" >&2
        echo "  Index is_valid      : ${is_valid:-<missing>} (expected: t)" >&2
        echo "  Index predicate     : ${actual_pred:-<missing>} (expected: ${expected_pred})" >&2
        echo "  Index columns order : ${actual_cols:-<missing>} (expected: ${expected_cols})" >&2
        return 2
    fi

    echo "[PASS] Database schema compatibility contract verified (read-only)."
    return 0
}

if [[ "${MODE}" == "verify-db" ]]; then
    verify_database_compatibility "${2:-}"
    exit $?
fi

STATE_FILE="${DEPLOY_STATE_DIR}/deploy-state.json"
DEPLOY_CURR_FILE="${DEPLOY_STATE_DIR}/.deploy-current"
DEPLOY_PREV_FILE="${DEPLOY_STATE_DIR}/.deploy-previous"
REL_CURR_FILE="${DEPLOY_STATE_DIR}/release-current"
REL_PREV_FILE="${DEPLOY_STATE_DIR}/release-previous"

CURRENT_TAG=""
PREVIOUS_TAG=""
CURRENT_RELEASE=""
PREVIOUS_RELEASE=""
CORRUPTION_REASON=""
IS_CORRUPTED=0
HAS_DRIFT=0

STATE_STATUS=""
# Read or synthesize deployment state
if [[ -f "${STATE_FILE}" ]]; then
    # Parse canonical deploy-state.json
    CURRENT_TAG="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('current_image_tag',''))" 2>/dev/null || true)"
    PREVIOUS_TAG="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('previous_image_tag',''))" 2>/dev/null || true)"
    CURRENT_RELEASE="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('current_release_sha',''))" 2>/dev/null || true)"
    PREVIOUS_RELEASE="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('previous_release_sha',''))" 2>/dev/null || true)"
    STATE_STATUS="$(python3 -c "import json; data=json.load(open('${STATE_FILE}')); print(data.get('status',''))" 2>/dev/null || true)"
fi

# Fallback to projection flat files if not in canonical state
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

# 1. Pre-deploy Interrupted Update Guard
if [[ "${MODE}" == "pre-deploy" && -f "${DEPLOY_STATE_DIR}/update-in-progress" ]]; then
    progress_content="$(<"${DEPLOY_STATE_DIR}/update-in-progress")"
    if [[ ! "${progress_content}" =~ :[[:space:]]*ROLLED_BACK$ ]]; then
        echo "[FATAL] An interrupted or schema-blocked update is pending in ${DEPLOY_STATE_DIR}/update-in-progress: ${progress_content}" >&2
        echo "Deployment halted fail-closed before any container or database modifications." >&2
        exit 1
    fi
fi

# 2. State Corruption Detection
if [[ "${STATE_STATUS}" == "CORRUPTED" ]]; then
    IS_CORRUPTED=1
    CORRUPTION_REASON="Canonical deploy-state.json explicitly records status CORRUPTED."
elif [[ "${STATE_STATUS}" == "ROLLBACK_FAILED" ]]; then
    IS_CORRUPTED=1
    CORRUPTION_REASON="Canonical deploy-state.json records status ROLLBACK_FAILED: a prior rollback failed verification."
elif [[ -n "${CURRENT_TAG}" && -n "${PREVIOUS_TAG}" && "${CURRENT_TAG}" == "${PREVIOUS_TAG}" ]]; then
    if [[ -n "${CURRENT_RELEASE}" && -n "${PREVIOUS_RELEASE}" && "${CURRENT_RELEASE}" != "${PREVIOUS_RELEASE}" ]]; then
        IS_CORRUPTED=1
        CORRUPTION_REASON="Duplicate image tags: .deploy-current and .deploy-previous both contain '${CURRENT_TAG}', but release metadata records previous release '${PREVIOUS_RELEASE}' vs current '${CURRENT_RELEASE}'."
    elif [[ "${MODE}" == "pre-rollback" ]]; then
        IS_CORRUPTED=1
        CORRUPTION_REASON="Cannot rollback: .deploy-current and .deploy-previous are identical ('${CURRENT_TAG}'). No distinct previous target exists."
    fi
elif [[ -n "${CURRENT_TAG}" && -f "${DEPLOY_CURR_FILE}" && -z "${CURRENT_RELEASE}" ]]; then
    IS_CORRUPTED=1
    CORRUPTION_REASON="Missing release metadata: .deploy-current exists ('${CURRENT_TAG}') but release-current is missing or empty."
elif [[ -n "${PREVIOUS_TAG}" && -f "${DEPLOY_PREV_FILE}" && -z "${PREVIOUS_RELEASE}" ]]; then
    IS_CORRUPTED=1
    CORRUPTION_REASON="Missing release metadata: .deploy-previous exists ('${PREVIOUS_TAG}') but release-previous is missing or empty."
fi

# 2. Container Image Drift Inspection
declare -A RUNNING_IMAGES
declare -A SERVICE_STATUS
declare -A EXPECTED_IMAGES
declare -A DETECTED_TAGS

HAS_DRIFT=0
IS_UNIFORM_MISMATCH=0
UNIFORM_TAG=""
UNIFORM_RELEASE=""
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
            if ! verify_image_identity "${svc}" "${EXPECTED_IMAGES[${svc}]}" "${cid}"; then HAS_DRIFT=1; fi
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

    # Check if all 10 running containers are uniformly on the same release tag
    if (( HAS_DRIFT )) && [[ "${RUNNING_COUNT}" -eq "${TOTAL_SERVICES}" ]] && [[ "${#DETECTED_TAGS[@]}" -eq 1 ]]; then
        candidate_uniform_tag="${!DETECTED_TAGS[@]}"
        if [[ -n "${candidate_uniform_tag}" && "${candidate_uniform_tag}" != "${validation_tag}" ]]; then
            candidate_resolved="$(resolve_service_image_references "${candidate_uniform_tag}" 2>/dev/null || true)"
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
                    IS_UNIFORM_MISMATCH=1
                    UNIFORM_TAG="${candidate_uniform_tag}"
                    if [[ "${UNIFORM_TAG}" =~ ^sha-([0-9a-f]{40})$ ]]; then
                        UNIFORM_RELEASE="${BASH_REMATCH[1]}"
                    else
                        UNIFORM_RELEASE="${UNIFORM_TAG#sha-}"
                    fi
                fi
            fi
        fi
    fi
fi

# 3. Mode-specific verifications
case "${MODE}" in
    inspect)
        echo "=== Deployment State Inspection ==="
        echo "Canonical State File : ${STATE_FILE}"
        echo "Current Image Tag    : ${CURRENT_TAG:-<unset>}"
        echo "Previous Image Tag   : ${PREVIOUS_TAG:-<unset>}"
        echo "Current Release SHA  : ${CURRENT_RELEASE:-<unset>}"
        echo "Previous Release SHA : ${PREVIOUS_RELEASE:-<unset>}"
        echo "Corrupted State      : $( (( IS_CORRUPTED )) && echo 'YES' || echo 'NO' )"
        if (( IS_CORRUPTED )); then
            echo "Corruption Details   : ${CORRUPTION_REASON}"
        fi
        if (( IS_UNIFORM_MISMATCH )); then
            echo "Container Drift      : YES (Uniform Release Mismatch: containers=${UNIFORM_TAG} vs metadata=${CURRENT_TAG})"
        elif (( HAS_DRIFT )); then
            echo "Container Drift      : YES (Mixed Versions Detected)"
        else
            echo "Container Drift      : NO"
        fi
        echo "Running Services:"
        for svc in "${APPLICATION_SERVICES[@]}"; do
            printf "  %-16s %-10s %s\n" "${svc}" "[${SERVICE_STATUS["${svc}"]:-NOT_CHECKED}]" "${RUNNING_IMAGES["${svc}"]:-NOT_CHECKED}"
        done
        if (( IS_CORRUPTED || HAS_DRIFT )); then
            echo ""
            echo "[ACTION REQUIRED] Run recover-deployment-state.sh --repair to restore consistent state."
            exit 1
        fi
        exit 0
        ;;

    pre-deploy)
        if (( IS_CORRUPTED )); then
            if [[ "${AUTO_RECOVER_ON_DEPLOY:-0}" == "1" && -n "${CURRENT_TAG}" && -n "${PREVIOUS_TAG}" && "${CURRENT_TAG}" == "${PREVIOUS_TAG}" && -n "${CURRENT_RELEASE}" && -n "${PREVIOUS_RELEASE}" && "${CURRENT_RELEASE}" != "${PREVIOUS_RELEASE}" ]]; then
                echo "[INFO] Detected legacy corrupted duplicate tag state in ${DEPLOY_STATE_DIR} (both tags: ${CURRENT_TAG})." >&2
                echo "[INFO] AUTO_RECOVER_ON_DEPLOY=1 active: executing automated pre-deploy recovery (Target: ${CURRENT_TAG}, Previous: sha-${PREVIOUS_RELEASE})..." >&2
                extra_recovery_flags=()
                if [[ "${SKIP_READINESS:-0}" == "1" ]]; then
                    extra_recovery_flags+=(--skip-readiness)
                fi
                AUTO_RECOVER_ON_DEPLOY=0 bash "${SCRIPT_DIR}/recover-deployment-state.sh" --repair --realign-containers "${extra_recovery_flags[@]}" \
                    --target-tag "${CURRENT_TAG}" \
                    --target-release "${CURRENT_RELEASE}" \
                    --previous-tag "sha-${PREVIOUS_RELEASE}" \
                    --previous-release "${PREVIOUS_RELEASE}" || {
                    echo "[FATAL] Automated pre-deploy recovery failed." >&2
                    exit 1
                }
                echo "[INFO] Automated pre-deploy recovery completed. Re-verifying deployment state..." >&2
                exec bash "${BASH_SOURCE[0]}" pre-deploy
            fi
            echo "[FATAL] Deployment state corruption detected." >&2
            echo "Details: ${CORRUPTION_REASON}" >&2
            echo "Deployment halted fail-closed before any container or database modifications." >&2
            echo "To recover, run:" >&2
            echo "  sudo DEPLOY_STATE_DIR=\"${DEPLOY_STATE_DIR}\" ENV_FILE=\"${ENV_FILE}\" \"\${RELEASE_DIR}/docker/scripts/recover-deployment-state.sh\" --repair --realign-containers" >&2
            exit 1
        fi
        if (( HAS_DRIFT )); then
            echo "[WARN] Container version drift detected across application services:" >&2
            for svc in "${APPLICATION_SERVICES[@]}"; do
                printf "  %-16s %s\n" "${svc}" "${RUNNING_IMAGES["${svc}"]:-NOT_CHECKED}" >&2
            done
            if [[ "${ALLOW_CONTAINER_DRIFT:-0}" != "1" ]]; then
                if (( IS_UNIFORM_MISMATCH )); then
                    echo "[INFO] Detected uniform running release (${UNIFORM_TAG}) differing from canonical metadata (${CURRENT_TAG})." >&2
                    if [[ "${AUTO_RECOVER_ON_DEPLOY:-0}" == "1" ]]; then
                        echo "[INFO] AUTO_RECOVER_ON_DEPLOY=1 active: reconciling deployment metadata to uniform running release ${UNIFORM_TAG}..." >&2
                        extra_recovery_flags=()
                        if [[ "${SKIP_READINESS:-0}" == "1" ]]; then
                            extra_recovery_flags+=(--skip-readiness)
                        fi
                        prev_rec_tag="${PREVIOUS_TAG:-}"
                        prev_rec_rel="${PREVIOUS_RELEASE:-}"
                        if [[ "${prev_rec_tag}" == "${UNIFORM_TAG}" ]]; then
                            prev_rec_tag=""
                            prev_rec_rel=""
                        fi
                        AUTO_RECOVER_ON_DEPLOY=0 bash "${SCRIPT_DIR}/recover-deployment-state.sh" --repair "${extra_recovery_flags[@]}" \
                            --target-tag "${UNIFORM_TAG}" \
                            --target-release "${UNIFORM_RELEASE}" \
                            ${prev_rec_tag:+--previous-tag "${prev_rec_tag}"} \
                            ${prev_rec_rel:+--previous-release "${prev_rec_rel}"} || {
                            echo "[FATAL] Automated pre-deploy metadata reconciliation failed." >&2
                            exit 1
                        }
                        echo "[INFO] Automated pre-deploy metadata reconciliation completed. Re-verifying deployment state..." >&2
                        exec bash "${BASH_SOURCE[0]}" pre-deploy
                    fi
                    echo "[FATAL] Uniform container release mismatch detected in pre-deploy check." >&2
                    echo "All 10 application containers are uniformly running release '${UNIFORM_TAG}', but canonical metadata records '${CURRENT_TAG}'." >&2
                    echo "Realign deployment metadata before deploying:" >&2
                    echo "  recover-deployment-state.sh --repair --target-tag \"${UNIFORM_TAG}\"" >&2
                    exit 1
                else
                    echo "[FATAL] Mixed-version containers detected in pre-deploy check. Realign containers before deploying." >&2
                    exit 1
                fi
            fi
        fi
        echo "Pre-deployment state validation passed."
        exit 0
        ;;

    pre-rollback)
        if (( IS_CORRUPTED )); then
            echo "[FATAL] Cannot rollback: deployment state is corrupted." >&2
            echo "Details: ${CORRUPTION_REASON}" >&2
            exit 1
        fi
        if [[ -z "${PREVIOUS_TAG}" ]]; then
            echo "[FATAL] Cannot rollback: no previous image tag is registered." >&2
            exit 1
        fi

        if [[ "${SKIP_DOCKER_INSPECT:-0}" != "1" ]]; then
            # Validate that all ten service images for PREVIOUS_TAG exist locally
            echo "Verifying local Docker images for rollback target: ${PREVIOUS_TAG}..."
            resolved_images=$(resolve_service_image_references "${PREVIOUS_TAG}") || {
                echo "[FATAL] Failed to resolve service images from compose configuration for ${PREVIOUS_TAG}." >&2
                exit 1
            }
            while read -r line; do
                [[ -n "${line}" ]] || continue
                svc="${line%%=*}"
                img="${line#*=}"
                if ! docker image inspect "${img}" >/dev/null 2>&1; then
                    echo "[FATAL] Rollback target image missing for service '${svc}': ${img}" >&2
                    echo "Rollback halted fail-closed. Image must be present locally before rollback." >&2
                    exit 1
                fi
            done <<< "${resolved_images}"

            # Schema Compatibility Check
            if ! verify_database_compatibility "${PREVIOUS_RELEASE:-unknown}"; then
                echo "[BLOCKED] Rollback blocked fail-closed due to schema incompatibility." >&2
                exit 2
            fi

            assert_rollback_migration_state "${PREVIOUS_TAG}" || exit 2

        fi

        echo "Pre-rollback validation passed for target ${PREVIOUS_TAG}."
        exit 0
        ;;

    post-deploy)
        if (( IS_CORRUPTED )); then echo "[FATAL] ${CORRUPTION_REASON}" >&2; exit 1; fi
        if (( HAS_DRIFT )); then
            echo "[FATAL] Post-deployment check failed: container drift detected." >&2
            for svc in "${APPLICATION_SERVICES[@]}"; do
                printf "  %-16s %s\n" "${svc}" "${RUNNING_IMAGES["${svc}"]:-NOT_CHECKED}" >&2
            done
            exit 1
        fi
        echo "Post-deployment state validation passed."
        exit 0
        ;;

    *)
        echo "Unknown mode: ${MODE}. Expected pre-deploy, pre-rollback, post-deploy, or inspect." >&2
        exit 1
        ;;
esac
