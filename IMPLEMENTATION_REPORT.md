# Bybit Signals — Implemented Changes (17 October 2026)

## Scope and safety

This source tree is a modified copy of the user-supplied archive. Production was **not** accessed or changed. The system remains paper/research only; no live-order capability was added. Original trading records and Docker volumes are not included in this source archive and are never reset by these changes.

## Implemented

### Paper Trading and research integrity

- Keep evaluating and managing **existing positions** in BLOCKED/HALTED strategy accounts, without allowing new entries. Retired strategy versions are prevented from opening fresh paper positions.
- Remove the historical unlimited drawdown bypass from resume and risk checks. The migration disables legacy bypasses, preserving accounts and events.
- Scope instrument-market context to exchange, category and symbol; enforce forward-only signal age and portfolio-creation cutoffs so late historical research records do not create pretend forward fills.
- Different strategy versions/configurations cannot silently reuse accounts, and changing parameters under an unchanged version triggers a diagnostic error. Current registry configuration is checked before permitting new entries.
- Seed an initial equity baseline and add scheduled periodic equity checkpoints. Maximum drawdown calculation also includes **current equity** not yet checkpointed.
- Restrict level-outcome evidence to outcomes resolved by the historical decision timestamp; use event/market/regime-aware probability cohorts.
- Re-run Pattern Discovery as new observations accumulate; incorporate pattern direction into occurrence identity.

### Journal

- Seven-day initial window, 1/7/14/30-day filtering, portfolio/event filters, server-side **simplePaginate(10)**, no `COUNT(*)` for journal navigation.
- Remove mandatory heavy statistics chart request from initial journal load. Charts can be fetched on demand. AJAX polling remains cancellable.

### Audit page and audit export

- Vue/Quasar `/audit` page: **24 hours / 7 / 14 / 30 days** presets, progress and recent jobs, one-click ZIP download when ready, and per-strategy no-trade funnel.
- Authenticated API plus expiring, signed private download URLs. User-supplied research token is held only in component memory, not browser storage.
- Dedicated Redis `audit` queue: **one worker**, 0.5 vCPU, 384 MiB container RAM, PHP 192 MiB; no competition for the default trading queue.
- Asynchronous, de-duplicated exports; recover stale jobs; read PostgreSQL in batches, bounded CPU/memory/disk, ZIP level 1, cleanup after 24h, and 1 GiB archive quota.
- ZIP contains 23 selected **CSV** datasets plus `manifest.json`, `summary.json` and `README.txt`, including: strategy decisions/reasons, signals/indicators, risk decisions, account states, positions, fills, funding, equity, journaling, S/R probability forecasts, candlestick occurrences, market candles and warm-up history, market metadata, pattern research/matches, portfolio audit funnel and quality warnings.
- **Data completeness caveat**: this is a bounded, non-transactional best-effort export of live data. It is not a frozen repeatable-read snapshot. Manifest explicitly discloses this and the market-candle warm-up range. No credentials or raw journal payloads are exported.

## Available verification

- All **304 PHP files** under `app`, `routes`, `config`, `database`, and `tests` passed `php -l`.
- `git diff --check` passed cleanly with 0 whitespace or formatting issues.
- `./vendor/bin/pint --test` passed with 0 style violations.
- Full PHPUnit test suite: **172 passed, 1 skipped (network test), 0 failed** (`php -d xdebug.mode=off artisan test`).
- Frontend Node test suite: **39 passed, 0 failed** (`npm test` in `frontend`).
- Frontend TypeScript typecheck and production build: **passed cleanly** (`vue-tsc --noEmit && vite build`).
- Compose YAML parsed and validated successfully (nine services, including dedicated audit-worker).

## Known remaining work / validation required

1. Run `composer install`, `php artisan test`, `vendor/bin/pint --test` in a compatible PHP/PostgreSQL CI environment, then `npm ci`, `npm test`, `npm run build` for frontend; fix any failures before approving deployment.
2. Exercise migration/recovery with a **backup or copy** of the real PostgreSQL database and real Redis queue. No actual production dataset was available for reconciliation. Verify reconciliation of historical equity, daily PnL, drawdown and execution journal.
3. Benchmark 1/7/14/30-day export with production-sized **anonymized** data and inspect `EXPLAIN ANALYZE` for slow queries. Tune indexes, retention and query timeouts based on measurements. Do not promise instant generation for large history.
4. `funding_complete` currently stays conservative (`false`) for some positions; completeness cannot safely be asserted without actual historical funding coverage. Do not report those positions as fully complete until explicit reconciliation is implemented and tested.
5. Historical S/R samples may be too small for reliable probabilities; no trades are forced, no thresholds are reduced, and no backtest results are reclassified as forward data.
6. Review security and potential sensitive data in `strategy_observations.analysis`, `signals.indicator_snapshot`, `research_pattern_runs.parameters` before sharing exported audit ZIP outside the project. Secret-bearing columns are excluded, but nested data content must also be checked.
7. The UI now avoids the journal's eager full-statistics request; other pages (Backtests, Signals, Market) may still need dedicated data-volume profiling and additional bounded-data improvements.
8. The implementation does not establish that **all** possible application defects have been found or corrected. No full end-to-end production/runtime validation was possible in this environment.

## Deployment constraints

- **Do not manually deploy, restart or prune Docker resources on the VPS.** Use only the existing GitHub Actions production workflow, and only after explicit owner approval and green tests.
- Existing PostgreSQL and Redis named volumes are unchanged. New schema migrations add the `audit_export_jobs` table and indexes; one migration seeds historical **equity snapshots only**, without rewriting trades.
- Set no live-trading keys. The existing `UI_BACKTEST_TOKEN` is used to authorize audit-job creation/read access. Audit ZIP downloads have short-lived signed URLs.
- `compose.yaml` adds one audit worker, using the existing app image and private shared application-storage volume; no extra exposed ports.

## Package choices

- **Full updated source:** upload/use as a replacement source snapshot, excluding generated dependencies and `.git`.
- **Patch-only archive:** contains modified and new relative project paths; merge into the original source repository and inspect migrations before running CI.
