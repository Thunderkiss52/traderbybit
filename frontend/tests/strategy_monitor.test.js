import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

test('strategy monitor page is routed and listed in drawer navigation', () => {
  const routerSrc = readFileSync(resolve('src/router/index.ts'), 'utf-8');
  assert.match(routerSrc, /path:\s*['"]monitor['"]/);
  assert.match(routerSrc, /StrategyMonitorPage\.vue/);

  const layoutSrc = readFileSync(resolve('src/layouts/MainLayout.vue'), 'utf-8');
  assert.match(layoutSrc, /\['\/monitor',\s*'track_changes',\s*'Signal Monitor'\]/);
});

test('strategy monitor enforces research mode and virtual paper disclaimer', () => {
  const pageSrc = readFileSync(resolve('src/pages/StrategyMonitorPage.vue'), 'utf-8');
  assert.match(pageSrc, /Исследовательский режим/);
  assert.match(pageSrc, /Торговые ордера на бирже отключены/);
  assert.match(pageSrc, /PAPER RESEARCH ONLY/);
});

test('strategy monitor contains decision explainability, sizing, and timeline panels', () => {
  const pageSrc = readFileSync(resolve('src/pages/StrategyMonitorPage.vue'), 'utf-8');
  assert.match(pageSrc, /Decision Explainability & Rule Evidence/);
  assert.match(pageSrc, /Risk Guardrails & Position Sizing/);
  assert.match(pageSrc, /Virtual PAPER Execution & Financial Result/);
  assert.match(pageSrc, /Chronological Audit Timeline/);
  assert.match(pageSrc, /q-timeline/);
});


test('strategy monitor shows account-scoped decisions and positions without collapsing financial history', () => {
  const src = readFileSync(resolve('src/pages/StrategyMonitorPage.vue'), 'utf-8');
  assert.match(src, /risk_decision_count > 1/);
  assert.match(src, /position_count > 1/);
  assert.match(src, /v-for="decision in selectedItem\.risk_decisions"/);
  assert.match(src, /selectedItem\.positions\?\.length/);
  assert.match(src, /position\.paper_account_id/);
});

test('strategy monitor protects against stale filter and timeline responses', () => {
  const src = readFileSync(resolve('src/pages/StrategyMonitorPage.vue'), 'utf-8');
  assert.match(src, /currentController !== controller \|\| controller\.signal\.aborted/);
  assert.match(src, /requestId === timelineRequestId/);
  assert.match(src, /selectItem\(null\);/);
});
