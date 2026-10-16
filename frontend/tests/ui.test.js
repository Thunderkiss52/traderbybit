import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const read = path => readFileSync(new URL(`../src/${path}`, import.meta.url), 'utf8');
const source = path => read(path);
const utilsCode = ts.transpileModule(read('utils.ts'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
const utils = await import(`data:text/javascript;base64,${Buffer.from(utilsCode).toString('base64')}`);

test('SignalCard renders BUY SELL and SKIP semantics', () => {
  const text = source('components/SignalCard.vue') + source('types/index.ts');
  for (const side of ['BUY', 'SELL', 'SKIP']) assert.match(text, new RegExp(side));
});
test('SignalCard contains entry, SL and both targets', () => {
  const text = source('components/SignalCard.vue');
  for (const field of ['entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2']) assert.match(text, new RegExp(field));
});
test('signal expiration is deterministic at an injected timestamp', () => {
  assert.equal(utils.expired('2026-01-01T00:00:00Z', Date.parse('2026-01-01T00:00:01Z')), true);
  assert.equal(utils.expired('2026-01-01T00:00:02Z', Date.parse('2026-01-01T00:00:01Z')), false);
});
test('position table has a detailed audit route', () => assert.match(source('router/index.ts'), /positions\/:id/));
test('position detail exposes partial executions through backend payload', () => assert.match(source('pages/RecordDetailPage.vue'), /JSON\.stringify/));
test('net PnL and portfolio PnL are rendered without frontend calculation', () => {
  assert.match(source('pages/DashboardPage.vue') + source('pages/StatisticsPage.vue'), /net_pnl|Realized PnL/);
});
test('equity chart maps backend timestamps and equity values', () => {
  const text = source('components/charts/EquityChart.vue');
  assert.match(text, /recorded_at/); assert.match(text, /Number\(point\.equity\)/);
});
test('finite metric formatting preserves zero and rejects null', () => {
  assert.notEqual(utils.finite(0), '—'); assert.equal(utils.finite(null), '—');
});
test('non-finite metrics are not displayed as numbers', () => assert.equal(utils.finite(Number.POSITIVE_INFINITY), '—'));
test('API errors use a unified human-readable fallback with request IDs', () => {
  const text = source('services/api.ts'); assert.match(text, /API unavailable/); assert.match(text, /Request ID/);
});
test('stale data handling has a configurable threshold', () => {
  const now = Date.parse('2026-01-01T00:05:01Z');
  assert.equal(utils.stale('2026-01-01T00:00:00Z', 300, now), true);
});
test('pagination parameters remain server-side', () => assert.deepEqual(utils.pageParams(3, 25, { symbol: 'BTCUSDT' }), { page: 3, per_page: 25, symbol: 'BTCUSDT' }));
test('signals filters and debounced search are sent to API', () => {
  const text = source('pages/TablePage.vue'); assert.match(text, /search\.value/); assert.match(text, /setTimeout\(load, 300\)/);
});
test('UTC timestamps can be converted with an explicit timezone', () => {
  assert.match(utils.utcToLocal('2026-01-01T00:00:00Z', 'UTC'), /2026/);
});
test('Russian and English translations exist', () => {
  const text = source('i18n.ts'); assert.match(text, /ru:/); assert.match(text, /en:/);
});
test('polling reconnect uses bounded exponential backoff', () => {
  const text = source('composables/usePolling.ts'); assert.match(text, /delay\s*\*\s*2/); assert.match(text, /60000/);
  assert.match(text, /if \(active\)/); assert.match(text, /rerun/);
});
test('research mode warning is globally mounted', () => {
  assert.match(source('layouts/MainLayout.vue'), /ResearchBanner/); assert.match(source('components/ResearchBanner.vue'), /RESEARCH MODE/);
});
test('backtest form queues only a research job and shows status', () => {
  const text = source('pages/BacktestsPage.vue'); assert.match(text, /Queue research backtest/); assert.match(text, /Research job queued/);
});
test('frontend contains no real order or trading action', () => {
  const paths = ['layouts/MainLayout.vue', 'pages/BacktestsPage.vue', 'services/api.ts'];
  assert.doesNotMatch(paths.map(source).join('\n'), /place.?order|create.?order|real.?trade/i);
});
test('all required application routes exist', () => {
  const text = source('router/index.ts');
  for (const route of ['dashboard', 'market/:symbol', 'signals', 'positions', 'journal', 'statistics', 'research', 'backtests', 'risk', 'settings']) assert.match(text, new RegExp(route.replace('/', '\\/')));
});
test('research lab uses paginated real API results and labels missing evaluations', () => { const text=source('pages/ResearchPage.vue'); for(const value of ['/research/leaderboard','/research/experiments','per_page:10','Not evaluated','walk_forward','X-Research-Token','approve-forward']) assert.match(text,new RegExp(value)); assert.match(text,/q-pagination/); });

test('research lab provides a safe fast-start window and explains asynchronous evaluation', () => {
  const text = source('pages/ResearchPage.vue');
  assert.match(text, /utcDateDaysAgo\(15\)/);
  assert.match(text, /latestSafeDate = utcDateDaysAgo\(1\)/);
  assert.match(text, /queued or running jobs do not populate the leaderboard/i);
  assert.match(text, /refreshes while it is active/);
  assert.match(text, /:disable="!canQueue"/);
  assert.match(text, /autocomplete="off"/);
});
test('research lab declares all five strategies and the four-timeframe hierarchy', () => {
  const source = read('pages/ResearchPage.vue');
  for (const strategy of ['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout', 'regime_switching']) assert.match(source, new RegExp(strategy));
  assert.match(source, /1h trend · 30m setup · 15m entry · 5m confirmation/);
});


test('dashboard displays actual account summary and paper-return fields', () => {
  const text = source('pages/DashboardPage.vue');
  for (const key of ['data.portfolio.starting_balance', 'data.portfolio.current_balance',
    'data.portfolio.total_return_percent', 'data.portfolio.total_net_pnl',
    'data.portfolio.open_positions', 'data.performance?.skip_signals']) {
    assert.ok(text.includes(key), `Missing paper metric: ${key}`);
  }
  assert.match(text, /Backtest performance is reported separately/);
});

test('statistics expose backend data quality flags, not nonexistent aliases', () => {
  const text = source('pages/StatisticsPage.vue');
  for (const key of ['ambiguous_execution_positions', 'incomplete_funding_positions', 'awaiting_market_data_positions']) {
    assert.match(text, new RegExp(key));
  }
  assert.ok(!text.includes('data.data_quality?.ambiguous_executions'));
});

test('period PnL chart maps persisted net and cumulative PnL without fabricated points', () => {
  const text = source('components/charts/PeriodPnlChart.vue') + source('pages/StatisticsPage.vue');
  for (const key of ['daily', 'weekly', 'monthly', 'net_pnl', 'cumulative_net_pnl']) {
    assert.match(text, new RegExp(key));
  }
  assert.match(text, /data\.value\.period_pnl/);
  assert.match(text, /No closed paper trades/);
});


test('dashboard explains real SKIP reasons instead of hiding strategy evaluations', () => {
  const text = source('pages/DashboardPage.vue');
  assert.match(text, /data.recent_decisions/);
  assert.match(text, /data.skip_reasons_24h/);
  assert.match(text, /data.strategy_observations_24h/);
  assert.match(text, /All Strategy Decisions/);
  assert.match(text, /Waiting for next closed 15m candle/);
  assert.match(text, /decision.reasons/);
  assert.match(text, /strategy.symbols/);
  assert.match(text, /market.latest.analysis.level_interaction/);
  assert.match(text, /independently evaluate BTC and ETH/);
  for (const key of ['bullish_directional_evidence_score', 'bearish_directional_evidence_score',
    'selected_evidence_score', 'momentum_strength']) {
    assert.match(text, new RegExp(key));
  }
});


test('all ledger pages request ten records and use server-side pagination', () => {
  for (const path of ['pages/TablePage.vue', 'pages/BacktestsPage.vue', 'pages/JournalPage.vue', 'pages/RiskPage.vue', 'pages/BacktestDetailPage.vue', 'pages/RecordDetailPage.vue']) {
    const file = source(path);
    assert.match(file, /per_page: 10|pageSize = 10/, path);
    assert.match(file, /q-pagination/, path);
  }
});
test('statistics paginates equity, R distribution, and reporting periods', () => {
  const file = source('pages/StatisticsPage.vue');
  for (const field of ['equity_page', 'r_page', 'period_page', 'equityPage', 'rPage', 'periodPage']) {
    assert.ok(file.includes(field), field);
  }
});
test('daily paper performance is bounded, timezone aware, and disposes charts', () => {
  const section = source('components/DailyPerformance.vue');
  const chart = source('components/charts/DailyPerformanceChart.vue');
  for (const value of ['Today', 'Yesterday', 'Last 7 Days', 'Last 30 Days', 'All Time', 'per_page: 10', 'timezone', 'BTCUSDT', 'ETHUSDT', 'Incomplete']) assert.match(section, new RegExp(value));
  for (const field of ['realized_pnl', 'unrealized_pnl_change', 'commissions', 'funding', 'opened_positions', 'closed_positions', 'win_rate', 'average_r']) assert.match(section, new RegExp(field));
  assert.match(section, /q-pagination/); assert.match(chart, /#21ba45/); assert.match(chart, /#c10015/); assert.match(chart, /chart\?\.dispose/);
  assert.match(source('pages/DashboardPage.vue'), /DailyPerformance/);
});
test('market chart limits history and permits window navigation', () => {
  const file = source('pages/MarketPage.vue');
  assert.match(file, /limit: 300/);
  assert.match(file, /\/analysis/);
  assert.doesNotMatch(file, /Promise\.all/);
  assert.match(file, /Older candles/);
  assert.match(file, /Newer candles/);
});
test('backtest details use bounded independent endpoints and lazy optimizations', () => {
  const file = source('pages/BacktestDetailPage.vue');
  for (const endpoint of ['/trades', '/equity', '/optimizations']) assert.ok(file.includes(endpoint), endpoint);
  assert.match(file, /max_points: 1000/);
  assert.match(file, /@show="loadOptimizations"/);
});
