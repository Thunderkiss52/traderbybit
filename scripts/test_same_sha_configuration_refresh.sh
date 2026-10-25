#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "================================================================="
echo "  TEST: SAME-SHA CONFIGURATION REFRESH & SERVICE RECONCILIATION  "
echo "================================================================="

TEST_DIR="$(mktemp -d /tmp/bybit_reconcile_test_XXXXXX)"
PROJECT_NAME="bybit-signals-reconcile-test-$$"

cleanup() {
    echo "[INFO] Cleaning up test project ${PROJECT_NAME}..."
    docker compose -p "${PROJECT_NAME}" -f "${TEST_DIR}/compose.yaml" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "${TEST_DIR}"
}
trap cleanup EXIT

# 1. Prepare minimal isolated compose file with two services:
#    - 'app': consumes the Binance fee/slippage variables
#    - 'db': unaffected service that should NOT be recreated on app-only env changes
cat <<'EOF' >"${TEST_DIR}/compose.yaml"
services:
  app:
    image: alpine:latest
    command: ["sleep", "3600"]
    environment:
      APP_NAME: "Reconcile Test App"
      VECTOR_BINANCE_PAPER_TAKER_RATE: ${VECTOR_BINANCE_PAPER_TAKER_RATE:-}
      VECTOR_BINANCE_PAPER_SLIPPAGE_RATE: ${VECTOR_BINANCE_PAPER_SLIPPAGE_RATE:-}
  db:
    image: alpine:latest
    command: ["sleep", "3600"]
    environment:
      DB_NAME: "reconcile_test_db"
EOF

# 2. Stage 1: Initial deployment with baseline configuration
ENV_FILE="${TEST_DIR}/.env"
cat <<'EOF' >"${ENV_FILE}"
VECTOR_BINANCE_PAPER_TAKER_RATE=0.0004
VECTOR_BINANCE_PAPER_SLIPPAGE_RATE=0.0002
EOF

echo "[STEP 1] Initial deployment..."
docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" up -d

CID_APP_1="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q app)"
CID_DB_1="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q db)"

[[ -n "${CID_APP_1}" && -n "${CID_DB_1}" ]] || { echo "[FAIL] Containers not started" >&2; exit 1; }

RATE_APP_1="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${CID_APP_1}" | grep '^VECTOR_BINANCE_PAPER_TAKER_RATE=' | cut -d= -f2)"
echo "  app container ID : ${CID_APP_1} (TAKER_RATE=${RATE_APP_1})"
echo "  db container ID  : ${CID_DB_1}"

if [[ "${RATE_APP_1}" != "0.0004" ]]; then
    echo "[FAIL] Expected initial TAKER_RATE=0.0004, got ${RATE_APP_1}" >&2
    exit 1
fi

# 3. Stage 2: Repeated deployment with UNCHANGED configuration (Requirement 8)
echo "[STEP 2] Repeated deployment with UNCHANGED configuration..."
docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" up -d --remove-orphans

CID_APP_2="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q app)"
CID_DB_2="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q db)"

if [[ "${CID_APP_2}" != "${CID_APP_1}" ]]; then
    echo "[FAIL] Unchanged deployment unnecessarily recreated app service! (${CID_APP_1} -> ${CID_APP_2})" >&2
    exit 1
fi
if [[ "${CID_DB_2}" != "${CID_DB_1}" ]]; then
    echo "[FAIL] Unchanged deployment unnecessarily recreated db service! (${CID_DB_1} -> ${CID_DB_2})" >&2
    exit 1
fi
echo "  [PASS] Unchanged deployment left all services intact without recreation."

# 4. Stage 3: Repeated deployment with CHANGED environment configuration (Requirement 7 & 3)
echo "[STEP 3] Updating .env to VECTOR_BINANCE_PAPER_TAKER_RATE=0.0005..."
cat <<'EOF' >"${ENV_FILE}"
VECTOR_BINANCE_PAPER_TAKER_RATE=0.0005
VECTOR_BINANCE_PAPER_SLIPPAGE_RATE=0.0002
EOF

docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" up -d --remove-orphans

CID_APP_3="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q app)"
CID_DB_3="$(docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${TEST_DIR}/compose.yaml" ps -q db)"

if [[ "${CID_APP_3}" == "${CID_APP_2}" ]]; then
    echo "[FAIL] Environment-changed deployment failed to recreate app service!" >&2
    exit 1
fi
if [[ "${CID_DB_3}" != "${CID_DB_2}" ]]; then
    echo "[FAIL] Unaffected db service was unnecessarily recreated! (${CID_DB_2} -> ${CID_DB_3})" >&2
    exit 1
fi

RATE_APP_3="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${CID_APP_3}" | grep '^VECTOR_BINANCE_PAPER_TAKER_RATE=' | cut -d= -f2)"
SLIP_APP_3="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${CID_APP_3}" | grep '^VECTOR_BINANCE_PAPER_SLIPPAGE_RATE=' | cut -d= -f2)"

echo "  app container recreated: ${CID_APP_3}"
echo "  effective TAKER_RATE   : ${RATE_APP_3}"
echo "  effective SLIPPAGE_RATE: ${SLIP_APP_3}"
echo "  db container untouched : ${CID_DB_3}"

if [[ "${RATE_APP_3}" != "0.0005" || "${SLIP_APP_3}" != "0.0002" ]]; then
    echo "[FAIL] Effective environment in container does not match updated configuration!" >&2
    exit 1
fi

echo "================================================================="
echo "  ALL SAME-SHA CONFIGURATION REFRESH TESTS PASSED (100% OK)     "
echo "================================================================="
