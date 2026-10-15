<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import EquityChart from '../components/charts/EquityChart.vue';

const route = useRoute();
const summary = shallowRef<any>({ run: {}, segments: { data: [], last_page: 1 } });
const trades = shallowRef<any>({ data: [], last_page: 1 });
const curve = shallowRef<any>({ points: [], original_points: 0, returned_points: 0, downsampled: false });
const optimizations = shallowRef<any>({ data: [], last_page: 1 });
const tradePage = ref(1);
const optimizationPage = ref(1);
const error = ref('');
const loading = ref(false);
const optimizationLoading = ref(false);
let summaryController: AbortController | undefined;
let tradeController: AbortController | undefined;
let curveController: AbortController | undefined;
let optimizationController: AbortController | undefined;

async function loadSummary() {
  summaryController?.abort();
  summaryController = new AbortController();
  try {
    summary.value = await get(`/backtests/${route.params.id}`, { page: 1, per_page: 10 }, summaryController.signal);
    error.value = '';
  } catch (exception: any) {
    if (!summaryController.signal.aborted) error.value = exception.message;
  }
}
async function loadTrades() {
  tradeController?.abort();
  tradeController = new AbortController();
  loading.value = true;
  try {
    trades.value = await get(`/backtests/${route.params.id}/trades`, { page: tradePage.value, per_page: 10 }, tradeController.signal);
    error.value = '';
  } catch (exception: any) {
    if (!tradeController.signal.aborted) error.value = exception.message;
  } finally {
    if (!tradeController.signal.aborted) loading.value = false;
  }
}
async function loadCurve() {
  curveController?.abort();
  curveController = new AbortController();
  try {
    curve.value = await get(`/backtests/${route.params.id}/equity`, { max_points: 1000 }, curveController.signal);
  } catch (exception: any) {
    if (!curveController.signal.aborted) error.value = exception.message;
  }
}
async function loadOptimizations() {
  optimizationController?.abort();
  optimizationController = new AbortController();
  optimizationLoading.value = true;
  try {
    optimizations.value = await get(`/backtests/${route.params.id}/optimizations`, { page: optimizationPage.value, per_page: 10 }, optimizationController.signal);
  } catch (exception: any) {
    if (!optimizationController.signal.aborted) error.value = exception.message;
  } finally {
    if (!optimizationController.signal.aborted) optimizationLoading.value = false;
  }
}
watch(() => route.params.id, () => {
  tradePage.value = optimizationPage.value = 1;
  optimizations.value = { data: [], last_page: 1 };
  void Promise.all([loadSummary(), loadTrades(), loadCurve()]);
}, { immediate: true });
watch(tradePage, loadTrades);
watch(optimizationPage, loadOptimizations);
onBeforeUnmount(() => [summaryController, tradeController, curveController, optimizationController].forEach(controller => controller?.abort()));
</script>

<template>
  <q-page padding>
    <div class="page-title"><h1>Backtest Report</h1><q-badge>OUT-OF-SAMPLE clearly separated</q-badge></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }} <q-btn flat label="Retry" @click="loadSummary" /></q-banner>
    <div class="panel">
      <h2>Research summary</h2>
      <div>Run: {{ summary.run?.id }} · Status: {{ summary.run?.status }} · Progress: {{ summary.run?.progress }}%</div>
      <pre class="report">{{ JSON.stringify(summary.run?.metrics || {}, null, 2) }}</pre>
    </div>
    <div class="panel">
      <h2>Equity curve · maximum 1,000 server-selected points</h2>
      <div class="text-caption">{{ curve.returned_points }} of {{ curve.original_points }} points<span v-if="curve.downsampled"> · downsampled with extrema preserved</span></div>
      <EquityChart :points="curve.points || []" />
    </div>
    <h2>Latest 10 simulated trades</h2>
    <q-table flat bordered :rows="trades.data || []" row-key="id" :loading="loading" :pagination="{ rowsPerPage: 0 }" hide-pagination />
    <q-pagination v-model="tradePage" :max="trades.last_page || 1" :max-pages="7" boundary-links direction-links />
    <q-expansion-item label="Optimization results" @show="loadOptimizations">
      <q-table flat bordered :rows="optimizations.data || []" row-key="id" :loading="optimizationLoading" :pagination="{ rowsPerPage: 0 }" hide-pagination />
      <q-pagination v-model="optimizationPage" :max="optimizations.last_page || 1" :max-pages="7" boundary-links direction-links />
    </q-expansion-item>
  </q-page>
</template>
