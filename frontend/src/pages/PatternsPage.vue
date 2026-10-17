<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { api, get } from '../services/api';
import { usePolling } from '../composables/usePolling';

interface Pattern { id: string; symbol: string; direction: 'BUY' | 'SELL'; status: string; terms: string[]; metrics: { train: Metrics; validation: Metrics; test: Metrics; baseline_test: Metrics; support?: number | null; confidence?: number | null; lift?: number | null; walk_forward?: any[] } }
interface Metrics { count: number; win_rate: number | null; mean_r: number | null; expectancy_r?: number | null; profit_factor?: number | null; average_mfe_r?: number | null; average_mae_r?: number | null; ambiguous_count: number; anchor_decisions?: Record<string,number>; risk_outcomes?: Record<string,number>; paper_outcomes?: Record<string,number> }
interface Readiness { symbol: string; collected_decisions: number; eligible_decisions: number; minimum_required: number; remaining_decisions: number; progress_percent: number; ready: boolean }
interface PatternOccurrence { id: string; symbol: string; timeframe: string; pattern_name: string; pattern_type: string; direction: string; formation_time: string; detected_at: string; version: string; outcome: string | null; forward_return: string | null }
interface LevelForecast { id: string; event: string; side: string; triggered_at: string; sample_size: number; success_probability: string | null; confidence_low: string | null; confidence_high: string | null; validation_status: string; outcome: string | null }
interface OccurrenceStatistic { pattern_type: string; pattern_name: string; direction: string; observations: number; resolved: number; successes: number; success_rate: number | null; confidence_low: number | null; confidence_high: number | null; validation_status: string; average_forward_return: string | null }
interface Page<T> { data: T[]; meta: { last_page: number; total: number; readiness?: Readiness[]; statistics?: OccurrenceStatistic[] } }
const page = ref(1);
const symbol = ref('BTCUSDT');
const direction = ref('');
const status = ref('');
const patterns = shallowRef<Page<Pattern>>({ data: [], meta: { last_page: 1, total: 0 } });
const runs = shallowRef<Page<any>>({ data: [], meta: { last_page: 1, total: 0 } });
const matches = shallowRef<Pattern[]>([]);
const storedMatches = shallowRef<any[]>([]);
const occurrences = shallowRef<Page<PatternOccurrence>>({ data: [], meta: { last_page: 1, total: 0 } });
const forecasts = shallowRef<any>({ data: [], meta: { calibration: {} } });
const forecastRows = computed(() => forecasts.value.data as LevelForecast[]);
const strategyObservations = shallowRef<any[]>([]);
const error = ref('');
const notice = ref('');
const utcDateDaysAgo = (days: number) => new Date(Date.now() - days * 86_400_000).toISOString().slice(0, 10);
const latestSafeDate = utcDateDaysAgo(1);
const from = ref(utcDateDaysAgo(31));
const to = ref(latestSafeDate);
const queued = ref(false);
const selected = shallowRef<Pattern | null>(null);
const selectedReadiness = computed(() => runs.value.meta.readiness?.find(item => item.symbol === symbol.value));
let controller: AbortController | undefined;
let requestNumber = 0;
const fmt = (value: number | null | undefined) => value == null ? 'Insufficient data' : value.toFixed(3);
const load = async () => {
  const version = ++requestNumber;
  controller?.abort();
  const abort = new AbortController();
  controller = abort;
  try {
    const [rowsResponse, recent, runsResponse, dashboard, occurrenceResponse, forecastResponse, storedMatchResponse] = await Promise.all([
      api.get('/research/patterns', { params: { page: page.value, per_page: 10, symbol: symbol.value, ...(direction.value ? { direction: direction.value } : {}), ...(status.value ? { status: status.value } : {}) }, signal: abort.signal }),
      get<Pattern[]>('/research/patterns/latest-matches', { symbol: symbol.value }, abort.signal),
      api.get('/research/patterns/runs', { params: { page: 1, per_page: 10 }, signal: abort.signal }),
      get<any>('/dashboard', undefined, abort.signal),
      api.get('/research/candlestick-patterns', { params: { symbol: symbol.value, per_page: 10 }, signal: abort.signal }),
      api.get('/research/level-forecasts', { params: { symbol: symbol.value, per_page: 10 }, signal: abort.signal }),
      api.get('/research/pattern-matches', { params: { symbol: symbol.value, per_page: 10 }, signal: abort.signal })
    ]);
    if (version !== requestNumber) return;
    patterns.value = rowsResponse.data;
    matches.value = recent;
    runs.value = runsResponse.data;
    strategyObservations.value = dashboard.strategy_observations_24h || [];
    occurrences.value = occurrenceResponse.data;
    forecasts.value = forecastResponse.data;
    storedMatches.value = storedMatchResponse.data.data;
    error.value = '';
  } catch(e:any) {
    if (version === requestNumber && !abort.signal.aborted) error.value = e.message || 'Research unavailable';
  }
};
const hasActiveRuns = computed(() => runs.value.data.some(run => ['QUEUED', 'RUNNING'].includes(run.status)));
const rangeDays = computed(() => (Date.parse(to.value) - Date.parse(from.value)) / 86_400_000);
const canQueue = computed(() => from.value < to.value && to.value <= latestSafeDate && rangeDays.value <= 90);
const { loading, refresh } = usePolling(load, 10000, hasActiveRuns);
watch([symbol, direction, status], () => { page.value = 1; selected.value = null; void refresh(); });
watch(page, () => { selected.value = null; void refresh(); });
onBeforeUnmount(() => { requestNumber++; controller?.abort(); });
const submit = async () => {
  queued.value = true;
  notice.value = '';
  try {
    const response = await api.post('/research/patterns/runs', { symbol: symbol.value, from: from.value, to: to.value });
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
      <h2>All Strategy Context · BTC and ETH</h2>
      <q-banner class="bg-blue-grey-10 text-white q-mb-md">These are current four-timeframe strategy observations. Pattern metrics below are separate market-pattern evidence and are not attributed to a strategy unless tested by that strategy's experiment.</q-banner>
      <q-markup-table flat bordered wrap-cells>
        <thead><tr><th class="text-left">Strategy</th><th v-for="market in ['BTCUSDT','ETHUSDT']" :key="market" class="text-left">{{ market }}</th></tr></thead>
        <tbody><tr v-for="strategy in strategyObservations" :key="strategy.strategy_id">
          <td class="text-left"><b>{{ strategy.strategy_id }}</b><div class="muted">v{{ strategy.strategy_version }} · 1h → 30m → 15m → 5m</div></td>
          <td v-for="market in strategy.symbols" :key="market.symbol" class="text-left">
            <div><span class="text-positive">BUY {{ market.buy }}</span> · <span class="text-negative">SELL {{ market.sell }}</span> · SKIP {{ market.skip }}</div>
            <template v-if="market.latest"><b>{{ market.latest.side }}</b><div class="muted">{{ market.latest.reasons?.join(', ') }}</div></template>
            <span v-else class="muted">Waiting for observation</span>
          </td>
        </tr></tbody>
      </q-markup-table>
    </section>
    <section>
      <h2>Candlestick and Market-Structure Patterns · Latest 10</h2>
      <q-banner class="bg-blue-grey-10 text-white q-mb-md">Deterministic closed-candle detections include classical candles, pivots, structure sequences, breakouts, retests, false breakouts, liquidity sweeps, and level rejections. Formation and detection times are stored separately.</q-banner>
      <q-markup-table flat bordered wrap-cells>
        <thead><tr><th class="text-left">Pattern</th><th>Type</th><th>Timeframe</th><th>Direction</th><th>Outcome</th><th class="text-left">Detected</th></tr></thead>
        <tbody><tr v-for="pattern in occurrences.data" :key="pattern.id">
          <td class="text-left"><b>{{ pattern.pattern_name }}</b><div class="muted">v{{ pattern.version }}</div></td><td class="text-center">{{ pattern.pattern_type }}</td>
          <td class="text-center">{{ pattern.timeframe }}m</td><td class="text-center">{{ pattern.direction }}</td><td class="text-center">{{ pattern.outcome ?? 'PENDING' }}<div class="muted">{{ pattern.forward_return == null ? '' : (Number(pattern.forward_return)*100).toFixed(2)+'%' }}</div></td>
          <td class="text-left">{{ pattern.detected_at }}<div class="muted">Formation: {{ pattern.formation_time }}</div></td>
        </tr></tbody>
      </q-markup-table>
      <div v-if="!loading && occurrences.data.length === 0" class="muted q-pa-md">No pattern has been detected for {{ symbol }} yet.</div>
      <q-markup-table v-if="occurrences.meta.statistics?.length" flat bordered dense class="q-mt-md">
        <thead><tr><th class="text-left">Outcome statistics</th><th>Direction</th><th>Evidence</th><th>Success / 95% CI</th><th>Average return</th></tr></thead>
        <tbody><tr v-for="row in occurrences.meta.statistics" :key="`${row.pattern_type}:${row.pattern_name}:${row.direction}`">
          <td class="text-left">{{ row.pattern_name }}</td><td class="text-center">{{ row.direction }}</td><td class="text-center">{{ row.validation_status }} · {{ row.resolved }} / {{ row.observations }}</td>
          <td class="text-center">{{ row.direction === 'NEUTRAL' ? 'Directional only' : (row.success_rate == null ? 'Insufficient data' : (row.success_rate*100).toFixed(1)+'% / '+(Number(row.confidence_low)*100).toFixed(1)+'–'+(Number(row.confidence_high)*100).toFixed(1)+'%') }}</td><td class="text-center">{{ row.average_forward_return == null ? 'Insufficient data' : (Number(row.average_forward_return)*100).toFixed(2)+'%' }}</td>
        </tr></tbody>
      </q-markup-table>
    </section>
    <section>
      <h2>Support / Resistance Forecasts · Latest 10</h2>
      <div class="muted q-mb-sm">Resolved forecasts: {{ forecasts.meta.calibration?.resolved_forecasts ?? 0 }} · Mean Brier score: {{ forecasts.meta.calibration?.mean_brier_score ?? 'Insufficient data' }} · Calibration degradation: {{ forecasts.meta.calibration?.degradation_detected ? 'DETECTED' : 'not detected' }}</div>
      <q-markup-table flat bordered wrap-cells>
        <thead><tr><th class="text-left">Event</th><th>Side</th><th>Evidence</th><th>Probability / 95% CI</th><th>Outcome</th><th class="text-left">Triggered</th></tr></thead>
        <tbody><tr v-for="forecast in forecastRows" :key="forecast.id">
          <td class="text-left">{{ forecast.event }}</td><td class="text-center">{{ forecast.side }}</td>
          <td class="text-center">{{ forecast.validation_status }} · N={{ forecast.sample_size }}</td>
          <td class="text-center"><template v-if="forecast.success_probability !== null">{{ (Number(forecast.success_probability) * 100).toFixed(1) }}% / {{ (Number(forecast.confidence_low) * 100).toFixed(1) }}–{{ (Number(forecast.confidence_high) * 100).toFixed(1) }}%</template><span v-else>Insufficient data</span></td>
          <td class="text-center">{{ forecast.outcome ?? 'PENDING' }}</td><td class="text-left">{{ forecast.triggered_at }}</td>
        </tr></tbody>
      </q-markup-table>
      <div v-if="!loading && forecasts.data.length === 0" class="muted q-pa-md">No level-interaction forecast has been recorded for {{ symbol }} yet.</div>
    </section>
    <section>
      <h2>Research Runs</h2>
      <q-banner v-if="selectedReadiness" class="bg-blue-grey-10 text-white q-mb-md">
        <div class="text-subtitle2">{{ symbol }} automatic discovery readiness: {{ selectedReadiness.eligible_decisions }} / {{ selectedReadiness.minimum_required }} eligible decisions</div>
        <q-linear-progress :value="selectedReadiness.progress_percent / 100" color="primary" class="q-my-sm" />
        <div v-if="selectedReadiness.ready">Enough closed, outcome-labeled history is available. Automatic discovery will queue on the hourly research schedule.</div>
        <div v-else>{{ selectedReadiness.remaining_decisions }} more mature decisions are required. {{ selectedReadiness.collected_decisions - selectedReadiness.eligible_decisions }} newer decisions are still waiting for their outcome window. This protects the results from incomplete labels and small-sample claims.</div>
      </q-banner>
      <q-card flat bordered class="panel q-pa-md q-gutter-sm">
        <q-select v-model="symbol" :options="['BTCUSDT','ETHUSDT']" label="Symbol" dense />
        <q-input v-model="from" type="date" label="From" :max="latestSafeDate" dense />
        <q-input v-model="to" type="date" label="To" :max="latestSafeDate" dense />
        <div class="muted">Pre-filled with the last 30 complete UTC days. Discovery accepts past windows up to 90 days.</div>
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
        <q-select v-model="status" :options="['','VALIDATING','VALIDATED','REJECTED','STALE']" label="Evidence status" dense style="min-width:180px" />
      </div>
      <q-table :loading="loading" flat bordered :rows="patterns.data" :columns="columns" row-key="id" :pagination="{ rowsPerPage: 0 }" hide-pagination @row-click="(_:unknown, row:Pattern) => selected = row" />
      <q-pagination v-model="page" :max="pageMax" class="q-mt-sm" />
      <div v-if="!loading&&patterns.data.length===0" class="muted q-pa-md"><span v-if="hasActiveRuns">Discovery is still queued or running; no result is expected until it completes.</span><span v-else-if="runs.data.length===0">No discovered patterns yet because no discovery job has completed.</span><span v-else>No pattern met the minimum train, validation, and out-of-sample evidence thresholds in the selected historical data. Check the latest job's Examined and Labeled counts; zero means more persisted signal history is needed.</span></div>
      <q-card v-if="selected" flat bordered class="panel q-pa-md q-mt-md">
        <h3>Pattern Details — {{ selected.direction }}</h3>
        <p>{{ selected.terms.join(' AND ') }}</p>
        <p>Training: {{selected.metrics.train.count}} observations · Validation: {{selected.metrics.validation.count}} · Test: {{selected.metrics.test.count}}</p>
        <p>OOS mean R: {{ fmt(selected.metrics.test.mean_r) }} · OOS win rate: {{ selected.metrics.test.win_rate===null?'Insufficient data':(selected.metrics.test.win_rate*100).toFixed(1)+'%' }}</p>
        <p>Support: {{ selected.metrics.support == null ? 'Insufficient data' : (selected.metrics.support*100).toFixed(1)+'%' }} · Confidence: {{ selected.metrics.confidence == null ? 'Insufficient data' : (selected.metrics.confidence*100).toFixed(1)+'%' }} · Lift: {{ fmt(selected.metrics.lift) }}</p>
        <p>Profit factor: {{ fmt(selected.metrics.test.profit_factor) }} · MFE: {{ fmt(selected.metrics.test.average_mfe_r) }}R · MAE: {{ fmt(selected.metrics.test.average_mae_r) }}R · Walk-forward folds: {{ selected.metrics.walk_forward?.length ?? 0 }}</p>
        <p>Anchor decisions: {{ JSON.stringify(selected.metrics.test.anchor_decisions || {}) }} · Risk outcomes: {{ JSON.stringify(selected.metrics.test.risk_outcomes || {}) }} · Paper outcomes: {{ JSON.stringify(selected.metrics.test.paper_outcomes || {}) }}</p>
        <p>Ambiguous executions (test): {{selected.metrics.test.ambiguous_count}}</p>
        <p>Status: {{selected.status}}. No automatically generated virtual entries.</p>
      </q-card>
    </section>
    <section>
      <h2>Latest Research Matches (No Trading Action)</h2>
      <div v-if="matches.length===0" class="muted q-pa-md">No validated patterns match the latest available signal.</div>
      <q-list v-else bordered separator><q-item v-for="match in matches" :key="match.id"><q-item-section>{{match.direction}} — {{match.terms.join(' + ')}}</q-item-section></q-item></q-list>
      <h3>Persisted Match Outcomes</h3>
      <q-markup-table v-if="storedMatches.length" flat bordered>
        <thead><tr><th class="text-left">Conditions</th><th>Direction</th><th>Status</th><th>Outcome</th><th>Net R</th><th class="text-left">Matched</th></tr></thead>
        <tbody><tr v-for="match in storedMatches" :key="match.id"><td class="text-left">{{ match.terms?.join(' + ') }}</td><td class="text-center">{{ match.direction }}</td><td class="text-center">{{ match.status }}</td><td class="text-center">{{ match.outcome ?? 'PENDING' }}</td><td class="text-center">{{ match.net_r ?? '—' }}</td><td class="text-left">{{ match.matched_at }}</td></tr></tbody>
      </q-markup-table>
      <div v-else class="muted q-pa-md">No validated pattern match has been persisted yet.</div>
    </section>
  </q-page>
</template>
