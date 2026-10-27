import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

function source(path) {
  return readFileSync(resolve(process.cwd(), 'src', path), 'utf8');
}

test('candidate lab displays validation lifecycle, evidence metrics and rejection reasons', () => {
  const text = source('pages/ResearchLearningPage.vue');

  // Lifecycle column and badges
  assert.match(text, /label:\s*'Validation Lifecycle'/);
  assert.match(text, /body-cell-lifecycle/);
  assert.match(text, /VALIDATION_IN_PROGRESS/);
  assert.match(text, /MISSING_EVIDENCE/);

  // Evidence metrics and rejection reasons
  assert.match(text, /label:\s*'Validation Evidence & Rejection Reasons'/);
  assert.match(text, /body-cell-metrics_reasons/);
  assert.match(text, /props\.row\.validation_gates\?\.failure_reason/);
  assert.match(text, /oos_samples/);
  assert.match(text, /expectancy_r/);
  assert.match(text, /max_drawdown_percent/);

  // Validate action and API linkage
  assert.match(text, /validateCandidate/);
  assert.match(text, /\/research\/learning\/candidates\/\$\{id\}\/validate/);
  assert.match(text, /validatingId/);
});
