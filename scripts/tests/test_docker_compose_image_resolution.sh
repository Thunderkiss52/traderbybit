#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Automated Test Suite for Docker Compose Image Resolution & Validation
# Verifies that:
# 1. Under real production deployment configuration, all 3 custom images
#    (app, web, research-worker) and 5 dependent services resolve to SHA-tagged GHCR images.
# 2. Compose rejects missing environment variables (APP_IMAGE, WEB_IMAGE, RESEARCH_WORKER_IMAGE, IMAGE_TAG) fail-closed.
# 3. No fallback local image names (bybit-signals-*) exist in the resolved configuration.
# 4. common.sh auto-derives RESEARCH_WORKER_IMAGE from APP_IMAGE when omitted.
# 5. common.sh rejects unqualified fallback image names in pull deployment mode.
# =============================================================================

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TESTS_PASSED=0
TESTS_FAILED=0
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_test() {
    local name="$1"
    local expected_exit="$2"
    local expected_output="$3"
    shift 3
    local cmd=("$@")

    local actual_output actual_exit
    set +e
    actual_output="$("${cmd[@]}" 2>&1)"
    actual_exit=$?
    set -e

    if [[ "${actual_exit}" -eq "${expected_exit}" ]] && [[ "${actual_output}" == *"${expected_output}"* ]]; then
        echo "PASS: ${name}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo "FAIL: ${name}"
        echo "  Expected exit: ${expected_exit}, got: ${actual_exit}"
        echo "  Expected output to contain: '${expected_output}'"
        echo "  Actual output: '${actual_output}'"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}

echo "=== Running Docker Compose Image Resolution Tests ==="

# Prepare temporary production env file based on .env.production.example
PROD_ENV="${TMP_DIR}/.env.production"
cp "${PROJECT_ROOT}/.env.production.example" "${PROD_ENV}"
# Set explicit values for test
sed -i 's|ghcr.io/OWNER/REPOSITORY/app|ghcr.io/vector-test/app|g' "${PROD_ENV}"
sed -i 's|ghcr.io/OWNER/REPOSITORY/web|ghcr.io/vector-test/web|g' "${PROD_ENV}"
sed -i 's|ghcr.io/OWNER/REPOSITORY/research-worker|ghcr.io/vector-test/research-worker|g' "${PROD_ENV}"

# 1. Real production configuration resolves app, web, research-worker to exact GHCR tags
run_resolve_all() {
    ENV_FILE="${PROD_ENV}" IMAGE_TAG="sha-abcdef123456" docker compose \
        --env-file "${PROD_ENV}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config --format json | python3 -c '
import json, sys
data = json.load(sys.stdin)
services = data.get("services", {})
app_img = services.get("app", {}).get("image", "")
web_img = services.get("web", {}).get("image", "")
res_img = services.get("research-worker", {}).get("image", "")
queue_img = services.get("queue", {}).get("image", "")
audit_img = services.get("audit-worker", {}).get("image", "")

assert app_img == "ghcr.io/vector-test/app:sha-abcdef123456", f"Unexpected app image: {app_img}"
assert web_img == "ghcr.io/vector-test/web:sha-abcdef123456", f"Unexpected web image: {web_img}"
assert res_img == "ghcr.io/vector-test/research-worker:sha-abcdef123456", f"Unexpected research-worker image: {res_img}"
assert queue_img == app_img, f"Queue worker image must match app: {queue_img}"
assert audit_img == app_img, f"Audit worker image must match app: {audit_img}"

print("ALL_IMAGES_RESOLVED_EXACT_GHCR")
'
}
assert_test \
    "Real production configuration resolves all services to exact SHA-tagged GHCR images" \
    0 \
    "ALL_IMAGES_RESOLVED_EXACT_GHCR" \
    run_resolve_all

# 2. Zero fallback image names in resolved production configuration
run_check_no_fallbacks() {
    ENV_FILE="${PROD_ENV}" IMAGE_TAG="sha-abcdef123456" docker compose \
        --env-file "${PROD_ENV}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config --format json | python3 -c '
import json, sys
data = json.load(sys.stdin)
fallbacks = []
for sname, scfg in data.get("services", {}).items():
    img = scfg.get("image", "")
    if "bybit-signals-" in img:
        fallbacks.append(f"{sname}={img}")
if fallbacks:
    print(f"FAILED_FALLBACKS_FOUND: {fallbacks}")
    sys.exit(1)
print("ZERO_FALLBACK_IMAGES_FOUND")
'
}
assert_test \
    "Zero unqualified fallback images in resolved production configuration" \
    0 \
    "ZERO_FALLBACK_IMAGES_FOUND" \
    run_check_no_fallbacks

