#!/usr/bin/env bash
set -Eeuo pipefail
# Each caller owns the entire PostgreSQL and Redis service instances.
[[ "${APP_ENV:-}" == testing && "${DB_DATABASE:-}" == bybit_test && "${RUN_DB:-}" == bybit_test ]]
[[ "${REDIS_HOST:-}" == 127.0.0.1 && "${REDIS_PORT:-}" =~ ^[0-9]+$ ]]
[[ "${REDIS_PREFIX:-}" =~ ^vector-ci-[a-zA-Z0-9_-]+-$ ]]
[[ -z "${REDIS_URL:-}" && -z "${DB_URL:-}" ]]
cp .env.example .env
chmod 600 .env
start=$SECONDS
php scripts/verify-test-database.php
echo "PostgreSQL identity initialization: $((SECONDS-start))s"
start=$SECONDS
php -r 'require "vendor/autoload.php"; $r = new Predis\Client(["host"=>getenv("REDIS_HOST"), "port"=>getenv("REDIS_PORT")]); if ((string) $r->ping() !== "PONG") { exit(1); }'
echo "Redis readiness: $((SECONDS-start))s"
start=$SECONDS
php artisan migrate --database=pgsql --force --no-interaction
echo "PostgreSQL migrations: $((SECONDS-start))s"
