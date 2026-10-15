<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import EquityChart from '../components/charts/EquityChart.vue';
const route = useRoute();
const d = ref<any>({ run: {}, reports: [], trades: { data: [], last_page: 1 }, equity: [], equity_pagination: { last_page: 1 } });
const page = ref(1);
const equityPage = ref(1);
const error = ref('');
const loading = ref(false);
let version = 0;
async function load() {
  const current = ++version;
  loading.value = true;
  try {
    const response = await get(`/backtests/${route.params.id}/report`, { page: page.value, equity_page: equityPage.value, per_page: 10 });
    if (current === version) { d.value = response; error.value = ''; }
  } catch (exception: any) {
    if (current === version) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
watch([() => route.params.id, page, equityPage], load, { immediate: true });
</script>
<template>
  <q-page padding>
    <div class="page-title"><h1>Backtest Report</h1><q-badge>OUT-OF-SAMPLE clearly separated</q-badge></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="panel">
      <h2>Research summary</h2>
      <div>Run: {{ d.run?.id }} · Status: {{ d.run?.status }} · Progress: {{ d.run?.progress }}%</div>
      <pre class="report">{{ JSON.stringify(d.run?.metrics || {}, null, 2) }}</pre>
      <div class="text-caption">Report types: {{ (d.reports || []).map((report: any) => report.kind).join(', ') || 'None' }}</div>
    </div>
    <div class="panel">
      <h2>Equity · 100 points per page</h2>
      <EquityChart :points="d.equity || []" />
      <q-pagination v-model="equityPage" :max="d.equity_pagination?.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
    <h2>Latest 10 simulated trades</h2>
    <q-table flat bordered :rows="d.trades?.data || []" row-key="id" :loading="loading" :pagination="{ rowsPerPage: 0 }" hide-pagination />
    <q-pagination v-model="page" :max="d.trades?.last_page || 1" :max-pages="7" boundary-links direction-links />
  </q-page>
</template>
