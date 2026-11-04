import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = p => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');

test('exchanges page is routed and listed in drawer navigation', () => {
  const router = read('router/index.ts');
  assert.match(router, /exchanges/);
  assert.match(router, /ExchangesPage\.vue/);

  const layout = read('layouts/MainLayout.vue');
  assert.match(layout, /\/exchanges/);
  assert.match(layout, /currency_exchange/);
  assert.match(layout, /Exchanges/);
});

test('types/index.ts exports complete universal exchange onboarding interfaces', () => {
  const types = read('types/index.ts');
  assert.match(types, /ExchangeLifecycleStatus/);
  assert.match(types, /'OFFLINE_DATA_SOURCE'/);
  assert.match(types, /'CONNECTED_PUBLIC_DATA'/);
  assert.match(types, /'RESEARCH_ENABLED'/);
  assert.match(types, /'PAPER_ELIGIBLE'/);
  assert.match(types, /'ACTIVE_PAPER'/);
  assert.match(types, /ExchangeCapabilities/);
  assert.match(types, /ExchangeCostModel/);
  assert.match(types, /ExchangeEligibility/);
  assert.match(types, /Exchange/);
  assert.match(types, /ExchangeCoverageReport/);
  assert.match(types, /HistoricalCandleUploadResult/);
});

test('api service exports all required exchange onboarding endpoints', () => {
  const api = read('services/api.ts');
  assert.match(api, /fetchExchanges/);
  assert.match(api, /registerExchange/);
  assert.match(api, /fetchExchangeDetail/);
  assert.match(api, /fetchExchangeCoverage/);
  assert.match(api, /uploadExchangeCandles/);
  assert.match(api, /updateExchangeLifecycle/);
  assert.match(api, /approveExchangePaper/);
  assert.match(api, /syncExchangeTopology/);
});

test('ExchangesPage enforces mandatory research mode and virtual paper disclaimer', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /VIRTUAL PAPER TRADING ONLY/);
  assert.match(page, /Zero live exchange orders, withdrawals, or trade credentials exist/);
  assert.match(page, /provisions isolated PAPER accounts in <code>BLOCKED<\/code> status/);
  assert.match(page, /global 5% portfolio risk budget/);
});

test('ExchangesPage supports capability-based lifecycle states and diagnostics', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /OFFLINE_DATA_SOURCE/);
  assert.match(page, /CONNECTED_PUBLIC_DATA/);
  assert.match(page, /RESEARCH_ENABLED/);
  assert.match(page, /PAPER_ELIGIBLE/);
  assert.match(page, /ACTIVE_PAPER/);
  assert.match(page, /Declared Capabilities/);
  assert.match(page, /Technical PAPER Eligibility Audit/);
  assert.match(page, /Blockers preventing PAPER activation/);
});

test('ExchangesPage provides CSV upload, owner approval, and topology sync dialogs', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /registerDialogOpen/);
  assert.match(page, /uploadDialogOpen/);
  assert.match(page, /approveDialogOpen/);
  assert.match(page, /syncDialogOpen/);
  assert.match(page, /Import Dataset/);
  assert.match(page, /Approve Activation/);
  assert.match(page, /Apply Provisioning/);
  assert.match(page, /Checksum \(SHA-256\)/);
});
