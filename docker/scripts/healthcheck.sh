#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

WEB_BIND_IP="${WEB_BIND_IP:-127.0.0.1}"
WEB_PORT="${WEB_PORT:-18080}"

compose ps
curl --fail --silent --show-error "http://${WEB_BIND_IP}:${WEB_PORT}/up" >/dev/null
curl --fail --silent --show-error --user "${UI_BASIC_AUTH_USER}:${UI_BASIC_AUTH_PASSWORD}" \
    "http://${WEB_BIND_IP}:${WEB_PORT}/api/v1/dashboard" >/dev/null
compose exec -T postgres pg_isready -U "${POSTGRES_USER}" -d "${POSTGRES_DB}"
compose exec -T redis redis-cli --no-auth-warning -a "${REDIS_PASSWORD}" ping | grep -q PONG
compose exec -T app php artisan queue:monitor default,backtests --max="${QUEUE_BACKLOG_WARNING:-100}" --no-interaction

echo "Latest closed candles (UTC):"
compose exec -T postgres psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -Atc \
    "SELECT symbol || ' ' || interval || ' ' || COALESCE(MAX(open_time)::text, 'no-data') FROM market_candles WHERE is_closed = true GROUP BY symbol, interval ORDER BY symbol, interval;"

BACKUP_DIR="${BACKUP_DIR:-${PROJECT_ROOT}/backups/bybit-signals}"
mkdir -p "${BACKUP_DIR}"
df -P "${BACKUP_DIR}" | awk 'NR == 2 {print "Backup filesystem usage: " $5 ", available: " $4 " KiB"}'

if compose logs --since 10m market-ws | grep -Eqi 'fatal|uncaught|retCode.?10006|HTTP.?429'; then
    echo "Warning: recent MarketData/Bybit errors found; inspect docker compose logs market-ws." >&2
fi

echo "Healthcheck passed."
