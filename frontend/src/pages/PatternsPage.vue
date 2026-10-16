<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { api, get } from '../services/api';
import { usePolling } from '../composables/usePolling';

interface Pattern { id: string; symbol: string; direction: 'BUY' | 'SELL'; status: string; terms: string[]; metrics: { train: Metrics; validation: Metrics; test: Metrics; baseline_test: Metrics } }
interface Metrics { count: number; win_rate: number | null; mean_r: number | null; ambiguous_count: number }
interface Page<T> { data: T[]; meta: { last_page: number; total: number } }
const page = ref(1);
const symbol = ref('BTCUSDT');
const direction = ref('');
const status = ref('');
const patterns = shallowRef<Page<Pattern>>({ data: [], meta: { last_page: 1, total: 0 } });
const runs = shallowRef<Page<any>>({ data: [], meta: { last_page: 1, total: 0 } });
const matches = shallowRef<Pattern[]>([]);
const error = ref('');
const notice = ref('');
const token = ref('');
const utcDateDaysAgo = (days: number) => new Date(Date.now() - days * 86_400_000).toISOString().slice(0, 10);
const latestSafeDate = utcDateDaysAgo(1);
const from = ref(utcDateDaysAgo(31));
const to = ref(latestSafeDate);
const queued = ref(false);
const selected = shallowRef<Pattern | null>(null);
let controller: AbortController | undefined;
let requestNumber = 0;
const fmt = (value: number | null | undefined) => value == null ? 'Insufficient data' : value.toFixed(3);
const load = async () => {
  const version = ++requestNumber;
  controller?.abort();
  const abort = new AbortController();
  controller = abort;
  try {
    const [rows, recent, runRows] = await Promise.all([
      get<Page<Pattern>>('/research/patterns', { page: page.value, per_page: 10, symbol: symbol.value, ...(direction.value ? { direction: direction.value } : {}), ...(status.value ? { status: status.value } : {}) }, abort.signal),
      get<Pattern[]>('/research/patterns/latest-matches', { symbol: symbol.value }, abort.signal),
      get<Page<any>>('/research/patterns/runs', { page: 1, per_page: 10 }, abort.signal)
    ]);
    if (version !== requestNumber) return;
    patterns.value = rows;
    matches.value = recent;
    runs.value = runRows;
    error.value = '';
  } catch(e:any) {
    if (version === requestNumber && !abort.signal.aborted) error.value = e.message || 'Research unavailable';
  }
};
const hasActiveRuns = computed(() => runs.value.data.some(run => ['QUEUED', 'RUNNING'].includes(run.status)));
const rangeDays = computed(() => (Date.parse(to.value) - Date.parse(from.value)) / 86_400_000);
const canQueue = computed(() => token.value.trim().length > 0 && from.value < to.value && to.value <= latestSafeDate && rangeDays.value <= 90);
const { loading, refresh } = usePolling(load, 10000, hasActiveRuns);
watch([symbol, direction, status], () => { page.value = 1; selected.value = null; void refresh(); });
watch(page, () => { selected.value = null; void refresh(); });
onBeforeUnmount(() => { requestNumber++; controller?.abort(); });
const submit = async () => {
  queued.value = true;
  notice.value = '';
  try {
    const response = await api.post('/research/patterns/runs', { symbol: symbol.value, from: from.value, to: to.value }, { headers: { 'X-Research-Token': token.value } });
    notice.value = `Discovery ${response.data.data.id} queued. It runs asynchronously and this page refreshes while it is active.`;
    error.value = '';
    await refresh();
  } catch(e:any) { error.value = e.message || 'Could not queue research'; }
  finally { queued.value = false; }
};
const columns: any[] = [
  { name: 'direction', label: 'Direction', field: 'direction', align: 'left' },
  { name: 'status', label: 'Evidence', field: 'status' },
  { name: 'terms', label: 'Conditions', field: (r:Pattern) => r.terms.join(' + ') },
  { name: 'train', label: 'Train N', field: (r:Pattern) => r.metrics.train.count },
  { name: 'test', label: 'Test N', field: (r:Pattern) => r.metrics.test.count },
  { name: 'expectancy', label: 'OOS Avg R', field: (r:Pattern) => fmt(r.metrics.test.mean_r) },
];
const pageMax = computed(() => Math.max(1, patterns.value.meta.last_page));
</script>
<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">RESEARCH ONLY · PAPER MODE</span><h1>Pattern Discovery</h1></div></div>
    <q-banner class="bg-grey-9 text-white q-mb-md">Patterns are historical research observations, not BUY/SELL signals or guaranteed profitability. Only point-in-time information is used for matching.</q-banner>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }} <q-btn flat label="Refresh" @click="refresh" /></q-banner>
    <q-banner v-if="notice" class="bg-positive text-white q-mb-md">{{ notice }}</q-banner>
    <q-banner v-if="hasActiveRuns" class="bg-blue-grey-9 text-white q-mb-md">Pattern discovery is queued or running asynchronously on the dedicated research worker. Jobs may wait behind experiments; status and results refresh automatically about every 10 seconds.</q-banner>
    <section>
      <h2>Research Runs</h2>
      <q-card flat bordered class="panel q-pa-md q-gutter-sm">
        <q-select v-model="symbol" :options="['BTCUSDT','ETHUSDT']" label="Symbol" dense />
        <q-input v-model="from" type="date" label="From" :max="latestSafeDate" dense />
        <q-input v-model="to" type="date" label="To" :max="latestSafeDate" dense />
        <q-input v-model="token" type="password" label="Research token" autocomplete="off" dense />
        <div class="muted">Pre-filled with the last 30 complete UTC days. Discovery accepts past windows up to 90 days; the token is sent only with this protected request.</div>
        <q-btn color="primary" :loading="queued" :disable="!canQueue" label="Queue bounded discovery" @click="submit" />
      </q-card>
    </section>
    <section>
      <h2>Latest Research Jobs</h2>
      <q-table flat bordered :rows="runs.data" row-key="id" :pagination="{ rowsPerPage: 0 }" hide-pagination :columns="[
        {name:'symbol',label:'Symbol',field:'symbol',align:'left'},
        {name:'status',label:'Status',field:'status'},
        {name:'decisions',label:'Examined',field:'examined_decisions'},
        {name:'labeled',label:'Labeled',field:'labeled_observations'},
      ]" />
      <div v-if="!loading&&runs.data.length===0" class="muted q-pa-md">No discovery job has been queued. Submit the pre-filled bounded window above; the result will appear here after the asynchronous worker starts.</div>
    </section>
    <section>
      <h2>Discovered Patterns · 10 per page</h2>
      <div class="row q-gutter-sm q-mb-md">
        <q-select v-model="direction" :options="['','BUY','SELL']" label="Direction" dense style="min-width:150px" />
        <q-select v-model="status" :options="['','VALIDATED','INCONCLUSIVE']" label="Evidence status" dense style="min-width:180px" />
      </div>
      <q-table :loading="loading" flat bordered :rows="patterns.data" :columns="columns" row-key="id" :pagination="{ rowsPerPage: 0 }" hide-pagination @row-click="(_:unknown, row:Pattern) => selected = row" />
      <q-pagination v-model="page" :max="pageMax" class="q-mt-sm" />
      <div v-if="!loading&&patterns.data.length===0" class="muted q-pa-md"><span v-if="hasActiveRuns">Discovery is still queued or running; no result is expected until it completes.</span><span v-else-if="runs.data.length===0">No discovered patterns yet because no discovery job has completed.</span><span v-else>No pattern met the minimum train, validation, and out-of-sample evidence thresholds in the selected historical data. Check the latest job's Examined and Labeled counts; zero means more persisted signal history is needed.</span></div>
      <q-card v-if="selected" flat bordered class="panel q-pa-md q-mt-md">
        <h3>Pattern Details — {{ selected.direction }}</h3>
        <p>{{ selected.terms.join(' AND ') }}</p>
        <p>Training: {{selected.metrics.train.count}} observations · Validation: {{selected.metrics.validation.count}} · Test: {{selected.metrics.test.count}}</p>
        <p>OOS mean R: {{ fmt(selected.metrics.test.mean_r) }} · OOS win rate: {{ selected.metrics.test.win_rate===null?'Insufficient data':(selected.metrics.test.win_rate*100).toFixed(1)+'%' }}</p>
        <p>Ambiguous executions (test): {{selected.metrics.test.ambiguous_count}}</p>
        <p>Status: {{selected.status}}. No automatically generated virtual entries.</p>
      </q-card>
    </section>
    <section>
      <h2>Latest Research Matches (No Trading Action)</h2>
      <div v-if="matches.length===0" class="muted q-pa-md">No validated patterns match the latest available signal.</div>
      <q-list v-else bordered separator><q-item v-for="match in matches" :key="match.id"><q-item-section>{{match.direction}} — {{match.terms.join(' + ')}}</q-item-section></q-item></q-list>
    </section>
  </q-page>
</template>
