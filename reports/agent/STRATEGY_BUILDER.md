# Universal strategy builder evidence

Historical v1 checkpoint. The current implementation and remaining capability limits are documented in [the November 5 completion report](STRATEGY_BUILDER_COMPLETION_20261105.md).

Baseline: `37777f2c03fddcbcbb4cb4688e222a7267ef62d8`, local workspace, 2026-11-04.

## Stage 1: compatibility mapping

- `StrategyRegistry` owns 13 compiled PHP strategies; preserve their identities and configuration hashes.
- `research-go/internal/algorithms` provides deterministic decimal indicators; `backtest.Engine.RunSignals` accepts causal signals and reuses execution costs and metrics.
- `DatasetInspector` pins and verifies exchange-scoped historical inputs. DSL research must retain pin verification and complete coverage.
- `/research` and its APIs remain available; `/strategies` will be additive.
- `research.token` is the existing configured-token authorization boundary. New management endpoints require it on reads and writes.
- `PaperTopologySynchronizer` accepts configured, functioning PRIMARY strategies. Draft DSL definitions must never enter that registry. PAPER eligibility requires verified execution parity; lack of parity blocks approval.
- Existing account provisioning, GlobalPaperCapitalGuard, drawdown provenance, exchange onboarding and V3.3 migration authorities remain unchanged.
- Two pre-existing untracked audit artifacts are outside this change.

Tracker baseline: Implementation 0%, Verification 0%, Product 0%; Phase 0 evidence closure blocked. No product credit is claimed for implementation.

## Implementation sequence

DSL and immutable persistence; Go interpretation; deterministic authoring and visual editor; pinned historical research integration; fail-closed governance; regression and browser verification. Each unit requires executed targeted checks before its local commit. No production actions are authorized.

## Stages 2 and 3: executed evidence

Stage 2: isolated PostgreSQL 17, task-owned `vector-builder-pg-20261104`, port 26432, database `bybit_test`; PHP 8.5 test image. Definition tests: 2 passed, 8 assertions. Baseline Pint: 775 files passed. Immutable definitions and audit events are additive; no account changes.

Stage 3: Go interpreter reuses decimal indicators and `Engine.RunSignals`; primary and higher-timeframe bars are aligned by close time, complete contiguous exchange-scoped inputs are mandatory, and entries fill on the next bar. Go race suite and vet passed, including determinism, prefix causality, incomplete candles, wrong exchange, gaps, future bars, warmup and higher-timeframe tests.

DSL v1 research profile: SMA, EMA, RSI, ATR, volume SMA, close PRICE, MACD line (period fast, twice-period slow), Bollinger bands (2 deviations), Donchian high/low; AND/OR/NOT, typed comparisons, crossover, inclusive range and percentage change. ATR(14) stop/target, bounded risk <=0.5%, cooldown and timeout. ADX, partial exits, custom exit conditions and funding costs are explicitly unsupported. PAPER eligibility remains false until execution parity is implemented and verified. No profitability claim.

## Implemented UI and authoring workflow

Path: `/strategies` (new navigation link; existing `/research` is preserved).

1. Enter the configured research owner token and load strategies. The token stays in component memory.
2. Use the EMA/RSI visual form, or interpret this supported English description:

   `Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.`

   Equivalent Russian grammar:

   `Создай стратегию для BTCUSDT. Войти в лонг когда EMA 20 пересекает снизу вверх EMA 50 и RSI ниже 65. Стоп-лосс 1.5 ATR. Тейк-профит 3 ATR. Риск 0.5%. Таймфрейм 15m.`

3. Review the displayed definition and assumptions (RSI 14, ATR 14, Bybit, timeout 48 bars, zero signal cooldown). Confirm, validate and save.
4. To clone/version, edit the identifier/version in the advanced DSL; existing immutable versions reject conflicting writes. The visual form emits the same EMA crossover + RSI conditions.
5. Inspect/select a saved version. Set an ISO 8601 UTC range (maximum seven days) and starting virtual capital, then run the pinned Go backtest. No execution starts if the binary, complete data, warmup or costs are unavailable.
6. Historical results expose transaction evidence, equity points, costs and reproduction metadata as JSON. Repeated identical runs return the same run identity.
7. Submit for PAPER approval. The current supported outcome is an audited `PAPER_INELIGIBLE` rejection. This is an eligibility check, not an owner-activation implementation. No accounts are created or altered.

