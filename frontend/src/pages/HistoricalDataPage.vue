<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { historyClient } from '../services/historical';
import type { Batch, BatchSummary, Entry, Page, Plan, PlanInput, Run, Strategy } from '../services/historical';

const token = ref('');
const client = historyClient(() => token.value);
const busy = ref(false), error = ref(''), stale = ref(false);
const form = ref<PlanInput>({ provider: 'BINANCE_VISION', market: 'futures/um', symbols: ['BTCUSDT', 'ETHUSDT'], intervals: ['1m', '5m', '15m', '30m', '1h'], from: '2024-01-01T00:00:00Z', to: '2025-12-31T23:59:59.999Z', funding: true, mark_prices: false, intended_use: 'NONCOMMERCIAL_RESEARCH' });
const preview = ref<Plan | null>(null), batch = ref<Batch | null>(null);
const batches = ref<Page<BatchSummary> | null>(null), entries = ref<Page<Entry> | null>(null), runs = ref<Page<Run> | null>(null);
const strategies = ref<Strategy[]>([]), selectedStrategy = ref<Strategy | null>(null);
const batchPage = ref(1), entryPage = ref(1), runPage = ref(1);
const ack = ref(false), reference = ref(''), idempotency = ref(crypto.randomUUID());
const selectedRun = ref<Run | null>(null), artifacts = ref<Page<Record<string, unknown>> | null>(null), artifactPage = ref(1), artifact = ref('trades');
const research = ref({ symbol: 'BTCUSDT', from: '2024-07-01T00:00:00Z', to: '2024-07-08T00:00:00Z', capital: '10000', fee: '0.0005', slippage: '0.0002', risk_percent: '0.5', funding: 'EXACT', intended_use: 'NONCOMMERCIAL_RESEARCH' });
let timer: ReturnType<typeof setTimeout> | undefined;
let controller = new AbortController();
let mounted = true;
watch(form, () => { preview.value = null; idempotency.value = crypto.randomUUID(); }, { deep: true });
watch(token, () => { controller.abort(); controller = new AbortController(); batch.value = null; selectedRun.value = null; preview.value = null; clearTimeout(timer); });
onBeforeUnmount(() => { mounted = false; clearTimeout(timer); controller.abort(); });
async function action(work: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true; error.value = ''; clearTimeout(timer);
  try { await work(); stale.value = false; } catch (e) { error.value = e instanceof Error ? e.message : 'Historical API unavailable'; stale.value = true; }
  finally { busy.value = false; poll(); }
}
function poll() {
  clearTimeout(timer);
  if (mounted && (batch.value?.status === 'ACTIVE' || ['QUEUED', 'RUNNING'].includes(selectedRun.value?.status || ''))) {
    timer = setTimeout(() => { if (!document.hidden) void action(refresh); else poll(); }, 5000);
  }
}
async function load() {
  [batches.value, strategies.value] = await Promise.all([client.get<Page<BatchSummary>>('batches', { page: batchPage.value }, controller.signal), client.get<Strategy[]>('strategies', undefined, controller.signal)]);
  selectedStrategy.value ??= strategies.value[0] || null;
}
async function select(id: string) {
  controller.abort(); controller = new AbortController(); clearTimeout(timer); entryPage.value = 1; runPage.value = 1; selectedRun.value = null; artifacts.value = null;
  batch.value = await client.get<Batch>(`batches/${id}`, undefined, controller.signal);
  await refresh();
}
async function refresh() {
  if (!batch.value) return;
  const id = batch.value.id;
  const [b, e, r] = await Promise.all([client.get<Batch>(`batches/${id}`, undefined, controller.signal), client.get<Page<Entry>>(`batches/${id}/entries`, { page: entryPage.value }, controller.signal), client.get<Page<Run>>(`batches/${id}/research`, { page: runPage.value }, controller.signal)]);
  if (batch.value?.id !== id) return;
  batch.value = b; entries.value = e; runs.value = r;
  if (selectedRun.value) selectedRun.value = await client.get<Run>(`runs/${selectedRun.value.id}`, undefined, controller.signal);
}
async function plan() { preview.value = await client.post<Plan>('preview', form.value); }
async function create() {
  if (!preview.value) return;
  const b = await client.post<Batch>('batches', preview.value.input, idempotency.value);
  await select(b.id); await load();
}
async function control(kind: string) {
  if (!batch.value) return;
  batch.value = await client.post<Batch>(`batches/${batch.value.id}/${kind}`, { ...(reference.value.length >= 8 ? { reference: reference.value } : {}), terms_version: batch.value.license.version });
  await refresh();
}
async function validate() { if (batch.value) { await client.post(`batches/${batch.value.id}/validate`); await refresh(); } }
async function run() {
  if (!batch.value || !selectedStrategy.value) return;
  selectedRun.value = await client.post<Run>(`batches/${batch.value.id}/research`, { ...research.value, strategy_id: selectedStrategy.value.strategy_id, definition_id: selectedStrategy.value.definition_id });
  artifacts.value = null; await refresh();
}
async function inspect(id: string) { selectedRun.value = await client.get<Run>(`runs/${id}`); artifactPage.value = 1; artifacts.value = null; if (selectedRun.value.status === 'COMPLETED') await loadArtifacts(); }
async function loadArtifacts() { if (selectedRun.value) artifacts.value = await client.get<Page<Record<string, unknown>>>(`runs/${selectedRun.value.id}/${artifact.value}`, { page: artifactPage.value }); }
async function cancelRun() { if (selectedRun.value) { selectedRun.value = await client.post<Run>(`runs/${selectedRun.value.id}/cancel`); await refresh(); } }
const json = (value: unknown) => JSON.stringify(value, null, 2);
const columns = [ { name: 'filename', label: 'File / source', field: 'filename', align: 'left' as const }, { name: 'status', label: 'Import state', field: 'status' }, { name: 'verification_status', label: 'SHA-256', field: 'verification_status' }, { name: 'imported_rows', label: 'Imported', field: 'imported_rows' }, { name: 'duplicate_rows', label: 'Duplicates', field: 'duplicate_rows' }, { name: 'conflict_rows', label: 'Conflicts', field: 'conflict_rows' }, { name: 'error_code', label: 'Error', field: 'error_code' } ];
</script>

