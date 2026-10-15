<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { get } from '../services/api';
import EquityChart from '../components/charts/EquityChart.vue';
import PerformanceCharts from '../components/charts/PerformanceCharts.vue';

const journal = ref<any>({ data: [], last_page: 1 });
const statistics = ref<any>({ metrics: null, equity: [], breakdowns: {} });
const page = ref(1);
const loading = ref(false);
const error = ref('');
let version = 0;
async function loadJournal() {
  const current = ++version;
  loading.value = true;
  try {
    const result: any = await get('/journal', { page: page.value, per_page: 10 });
    if (current === version) { journal.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version) error.value = exception.message;
  } finally {
    if (current === version) loading.value = false;
  }
}
watch(page, loadJournal);
onMounted(async () => {
  await loadJournal();
  try { statistics.value = await get('/statistics', { equity_page: 1, r_page: 1, period_page: 1 }); }
  catch (exception: any) { error.value = exception.message; }
});
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">PAPER LEDGER</span><h1>Trading Journal</h1></div></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="grid">
      <div class="panel"><h2>Recent Equity Curve</h2><EquityChart :points="statistics.equity || []" /></div>
      <div class="panel"><h2>Recent PnL and R</h2><PerformanceCharts :breakdowns="statistics.breakdowns" /></div>
    </div>
    <q-table flat bordered :rows="journal.data || []" row-key="id" :loading="loading" :pagination="{ rowsPerPage: 0 }" hide-pagination />
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 journal events · Page {{ page }} / {{ journal.last_page || 1 }}</span>
      <q-pagination v-model="page" :max="journal.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
