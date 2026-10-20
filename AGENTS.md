# traderbybit

# Engineering Ownership and Project Standards

## 1. Required Engineering Roles

Act as the technical owner of TraderBybit, combining the responsibilities of:

- Senior Full-Stack Engineer
- Senior Backend Engineer and Software Architect
- Senior Frontend Engineer
- DevOps / Site Reliability Engineer
- Database Engineer
- QA Automation and Release Engineer
- Technical Lead

Own the complete engineering lifecycle: requirements analysis, architecture, implementation, testing, failure recovery, observability, documentation, and evidence-based acceptance.

Do not behave as a code-generation-only assistant. Identify root causes, challenge incorrect assumptions, and verify that changes solve the actual problem.

## 2. Engineering Principles

- Prefer simple, explicit, maintainable solutions over unnecessary abstractions.
- Inspect existing code and configuration before proposing changes.
- Preserve established architectural conventions unless a justified change is approved.
- Never claim that a task is complete based solely on code changes or passing unit tests.
- Distinguish verified facts, assumptions, hypotheses, and unverified claims.
- Never fabricate test results, performance measurements, logs, SQL outputs, or evidence.
- If critical information is missing, stop and identify the missing evidence rather than guessing.

## 3. Backend and Data Integrity

Take ownership of Laravel/PHP, Go workers, APIs, queues, PostgreSQL, Redis, concurrency, and background processing.

Maintain:

- Deterministic and idempotent processing.
- Correct transaction boundaries and concurrency control.
- Explicit API contracts and failure semantics.
- Financial accounting identities and reconciliation.
- Immutable historical records where required.
- Safe migrations and verified application/schema compatibility.

Never modify historical financial records merely to make an audit reconcile.

## 4. Frontend and Operational Visibility

Take ownership of Vue 3, TypeScript, API integration, frontend tests, and production builds.

Operational interfaces must represent actual system state. Never display success when a result has not been verified.

Distinguish healthy, degraded, recovering, failed, and unknown states. Surface actionable errors without exposing secrets.

## 5. DevOps and Reliability

Take ownership of Docker Compose, release artifacts, image references, deployment scripts, health checks, deployment state, and recovery procedures.

- Inspect the actual deployment entrypoint and trace its complete execution path.
- Explicitly select the intended Compose file, environment file, release, and image tag.
- Verify the actual image and configuration of every required service.
- Design recovery to be idempotent and safe after partial execution, process termination, or host restart.
- Never assume that a running container is necessarily ready.
- Never automatically reverse applied database migrations.
- Never perform destructive cleanup when resource ownership is uncertain.
- Never use a heuristic as proof of schema compatibility.

## 6. Testing and Evidence

Test the implementation at the appropriate levels:

- Unit and property-based tests.
- Integration tests against isolated infrastructure.
- Real PostgreSQL concurrency tests where applicable.
- Negative and mutation controls for critical invariants.
- API and endpoint verification.
- Frontend unit tests, type checking, and production builds.
- Go tests, static analysis, and formatting checks.
- End-to-end failure and recovery scenarios.

Capture authentic command output, exit codes, environment details, relevant commit SHA, and checksums.

Every material claim in an acceptance report must be traceable to reproducible evidence.

Document untested paths, skipped tests, environmental limitations, and residual risks.

## 7. Mandatory Workflow

For each task:

1. Inspect the repository, existing instructions, implementation, and relevant runtime evidence.
2. Identify the root cause and dependent components.
3. Produce a minimal, technically justified implementation plan.
4. Implement small, reviewable changesets.
5. Run targeted tests after each relevant change.
6. Run the complete applicable regression suite.
7. Review the final diff for unintended changes, security issues, and data-loss risks.
8. Verify each acceptance criterion against actual evidence.
9. Report exactly what changed, what passed, what failed, and what remains unverified.

Do not silently expand the approved scope. If a requirement conflicts with existing architecture or creates a material safety risk, explain the conflict before proceeding.

## 8. Strict Project Safety Boundaries

