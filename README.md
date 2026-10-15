# traderbybit

Исследовательская платформа анализа публичных рыночных данных Bybit.

> **Исследовательский режим. Не является инвестиционной рекомендацией.**
> Приложение не размещает ордера, не содержит торговых endpoints и на этапе MarketData не использует API-ключи.

## Реализованные этапы

### MarketData

- Laravel 13 / PHP 8.3+, PostgreSQL в рабочей среде, Redis для очередей, кеша и распределённых блокировок.
- Публичный REST API Bybit v5: свечи, ticker, стакан, funding history и open interest.
- Исторические свечи с обратной пагинацией Bybit, сортировкой по возрастанию времени и проверкой ответа.
- Повторы при HTTP `429`, HTTP `5xx`, сетевых ошибках и Bybit `retCode=10006`: exponential backoff с jitter и конечным числом попыток.
- Идемпотентное хранение `NUMERIC`-значений и уникальный ключ серии свечей.
- Поиск разрывов и ограниченный REST-backfill без создания искусственных свечей.
- Публичный WebSocket `linear`, подписки на BTCUSDT/ETHUSDT и 5m/15m/1h, сохранение только `confirm=true`.
- Heartbeat каждые 20 секунд, переподключение, REST-восстановление и SIGTERM shutdown.
- Queue jobs для первоначальной синхронизации, восстановления пропусков и контроля свежести; Scheduler запускает последние две задачи.

### Indicators

- Собственные реализации SMA, EMA, RSI Wilder, MACD, ATR Wilder, Bollinger Bands, ADX Wilder, rolling VWAP и анализа объёма.
- Поддержка/сопротивление строятся по подтверждённым pivot high/low. Pivot становится доступен только после закрытия `right_bars` свечей, поэтому будущие данные не используются.
- Все вычисления используют `brick/math`, фиксированную внутреннюю точность 24 знака, банковское округление и выходную точность 8 знаков. Одинаковая последовательность свечей даёт байтово одинаковый результат.
- Входная серия должна состоять только из закрытых, строго упорядоченных и непрерывных свечей одного инструмента и таймфрейма.
- Warm-up вычисляется из всех настроек. При стандартной конфигурации требуется 200 свечей из-за EMA/SMA 200; до этого `IndicatorSeries::isReadyForSignals()` возвращает `false`.
- Недоступные до завершения warm-up значения представлены как `null`, а не как искусственные нули.

Формулы и начальные значения зафиксированы явно:

- EMA начинается с SMA первых `period` значений.
- RSI, ATR и ADX используют сглаживание Wilder.
- Первый true range для ATR равен `high - low`.
- Bollinger Bands используют population standard deviation (`N`, не `N-1`).
- VWAP является скользящим и использует typical price `(high + low + close) / 3`.
- ADX впервые доступен после `2 × period` свечей.

Параметры находятся в `config/indicators.php`. Чистая точка входа для следующего этапа — `App\Indicators\Application\IndicatorEngine`.

### Strategy + Signals

- Детерминированный `StrategyEngine` анализирует только закрытые и синхронизированные 1h/15m/5m свечи и выдаёт BUY, SELL или сохраняемый SKIP с конкретной причиной.
- 1h-фильтр использует EMA 50/200 и ADX/+DI/-DI; 15m поддерживает EMA pullback, MACD crossover и подтверждённый breakout; 5m обязан подтвердить направление.
- Ликвидность, спред, ATR, относительный объём, средний оборот и пространство до противоположного уровня проверяются до выпуска сигнала. Funding и изменение open interest сохраняются как контекстные риски.
- Entry zone, SL, TP1/TP2 и R/R рассчитываются детерминированно через ATR/структуру и округляются по Bybit `tickSize`. При отсутствии исторического рыночного контекста или `tickSize` результат — SKIP.
- Классы A/B/C, scoring, модельный риск, срок входа и максимальное удержание настраиваются в `config/strategy.php`.
- Сигналы имеют UUID v5, версию стратегии/JSON-схемы и неизменяемую идемпотентную запись на комбинацию рынок/свеча/версия стратегии.
- Планировщик сохраняет публичный ticker/order book/инструментальный контекст на границе 15 минут и запускает генерацию через две минуты. Исторический `--at` использует только снимки с `observed_at <= decision_time`.
- JSON-контракт всегда содержит `research_mode=true` и предупреждение об исследовательском режиме. Торговые endpoints отсутствуют.

