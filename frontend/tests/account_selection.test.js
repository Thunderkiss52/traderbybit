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
});

test('risk page provides exchange-qualified account selection and prevents stale responses', () => {
  const text = source('pages/RiskPage.vue');
  assert.match(text, /selectedAccount/);
  assert.match(text, /accountOptions/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
  assert.match(text, /controller\?\.abort\(\)/);
  assert.match(text, /current === version/);
});

test('statistics page provides exchange-qualified account selection and prevents stale responses', () => {
  const text = source('pages/StatisticsPage.vue');
  assert.match(text, /selectedAccount/);
  assert.match(text, /accountOptions/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
  assert.match(text, /statsController\?\.abort\(\)/);
  assert.match(text, /current === statsVersion/);
});

test('journal page provides account selection synced with settings store', () => {
  const text = source('pages/JournalPage.vue');
  assert.match(text, /useSettings/);
  assert.match(text, /settings\.account\s*=\s*val/);
  assert.match(text, /account\.value\s*=\s*val/);
});

test('table page provides account filter for signals and positions', () => {
  const text = source('pages/TablePage.vue');
  assert.match(text, /isSignals \|\| isPositions/);
  assert.match(text, /selectedAccount/);
  assert.match(text, /params\.account\s*=\s*selectedAccount\.value/);
});

test('settings store clears account on exchange toggle to prevent cross-exchange contamination', () => {
  const text = source('stores/settings.ts');
  assert.match(text, /watch\(exchange,\s*\(\)\s*=>\s*\{\s*account\.value\s*=\s*'';\s*\}\)/);
});
