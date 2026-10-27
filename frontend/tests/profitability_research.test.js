import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ts from 'typescript';

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

    // Must surface the verification status of the counterfactual instead of
    // presenting an unverified figure as a result
    assert.match(pageSrc, /from '\.\.\/researchSemantics'/, 'Page must delegate counterfactual semantics to researchSemantics.ts');
    assert.match(pageSrc, /counterfactual\.status/, 'Page must display the counterfactual verification status');
    assert.match(pageSrc, /raw_counterfactual_status/, 'Page must display the raw full-fill counterfactual status');
    assert.doesNotMatch(
      pageSrc,
      /realized_scope_net_pnl\s*\?\?\s*[\w?.]*baseline_actual_net_pnl/,
      'Realized-scope PnL must never silently fall back to the all-positions baseline'
    );
});

const semanticsCode = ts.transpileModule(
  readFileSync(resolve(process.cwd(), 'src/researchSemantics.ts'), 'utf8'),
  { compilerOptions: { module: ts.ModuleKind.ESNext } }
).outputText;
const semantics = await import(`data:text/javascript;base64,${Buffer.from(semanticsCode).toString('base64')}`);

test('P-1 counterfactual semantics never render unavailable values as zero', () => {
  const unavailable = semantics.counterfactualView({
    status: 'PARTIAL_CLOSE_UNRESOLVED',
    realized_pnl_scope_status: 'PARTIAL_CLOSE_UNRESOLVED',
    realized_pnl_scope_reason: 'Partial close realized-scope PnL cannot be derived',
    realized_scope_net_pnl: null,
    realized_allocated_slippage_drag: '0.013',
    open_unallocated_slippage_drag: null,
    hypothetical_net_pnl_zero_slippage: null,
    baseline_actual_net_pnl: '-260.36',
  });

  assert.equal(unavailable.verified, false);
  assert.equal(unavailable.realizedScopePositive, null);
  assert.equal(unavailable.realizedScopeFormatted, '—');
  assert.equal(unavailable.hypotheticalFormatted, '—');
  assert.equal(unavailable.openUnallocatedDragFormatted, '—');
  assert.equal(unavailable.attributableDragFormatted, '0.013');
  assert.equal(unavailable.showUnavailable, true);
  assert.match(unavailable.unavailableReason, /Partial close/);
  assert.equal(unavailable.baselineAllPositionsFormatted, '-260.36');
});

test('P-1 counterfactual semantics preserve a legitimate zero', () => {
  const computed = semantics.counterfactualView({
    status: 'COMPUTED',
    realized_pnl_scope_status: 'ESTABLISHED',
    realized_scope_net_pnl: '0',
    realized_allocated_slippage_drag: '0',
    open_unallocated_slippage_drag: '0',
    hypothetical_net_pnl_zero_slippage: '0',
  });

  assert.equal(computed.verified, true);
  assert.equal(computed.realizedScopePositive, true);
  assert.equal(computed.hypotheticalPositive, true);
  assert.equal(computed.realizedScopeFormatted, '0');
  assert.equal(computed.hypotheticalFormatted, '0');
  assert.equal(computed.attributableDragFormatted, '0');
  assert.equal(computed.showUnavailable, false);
});

test('P-1 counterfactual semantics do not trust the legacy reconciled status', () => {
  const legacy = semantics.counterfactualView({
    status: 'COMPUTED_RECONCILED',
    realized_scope_net_pnl: '10',
    hypothetical_net_pnl_zero_slippage: '12',
  });

  assert.equal(legacy.verified, false);
  assert.equal(legacy.hypotheticalPositive, null);
  assert.equal(legacy.hypotheticalFormatted, '—');
  assert.equal(legacy.showUnavailable, true);
});


test('profitability payload validation rejects missing HTTP 200 data and preserves measured zero', async () => {
  const source = readFileSync(resolve(process.cwd(), 'src/profitabilityContract.ts'), 'utf8');
  const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
  const { requirePayload } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`);
  for (const invalid of [undefined, null, [], {}, { catalog: null }]) {
    assert.throws(() => requirePayload(invalid, ['catalog']), /UNAVAILABLE/);
  }
  assert.equal(requirePayload({ net_pnl: '0.00' }, ['net_pnl']).net_pnl, '0.00');
});