### Risk Management, Journal и Paper Trading

- Изолированные виртуальные портфели со стартовым балансом 10 000 USDT, equity, realized/unrealized PnL, зарезервированным риском и drawdown-защитой.
- `RiskEngine` проверяет лимит позиций, суммарный риск, notional exposure, свободный капитал, корреляцию BTC/ETH, cooldown, drawdown и качество рыночных данных.
- Размер PAPER-позиции рассчитывается от риска до SL с учётом taker fee и slippage, затем округляется вниз по публичному Bybit `qtyStep`; неизвестные ограничения приводят к REJECTED.
- Строгие состояния сигналов `PENDING_ENTRY/ACTIVE/EXPIRED/REJECTED/COMPLETED` и позиций `OPEN/PARTIALLY_CLOSED/CLOSED_*`.
- Детерминированное исполнение по закрытым 5m-свечам: entry, TP1 50%, TP2, SL, timeout и критическое risk closure. Неоднозначные OHLC-бары помечаются и обрабатываются консервативно — Stop Loss имеет приоритет.
- Комиссии, проскальзывание, funding, gross/net PnL и R-multiple сохраняются отдельными неизменяемыми исполнениями. Журнал событий append-only.
- Повторная обработка безопасна благодаря UUID/idempotency keys, транзакциям, row locks и Redis locks. При разрыве 5m-ряда позиция ждёт восстановления данных.
- Статистика включает Win Rate, Profit Factor, Average R, Expectancy, Net PnL, комиссии, funding, drawdown, время удержания и серии побед/поражений с фильтрами по портфелю и сигналу.
- Все операции явно являются `PAPER/SIMULATED`; приватные торговые API, биржевые балансы и реальные ордера отсутствуют.

### Backtest + Walk-Forward

- Изолированный backtest не изменяет рабочие `signals`, paper accounts или виртуальные позиции. Он переиспользует `StrategyEngine`, `RiskEngine`, `PositionSizingService` и `PaperExecutionEngine`.
- Перед запуском проверяются закрытость и непрерывность 5m/15m/1h истории, warm-up и исторические market-context/funding данные; набор данных получает SHA-256 checksum.
- Обычный эксперимент делится хронологически на Train 60%, Validation 20% и независимый Test 20%. Позиции на границе сегмента закрываются по последней доступной закрытой свече и явно помечаются.
- Walk-forward использует настраиваемые окна 6/2/2 месяца с шагом 2 месяца. Параметры выбираются только на Train/Validation; агрегат содержит только out-of-sample сделки без двойного учёта.
- Ограниченный grid search оценивает не только Net PnL, но также минимальное число сделок, drawdown и устойчивость Train/Validation. Test никогда не участвует в выборе параметров.
- Сохраняются все решения BUY/SELL/SKIP, исполнения, сделки, equity curve, версии алгоритмов и конфигурации. Завершённый запуск идемпотентен и не пересчитывается задним числом.
- Отчёт включает Win Rate, Profit Factor, Average R, Expectancy, Maximum Drawdown, Sharpe/Sortino по регулярным 5m equity returns, exposure, holding time и серии убытков, а также разрезы и stress-сценарии комиссий/slippage.

### Frontend UI + Dashboard

