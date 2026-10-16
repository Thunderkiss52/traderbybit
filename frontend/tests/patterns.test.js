import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = p => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');
test('pattern page is routed and listed', () => {
  assert.match(read('router/index.ts'), /research\/patterns/);
  assert.match(read('layouts/MainLayout.vue'), /research\/patterns/);
});
test('paginated pattern research uses bounded server requests', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /per_page: 10/);
  assert.match(page, /q-pagination/);
  assert.match(page, /AbortController/);
  assert.match(page, /onBeforeUnmount/);
});
test('pattern UI distinguishes observations from trading signals', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /not BUY\/SELL signals/);
  assert.match(page, /No automatically generated virtual entries/);
  assert.match(page, /Insufficient data/);
});
test('pattern discovery starts with a safe complete window and explains asynchronous empty states', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /utcDateDaysAgo\(31\)/);
  assert.match(page, /latestSafeDate = utcDateDaysAgo\(1\)/);
  assert.match(page, /rangeDays\.value <= 90/);
  assert.match(page, /dedicated research worker/);
  assert.match(page, /refresh automatically about every 10 seconds/);
  assert.match(page, /zero means more persisted signal history is needed/);
  assert.match(page, /'X-Research-Token': token\.value/);
});
test('pattern page shows automatic discovery readiness instead of an unexplained empty table', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /automatic discovery readiness/);
  assert.match(page, /eligible_decisions/);
  assert.match(page, /remaining_decisions/);
  assert.match(page, /protects the results from incomplete labels and small-sample claims/);
});
test('pattern page preserves top-level pagination metadata instead of unwrapping it', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /api\.get\('\/research\/patterns'/);
  assert.match(page, /patterns\.value = rowsResponse\.data/);
  assert.match(page, /runs\.value = runsResponse\.data/);
});
test('pattern page exposes all strategy BUY SELL and SKIP context by symbol', () => {
  const page = read('pages/PatternsPage.vue');
  assert.match(page, /dashboard\.strategy_observations_24h/);
  assert.match(page, /All Strategy Context/);
  assert.match(page, /strategy\.symbols/);
  for (const decision of ['BUY', 'SELL', 'SKIP']) assert.match(page, new RegExp(decision));
});
