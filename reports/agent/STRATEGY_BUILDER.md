# Universal strategy builder evidence

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
