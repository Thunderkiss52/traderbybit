#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Automated Test Suite for Immutable Pull Scenarios (Finding F-03)
# =============================================================================

TESTS_PASSED=0
TESTS_FAILED=0
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_scenario() {
    local name="$1"
    local expected_exit="$2"
    local expected_output="$3"
    local pull_fails="$4"
    local missing_image="$5"
    local healthcheck_fails="$6"

    local mock_compose="${TMP_DIR}/compose"
    cat <<EOF > "${mock_compose}"
#!/usr/bin/env bash
if [[ "\$*" == *"pull"* ]]; then
    if [[ "${pull_fails}" == "1" ]]; then
        echo "Error response from daemon: repository not found" >&2
        exit 1
    fi
    exit 0
fi
if [[ "\$*" == *"build"* ]]; then
    echo "ERROR: compose build was invoked! Build fallback is strictly prohibited." >&2
    exit 99
fi
if [[ "\$*" == *"config"* ]]; then exit 0; fi
if [[ "\$*" == *"up"* ]]; then exit 0; fi
if [[ "\$*" == *"restart"* ]]; then exit 0; fi
if [[ "\$*" == *"ps"* ]]; then exit 0; fi
exit 0
EOF
    chmod +x "${mock_compose}"

    local mock_docker="${TMP_DIR}/docker"
    cat <<EOF > "${mock_docker}"
#!/usr/bin/env bash
if [[ "\$*" == *"image inspect"* ]]; then
    if [[ -n "${missing_image}" ]] && [[ "\$*" == *"${missing_image}"* ]]; then
        echo "Error: No such image: \$*" >&2
        exit 1
    fi
    exit 0
fi
if [[ "\$*" == *"compose"* ]]; then
    "${mock_compose}" "\${@:2}"
    exit \$?
fi
exit 0
EOF
    chmod +x "${mock_docker}"

    local mock_health="${TMP_DIR}/healthcheck.sh"
    cat <<EOF > "${mock_health}"
#!/usr/bin/env bash
if [[ "${healthcheck_fails}" == "1" ]]; then
    echo "Healthcheck failed: service unhealthy" >&2
    exit 1
fi
echo "Healthcheck passed"
exit 0
EOF
    chmod +x "${mock_health}"

    local test_script="${TMP_DIR}/run_deploy_test.sh"
    cat <<EOF > "${test_script}"
#!/usr/bin/env bash
set -Eeuo pipefail

compose() {
    "${mock_compose}" "\$@"
}

CURRENT_TAG="sha-previous111"
NEW_TAG="sha-new2222222"
DEPLOY_MODE="pull"
IMAGE_TAG="\${NEW_TAG}"
APPLICATION_SERVICES=(app web research-worker)
SCRIPT_DIR="${TMP_DIR}"

APP_IMAGE="\${APP_IMAGE:-ghcr.io/example/traderbybit/app}"
WEB_IMAGE="\${WEB_IMAGE:-ghcr.io/example/traderbybit/web}"
RESEARCH_WORKER_IMAGE="\${RESEARCH_WORKER_IMAGE:-ghcr.io/example/traderbybit/research-worker}"

if [[ "\${DEPLOY_MODE:-pull}" == "pull" ]]; then
    echo "Pulling immutable images (app, web, research-worker) for tag \${NEW_TAG}..."
    if ! compose pull app web research-worker; then
        echo "Error: Failed to pull required immutable images for tag \${NEW_TAG}." >&2
        echo "Existing running containers are preserved." >&2
        exit 1
    fi
    for svc_img in "\${APP_IMAGE}:\${NEW_TAG}" \\
                   "\${WEB_IMAGE}:\${NEW_TAG}" \\
                   "\${RESEARCH_WORKER_IMAGE}:\${NEW_TAG}"; do
        if ! "${mock_docker}" image inspect "\${svc_img}" >/dev/null 2>&1; then
            echo "Error: Required immutable image '\${svc_img}' is missing after pull." >&2
            echo "Silent build fallback is prohibited in production. Existing running containers are preserved. Aborting deployment." >&2
            exit 1
        fi
    done
    echo "Verified all required immutable images are present locally."
else
    compose build --pull app web research-worker
fi

# Arm rollback trap only when state-mutating container operations begin
rollback_application() {
    local failure_code="\$?"
    trap - ERR
    echo "Deployment failed during container transition; attempting application rollback to \${CURRENT_TAG}." >&2
    export IMAGE_TAG="\${CURRENT_TAG}"
    compose up -d --no-deps "\${APPLICATION_SERVICES[@]}" || true
    echo "Rollback to \${CURRENT_TAG} executed." >&2
    exit "\${failure_code}"
}
trap rollback_application ERR

bash "${mock_health}"
echo "Deployment successful for \${NEW_TAG}."
EOF
    chmod +x "${test_script}"

    local actual_output actual_exit
    set +e
    actual_output="$(PATH="${TMP_DIR}:${PATH}" "${test_script}" 2>&1)"
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

# 1. All three images available -> deploy succeeds
assert_scenario \
    "All three images available -> deploy succeeds" \
    0 \
    "Deployment successful for sha-new2222222" \
    0 "" 0

# 2. Research-worker image missing -> fails closed, no build, preserves containers
assert_scenario \
    "Research-worker image missing -> aborts, no build fallback, preserves containers" \
    1 \
    "Required immutable image 'ghcr.io/example/traderbybit/research-worker:sha-new2222222' is missing after pull." \
    0 "research-worker" 0

# 3. App image missing -> fails closed, no build, preserves containers
assert_scenario \
    "App image missing -> aborts, no build fallback, preserves containers" \
    1 \
    "Required immutable image 'ghcr.io/example/traderbybit/app:sha-new2222222' is missing after pull." \
    0 "app" 0

# 4. Image pull failure -> fails closed without build, preserves containers
assert_scenario \
    "Image pull failure -> aborts fail-closed, preserves containers" \
    1 \
    "Existing running containers are preserved." \
    1 "" 0

# 5. Failed health check during container transition -> triggers application rollback
assert_scenario \
    "Failed health check during container transition -> triggers application rollback" \
    1 \
    "Rollback to sha-previous111 executed." \
    0 "" 1

echo "--- Summary ---"
echo "Total passed: ${TESTS_PASSED}"
echo "Total failed: ${TESTS_FAILED}"

if [[ "${TESTS_FAILED}" -gt 0 ]]; then
    exit 1
fi
