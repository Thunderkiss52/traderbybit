<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { get } from '../services/api';
import EquityChart from '../components/charts/EquityChart.vue';
import PerformanceCharts from '../components/charts/PerformanceCharts.vue';

const journal = ref<any>({ data: [] });
const statistics = ref<any>({ metrics: null, equity: [], breakdowns: {} });
const page = ref(1);
const load = async () => {
  [journal.value, statistics.value] = await Promise.all([
    get('/journal', { page: page.value, per_page: 25 }),
    get('/statistics'),
  ]);
};
onMounted(load);
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">PAPER LEDGER</span><h1>Trading Journal</h1></div></div>
    <div class="grid">
      <div class="panel"><h2>Equity Curve</h2><EquityChart :points="statistics.equity || []" /></div>
      <div class="panel"><h2>PnL and R</h2><PerformanceCharts :breakdowns="statistics.breakdowns" /></div>
    </div>
    <q-table flat bordered :rows="journal.data || []" row-key="id" :pagination="{ rowsPerPage: 25 }" hide-pagination />
    <q-pagination v-model="page" :max="journal.last_page || 1" @update:model-value="load" />
  </q-page>
</template>