- Vue 3, TypeScript, Quasar, Pinia и Vue Router собраны как отдельное приложение в `frontend/`; интерфейс адаптивен, по умолчанию тёмный и поддерживает RU/EN, локальное/UTC-время и пользовательские настройки в `localStorage`.
- Dashboard, рынок, сигналы, PAPER-позиции, журнал, статистика, RiskEngine и backtest-отчёты получают только реальные сохранённые данные через версионированный Laravel API `/api/v1`.
- График инструмента показывает закрытые свечи, рассчитанные backend индикаторы EMA/Bollinger/VWAP/RSI/MACD/ADX/volume, маркеры сигналов и уровни Entry/SL/TP. Frontend не пересчитывает стратегию.
- Таблицы используют серверную пагинацию, поиск, детальный audit-view и CSV-экспорт текущей страницы. Polling автоматически восстанавливается с ограниченным exponential backoff.
- Единственная UI-операция записи — постановка исследовательского backtest в очередь. Она выключена без `UI_BACKTEST_TOKEN` и требует Bearer token; торговых endpoints и ручных операций с позициями нет.
- На всех страницах отображается предупреждение `PAPER TRADING / RESEARCH MODE — Виртуальные операции. Не является инвестиционной рекомендацией.`

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Заполните параметры PostgreSQL и Redis в `.env`. Публичные endpoints Bybit не требуют ключей.
Для локального запуска исследований через UI задайте случайный `UI_BACKTEST_TOKEN`; без него POST endpoint безопасно возвращает `503`.

Production Docker Compose для изолированного VPS-деплоя описан в [`docker/README.md`](docker/README.md). Он использует project name `bybit-signals`, публикует только `127.0.0.1:18080`, защищает UI/API Basic Auth и не открывает PostgreSQL/Redis наружу.

Frontend:

```bash
cd frontend
npm install
npm run dev
```

Frontend использует Node.js 22+ (требование актуальной исправленной версии Quasar). Vite проксирует `/api` на Laravel `http://127.0.0.1:8000`. Для production-сборки выполните `npm run build`.

## Использование

Синхронно:

```bash
php artisan market:sync-candles --symbol=BTCUSDT --interval=15 --from=2026-01-01 --to=2026-02-01
```

Через очередь:

```bash
php artisan market:sync-candles --symbol=ETHUSDT --interval=60 --from=2026-01-01 --to=2026-02-01 --queue
php artisan queue:work --tries=3 --timeout=900
```

Живые закрытые свечи и планировщик:

```bash
php artisan market:stream-candles
php artisan schedule:work
```

Генерация исследовательского результата:

```bash
php artisan signals:generate --symbol=BTCUSDT
php artisan signals:generate --symbol=ETHUSDT --at=2026-10-13T10:15:00Z
```

Перед первым запуском требуется минимум 220 непрерывных закрытых свечей каждого таймфрейма и сохранённый свежий снимок рыночного контекста. Планировщик создаёт такие снимки автоматически; исторический replay намеренно возвращает SKIP, если исторического снимка нет.

Paper trading:

```bash
php artisan paper:process --account=<uuid> --symbol=BTCUSDT
php artisan paper:process --account=<uuid> --symbol=BTCUSDT --at=2026-10-14T10:30:00Z
php artisan paper:positions --account=<uuid> --symbol=BTCUSDT
php artisan paper:stats --account=<uuid> --symbol=BTCUSDT
php artisan paper:account
php artisan paper:account --account=<uuid> --resume
```

`--resume` является явным административным разрешением после drawdown halt. Исторический `paper:process --at` использует только закрытые свечи и snapshots, доступные не позже указанного UTC-момента.

Backtest и walk-forward:

```bash
php artisan backtest:run --symbols=BTCUSDT,ETHUSDT --from=2025-01-01 --to=2026-10-01
php artisan backtest:walk-forward --symbols=BTCUSDT,ETHUSDT --from=2025-01-01 --to=2026-10-01 --queue
php artisan backtest:report --run=<uuid>
php artisan backtest:compare --runs=<uuid1>,<uuid2>
php artisan backtest:cancel --run=<uuid>
php artisan queue:work --tries=2 --timeout=3600
```

