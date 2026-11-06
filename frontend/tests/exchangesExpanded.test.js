import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');

test('types/index.ts exports MarketType and CapabilityStatus interfaces', () => {
  const types = read('types/index.ts');
  assert.match(types, /MarketType/);
  assert.match(types, /'spot'/);
  assert.match(types, /'futures'/);
  assert.match(types, /'perpetual'/);
  assert.match(types, /'options'/);

  assert.match(types, /CapabilityStatus/);
  assert.match(types, /'SUPPORTED'/);
  assert.match(types, /'PARTIALLY_SUPPORTED'/);
  assert.match(types, /'UNSUPPORTED'/);
  assert.match(types, /'TEMPORARILY_UNAVAILABLE'/);
  assert.match(types, /'NOT_ASSESSED'/);
});

test('api service exports getExchangeCapabilities and getExchangeInstruments', () => {
  const api = read('services/api.ts');
  assert.match(api, /export\s+async\s+function\s+getExchangeCapabilities\(/);
  assert.match(api, /api\.get\(`\/exchanges\/\$\{exchangeId\}\/capabilities`\)/);
  assert.match(api, /export\s+async\s+function\s+getExchangeInstruments\(/);
  assert.match(api, /api\.get\(`\/exchanges\/\$\{exchangeId\}\/instruments`/);
});

test('ExchangesPage renders badges for supported market types across target exchanges', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /getMarketTypes/);
  assert.match(page, /getMarketTypeColor/);
  assert.match(page, /v-for="mkt in getMarketTypes\(exchange\)"/);
  assert.match(page, /'SPOT'/);
  assert.match(page, /'PERPETUAL'/);
  assert.match(page, /'FUTURES'/);
  assert.match(page, /'OPTIONS'/);
});

test('ExchangesPage provides Market Capabilities matrix inspector with explicit status values', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /capabilitiesDialogOpen/);
  assert.match(page, /openCapabilitiesInspector/);
  assert.match(page, /getExchangeCapabilities/);
  assert.match(page, /Market Capabilities Matrix/);
  assert.match(page, /SUPPORTED/);
  assert.match(page, /PARTIALLY_SUPPORTED/);
  assert.match(page, /UNSUPPORTED/);
  assert.match(page, /BLOCKED_BY_DATA/);
  assert.match(page, /TEMPORARILY_UNAVAILABLE/);
  assert.match(page, /NOT_ASSESSED/);
  assert.match(page, /getCapabilityBadgeColor/);
});

test('ExchangesPage provides Instrument Discovery dialog with market type selector', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /instrumentDialogOpen/);
  assert.match(page, /openInstrumentDiscovery/);
  assert.match(page, /getExchangeInstruments/);
  assert.match(page, /instrumentSearch/);
  assert.match(page, /filteredInstruments/);
  assert.match(page, /Instrument Discovery & Market Specification/);
  assert.match(page, /DISCOVERY ONLY — NO REAL ORDERS/);
});

test('ExchangesPage strictly enforces research and virtual paper disclaimers without live trading status', () => {
  const page = read('pages/ExchangesPage.vue');
  assert.match(page, /VIRTUAL PAPER TRADING ONLY/);
  assert.match(page, /Zero live exchange orders, withdrawals, or trade credentials exist/);
  assert.doesNotMatch(page, /label="LIVE"/);
  assert.doesNotMatch(page, /label="READY FOR LIVE"/);
});
