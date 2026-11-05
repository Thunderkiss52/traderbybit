#!/usr/bin/env bash
set -Eeuo pipefail
suite="${1:?Browser suite required}"
mkdir -p ci-browser
export VECTOR_BROWSER_BASE_URL=http://127.0.0.1:4173
export VECTOR_BROWSER_API_URL=http://127.0.0.1:8000
export VECTOR_BROWSER_SCREENSHOT="$PWD/ci-browser/${suite}.png"
export VECTOR_BROWSER_PHP="${VECTOR_BROWSER_PHP:-php}"
export VECTOR_BROWSER_REPORT_DIR="$PWD/ci-browser"
export PLAYWRIGHT_MODULE="$PWD/frontend/node_modules/playwright-core/index.mjs"
export CACHE_STORE=array QUEUE_CONNECTION=database SESSION_DRIVER=array
backend_pid='' frontend_pid=''
cleanup() {
    local pids=()
    [[ -n "${backend_pid:-}" ]] && pids+=("$backend_pid")
    [[ -n "${frontend_pid:-}" ]] && pids+=("$frontend_pid")
    if ((${#pids[@]} > 0)); then
        kill -TERM "${pids[@]}" 2>/dev/null || true
        sleep 0.2
        kill -9 "${pids[@]}" 2>/dev/null || true
    fi
    pkill -P $$ 2>/dev/null || true
}
trap cleanup EXIT

if [[ "$suite" == phase0 ]]; then
    python3 -u scripts/verify-browser-phase0.py 2>&1 | tee ci-browser/phase0.log
    exit
fi
[[ "$DB_HOST" == 127.0.0.1 && "$DB_DATABASE" == bybit_test && "$APP_ENV" == testing ]]
router=public/index.php
case "$suite" in
    dashboard) ;;
    builder|historical)
        if [[ "$suite" == builder ]]; then
            export DB_DATABASE=bybit_builder_browser RESEARCH_API_TOKEN=builder-browser-test
        else
            export DB_DATABASE=bybit_vision_browser RESEARCH_API_TOKEN=vision-browser-test
            router=scripts/browser/historical_fixture.php
        fi
        export TEST_DB_DATABASE="$DB_DATABASE"
        export PGPASSWORD="$DB_PASSWORD"
        createdb --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" "$DB_DATABASE"
        "$VECTOR_BROWSER_PHP" artisan migrate --force --no-interaction > ci-browser/migrations.log
        if [[ "$suite" == builder ]]; then
            "$VECTOR_BROWSER_PHP" scripts/browser/seed_strategy_builder.php
        else
            "$VECTOR_BROWSER_PHP" scripts/browser/historical_fixture.php seed
        fi
        ;;
    *) echo "Unknown browser suite: $suite" >&2; exit 2 ;;
esac
export PHP_CLI_SERVER_WORKERS=4
"$VECTOR_BROWSER_PHP" -S 127.0.0.1:8000 -t public "$router" > ci-browser/backend.log 2>&1 &
backend_pid=$!
(cd frontend && exec node node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port 4173 --strictPort) > ci-browser/frontend.log 2>&1 &
frontend_pid=$!
for attempt in {1..100}; do
    kill -0 "$backend_pid" "$frontend_pid"
    if curl --fail --silent --connect-timeout 1 http://127.0.0.1:8000/up >/dev/null && curl --fail --silent --connect-timeout 1 http://127.0.0.1:4173/ >/dev/null; then break; fi
    sleep 0.2
done
curl --fail --silent --connect-timeout 1 http://127.0.0.1:8000/up >/dev/null
curl --fail --silent --connect-timeout 1 http://127.0.0.1:4173/ >/dev/null
case "$suite" in
    dashboard) python3 -u scripts/run-browser-e2e.py 2>&1 | tee ci-browser/dashboard.log ;;
    builder) node scripts/browser/strategy_builder.mjs 2>&1 | tee ci-browser/builder.log ;;
    historical) node scripts/browser/historical_data.mjs 2>&1 | tee ci-browser/historical.log ;;
esac
