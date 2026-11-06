import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');

test('types/index.ts exports complete TradingMethod and parameter interfaces', () => {
  const types = read('types/index.ts');
  assert.match(types, /export\s+type\s+MarketType\s*=\s*'spot'\s*\|\s*'futures'\s*\|\s*'perpetual'\s*\|\s*'options'/);
  assert.match(types, /export\s+type\s+CapabilityStatus\s*=/);
  assert.match(types, /'SUPPORTED'/);
  assert.match(types, /'PARTIALLY_SUPPORTED'/);
  assert.match(types, /'UNSUPPORTED'/);
  assert.match(types, /'TEMPORARILY_UNAVAILABLE'/);
  assert.match(types, /'NOT_ASSESSED'/);

  assert.match(types, /export\s+interface\s+TradingMethodParameter/);
  assert.match(types, /name:\s*string/);
  assert.match(types, /type:\s*string/);
  assert.match(types, /default:\s*any/);

  assert.match(types, /export\s+interface\s+TradingMethod\s*\{/);
  assert.match(types, /id:\s*string;/);
  assert.match(types, /name:\s*string;/);
  assert.match(types, /description:\s*string;/);
  assert.match(types, /category:\s*string;/);
  assert.match(types, /category_display:\s*string;/);
  assert.match(types, /type:\s*string;/);
  assert.match(types, /type_display:\s*string;/);
  assert.match(types, /parameters:\s*TradingMethodParameter\[\];/);
  assert.match(types, /required_input_data:\s*string\[\];/);
  assert.match(types, /required_indicators:\s*string\[\];/);
  assert.match(types, /supported_market_types:\s*MarketType\[\];/);
  assert.match(types, /status:\s*string;/);
  assert.match(types, /status_display:\s*string;/);
  assert.match(types, /blocker_reason\?:\s*string\s*\|\s*null;/);
  assert.match(types, /is_executable:\s*boolean;/);
  assert.match(types, /research_eligible:\s*boolean;/);
  assert.match(types, /shadow_eligible:\s*boolean;/);
  assert.match(types, /paper_eligible:\s*boolean;/);
});

test('api service exports strategy catalog and exchange assignment endpoints', () => {
  const api = read('services/api.ts');
  assert.match(api, /export\s+async\s+function\s+getStrategyMethods\(/);
  assert.match(api, /api\.get\('\/strategy-methods'/);
  assert.match(api, /export\s+async\s+function\s+getStrategyMethod\(id:\s*string\)/);
  assert.match(api, /api\.get\(`\/strategy-methods\/\$\{id\}`\)/);
  assert.match(api, /export\s+async\s+function\s+assignStrategyExchange\(/);
  assert.match(api, /api\.post\(`\/strategies\/\$\{strategyId\}\/assign-exchange`/);
});

test('StrategiesPage provides 50-method catalog tab with Family A-F and type filters', () => {
  const page = read('pages/StrategiesPage.vue');
  assert.match(page, /Strategy Method Library \(50 Catalog\)/);
  assert.match(page, /Quantitative Trading Method Library/);
  assert.match(page, /Family A: Trading Horizons and Direction/);
  assert.match(page, /Family B: Price and Technical Analysis/);
  assert.match(page, /Family C: Volatility and Volume/);
  assert.match(page, /Family D: Automation and Relative Value/);
  assert.match(page, /Family E: Events and Alternative Data/);
  assert.match(page, /Family F: Trade Management and Risk/);

  assert.match(page, /Trading Style/);
  assert.match(page, /Signal Generator/);
  assert.match(page, /Confirmation Filter/);
  assert.match(page, /Market Regime Filter/);
  assert.match(page, /Position Sizing Model/);
  assert.match(page, /Portfolio Allocation/);
  assert.match(page, /Execution Method/);
  assert.match(page, /Position Management/);
  assert.match(page, /Alternative Data/);

  assert.match(page, /searchFilter/);
  assert.match(page, /filteredCatalog/);
  assert.match(page, /loadCatalog/);
});

test('StrategiesPage displays method cards with honest status badges and blocker callouts', () => {
  const page = read('pages/StrategiesPage.vue');
  assert.match(page, /getMethodStatusBadgeColor/);
  assert.match(page, /RESEARCH_READY/);
  assert.match(page, /PAPER_READY/);
  assert.match(page, /BLOCKED_BY_DATA/);
  assert.match(page, /CATALOGUED/);
  assert.match(page, /method\.blocker_reason/);
  assert.match(page, /Blocked by data:/);
  assert.match(page, /openMethodDetails\(method\)/);
  assert.match(page, /loadMethodIntoBuilder\(method\)/);
});

test('StrategiesPage provides parameter schemas and diagnostics details dialog', () => {
  const page = read('pages/StrategiesPage.vue');
  assert.match(page, /v-model="detailDialogOpen"/);
  assert.match(page, /Parameter Schemas/);
  assert.match(page, /Required Input Feeds/);
  assert.match(page, /Required Indicators/);
  assert.match(page, /Supported Market Venues/);
  assert.match(page, /Data Dependency Diagnostic \/ Blocker/);
  assert.match(page, /Configure in Visual Builder/);
});

test('VisualDefinitionEditor integrates method selection, parameter configuration, and explicit exchange assignment', () => {
  const editor = read('components/strategyBuilder/VisualDefinitionEditor.vue');
  assert.match(editor, /Explicit Exchange Assignment \(10 Target Venues\)/);
  assert.match(editor, /Virtual PAPER Mode Only/);
  assert.match(editor, /Method Specification & Parameter Configuration/);
  assert.match(editor, /Select Method from 50 Catalog/);
  assert.match(editor, /availableMethods/);
  assert.match(editor, /selectedMethod/);
  assert.match(editor, /configuredParams/);
  assert.match(editor, /updateMethodParam/);
  assert.match(editor, /handleAssignExchange/);
  assert.match(editor, /assignStrategyExchange/);
  assert.match(editor, /targetExchanges/);
  assert.match(editor, /bybit/);
  assert.match(editor, /binance/);
  assert.match(editor, /coinbase/);
  assert.match(editor, /okx/);
  assert.match(editor, /bitget/);
  assert.match(editor, /gate/);
  assert.match(editor, /kucoin/);
  assert.match(editor, /kraken/);
  assert.match(editor, /htx/);
  assert.match(editor, /upbit/);
});
