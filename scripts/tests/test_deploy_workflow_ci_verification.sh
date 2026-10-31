#!/usr/bin/env bash
set -Eeuo pipefail

# =============================================================================
# Automated Test Suite for Deploy Workflow CI Verification (Finding F-02)
# =============================================================================

TESTS_PASSED=0
TESTS_FAILED=0
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_scenario() {
    local name="$1"
    local expected_exit="$2"
    local expected_output="$3"
    local commit_sha="$4"
    local runs_json="$5"
    local mock_gh="${TMP_DIR}/gh"

    cat <<EOF > "${mock_gh}"
#!/usr/bin/env bash
if [[ "\$*" == *"run list --workflow=ci.yml --commit=${commit_sha}"* ]]; then
    cat <<'JSON'
${runs_json}
JSON
    exit 0
fi
echo "[]"
exit 0
EOF
    chmod +x "${mock_gh}"

    local script="${TMP_DIR}/verify_ci.sh"
    cat <<'EOF' > "${script}"
#!/usr/bin/env bash
set -Eeuo pipefail
runs_json="$(gh run list --workflow=ci.yml --commit="${COMMIT_SHA}" --json databaseId,status,conclusion,headSha 2>/dev/null || echo '[]')"
if [[ -z "${runs_json}" || "${runs_json}" == "[]" ]]; then
    echo "Error: No CI workflow run found for commit ${COMMIT_SHA} in '.github/workflows/ci.yml'." >&2
    echo "Deployment rejected: every production deployment must be verified by a passing CI run." >&2
    exit 1
fi

successful_run="$(echo "${runs_json}" | python3 -c 'import sys, json; data=json.load(sys.stdin) if not sys.stdin.isatty() else []; matches=[str(r.get("databaseId")) for r in data if r.get("headSha")==sys.argv[1] and r.get("status")=="completed" and r.get("conclusion")=="success"]; print(matches[0] if matches else "")' "${COMMIT_SHA}")"

if [[ -n "${successful_run}" ]]; then
    echo "Verified: commit ${COMMIT_SHA} successfully passed CI in run ID ${successful_run}."
else
    statuses="$(echo "${runs_json}" | python3 -c 'import sys, json; data=json.load(sys.stdin) if not sys.stdin.isatty() else []; print("; ".join("Run %s: status=%s, conclusion=%s" % (r.get("databaseId"), r.get("status"), r.get("conclusion") or "in_progress") for r in data if r.get("headSha")==sys.argv[1]))' "${COMMIT_SHA}")"
    echo "Error: Commit ${COMMIT_SHA} has no successful completed CI run in '.github/workflows/ci.yml'." >&2
    echo "Existing CI runs for ${COMMIT_SHA}: ${statuses:-none}" >&2
    echo "Deployment rejected: immutable preverified deployment requires all CI matrix checks to pass." >&2
    exit 1
fi
EOF
    chmod +x "${script}"

    local actual_output actual_exit
    set +e
    actual_output="$(PATH="${TMP_DIR}:${PATH}" COMMIT_SHA="${commit_sha}" "${script}" 2>&1)"
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

TARGET_SHA="a1b2c3d4e5f60718293a4b5c6d7e8f9012345678"
OTHER_SHA="ffffffffffffffffffffffffffffffffffffffff"

# 1. Matching SHA + successful required CI -> accepted (exit 0)
assert_scenario \
    "Matching SHA + successful required CI -> accepted" \
    0 \
    "Verified: commit ${TARGET_SHA} successfully passed CI in run ID 12345" \
    "${TARGET_SHA}" \
    '[{"databaseId": 12345, "status": "completed", "conclusion": "success", "headSha": "'"${TARGET_SHA}"'"}]'

# 2. Matching SHA + failed CI -> rejected (exit 1)
assert_scenario \
    "Matching SHA + failed CI -> rejected" \
    1 \
    "Deployment rejected: immutable preverified deployment requires all CI matrix checks to pass." \
    "${TARGET_SHA}" \
    '[{"databaseId": 12346, "status": "completed", "conclusion": "failure", "headSha": "'"${TARGET_SHA}"'"}]'

# 3. Matching SHA + cancelled CI -> rejected (exit 1)
assert_scenario \
    "Matching SHA + cancelled CI -> rejected" \
    1 \
    "Deployment rejected: immutable preverified deployment requires all CI matrix checks to pass." \
    "${TARGET_SHA}" \
    '[{"databaseId": 12347, "status": "completed", "conclusion": "cancelled", "headSha": "'"${TARGET_SHA}"'"}]'

# 4. Matching SHA + pending CI -> rejected (exit 1)
assert_scenario \
    "Matching SHA + pending CI -> rejected" \
    1 \
    "Deployment rejected: immutable preverified deployment requires all CI matrix checks to pass." \
    "${TARGET_SHA}" \
    '[{"databaseId": 12348, "status": "in_progress", "conclusion": null, "headSha": "'"${TARGET_SHA}"'"}]'

# 5. No matching CI run -> rejected (exit 1)
assert_scenario \
    "No matching CI run -> rejected" \
    1 \
    "Error: No CI workflow run found for commit ${TARGET_SHA}" \
    "${TARGET_SHA}" \
    '[]'

# 6. Successful CI for another SHA -> rejected (exit 1)
assert_scenario \
    "Successful CI for another SHA -> rejected" \
    1 \
    "Error: Commit ${TARGET_SHA} has no successful completed CI run" \
    "${TARGET_SHA}" \
    '[{"databaseId": 99999, "status": "completed", "conclusion": "success", "headSha": "'"${OTHER_SHA}"'"}]'

# 7. Image verification negative test: missing image metadata
test_image_verification() {
    local name="Missing or invalid image metadata -> rejected"
    local mock_docker="${TMP_DIR}/docker"
    cat <<'EOF' > "${mock_docker}"
#!/usr/bin/env bash
if [[ "$*" == *"login"* ]]; then exit 0; fi
if [[ "$*" == *"manifest inspect"* ]]; then
    # Simulate missing research-worker image
    if [[ "$*" == *"research-worker"* ]]; then
        echo "Error response from daemon: manifest unknown" >&2
        exit 1
    fi
    exit 0
fi
exit 0
EOF
    chmod +x "${mock_docker}"

    local script="${TMP_DIR}/verify_images.sh"
    cat <<'EOF' > "${script}"
#!/usr/bin/env bash
set -Eeuo pipefail
repo_lc="traderbybit/traderbybit"
for target in app web research-worker; do
    image_ref="ghcr.io/${repo_lc}/${target}:sha-${COMMIT_SHA}"
    if ! docker manifest inspect "${image_ref}" >/dev/null 2>&1; then
        echo "Error: Immutable image ${image_ref} was not found in GHCR." >&2
        exit 1
    fi
done
EOF
    chmod +x "${script}"

    local actual_output actual_exit
    set +e
    actual_output="$(PATH="${TMP_DIR}:${PATH}" COMMIT_SHA="${TARGET_SHA}" "${script}" 2>&1)"
    actual_exit=$?
    set -e

    if [[ "${actual_exit}" -eq 1 ]] && [[ "${actual_output}" == *"Immutable image ghcr.io/traderbybit/traderbybit/research-worker:sha-${TARGET_SHA} was not found"* ]]; then
        echo "PASS: ${name}"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo "FAIL: ${name}"
        echo "  Expected exit 1, got ${actual_exit}"
        echo "  Output: ${actual_output}"
        TESTS_FAILED=$((TESTS_FAILED + 1))
    fi
}
test_image_verification

echo "--- Summary ---"
echo "Total passed: ${TESTS_PASSED}"
echo "Total failed: ${TESTS_FAILED}"

if [[ "${TESTS_FAILED}" -gt 0 ]]; then
    exit 1
fi