The supported natural-language grammar is intentionally narrow and fully anchored. It accepts only the long EMA crossover/RSI/ATR example structure, with variable symbols, periods, thresholds, risk and primary timeframe. Decimal commas are normalized. Higher-timeframe rules can be expressed in the advanced DSL using indicator timeframes, but are not inferred from vague trend prose. Unrecognized text fails explicitly. No hosted provider is configured or implemented; no authoring text leaves the application.

## DSL v1 contract

Required root fields: `schema_version` (integer 1), `id` (`user_` prefix), `version` (positive integer), unique `symbols` and `exchanges` lists, `timeframe` (`"5"`, `"15"`, `"30"`, `"60"`), named `indicators`, `entry` with BUY and/or SELL, numeric `stop_atr`, `take_atr`, `risk_percent`, integer `timeout_bars` and `cooldown_bars`.

Each indicator has only `type`, integer `period` (2–500), and string `timeframe` at or above the primary timeframe. Unknown fields/types are rejected. Conditions have exactly one form: `{"and":[...]}`, `{"or":[...]}`, `{"not":...}`, or `{"op":"lt","left":"rsi","right":65}`. Comparisons: gt/lt/gte/lte/crosses_above/crosses_below/within/change_pct. Right operands are JSON numbers or indicator names; `within` takes two ordered numeric bounds. `change_pct` compares change from the preceding closed indicator value against a signed percentage threshold. Nesting is bounded to eight levels and boolean groups to 16 children. Missing warmup never becomes a true NOT condition.

ATR stops/targets use primary-frame ATR(14) at decision time. MACD currently means EMA(period) minus EMA(2*period), with no signal line. PRICE means closed candle close. Bollinger multiplier is fixed at two. Signal cooldown counts bars between emitted signals; it is not a post-loss or position-exit cooldown. If BUY and SELL both match, no signal is emitted. Each signal records a versioned reason and decision/entry bar indices. The existing conservative Go signal runner owns simulation, position sizing, commissions, slippage, timeout, and metrics.

## Historical integration and security

Laravel validates, authorizes, pins, loads bounded data and launches a configured Go executable via Symfony Process argument arrays and JSON stdin. User input is never executable code, a shell command, a binary path or an exchange endpoint. The Go process has no database, account or exchange client. Runtime execution is bounded by a 60-second process timeout and 64 MiB stdin limit.

Production entry uses a repeatable-read transaction for pin/data/result persistence. Independent candle-integrity sessions check every frame before and after evaluation. Existing pins are verified before returning cached results. All four existing dataset frames remain mandatory, including warmup; coverage requirements were not reduced. Each run stores definition checksum, pin ID/checksum, exchange/symbol/time range, explicit exchange-specific costs, input checksum, Go executable checksum, and `funding_complete=false`.

The PHP production Dockerfile includes a static Go DSL build stage and copies the executable into the application image. The stage was built locally; the complete production image and deployment were not exercised. Local source use requires `go build -o bin/strategy-dsl ./cmd/strategy-dsl` from `research-go`.

## API endpoints

All new endpoints require the existing `research.token` middleware. Shared-token authentication identifies the configured research owner; per-user identities/roles are not introduced.

| Method | Path under `/api/v1/strategies` | Behavior |
|---|---|---|
| GET | `/` | Paginated custom versions plus built-in metadata |
| POST | `/parse` | Deterministic draft interpretation, confirmation required |
| POST | `/validate` | Strict research-profile validation and checksum |
| POST | `/` | Confirmed immutable version save, idempotent by ID/version/content |
| GET | `/{id}` | Definition and audit evidence |
| GET | `/{id}/eligibility` | Explicit PAPER parity/registration blockers |
| POST | `/{id}/approve-paper` | Audited rejection until parity is supported |
| POST | `/{id}/backtests` | Pin, validate, execute Go, persist reproducible result |
| GET | `/{id}/backtests` | Paginated stored research results |

Authorization, confirmation, malformed definitions, immutable conflicts, save idempotency, incomplete data, real Go research, reproducibility, eligibility and approval rejection are covered by tests. Browser evidence covers interpretation, confirmation, validation, save, real pinned Go backtest, repeat identity and rejected approval. Browser data is synthetic, not exchange performance evidence.

## Incomplete mission requirements