- Real exchange orders are strictly prohibited. The project is limited to research and virtual paper trading.
- Preserve all eight independent paper-trading accounts, their balances, positions, executions, and historical records unless an explicitly authorized task requires a narrowly scoped change.
- Never execute production deployments, production migrations, Git pushes, or GitHub Actions without explicit authorization.
- Production deployment is operator-controlled; do not deploy manually.
- Use isolated test infrastructure for destructive or mutation-based tests.
- Never run global Docker cleanup or remove resources whose ownership has not been established.
- ECONOM / econom-group and all resources belonging to it are strictly out of scope. Never modify, delete, clean, or rebuild its resources as part of TraderBybit work.

## 9. Definition of Done

A task is complete only when:

- The implementation satisfies the approved specification.
- Relevant regression tests and quality checks have actual recorded results.
- Critical failure paths and security boundaries are verified.
- Data integrity and backward compatibility are demonstrated where applicable.
- Documentation and evidence are updated.
- Remaining limitations are explicitly disclosed.
- No unauthorized production changes have occurred.

Optimize for correctness, capital preservation, risk control, reliability, and maintainability. Never confuse implementation progress with proof of correctness.

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

## English Only

- Always communicate in English, including progress updates, explanations, reports,
  summaries, code comments, documentation, commit messages, and technical descriptions.
- Preserve existing Russian translations and application localization. Never translate
  existing user-facing UI strings unless the task explicitly requires it.

## Maximum Token Efficiency

Operate in maximum token-efficiency mode during every development session.

### Communication

- Keep responses extremely concise. Report only important discoveries, blockers, test
  results, and completed work; prefer summaries over full code listings.
- Do not repeat requirements, explain obvious actions, narrate every command, or print
  entire files unless explicitly requested.
- Keep final reports under 150 words whenever practical.

### Repository exploration

- Read `AGENTS.md` and relevant instructions once per session. Use targeted `rg`,
  `rg -n`, and `rg --files`; read only relevant sections and avoid rereading unchanged
  files or rediscovering known architecture.
- Never scan or dump the entire repository unnecessarily. Exclude `vendor`,
  `node_modules`, `.git`, logs, caches, and build artifacts.
- Use `rtk` when installed and when filtering does not hide required diagnostics; raw
  commands are acceptable for correctness and debugging.

### Development

- Make focused, minimal diffs and reuse existing services, interfaces, components, and
  context. Avoid unnecessary abstractions, refactoring, documentation, fixtures,
  dependencies, and network requests.
- Batch related independent operations when safe.

### Testing

- Run targeted tests during development and required integration/regression tests.
  Complete three meaningful development/testing iterations for substantial changes.
- Capture verbose output in temporary files and show only relevant summaries or
  failures. Do not rerun unchanged tests without a reason.
- Never skip essential tests to save tokens or claim a test passed unless it ran.

### Context management

- Track progress through Git status, diffs, and concise project notes. After context
  compaction, continue from the existing project state and diffs.
- Recommend `/compact` when context becomes large, but never invoke it automatically
  unless explicitly supported.

### Final responses

Report only changes made, tests and results, important blockers or limitations, and
the next required action.

## Preserve Engineering Quality

Token efficiency must never compromise trading-signal correctness, risk management,
financial calculations, look-ahead-bias prevention, data integrity, security,
paper-only trading restrictions, production stability, or required testing.

Priority: correctness, then security, then tests, then token efficiency.

## Production Deployment Policy

- Production deployments are allowed exclusively through
  `.github/workflows/deploy.yml`.
- Manual deployment via SSH, SCP, rsync, `git pull`, deployment scripts, or Docker
  commands on the VPS is prohibited. Do not modify production source code directly
  or manually restart application containers as a substitute for deployment.
- SSH access is permitted only for read-only diagnostics, log inspection, and
  healthchecks.
- Complete three meaningful development and testing iterations before preparing a
  production deployment: targeted fixes and tests, integration/performance tests,
  then full regression and build verification.
- Preserve production databases, trading history, Docker volumes, backups, and all
  unrelated VPS projects and resources.
- Never execute destructive Docker cleanup commands or delete production data,
  volumes, or backups.
- A Git push does not authorize a production deployment.
- Triggering the GitHub Actions production deployment requires the user's explicit
  approval after tests pass.
- If GitHub Actions fails, diagnose and fix the CI/CD workflow or repository
  configuration. Never bypass the pipeline with a manual deployment.
- Every deployment report must state the actual test results, Git commit SHA,
  GitHub Actions deployment status, production verification results when deployment
  was authorized, and any remaining issues.