<template>
  <q-page padding>
    <h1 class="text-h4">Binance → Historical Data → Download from Binance</h1>
    <q-banner class="bg-blue-grey-10 text-white">RESEARCH / PAPER MODE ONLY — NOT FINANCIAL ADVICE. Real exchange ordering is disabled. Historical simulations never enter the PAPER journal.</q-banner>
    <q-input v-model="token" type="password" label="Research owner token" autocomplete="off" data-testid="history-token" />
    <q-btn label="Load batches" :loading="busy" @click="action(load)" />
    <q-banner v-if="error" class="bg-negative text-white q-my-md" role="alert">{{ error }}</q-banner>
    <q-banner v-if="stale" class="bg-warning">Displayed state may be stale. Refresh after resolving the error.</q-banner>
    <h2 class="text-h5">BTCUSDT + ETHUSDT | USD-M Futures | 2024–2025 | 1m/5m/15m/30m/1h</h2>
    <p>Provider: Binance Data Collection / Binance Vision. Market: futures/um. All dates are UTC; acquisition end is inclusive.</p>
    <div class="row q-col-gutter-md">
      <q-select class="col-12 col-md-6" v-model="form.symbols" multiple :options="['BTCUSDT','ETHUSDT']" label="Instruments" />
      <q-select class="col-12 col-md-6" v-model="form.intervals" multiple :options="['1m','5m','15m','30m','1h']" label="Intervals" />
      <q-input class="col-12 col-md-6" v-model="form.from" label="Start UTC" data-testid="history-from" />
      <q-input class="col-12 col-md-6" v-model="form.to" label="Inclusive end UTC (.999)" data-testid="history-to" />
    </div>
    <q-checkbox v-model="form.funding" label="Historical funding archives + settlement API marks" />
    <q-checkbox v-model="form.mark_prices" label="Also import separate 1m mark-price archives" />
    <q-btn label="Preview download plan" :loading="busy" @click="action(plan)" />
    <section v-if="preview" data-testid="history-preview">
      <h2 class="text-h5">Dry-run estimate — no downloads</h2>
      <pre>{{ json(preview.estimate) }}</pre>
      <p>Availability and completeness remain unverified until import.</p>
      <q-banner class="bg-amber-2 text-black">{{ preview.license.attribution }} · {{ preview.license.license }}. Restricted to permitted non-commercial historical research. Commercial use may require a separate Binance license. Export and PAPER promotion are separately blocked by source policy.</q-banner>
      <a :href="preview.license.terms" target="_blank" rel="noopener noreferrer">Read official dataset terms ({{ preview.license.version }})</a>
      <q-btn label="Save plan without downloading" :loading="busy" @click="action(create)" />
    </section>
    <h2 class="text-h5">Acquisition batches</h2>
    <p v-if="batches && !batches.data.length">No batches. Preview and save a plan first.</p>
    <q-list bordered v-if="batches"><q-item v-for="b in batches.data" :key="b.id" clickable @click="action(() => select(b.id))"><q-item-section>{{ b.id }} · {{ b.status }} · {{ b.created_at }}</q-item-section></q-item></q-list>
    <q-pagination v-if="batches" v-model="batchPage" :max="batches.last_page" @update:model-value="action(load)" />
    <section v-if="batch" data-testid="history-batch">
      <h2 class="text-h5">{{ batch.id }} · {{ batch.status }}</h2>
      <p>Last update: {{ batch.updated_at }}. Worker: {{ batch.worker ? `${batch.worker.filename} (lease ${batch.worker.lease_until})` : 'Idle / awaiting queued worker' }}.</p>
      <pre>{{ json(batch.counts) }}</pre>
      <q-checkbox v-model="ack" label="I acknowledge the recorded Binance terms for permitted non-commercial research and the resource estimate." />
      <a :href="batch.license.terms" target="_blank" rel="noopener noreferrer">Recorded terms: {{ batch.license.version }}</a>
      <q-input v-model="reference" label="Owner acknowledgement reference (8–160 characters)" maxlength="160" data-testid="history-reference" />
      <div class="q-gutter-sm q-my-md">
        <q-btn v-if="['PLANNED','PAUSED','FAILED'].includes(batch.status)" label="Start / resume downloads" color="primary" :disable="busy || (!batch.acknowledged_at && (!ack || reference.length < 8))" @click="action(() => control(batch?.status === 'PLANNED' ? 'start' : 'resume'))" />
        <q-btn v-if="batch.status === 'ACTIVE'" label="Pause downloads" :disable="busy" @click="action(() => control('pause'))" />
        <q-btn v-if="['ACTIVE','PAUSED','FAILED'].includes(batch.status)" label="Retry failed archives" :disable="busy" @click="action(() => control('retry'))" />
        <q-btn v-if="['PLANNED','ACTIVE','PAUSED','FAILED'].includes(batch.status)" label="Cancel batch" color="negative" :disable="busy" @click="action(() => control('cancel'))" />
        <q-btn label="Refresh progress" :disable="busy" @click="action(refresh)" />
        <q-btn label="Validate imported coverage" :disable="busy || batch.status === 'ACTIVE'" @click="action(validate)" />
      </div>
      <q-table :rows="entries?.data || []" :columns="columns" row-key="id" :pagination="{rowsPerPage: 0}" hide-pagination flat bordered>
        <template #body-cell-filename="props"><q-td :props="props">{{ props.row.filename }}<details><summary>Verification evidence</summary><pre>{{ json({ sha256: props.row.sha256, attempts: props.row.attempts, quality: props.row.quality, diagnostics: props.row.diagnostics }) }}</pre></details></q-td></template>
      </q-table>
      <q-pagination v-if="entries" v-model="entryPage" :max="entries.last_page" @update:model-value="action(refresh)" />
      <h3 class="text-h6">Download completion and research readiness</h3>
      <p v-if="!batch.quality">Coverage has not been validated. Download completion alone does not prove research readiness.</p>
      <template v-else>
        <p>Downloaded: {{ batch.quality.download_complete }} · Candle coverage: {{ batch.quality.candle_complete }} · Funding complete: {{ batch.quality.funding_complete }} · Research ready: {{ batch.quality.research_ready }} · Full fidelity: {{ batch.quality.full_fidelity_ready }} · Checked: {{ batch.quality.validated_at }}</p>
        <details><summary>Coverage, missing bars and funding / mark-price evidence</summary><pre>{{ json(batch.quality) }}</pre></details>
      </template>
      <h2 class="text-h5">Run Historical Backtest</h2>
      <p>Requires all 5m/15m/30m/1h series and strategy warmup. Research end is exclusive. Missing inputs fail closed. Funding disabled produces an explicitly qualified result.</p>
      <q-select v-model="selectedStrategy" :options="strategies" popup-content-style="max-height: 320px" :option-label="(s: Strategy) => `${s.strategy_id} v${s.version} · ${s.kind}`" label="Strategy / immutable version" />
      <div class="row q-col-gutter-sm">
        <q-select class="col" v-model="research.symbol" :options="batch.plan.input.symbols" label="Symbol" />
        <q-input class="col" v-model="research.from" label="Replay start UTC (warmup required)" data-testid="replay-from" />
        <q-input class="col" v-model="research.to" label="Replay end UTC (exclusive)" data-testid="replay-to" />
      </div>
      <div class="row q-col-gutter-sm">
        <q-input class="col" v-model="research.capital" label="Virtual capital USDT" />
        <q-input class="col" v-model="research.fee" label="Commission fraction" />
        <q-input class="col" v-model="research.slippage" label="Slippage fraction" />
        <q-input class="col" v-model="research.risk_percent" label="Risk percent (max 0.5)" />
        <q-select class="col" v-model="research.funding" :options="['EXACT','DISABLED']" label="Historical funding" />
      </div>
      <q-btn label="Run Historical Backtest" :loading="busy" :disable="!batch.quality?.research_ready" @click="action(run)" />
      <q-list bordered v-if="runs"><q-item v-for="r in runs.data" :key="r.id" clickable @click="action(() => inspect(r.id))"><q-item-section>{{ r.strategy_id }} v{{ r.strategy_version }} · {{ r.status }} · {{ r.error_code }}</q-item-section></q-item></q-list>
      <q-pagination v-if="runs" v-model="runPage" :max="runs.last_page" @update:model-value="action(refresh)" />
    </section>
    <section v-if="selectedRun" data-testid="history-run">
      <h2 class="text-h5">Historical research · {{ selectedRun.status }}</h2>
      <p>Immutable pin: {{ selectedRun.dataset_pin }}. {{ selectedRun.error_code }}</p>
      <q-btn v-if="['QUEUED','RUNNING'].includes(selectedRun.status)" label="Cancel replay" :disable="busy" @click="action(cancelRun)" />
      <pre>{{ json(selectedRun.result || selectedRun.skips) }}</pre>
      <template v-if="selectedRun.status === 'COMPLETED'">
        <q-select v-model="artifact" :options="['trades','equity','executions']" label="Verified evidence" @update:model-value="artifactPage = 1; action(loadArtifacts)" />
        <pre v-for="row in artifacts?.data || []" :key="String(row.id)">{{ json(row) }}</pre>
        <q-pagination v-if="artifacts" v-model="artifactPage" :max="artifacts.last_page" @update:model-value="action(loadArtifacts)" />
      </template>
    </section>
  </q-page>
</template>

<style scoped>
pre { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 28rem; overflow: auto; }
section { margin-top: 1.5rem; }
</style>
