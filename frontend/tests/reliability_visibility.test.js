import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../src/pages/ResearchHealthPage.vue', import.meta.url), 'utf8');

test('unavailable queues are not misreported as empty', () => {
  assert.match(page, /queues\.status === 'UNAVAILABLE'/);
  assert.match(page, /return 'UNAVAILABLE';/);
  for (const queue of ['default', 'audit', 'research']) {
    assert.ok(page.includes(`queueCount('${queue}')`), `missing safe ${queue} queue rendering`);
    assert.ok(!page.includes(`queues?.${queue}?.pending_jobs || 0`));
  }
});
