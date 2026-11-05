import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

function source(path) {
  return readFileSync(resolve(process.cwd(), 'src', path), 'utf8');
}

test('dashboard provides exchange-qualified account selection and aggregate vs account distinction', () => {
  const text = source('pages/DashboardPage.vue');
  assert.match(text, /selectedAccount/);
  assert.match(text, /accountOptions/);
  assert.match(text, /AGGREGATE/);
  assert.match(text, /data\.portfolio\?\.is_aggregate/);
  assert.match(text, /reqVersion/);
  assert.match(text, /dashboardController\?\.abort\(\)/);
  assert.match(text, /26 Independent PAPER Accounts/);
  assert.match(text, /selectedExchange\.value\s*===\s*'all'/);
  assert.match(text, /crossExchangeSummary/);
  assert.match(text, /watch\(\(\)\s*=>\s*settings\.exchange/);
});

test('risk page provides exchange-qualified account selection and prevents stale responses', () => {
  const text = source('pages/RiskPage.vue');
  assert.match(text, /selectedAccount/);
  assert.match(text, /accountOptions/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
  assert.match(text, /controller\?\.abort\(\)/);
  assert.match(text, /current === version/);
  assert.match(text, /watch\(\(\)\s*=>\s*settings\.exchange/);
});

test('statistics page provides exchange-qualified account selection and prevents stale responses', () => {
  const text = source('pages/StatisticsPage.vue');
  assert.match(text, /selectedAccount/);
  assert.match(text, /accountOptions/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
  assert.match(text, /statsController\?\.abort\(\)/);
  assert.match(text, /current === statsVersion/);
  assert.match(text, /watch\(\(\)\s*=>\s*settings\.exchange/);
});

test('journal page provides account selection synced with settings store', () => {
  const text = source('pages/JournalPage.vue');
  assert.match(text, /useSettings/);
  assert.match(text, /settings\.account\s*=\s*val/);
  assert.match(text, /account\.value\s*=\s*val/);
  assert.match(text, /watch\(\(\)\s*=>\s*settings\.exchange/);
  assert.match(text, /reloadAccounts/);
});

test('table page provides account filter for signals and positions', () => {
  const text = source('pages/TablePage.vue');
  assert.match(text, /isSignals \|\| isPositions/);
  assert.match(text, /selectedAccount/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
  assert.match(text, /watch\(\(\)\s*=>\s*settings\.exchange/);
  assert.match(text, /reloadAccounts/);
});

test('settings store clears account on exchange toggle to prevent cross-exchange contamination', () => {
  const text = source('stores/settings.ts');
  assert.match(text, /watch\(exchange,\s*\(\)\s*=>\s*\{[\s\S]*?account\.value\s*=\s*'';/);
});

test('dashboard aggregate mode and multi-page exchange reactivity are verified', () => {
  const dashboard = source('pages/DashboardPage.vue');
  const risk = source('pages/RiskPage.vue');
  const stats = source('pages/StatisticsPage.vue');
  const journal = source('pages/JournalPage.vue');
  const table = source('pages/TablePage.vue');

  // DEF-05: aggregate includes all accounts when selectedExchange is 'all'
  assert.match(dashboard, /if\s*\(selectedExchange\.value\s*===\s*'all'\)\s*\{\s*return accounts;\s*\}/);

  // DEF-06: each page reloads on settings.exchange change even if account was empty
  for (const [name, content] of Object.entries({ risk, stats, journal, table })) {
    assert.match(content, /watch\(\(\)\s*=>\s*settings\.exchange/, `${name} must watch settings.exchange`);
  }
});
