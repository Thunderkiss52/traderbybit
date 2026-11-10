import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

function source(path) {
  return readFileSync(resolve(process.cwd(), 'src', path), 'utf8');
}

test('settings store provides reactive instrument selection and canonical symbol resolution', () => {
  const text = source('stores/settings.ts');
  assert.match(text, /instrument\s*=\s*ref<string>\(saved\.instrument\s*\|\|\s*'all'\)/);
  assert.match(text, /availableInstruments/);
  assert.match(text, /selectedInstrumentName/);
  assert.match(text, /resolvedSymbol/);
  assert.match(text, /BTCUSDT/);
  assert.match(text, /ETHUSDT/);
  assert.match(text, /instrument:\s*instrument\.value/);
});

test('api service request interceptor sets X-Exchange, X-Instrument, and X-Symbol headers', () => {
  const text = source('services/api.ts');
  assert.match(text, /config\.headers\['X-Exchange'\]\s*=\s*exchange/);
  assert.match(text, /config\.headers\['X-Instrument'\]\s*=\s*instrument/);
  assert.match(text, /config\.headers\['X-Symbol'\]\s*=\s*'BTCUSDT'/);
  assert.match(text, /config\.headers\['X-Symbol'\]\s*=\s*'ETHUSDT'/);
});

test('MainLayout provides top-bar global instrument selector alongside exchange selector', () => {
  const text = source('layouts/MainLayout.vue');
  assert.match(text, /data-testid="global-exchange-dropdown"/);
  assert.match(text, /data-testid="global-instrument-dropdown"/);
  assert.match(text, /data-testid="global-instrument-badge"/);
  assert.match(text, /s\.availableInstruments/);
  assert.match(text, /s\.selectedInstrumentName/);
  assert.match(text, /s\.resolvedSymbol/);
});

test('ProfitabilityResearchPage reacts to exchange and instrument changes with scope isolation', () => {
  const text = source('pages/ProfitabilityResearchPage.vue');
  assert.match(text, /useSettings/);
  assert.match(text, /watch\(\(\)\s*=>\s*s\.exchange/);
  assert.match(text, /watch\(\(\)\s*=>\s*s\.instrument/);
  assert.match(text, /params\.exchange\s*=\s*s\.exchange/);
  assert.match(text, /params\.instrument\s*=\s*s\.instrument/);
  assert.match(text, /symbolBreakdown/);
  assert.match(text, /exchangeBreakdown/);
  assert.match(text, /Атрибуция по инструментам \(BTC vs ETH\)/);
  assert.match(text, /Атрибуция по биржам \(Exchange Isolation\)/);
});

test('operational pages watch settings.instrument and pass symbol filters to backend', () => {
  const dash = source('pages/DashboardPage.vue');
  assert.match(dash, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(dash, /params\.instrument\s*=\s*settings\.instrument/);

  const monitor = source('pages/StrategyMonitorPage.vue');
  assert.match(monitor, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(monitor, /selectedSymbol\.value\s*=\s*settings\.resolvedSymbol/);

  const risk = source('pages/RiskPage.vue');
  assert.match(risk, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(risk, /params\.instrument\s*=\s*settings\.instrument/);

  const journal = source('pages/JournalPage.vue');
  assert.match(journal, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(journal, /exchange:\s*settings\.exchange/);

  const table = source('pages/TablePage.vue');
  assert.match(table, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(table, /params\.instrument\s*=\s*settings\.instrument/);

  const stats = source('pages/StatisticsPage.vue');
  assert.match(stats, /watch\(\(\)\s*=>\s*settings\.instrument/);
  assert.match(stats, /params\.instrument\s*=\s*settings\.instrument/);
});
