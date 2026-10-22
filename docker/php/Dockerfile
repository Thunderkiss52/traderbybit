# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM php:8.5.12-fpm-alpine3.24 AS php-extensions
RUN apk add --no-cache $PHPIZE_DEPS libpq-dev icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip pcntl bcmath \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis

FROM php:8.5.12-fpm-alpine3.24 AS php-base
ARG APP_UID=10001
ARG APP_GID=10001
RUN apk add --no-cache ca-certificates curl su-exec icu-libs libpq libzip \
    && addgroup -g "${APP_GID}" -S app \
    && adduser -u "${APP_UID}" -S -G app -h /home/app -s /sbin/nologin app \
    && sed -ri 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf
COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/php/entrypoint.sh /usr/local/bin/bybit-entrypoint
COPY scripts/verify-php-runtime.php /usr/local/bin/verify-php-runtime.php
RUN php /usr/local/bin/verify-php-runtime.php && php-fpm -v | grep -F 'PHP 8.5.12'
RUN chmod 0755 /usr/local/bin/bybit-entrypoint
WORKDIR /var/www/html
ENTRYPOINT ["bybit-entrypoint"]

FROM php-base AS test
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip bash python3 go

FROM php-base AS vendor
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip
COPY --chown=app:app composer.json composer.lock ./
COPY --chown=app:app app ./app
COPY --chown=app:app database ./database
RUN --mount=type=cache,target=/home/app/.cache/composer,uid=10001,gid=10001 \
    COMPOSER_PROCESS_TIMEOUT=30 su-exec app composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM php-base AS app
ENV APP_ENV=production
LABEL io.bybit-signals.component="app"
COPY --chown=app:app app ./app
COPY --chown=app:app bootstrap ./bootstrap
COPY --chown=app:app config ./config
COPY --chown=app:app database ./database
COPY --chown=app:app public ./public
COPY --chown=app:app resources ./resources
COPY --chown=app:app routes ./routes
COPY --chown=app:app artisan composer.json composer.lock ./
COPY --from=vendor --chown=app:app /var/www/html/vendor ./vendor
COPY --from=frontend --chown=app:app /build/frontend/dist ./public
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS web
LABEL io.bybit-signals.component="web"
RUN apk add --no-cache apache2-utils
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/10-auth.sh /docker-entrypoint.d/10-auth.sh
COPY public/ /var/www/html/public/
COPY --from=frontend /build/frontend/dist /var/www/html/public
RUN chmod 0755 /docker-entrypoint.d/10-auth.sh
EXPOSE 80
