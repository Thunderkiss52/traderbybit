# Docker deployment — Bybit Signals

Конфигурация предназначена для Ubuntu 24.04 с Docker Engine и Compose v2. Она не меняет глобальный Nginx, контейнеры или volumes других проектов.

Production image использует PHP 8.4 FPM: текущий `composer.lock` содержит Symfony 8.1, которому требуется PHP 8.4. Node.js присутствует только в build stage и в runtime-контейнеры не попадает.

## Изоляция

- Compose project: `bybit-signals`.
- Единственный опубликованный порт: `127.0.0.1:${WEB_PORT:-18080}`.
- PostgreSQL и Redis не имеют `ports` и доступны только в сети `bybit-signals_internal`.
- Volumes: `bybit-signals_postgres_data`, `bybit-signals_redis_data`, `bybit-signals_app_storage`.
- `container_name`, host networking, privileged mode и Docker socket не используются.
- Все UI/API routes защищены Basic Auth внутри контейнера `web`; `/up` оставлен без авторизации только для локального healthcheck. POST backtest дополнительно требует отдельный `X-Research-Token`.

## Первый запуск

```bash
cp .env.production.example .env.production
chmod 600 .env.production
```

Создайте независимые секреты и внесите их в `.env.production`:

```bash
openssl rand -base64 32       # APP_KEY: добавьте префикс base64:
openssl rand -hex 32          # PostgreSQL password
openssl rand -hex 32          # Redis password
openssl rand -hex 24          # Basic Auth password
openssl rand -hex 32          # UI_BACKTEST_TOKEN
```

Проверьте `APP_URL`, `CORS_ALLOWED_ORIGINS`, свободный `WEB_PORT` и доступное место на диске. Затем:

```bash
ENV_FILE=.env.production ./docker/scripts/deploy.sh
```

Скрипт выполняет `docker compose config`, проверяет конфликт loopback-порта, собирает images, запускает только собственные PostgreSQL/Redis, ждёт healthchecks, применяет миграции и запускает остальные сервисы. Persistent volumes не удаляются.

Проверка:

```bash
ENV_FILE=.env.production ./docker/scripts/healthcheck.sh
docker compose --env-file .env.production -p bybit-signals ps
docker compose --env-file .env.production -p bybit-signals logs -f queue scheduler market-ws backtest-worker
```

## Существующий reverse proxy

Для host Nginx используйте `docker/nginx/external-reverse-proxy.example.conf`, адаптировав домен и сертификаты. Upstream остаётся `http://127.0.0.1:18080`; порт 80/443 Compose не занимает.

Если сервер использует общую external Docker network, сначала убедитесь, что сеть уже создана владельцем reverse proxy. Чужой Compose-файл менять не нужно:

```bash
USE_EXTERNAL_PROXY=1 \
EXTERNAL_PROXY_NETWORK=shared-proxy \
ENV_FILE=.env.production \
docker compose --env-file .env.production \
  -f compose.yaml -f compose.proxy.yaml \
  -p bybit-signals up -d
```

В этом режиме reverse proxy направляет трафик на DNS-имя `web` в общей сети. Loopback-порт можно оставить как аварийный локальный доступ.

Не публикуйте `WEB_BIND_IP=0.0.0.0`: HTTPS завершается на существующем reverse proxy, а внутренняя Basic Auth остаётся вторым контуром защиты.

## Обновление и rollback

Сначала получите проверенный commit/tag обычным workflow Git. Скрипт сам не переключает ветки и не выполняет destructive Git-команды:

```bash
NEW_IMAGE_TAG=v1.2.3 ENV_FILE=.env.production ./docker/scripts/update.sh
```

Перед миграциями создаётся проверенный backup. Старые images и volumes не удаляются. Предыдущий тег записывается в `.deploy-previous`.

Rollback приложения:

```bash
PREVIOUS_TAG=$(cat .deploy-previous)
IMAGE_TAG="$PREVIOUS_TAG" SKIP_BUILD=1 ENV_FILE=.env.production ./docker/scripts/deploy.sh
```

Миграции должны оставаться backward-compatible. Автоматический rollback базы намеренно запрещён: восстановление данных требует отдельного подтверждения и сначала выполняется в тестовую базу.

Никогда не используйте для обновления `docker compose down -v`.

## Backup и restore

Ручной backup:

```bash
ENV_FILE=.env.production ./docker/postgres/backup.sh
```

Создаётся custom-format `pg_dump`, затем `pg_restore --list` проверяет читаемость, рядом сохраняется SHA-256. По умолчанию архивы находятся в `./backups/bybit-signals`, права каталога `0700`, ротация — 14 дней.

Пример cron без пересечения с другими проектами:

```cron
17 3 * * * cd /srv/bybit-signals && ENV_FILE=.env.production ./docker/postgres/backup.sh >> /var/log/bybit-signals-backup.log 2>&1
```

Безопасная проверка восстановления в отдельную базу:

```bash
RESTORE_TARGET_DB=bybit_signals_restore \
ENV_FILE=.env.production \
./docker/scripts/restore.sh ./backups/bybit-signals/<backup>.dump --confirm
```

Скрипт проверяет checksum, отказывается менять production DB и прекращает работу, если тестовая база уже существует. После проверки удалите только тестовую базу явной командой:

```bash
docker compose --env-file .env.production -p bybit-signals \
  exec postgres dropdb -U bybit_signals bybit_signals_restore
```

## Эксплуатация

- `queue` обрабатывает только `default`; `backtest-worker` — только `backtests` и имеет отдельные CPU/memory limits.
- Scheduler существует в одном экземпляре. Не масштабируйте сервис `scheduler`.
- `market-ws` перезапускается автоматически, а приложение восстанавливает пропущенные свечи REST-механизмом MarketData.
- Docker logs ротируются через `json-file`.
- Laravel пишет JSON в stderr.
- `healthcheck.sh` проверяет web/API, PostgreSQL, Redis, queue backlog, последние закрытые свечи, Bybit errors и свободное место backup filesystem.
- Для graceful shutdown используйте `docker compose stop`; не отправляйте `kill -9`.

Для production рекомендуется настроить системный мониторинг на ненулевой exit code `healthcheck.sh`, Docker `unhealthy`, заполнение диска и отсутствие свежих 5m-свечей более 15 минут.
