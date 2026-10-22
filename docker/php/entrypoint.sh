#!/bin/sh
set -eu

php /usr/local/bin/verify-php-runtime.php >/dev/null

install -d -o app -g app \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

chown -R app:app /var/www/html/storage /var/www/html/bootstrap/cache

if [ "$(id -u)" = "0" ] && [ "${1:-}" != "php-fpm" ]; then
    exec su-exec app "$@"
fi

exec "$@"
