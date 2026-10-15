# traderbybit

\# Роль\
Ты — старший инженер-разработчик и квант-аналитик. Нужно спроектировать и написать\
систему, которая по техническому анализу выдаёт торговые сигналы BUY (открыть лонг /\
купить) и SELL (открыть шорт / продать) для инструментов на бирже Bybit (bybit.com).\
\
\# Главное ограничение\
Система только генерирует и показывает сигналы. Она НЕ размещает ордера и не имеет\
доступа к торговле. API-ключи Bybit — только read-only (без прав Trade/Withdraw).\
Интерфейс должен явно писать, что это исследовательский режим и не торговая рекомендация.\
\
\# Контекст\
\- Рынок: Bybit [spot / USDT-perpetual linear / оба]. Инструменты: [BTCUSDT, ETHUSDT, ...\
&#x20; или «топ-N по обороту»].\
\- Данные: публичные эндпоинты Bybit API v5 (свечи, тикеры, стакан, funding rate,\
&#x20; open interest) и WebSocket для живых свечей. Перед написанием кода сверься с актуальной\
&#x20; документацией Bybit API v5 (пути, лимиты, формат ответов, категории). Не угадывай поля.\
&#x20; Учти rate limits: кэширование, backoff при 429/10006, переподключение WebSocket.\
\- Таймфреймы: анализ на 3 таймфреймах [5m / 15m / 1h; старший даёт тренд, младший вход].\
\- Стек: PHP 8.3 + Laravel (API, очереди, планировщик), Vue 3 + Quasar (UI),\
&#x20; БД [MySQL/PostgreSQL], Redis, деплой в k3s. Тесты: Pest/PHPUnit и node:test для фронта.\
\
\# Что должна делать система\
1\. Загрузка и хранение свечей: исторические + живые, без дыр и дублей, в UTC.\
&#x20;  Сигналы считаются только по закрытым свечам.\
2\. Индикаторы (реализуй сам, с юнит-тестами на эталонных значениях): EMA/SMA, RSI, MACD,\
&#x20;  ATR, Bollinger Bands, ADX, объём/VWAP, уровни поддержки/сопротивления.\
3\. Стратегия (детерминированная, правила в конфиге, а не в коде):\
&#x20;  \- Фильтр тренда по старшему таймфрейму (EMA 50/200, ADX).\
&#x20;  \- Триггер входа на младшем таймфрейме (пересечение, откат к EMA, пробой уровня с\
&#x20;    подтверждением объёмом, дивергенция RSI — предложи и обоснуй набор).\
&#x20;  \- Фильтры качества: минимальный объём/ликвидность, спред, волатильность (ATR),\
&#x20;    запас до ближайшего уровня, funding rate/open interest как контекст.\
&#x20;  \- Классификация сигналов по классам качества (A/B/C) с понятными правилами.\
4\. Каждый сигнал содержит: инструмент, сторона (BUY/SELL), таймфрейм, цена входа\
&#x20;  (зона), стоп-лосс (по ATR/структуре), тейк-профит(ы), соотношение риск/прибыль,\
&#x20;  рекомендуемый риск на сделку в % от депозита, срок актуальности сигнала, список причин\
&#x20;  (какие правила сработали) и список рисков. Если условий недостаточно — SKIP с причиной.\
5\. Управление рисками: макс. число одновременных сигналов, корреляция инструментов,\
&#x20;  «охлаждение» после серии убыточных, запрет сигналов при экстремальной волатильности\
&#x20;  или при устаревших данных.\
6\. Журнал и статистика: сохраняй каждый сигнал и его исход (достиг TP / SL / истёк),\
&#x20;  считай winrate, средний R, profit factor, просадку — по классам и инструментам.\
7\. Бэктест и walk-forward: движок на исторических данных с комиссиями, проскальзыванием\
&#x20;  и без заглядывания в будущее (look-ahead bias). Отчёт по метрикам; запрет «подгонки»\
&#x20;  параметров под всю историю — используй разделение на train/validation/test.\
8\. UI: дашборд с активными сигналами (карточка: график свечей, вход/стоп/тейк, причины,\
&#x20;  риски, таймер актуальности), история сигналов с фильтрами, страница статистики.\
9\. Уведомления (по желанию): Telegram/e-mail о новых сигналах класса A.\
\
\# Архитектура и качество кода\
\- Модули: MarketData (клиент Bybit, WS, кэш), Indicators, Strategy, Signals, Risk,\
&#x20; Backtest, Notifications, API, UI. Контракты через интерфейсы; клиент биржи замокан\
&#x20; в тестах.\
\- Сигнал — стабильный JSON-контракт с версией схемы; версию стратегии сохраняй вместе\
&#x20; с каждым сигналом и не пересчитывай старые задним числом.\
\- Воркеры очереди с таймаутами, ретраями, идемпотентностью (один сигнал на свечу и\
&#x20; инструмент), корректное завершение по SIGTERM.\
\- Секреты — только через переменные окружения/секреты k8s, никогда не логируй ключи.\
\- Логи и метрики: возраст данных, задержка расчёта, число сигналов, ошибки API.\
\- Покрой тестами: индикаторы, правила стратегии (табличные кейсы), бэктест без\
&#x20; look-ahead, обработку ошибок и rate limits.\
\
\# Как работать\
1\. Сначала задай мне до 10 уточняющих вопросов (рынок, инструменты, горизонт сделки,\
&#x20;  допустимый риск, стек деплоя). Если ответа нет — выбери разумный дефолт и явно\
&#x20;  перечисли допущения.\
2\. Затем выдай план по этапам с критериями приёмки: (1) данные Bybit → (2) индикаторы →\
&#x20;  (3) стратегия и сигналы → (4) риск и журнал → (5) бэктест → (6) UI → (7) деплой.\
3\. Реализуй этап за этапом. Для каждого этапа: список файлов, код, тесты, команды\
&#x20;  запуска и что именно проверено. Не переходи дальше, пока тесты этапа не проходят.\
4\. Честно указывай, что не проверено (например, не запускалось на реальных данных),\
&#x20;  и не выдумывай результаты бэктестов и метрики.\
\
\# Чего делать нельзя\
\- Не обещать доходность и не называть стратегию «прибыльной» без бэктеста на отложенной\
&#x20; выборке с учётом комиссий.\
\- Не использовать данные из будущего, не считать сигнал по незакрытой свече.\
\- Не добавлять автоматическое размещение ордеров и не запрашивать торговые права ключа.\
\
\# Формат ответа\
Сначала вопросы и допущения, потом план этапов. Код отдавай по файлам, с путями.



## Уточнения

Чтобы не тормозить работу, пока приму такие допущения:

1. Рынок: Bybit USDT-perpetual, категория `linear`.
2. Инструменты: BTCUSDT и ETHUSDT; список фиксированный, без автоматического выбора топ-N.
3. Таймфреймы: 1h — фильтр тренда, 15m — сигнал, 5m — уточнение входа. Решения — только по закрытым свечам.
4. Сигналы: система выдаёт BUY/SELL/SKIP, но не размещает ордера и не требует API-ключей.
5. Стек: PHP 8.3, Laravel, Vue 3, Quasar, PostgreSQL, Redis, k3s.
6. Риск: параметры риска не считаются персональной рекомендацией; до бэктеста и paper trading система работает только в исследовательском режиме.
7. Рабочий процесс: сначала архитектура и этап 1, затем следующий этап — только после проверки тестов текущего.
8. Среда: считаю, что готового репозитория пока нет. Если он есть, перед написанием файлов нужно учитывать его структуру.

## План этапов и критерии приёмки

1. Данные Bybit: клиент публичного API v5, загрузка исторических и текущих свечей, проверка ответов, дедупликация, хранение времени в UTC, обработка лимитов и ошибок. Приёмка: тесты на формат ответов, повторы запросов, ошибки и отсутствие дублей.
2. Индикаторы: EMA/SMA, RSI, MACD, ATR, Bollinger Bands, ADX, объём/VWAP, уровни поддержки и сопротивления. Приёмка: тесты на эталонных рядах, включая начальные периоды, пропуски и граничные значения.
3. Стратегия и сигналы: конфигурируемые правила тренда и входа, фильтры качества, классы A/B/C, SKIP с причинами. Приёмка: табличные тесты для BUY, SELL, SKIP и пограничных условий.
4. Риск и журнал: срок действия сигнала, стоп и цели, лимиты одновременных сигналов, корреляция, охлаждение после серии неудач и хранение версии стратегии. Приёмка: тесты идемпотентности и расчёта исхода сигнала.
5. Бэктест: комиссии, проскальзывание, разделение train/validation/test, walk-forward и защита от look-ahead bias. Приёмка: синтетические тесты на известных сценариях; никаких выдуманных результатов на реальном рынке.
6. Интерфейс: активные сигналы, история и статистика, заметное предупреждение об исследовательском режиме. Приёмка: тесты ключевых компонентов и отображения пустых/ошибочных состояний.
7. Деплой: контейнеры и конфигурация k3s, секреты через окружение/Secret, мониторинг, завершение воркеров по SIGTERM. Приёмка: проверка конфигурации и инструкции запуска; реальный деплой отмечается как непроверенный, если он не выполнялся.

## Правила реализации

- Каждый этап — отдельный ответ: файлы с путями, код, тесты и команды запуска. Не переходить к следующему этапу, пока тесты текущего не проходят.
- Перед кодом для Bybit сверять актуальную документацию API v5: категории, пути, поля ответов, лимиты и WebSocket-события. Не угадывать структуру API.
- Сначала использовать только публичные рыночные данные; API-ключи не запрашивать и не добавлять в код.
- Показывать, что именно проверено. Если тесты не запускались или реальные данные не использовались, прямо это указать.
- Не обещать доходность и не представлять исторические результаты как прогноз.

Чтобы меньше тратить время на уточнения, добавь в исходный промпт: «Если параметр не указан, выбери безопасное разумное значение, перечисли допущение и продолжай. Не задавай повторно вопросы, на которые уже ответили. В одном ответе выполняй только один этап. Не заявляй, что код, документация или тесты проверены, если этого фактически не делал».


**5. Автономность и контроль качества реализации**

- Если параметр не указан, самостоятельно выбери безопасное и разумное значение, явно перечисли допущения и продолжай работу.
- Не задавай повторно вопросы, на которые уже получены ответы.
- В одном ответе реализуй только один этап разработки.
- Не переходи к следующему этапу, пока тесты текущего этапа не пройдут успешно.
- Не заявляй, что код, документация, интеграции или тесты проверены, если фактически проверка не выполнялась.
- Если тестирование невозможно из-за ограничений среды, укажи причину и предоставь команды для самостоятельной проверки.
- Не заменяй полноценную реализацию псевдокодом или заглушками без явного указания.
- Если возникают технические проблемы, сначала попытайся самостоятельно их устранить, не перекладывая решение на пользователя.
- Приоритет: корректность, безопасность, воспроизводимость, простота архитектуры и тестируемость. Избегай ненужных абстракций и переусложнения.


<!-- headroom:rtk-instructions -->
# RTK (Rust Token Killer) - Token-Optimized Commands

When running shell commands, **always prefix with `rtk`**. This reduces context
usage by 60-90% with zero behavior change. If rtk has no filter for a command,
it passes through unchanged — so it is always safe to use.

## Key Commands
```bash
# Git (59-80% savings)
rtk git status          rtk git diff            rtk git log

# Files & Search (60-75% savings)
rtk ls <path>           rtk read <file>         rtk grep <pattern>
rtk find <pattern>      rtk diff <file>

# Test (90-99% savings) — shows failures only
rtk pytest tests/       rtk cargo test          rtk test <cmd>

# Build & Lint (80-90% savings) — shows errors only
rtk tsc                 rtk lint                rtk cargo build
rtk prettier --check    rtk mypy                rtk ruff check

# Analysis (70-90% savings)
rtk err <cmd>           rtk log <file>          rtk json <file>
rtk summary <cmd>       rtk deps                rtk env

# GitHub (26-87% savings)
rtk gh pr view <n>      rtk gh run list         rtk gh issue list

# Infrastructure (85% savings)
rtk docker ps           rtk kubectl get         rtk docker logs <c>

# Package managers (70-90% savings)
rtk pip list            rtk pnpm install        rtk npm run <script>
```

## Rules
- In command chains, prefix each segment: `rtk git add . && rtk git commit -m "msg"`
- For debugging, use raw command without rtk prefix
- `rtk proxy <cmd>` runs command without filtering but tracks usage
<!-- /headroom:rtk-instructions -->

## Current engineering requirements (October 2026; supersedes deprecated setup assumptions above)

- Deployment is **Docker Compose** to the existing shared VPS, not k3s/Kubernetes.
  Keep unrelated containers, ports, networks, volumes and projects untouched.
- The application supports **research signals AND virtual paper execution**.
  These are paper-only: never create real orders or live trading endpoints, never
  request trade-enabled API keys, and do not add an operational LIVE toggle.
- `AGENTS.md` guides coding agents; the standalone Laravel runtime **does not
  execute this prompt**. Strategy decisions must be implemented as deterministic,
  versioned PHP rules in `config/strategy.php` and the Strategy/Indicators modules.
  Risk and paper execution rules belong in `config/paper.php` and PaperTrading.
- Keep source-controlled strategy/risk rules aligned with their unit and integration
  tests. Preserve recorded signal versions and execution history. Any future LLM
  decision support must be a separate explicitly approved design; never use an
  interactive Codex session as a required runtime trading component.
- Enforce historical causality: closed candles only, valid as-of-time market
  context, sufficient indicator warm-up, and no future data in decisions/fills.
- Scheduled paper trading is **forward-only** from paper-account creation; a
  historical replay must be explicitly requested and distinguished from actual
  forward-paper performance in any reporting.

### Incremental implementation / verification workflow

1. Inspect only relevant repository files and run a baseline check.
2. First iteration: implement a focused functional fix and its regression tests.
3. Run targeted tests; resolve failures before proceeding.
4. Second and later iterations: implement the next focused fix and test again.
5. Final verification: run the full available test suites, PHP lint, frontend
   typecheck/build, and repository diff checks. State any blocked tests accurately.
6. Proceed to **data analytics**: inspect actual persisted forward-paper signals,
   risk decisions, fills, PnL, funding completeness and missing market intervals.
   Report sample size and SKIP reasons. Never invent returns or conflate backtests
   with forward-paper results if historical data or a live database is unavailable.
7. Changes reach the VPS only through the established Git deployment workflow.
   Do not manually patch deployed code or access other Docker projects.

### Token-efficient agent behavior

- Prefer specific `rg`, small file reads, minimal patches and concise test output.
- Never dump `vendor`, `node_modules`, logs or binary files into prompt context.
- Use `rtk` only when installed and when its filtering does not hide needed error
  details; raw commands are acceptable for diagnostics and correctness.
- Save compact implementation notes when context compression is necessary.
- Token savings must not reduce essential testing, security or data integrity.
