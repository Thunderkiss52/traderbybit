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