Backtest требует полную локальную историю всех трёх таймфреймов и исторические snapshots с instrument metadata. При разрыве данных запуск завершается ошибкой, а не создаёт фиктивные цены. Отсутствующий ожидаемый funding event помечает сделку как `funding_complete=false`.

Тесты и форматирование:

```bash
php artisan test
vendor/bin/pint --test
cd frontend && npm test && npm run build
```

Опциональная проверка реального публичного API (не входит в изолированный unit suite):

```bash
RUN_BYBIT_INTEGRATION=1 php artisan test --testsuite=Integration
```

## Проверенные контракты Bybit v5

Реализация сверена с официальной документацией 13 октября 2026 года:

- [Get Kline](https://bybit-exchange.github.io/docs/v5/market/kline): `GET /v5/market/kline`, максимум 1000, список приходит от новых свечей к старым.
- [Get Tickers](https://bybit-exchange.github.io/docs/v5/market/tickers): `GET /v5/market/tickers`.
- [Get Orderbook](https://bybit-exchange.github.io/docs/v5/market/orderbook): `GET /v5/market/orderbook`.
- [Funding Rate History](https://bybit-exchange.github.io/docs/v5/market/history-fund-rate): `GET /v5/market/funding/history`.
- [Open Interest](https://bybit-exchange.github.io/docs/v5/market/open-interest): `GET /v5/market/open-interest`.
- [Public WebSocket connection](https://bybit-exchange.github.io/docs/v5/ws/connect): `wss://stream.bybit.com/v5/public/linear`, JSON ping рекомендуется раз в 20 секунд.
- [WebSocket Kline](https://bybit-exchange.github.io/docs/v5/websocket/public/kline): topic `kline.{interval}.{symbol}`; свеча закрыта только при `confirm=true`.
- [Rate limits](https://bybit-exchange.github.io/docs/v5/rate-limit) и [error codes](https://bybit-exchange.github.io/docs/v5/error): обработаны HTTP 429 и `retCode=10006`.

REST-свечи не содержат признака закрытия. Поэтому закрытие определяется строго как `open_time + interval <= response.time`; незакрытая свеча отбрасывается перед сохранением синхронизатором.

## Допущения этапа 1

- Категория фиксирована как `linear`; инструменты — BTCUSDT и ETHUSDT; интервалы Bybit — `5`, `15`, `60`.
- `--from` включён, `--to` исключён; даты без timezone трактуются как UTC.
- При недоступной части истории команда возвращает ненулевой код и сообщает число оставшихся пропусков. Искусственные свечи не создаются.
- WebSocket-клиент — поддерживаемый `phrity/websocket` 4.x (PHP 8.2+), преемник заброшенного `textalk/websocket`.
- Интеграционные запросы к реальному Bybit намеренно не входят в unit suite; unit/feature тесты не используют сеть.


## GitHub Actions deployment

Manual one-click deployment requires a one-time setup of the SSH server wrapper, GHCR image access, and production GitHub Secrets. See [`docker/GIT_DEPLOY.md`](docker/GIT_DEPLOY.md) for exact prerequisites, the Actions button workflow, repeated-deployment behavior, and recovery steps. Never assume the workflow is ready on the VPS before these checks pass.

### Local PHP test environment (no `.env` warnings)

Run `composer test` (or `XDEBUG_MODE=off php artisan test --no-ansi`). The test bootstrap and Composer test script create a local
`.env` from `.env.example` **only when `.env` is missing**. It never overwrites
an existing local or production environment. PHPUnit uses the isolated SQLite
configuration and test key from `phpunit.xml`. Disabling Xdebug for this
command prevents connection timeout messages when no IDE debugger is listening.

To run Artisan directly for the first time, initialize the local file once:

```sh
test -f .env || cp .env.example .env
XDEBUG_MODE=off php artisan test --no-ansi
```

Never commit `.env` or use actual production secrets in local test runs.
