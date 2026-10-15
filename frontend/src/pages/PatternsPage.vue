<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { api, get } from '../services/api';

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
const loading = ref(false);
const error = ref('');
const token = ref('');
const from = ref('');
const to = ref('');
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
  loading.value = true;
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
  } finally { if (version === requestNumber) loading.value = false; }
};
watch([symbol, direction, status], () => { page.value = 1; selected.value = null; void load(); }, { immediate: true });
watch(page, () => { selected.value = null; void load(); });
onBeforeUnmount(() => { requestNumber++; controller?.abort(); });
const submit = async () => {
  queued.value = true;
  try {
    await api.post('/research/patterns/runs', { symbol: symbol.value, from: from.value, to: to.value }, { headers: { 'X-Research-Token': token.value } });
    error.value = 'Research queued. Results will appear when the job completes.';
    await load();
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
    <q-banner v-if="error" class="q-mb-md">{{ error }} <q-btn flat label="Refresh" @click="load" /></q-banner>
    <section>
      <h2>Research Runs</h2>
      <q-card flat bordered class="panel q-pa-md q-gutter-sm">
        <q-select v-model="symbol" :options="['BTCUSDT','ETHUSDT']" label="Symbol" dense />
        <q-input v-model="from" type="date" label="From" dense />
        <q-input v-model="to" type="date" label="To" dense />
        <q-input v-model="token" type="password" label="Research token" dense />
        <q-btn color="primary" :loading="queued" label="Queue bounded discovery" @click="submit" />
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
    </section>
    <section>
      <h2>Discovered Patterns · 10 per page</h2>
      <div class="row q-gutter-sm q-mb-md">
        <q-select v-model="direction" :options="['','BUY','SELL']" label="Direction" dense style="min-width:150px" />
        <q-select v-model="status" :options="['','VALIDATED','INCONCLUSIVE']" label="Evidence status" dense style="min-width:180px" />
      </div>
      <q-table :loading="loading" flat bordered :rows="patterns.data" :columns="columns" row-key="id" :pagination="{ rowsPerPage: 0 }" hide-pagination @row-click="(_:unknown, row:Pattern) => selected = row" />
      <q-pagination v-model="page" :max="pageMax" class="q-mt-sm" />
      <div v-if="patterns.data.length===0" class="muted q-pa-md">No discovered patterns. Run research when sufficient historical data is available.</div>
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
