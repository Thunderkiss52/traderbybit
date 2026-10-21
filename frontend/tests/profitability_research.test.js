import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

test('router exposes /research/profitability route mapping to ProfitabilityResearchPage', () => {
  const routerPath = resolve(process.cwd(), 'src/router/index.ts');
  const routerSrc = readFileSync(routerPath, 'utf8');

  assert.match(
    routerSrc,
    /path:\s*'research\/profitability',\s*component:\s*\(\)\s*=>\s*import\(['"]\.\.\/pages\/ProfitabilityResearchPage\.vue['"]\)/,
    'Router must map /research/profitability to ProfitabilityResearchPage.vue'
  );
});

test('main layout sidebar contains link to profitability page', () => {
  const layoutPath = resolve(process.cwd(), 'src/layouts/MainLayout.vue');
  const layoutSrc = readFileSync(layoutPath, 'utf8');

  assert.match(
    layoutSrc,
    /\[\s*['"]\/research\/profitability['"]\s*,\s*['"]payments['"]\s*,\s*['"]Доходность['"]\s*\]/,
    'Main layout must have link for /research/profitability with Russian label Доходность'
  );
});

test('profitability research page enforces disclaimers and scope separation', () => {
  const pagePath = resolve(process.cwd(), 'src/pages/ProfitabilityResearchPage.vue');
  const pageSrc = readFileSync(pagePath, 'utf8');

  // Must contain Bybit paper trading disclaimer
  assert.match(pageSrc, /Bybit Paper Trading/, 'Page must include Bybit paper trading disclaimer banner');

  // Must contain FULL_LIFECYCLE scope label
  assert.match(pageSrc, /FULL_LIFECYCLE/, 'Page must explicitly display FULL_LIFECYCLE scope');

  // Must contain gated module placeholders P-1..P-6
  assert.match(pageSrc, /PROFIT-1 Roadmap/, 'Page must list gated modules roadmap');
  assert.match(pageSrc, /BLOCKED_PROHIBITED/, 'Page must display BLOCKED_PROHIBITED badge for P-3');
});

test('profitability research page includes P-1 slippage attribution and hypothetical counterfactual', () => {
  const pagePath = resolve(process.cwd(), 'src/pages/ProfitabilityResearchPage.vue');
  const pageSrc = readFileSync(pagePath, 'utf8');

  // Must contain P-1 Slippage Attribution tab
  assert.match(pageSrc, /Атрибуция проскальзывания \(P-1\)/, 'Page must have P-1 Slippage Attribution tab');

  // Must contain HYPOTHETICAL counterfactual badge
  assert.match(pageSrc, /HYPOTHETICAL/, 'Page must display HYPOTHETICAL badge on counterfactual card');

  // Must enforce prohibition of double deduction
  assert.match(pageSrc, /Двойное списание проскальзывания строго запрещено/, 'Page must enforce double deduction prohibition disclaimer');

  // Must have position-level trace modal
  assert.match(pageSrc, /Детальная трассировка проскальзывания позиции/, 'Page must have position-level trace modal');
});
