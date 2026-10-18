#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

mkdir -p reports

echo "=== Starting Browser E2E Environment Setup ==="

# 1. Check isolated PostgreSQL
if ! pg_isready -h 127.0.0.1 -p 25432 -U postgres >/dev/null 2>&1; then
    echo "[FAIL] Isolated PostgreSQL is not reachable on 127.0.0.1:25432" >&2
    exit 1
fi
echo "[OK] PostgreSQL reachable on 127.0.0.1:25432"

# 2. Check Chrome binary
if [ ! -f "/usr/bin/google-chrome" ]; then
    echo "[FAIL] Chrome binary missing at /usr/bin/google-chrome" >&2
    exit 1
fi
echo "[OK] Chrome binary found at /usr/bin/google-chrome"

# 3. Check Playwright Python module
if ! python3 -c "import playwright" >/dev/null 2>&1; then
    echo "[FAIL] Playwright module missing in Python 3 environment" >&2
    exit 1
fi
echo "[OK] Playwright module available"

# 4. Verify Frontend build
if [ ! -d "frontend/dist" ] || [ ! -f "frontend/dist/index.html" ]; then
    echo "Building frontend dist ..."
    (cd frontend && npm run build)
fi

LARAVEL_PID=""
VITE_PID=""

cleanup() {
    echo "=== Cleaning up E2E background servers ==="
    if [ -n "${VITE_PID}" ] && kill -0 "${VITE_PID}" 2>/dev/null; then
        kill -9 "${VITE_PID}" 2>/dev/null || true
    fi
    if [ -n "${LARAVEL_PID}" ] && kill -0 "${LARAVEL_PID}" 2>/dev/null; then
        kill -9 "${LARAVEL_PID}" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# 5. Start Laravel Backend on 127.0.0.1:8000
echo "Starting Laravel backend on 127.0.0.1:8000 ..."
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=25432 \
DB_DATABASE=bybit_test \
DB_USERNAME=postgres \
DB_PASSWORD=testpass \
REDIS_CLIENT=predis \
CACHE_STORE=redis \
REDIS_HOST=127.0.0.1 \
REDIS_PORT=26379 \
php -d xdebug.mode=off -S 127.0.0.1:8000 -t public public/index.php > reports/e2e_backend.log 2>&1 &
LARAVEL_PID=$!

# Wait for backend
for i in {1..30}; do
    if curl -s http://127.0.0.1:8000/api/v1/dashboard >/dev/null 2>&1; then
        echo "[OK] Backend ready on port 8000"
        break
    fi
    sleep 0.2
done

# 6. Start Vite Preview on 127.0.0.1:4173
echo "Starting Vite preview on 127.0.0.1:4173 ..."
(cd frontend && npx vite preview --host 127.0.0.1 --port 4173 > ../reports/e2e_vite.log 2>&1) &
VITE_PID=$!

# Wait for frontend preview
for i in {1..30}; do
    if curl -s http://127.0.0.1:4173/ >/dev/null 2>&1; then
        echo "[OK] Frontend ready on port 4173"
        break
    fi
    sleep 0.2
done

# 7. Execute Playwright E2E Test Suite
echo "Running Playwright E2E Suite ..."
python3 scripts/run-browser-e2e.py | tee reports/browser-e2e.log
