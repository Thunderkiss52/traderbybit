# Bybit Signals — paper-pipeline audit of the user-provided source archive

**Scope:** Offline source review and local checks only. This is **not** an
inspection of the deployed VPS, its PostgreSQL records, or its live market feed.
Do not present the code-level findings below as measured trading performance.

## What changed

### Iteration 1 — MarketData readiness & temporal integrity

- `EnsureStrategyHistoryJob` checks the most recent `strategy.history_limit`
  consecutive closed candles for each configured symbol and interval. If the
  required window is missing or broken, it queues an idempotent Bybit REST sync.
  It is scheduled hourly; the existing short-range gap recovery remains intact.
  This prevents cold starts from remaining permanently below EMA 200 warm-up.
- Market context is collected at minutes 13, 28, 43 and 58, *before* the
  decision candle closes. Quotes observed after a 15m close are not usable as
  if they were known at the close. A two-minute lead provides queue headroom.
- Automatic forward-paper processing ignores strategy decisions from before
  creation of the selected paper account. Explicit historical `--at` replay is
  not confused with scheduled processing; do not use it to manipulate forward
  results on the production account.

### Iteration 2 — Visible forward-paper analytics

- Dashboard uses the existing `portfolio` API data (balance, equity, total
  simulated return, net/realized/unrealized PnL, risk and open positions).
- Recent decision list includes **SKIP**, showing the decision's stored reasons.
  The API also aggregates the eight most frequent SKIP reasons in the last 24h.
- Added daily/weekly/monthly cumulative and period realized Net PnL chart from
  persisted closed trades, without fabricated sample values.
- Statistics page now displays actual backend data-quality keys:
  `ambiguous_execution_positions`, `incomplete_funding_positions`, and
  `awaiting_market_data_positions`. It polls and shows API errors.

## What must be checked on the real server after deployment

1. Confirm >220 contiguous closed 1h/15m/5m candles per symbol, and monitor
   queue history bootstrap until complete. Gaps or insufficient warm-up must
   produce SKIP, not look-ahead data.
2. Compare one 15m decision's `decision_time`, 1h/5m candle close times and
   `market_context.observed_at`. Context must be <= decision time and no more
   than 300 seconds old.
3. For each symbol, review latest BUY/SELL/SKIP and each SKIP reason frequency.
4. Verify risk approval creates PENDING_ENTRY, then a real newly closed bar
   triggers a paper entry only if eligible; watch TP1/TP2/SL/Timeout transitions.
5. Reconcile paper balance + unrealized PnL with account equity, executed fees,
   funding-completeness flags, and per-position results.
6. Confirm daily/weekly/monthly chart sums match journal closed-position Net PnL.
7. Ensure the backtest data and paper-forward accounts are isolated.
8. Check container health, backlog, and log warnings, without impacting other
   projects on the VPS. Deploy by the Git workflow only.

## Data-analysis constraints

The archive has source code and tests but contains **no usable production
PostgreSQL market, signals, positions, or portfolio history**. Therefore actual
BUY/SELL frequency, win rate, realized net returns and drawdown **cannot be
calculated from this archive**. Obtain those metrics from the deployed read-only
Dashboard/API only after successful deployment and verified data collection.
A paper-trading result is simulated, not a guarantee of executable live returns.

## Verification

Run:

```bash
php vendor/bin/phpunit --no-progress
php vendor/bin/pint --test
CACHE_STORE=array php artisan schedule:list
php artisan route:list --path=api
cd frontend
npm test
npm run build
```

If extensions are missing, use the existing PHP 8.4 Docker image with its
required extensions to run the PHPUnit/Pint checks. Never call them successful
without a real run.