This implementation is a working bounded research profile, not completion of the full mission. Remaining work includes durable editable drafts; full visual composition and side-by-side version comparisons; ADX and additional candle features; configurable MACD/BB outputs; partial/custom exits and funding; optional hosted authoring provider; optimization ranges and autonomous-research job integration; walk-forward/Monte Carlo/shadow for custom DSL; lifecycle transitions beyond validated versions and rejected PAPER submissions; equivalent forward-PAPER DSL evaluation; audited owner activation and safe 14th-strategy topology; chart/result presentation and broader E2E scenarios. No custom strategy can become PRIMARY, PAPER_ELIGIBLE or ACTIVE_PAPER through these endpoints. Existing topology integration is deliberately unchanged.

Acceptance scenarios fully or partially exercised: A–C (bounded EMA/RSI profile); D (identical parser definitions plus deterministic Go execution, not a separate exhaustive cross-language end-to-end parity suite); E–I (mandatory stop, malformed/unsupported rules, causal replay, complete synthetic history and missing coverage); K–L; N–O (exact fixture account/global guard preservation); Q (browser flow); R (existing regression tests); S–T. J and M are not implemented. P is structurally enforced by a pure Go process with no trading adapters and a no-network governance test, not a complete call-graph proof of the whole repository.

No production database or persisted forward-PAPER performance was inspected. Sample size, returns, funding completeness, production census and production SKIP reasons are therefore unverified. No push, deployment, real order or production mutation occurred.

## Completion accounting

Related frozen requirements are VEC-P1-001 through VEC-P1-008 (interface, parameter schema, metadata, immutable versions, approved registration, generator, compatibility and contracts). They are not marked complete by this research-only implementation. No frozen scope, weight, acceptance criterion or production obligation was changed. Before/after scores remain Implementation 0%, Verification 0%, Product 0%; the existing Phase 0 blocker remains. Tracker unit tests: 25 passed; generation and strict validation against starting HEAD passed. Source and local tests are evidence, not production/product acceptance.

## Verification commands and environments

Use `/home/thunder/.headroom/bin/rtk` if `rtk` is absent from PATH. Local checks used PHP 8.5 test image `vector-agent-php85-test:local`, PostgreSQL 17, a task-owned Redis 7.4, and Node 24.19.0 for the final frontend checks. Earlier Node 20 builds also passed, but Node 24 meets the declared frontend engine requirement.

```sh
rtk proxy bash -lc "cd research-go && go test -race ./... && go vet ./... && go build -o bin/strategy-dsl ./cmd/strategy-dsl"
rtk docker build --target strategy-dsl -f docker/php/Dockerfile -t vector-builder-dsl:local .
rtk proxy bash -lc "cd frontend && npm test && npm run build"
rtk proxy python3 -m unittest discover -s scripts/tests -p test_vector_progress.py
rtk proxy python3 scripts/vector_progress.py --strict --base-ref 37777f2
```

PHP checks ran in a task-owned Docker environment, mounting this checkout at `/app` and isolated storage at `/app/storage`; database `bybit_test` at local port 26432 and Redis at 26479. Essential overrides: `DB_PORT=26432 TEST_DB_PORT=26432 REDIS_HOST=127.0.0.1 REDIS_PORT=26479 REDIS_URL= REDIS_PASSWORD= REDIS_PREFIX=vector_builder_`. Tests use the repository safety preflight. Target command: `php artisan test tests/Feature/StrategyBuilder`; full regression: `php artisan test`; style: `./vendor/bin/pint --test`. PHP lint covers app, tests, database, config and routes.

Browser fixture uses the separate `bybit_builder_browser` database and isolated browser storage. The fixture seeder refuses other database names or non-testing environments. `scripts/browser/strategy_builder.mjs` needs Playwright Core (installed under `/tmp/vector-builder-browser` for this run), Chromium, the frontend preview at port 18125 and a real isolated API at port 18124. API interception forwards requests to that API; it does not mock responses. Reproduction:

```sh
rtk docker exec -w /app vector-builder-browser-php php scripts/browser/seed_strategy_builder.php
rtk proxy env PLAYWRIGHT_MODULE=/tmp/vector-builder-browser/node_modules/playwright-core/index.mjs node scripts/browser/strategy_builder.mjs
```

Browser API must explicitly set both `DB_DATABASE=bybit_builder_browser` and `TEST_DB_DATABASE=bybit_builder_browser`, clear `DB_URL`, configure the test owner token, and use its own storage mount. Its built-in PHP server runs with `/app/public` as working directory. The default test-image entrypoint uses a non-owner UID; Pint formatting was rerun with an explicit root shell entrypoint, followed by passing style checks.

