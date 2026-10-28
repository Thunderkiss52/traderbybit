import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = p => readFileSync(new URL('../src/' + p, import.meta.url), 'utf8');

test('quote integrity page is routed and listed in navigation', () => {
  const router = read('router/index.ts');
  assert.match(router, /quote-integrity/);
  assert.match(router, /QuoteIntegrityPage\.vue/);

  const layout = read('layouts/MainLayout.vue');
  assert.match(layout, /\/quote-integrity/);
  assert.match(layout, /Quote Integrity/);
});

test('status banner enforces mandatory disclaimers and cross-source unavailable panel', () => {
  const banner = read('components/qim/QimStatusBanner.vue');
  assert.match(banner, /Internal Bybit consistency monitoring does not establish independent market-price correctness/);
  assert.match(banner, /LEVEL 2 STATUS: CROSS_SOURCE_UNAVAILABLE/);
  assert.match(banner, /FEATURE_DISABLED/);
});

test('evidence import enforces SHA-256 disclaimer and no auto-promotion invariant', () => {
  const imp = read('components/qim/QimEvidenceImport.vue');
  assert.match(imp, /SHA-256 proves bytes unchanged after capture\. Not a verification of quote authenticity/);
  assert.match(imp, /A screenshot shows what was displayed in an interface, not official market data/);
  assert.match(imp, /INSUFFICIENT_EVIDENCE/);
});

test('quote integrity main page orchestrates all L1 components', () => {
  const page = read('pages/QuoteIntegrityPage.vue');
  assert.match(page, /QimStatusBanner/);
  assert.match(page, /QimSummaryCards/);
  assert.match(page, /QimComparisonsTable/);
  assert.match(page, /QimIncidentsTable/);
  assert.match(page, /QimAlertsPanel/);
  assert.match(page, /QimEvidenceImport/);
  assert.match(page, /QimIncidentDetail/);
});

test('quote integrity api service exports all required endpoints and types', () => {
  const api = read('services/quoteIntegrityApi.ts');
  assert.match(api, /fetchQimStatus/);
  assert.match(api, /fetchQimSummary/);
  assert.match(api, /fetchQimComparisons/);
  assert.match(api, /fetchQimIncidents/);
  assert.match(api, /fetchQimIncidentDetail/);
  assert.match(api, /transitionQimIncident/);
  assert.match(api, /fetchQimAlerts/);
  assert.match(api, /uploadQimEvidence/);
});

test('f22 quote integrity handles error propagation, visible banner, and stale state', () => {
  const page = read('pages/QuoteIntegrityPage.vue');
  assert.match(page, /errorMessage/);
  assert.match(page, /isStale/);
  assert.match(page, /Quote Integrity Monitor Notice/);
  assert.match(page, /Preserving last-known good state\. Data shown below may be stale\./);
  assert.match(page, /FEATURE_DISABLED/);
});