# 3. Missing RESEARCH_WORKER_IMAGE fails closed
run_missing_research_worker() {
    local env_file="${TMP_DIR}/env_no_research"
    grep -v '^RESEARCH_WORKER_IMAGE=' "${PROD_ENV}" > "${env_file}"
    ENV_FILE="${env_file}" IMAGE_TAG="sha-123" docker compose \
        --env-file "${env_file}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config
}
assert_test \
    "Compose rejects missing RESEARCH_WORKER_IMAGE fail-closed" \
    1 \
    "RESEARCH_WORKER_IMAGE is required" \
    run_missing_research_worker

# 4. Missing APP_IMAGE fails closed
run_missing_app_image() {
    local env_file="${TMP_DIR}/env_no_app"
    grep -v '^APP_IMAGE=' "${PROD_ENV}" > "${env_file}"
    ENV_FILE="${env_file}" IMAGE_TAG="sha-123" docker compose \
        --env-file "${env_file}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config
}
assert_test \
    "Compose rejects missing APP_IMAGE fail-closed" \
    1 \
    "APP_IMAGE is required" \
    run_missing_app_image

# 5. Missing WEB_IMAGE fails closed
run_missing_web_image() {
    local env_file="${TMP_DIR}/env_no_web"
    grep -v '^WEB_IMAGE=' "${PROD_ENV}" > "${env_file}"
    ENV_FILE="${env_file}" IMAGE_TAG="sha-123" docker compose \
        --env-file "${env_file}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config
}
assert_test \
    "Compose rejects missing WEB_IMAGE fail-closed" \
    1 \
    "WEB_IMAGE is required" \
    run_missing_web_image

# 6. Missing IMAGE_TAG fails closed
run_missing_image_tag() {
    local env_file="${TMP_DIR}/env_no_tag"
    grep -v '^IMAGE_TAG=' "${PROD_ENV}" > "${env_file}"
    ENV_FILE="${env_file}" docker compose \
        --env-file "${env_file}" \
        -f "${PROJECT_ROOT}/compose.yaml" \
        config
}
assert_test \
    "Compose rejects missing IMAGE_TAG fail-closed" \
    1 \
    "IMAGE_TAG is required" \
    run_missing_image_tag

# 7. common.sh auto-derives RESEARCH_WORKER_IMAGE when APP_IMAGE is provided
run_common_sh_autoderive() {
    local env_file="${TMP_DIR}/env_derive"
    grep -v '^RESEARCH_WORKER_IMAGE=' "${PROD_ENV}" > "${env_file}"
    bash -c "
        export ENV_FILE='${env_file}'
        export DEPLOY_MODE='build'
        source '${PROJECT_ROOT}/docker/scripts/common.sh'
        echo \"DERIVED_RESEARCH_WORKER=\${RESEARCH_WORKER_IMAGE}\"
    "
}
assert_test \
    "common.sh auto-derives RESEARCH_WORKER_IMAGE from APP_IMAGE" \
    0 \
    "DERIVED_RESEARCH_WORKER=ghcr.io/vector-test/research-worker" \
    run_common_sh_autoderive

# 8. common.sh rejects unqualified fallback image name in pull mode
run_common_sh_reject_fallback() {
    local env_file="${TMP_DIR}/env_bad_val"
    cp "${PROD_ENV}" "${env_file}"
    echo "RESEARCH_WORKER_IMAGE=bybit-signals-research-worker" >> "${env_file}"
    bash -c "
        export ENV_FILE='${env_file}'
        export DEPLOY_MODE='pull'
        source '${PROJECT_ROOT}/docker/scripts/common.sh'
    "
}
assert_test \
    "common.sh rejects unqualified fallback image name in pull mode" \
    1 \
    "RESEARCH_WORKER_IMAGE must be a qualified registry image reference" \
    run_common_sh_reject_fallback

# 9. common.sh preserves explicit RESEARCH_WORKER_IMAGE
run_common_sh_preserves_explicit() {
    local env_file="${TMP_DIR}/env_explicit"
    cp "${PROD_ENV}" "${env_file}"
    echo "RESEARCH_WORKER_IMAGE=ghcr.io/custom-org/custom-worker" >> "${env_file}"
    bash -c "
        export ENV_FILE='${env_file}'
        export DEPLOY_MODE='pull'
        source '${PROJECT_ROOT}/docker/scripts/common.sh'
        echo \"EXPLICIT_RESEARCH_WORKER=\${RESEARCH_WORKER_IMAGE}\"
    "
}
assert_test \
    "common.sh preserves explicit RESEARCH_WORKER_IMAGE" \
    0 \
    "EXPLICIT_RESEARCH_WORKER=ghcr.io/custom-org/custom-worker" \
    run_common_sh_preserves_explicit

echo "--- Summary ---"
echo "Total passed: ${TESTS_PASSED}"
echo "Total failed: ${TESTS_FAILED}"

if [[ "${TESTS_FAILED}" -gt 0 ]]; then
    exit 1
fi
