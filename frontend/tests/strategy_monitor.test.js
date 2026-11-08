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