Initial full run: 1,219 passed, 32 failed, 8 skipped, 10,751 assertions, 500.55 seconds. It encountered pre-existing malformed candle journal storage and unavailable Redis. These files were preserved; integrity gates were not weakened. An isolated baseline snapshot of `37777f2` passed all 11 Binance readiness tests separately. The corrected full run uses separate storage and Redis; its final result is recorded below and in the evidence files. No results are inferred from a partial log.

The first isolated full run reproduced the onboarding fixture leak: 1,233 passed, 19 failed, 8 skipped (11,586 assertions, 319.15 s). Starting-HEAD full baseline independently had 19 failed, 1,229 passed, 5 skipped (11,580 assertions, 461.21 s); baseline lacked the added builder tests and Redis fixture. `ExchangeOnboardingTest` now uses the established `CleansCandleConflictEvidence` helper, confining intentionally conflicting CSV evidence to each isolated test. Combined onboarding/builder regression then passed 19 tests / 116 assertions. Existing workspace journal bytes were preserved. The CSV runtime format issue itself is pre-existing and outside this bounded builder implementation; no journal-validation rule was relaxed.

The browser fixture produced one synthetic trade, so execution was exercised. This sample is not evidence of profitability or exchange performance.

## Local implementation commits

6b33a0398d2e1c1109bc64c2bb7f5b53e4cf4ddd build(strategy): package Go interpreter and verify browser research flow
01eb837aad98663a507faa900caa19c150358dea test(strategy): isolate onboarding journals from research regression
99eaa696f58ceff7fc0aabe7a1b5bdfc678d130b fix(strategy): fence research snapshots and preserve PAPER governance
010eca054f0e5f454789f2a1f8ec92ef5fe9753a feat(strategy): integrate authorized management and pinned Go backtests
ad269ce4ce383c7c6cf2dc23c1a586912a300124 feat(strategy): add bilingual authoring and visual EMA RSI builder
d6edcfd1bdd505277ac525ca62330ebcb4b72703 feat(strategy): execute causal DSL research through Go
2d15997683daa595dba6fe8e54efc519241be27c feat(strategy): validate and persist immutable DSL versions
a07946d9a2c52cb10d5bcff0d753a8bc8023d22f docs(strategy): map builder compatibility and safety boundaries

Additional regression commit: `7e56831` freezes the recovery fixture clock across retries. The preceding full run had 1,251 passed, one failed, eight skipped (11,897 assertions, 335.22 s). Its failed before/after comparison contained 154 vs 155 rows: a newly eligible ETHUSDT candle, with no modified original rows. The fixed-window fixture and real Go worker integration subsequently passed together: seven tests, 74 assertions. The initial host-built worker was dynamically linked and could not execute in Alpine; a static `CGO_ENABLED=0` build resolved that environment error. The static worker binary is a local ignored test artifact, not a source change or deployment.

Expected environment skips in the final run: four Docker Compose checks (the PHP image has no Docker CLI) and one opt-in public Bybit API check. The three previously skipped Go worker tests now execute. Full production-image build and production runtime acceptance remain unverified.

## Final verification outcome

Direct PHPUnit completed with exit **0**: **1,255 passed, 5 skipped, 11,945 assertions, 6 PHPUnit notices**, 366.039 seconds. The final Artisan invocation printed the same passing counts but its outer runner exited 143; that is retained as a failed harness attempt, not counted as a clean command. The direct run verifies the suite at `7e56831`; the subsequent strict list-shape correction `70a9894` passed its isolated targeted checks (two tests / ten assertions) and Pint. No further runtime changes followed.

Other executed results: Go race suite and vet exit 0; frontend 71 passed and production typecheck/build passed on Node 24.19.0; PHP lint exit 0; full Pint 787 files passed and final touched-file style checks passed; Chromium completed the real API/Go flow with zero page errors; static DSL Docker build passed; 25 tracker tests and strict validation passed. Remaining skips are the four Docker-CLI-dependent Compose checks inside the PHP image and the opt-in public Bybit API call. Six PHPUnit notices remain reported; they are not test failures. No real-market performance or production acceptance is claimed.

Retained evidence: `strategy-builder/manifest.json`, `phpunit.txt`, `targeted.txt`, `controls.txt`, `typed-lists.txt`, `go.txt`, `frontend.txt`, `pint.txt`, `browser.log`, `strategies.png`, and the actual saved `example.json`. The manifest preserves hashes for earlier failed logs as well as final checks. Source commits are listed above; the last validation correction is `70a9894`. Generated completion projections remain provisional at 0/0/0.
