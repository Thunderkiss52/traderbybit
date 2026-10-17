<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import EquityChart from '../components/charts/EquityChart.vue';
import PerformanceCharts from '../components/charts/PerformanceCharts.vue';

const journal = shallowRef<any>({ data: [], next_page_url: null });
const statistics = shallowRef<any>({ metrics: null, equity: [], breakdowns: {} });
const accounts = ref<any[]>([]);
const account = ref('');
const days = ref(7);
const eventType = ref('');
const page = ref(1);
const pages = computed(() => journal.value?.last_page || (journal.value?.next_page_url ? page.value + 1 : Math.max(1, page.value)));
const loading = ref(false);
const chartsLoading = ref(false);
const showCharts = ref(false);
const error = ref('');
let version = 0;
let controller: AbortController | undefined;
let chartsController: AbortController | undefined;
async function loadJournal() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const result: any = await get('/journal', {
      page: page.value, per_page: 10, period_days: days.value,
      ...(account.value ? { account: account.value } : {}),
      ...(eventType.value ? { event_type: eventType.value } : {}),
    }, controller.signal);
    if (current === version) { journal.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
function resetPage() { if (page.value === 1) loadJournal(); else page.value = 1; showCharts.value = false; chartsController?.abort(); }
async function loadCharts() {
  if (chartsLoading.value) return;
  chartsController?.abort();
  chartsController = new AbortController();
  chartsLoading.value = true;
  try {
    statistics.value = await get('/statistics', {
      ...(account.value ? { account: account.value } : {}), equity_page: 1, r_page: 1, period_page: 1,
    }, chartsController.signal);
    showCharts.value = true;
  } catch (exception: any) {
    if (!chartsController.signal.aborted) error.value = exception.message;
  } finally { chartsLoading.value = false; }
}
watch(page, loadJournal);
watch([days, account, eventType], resetPage);
onMounted(async () => {
  loadJournal();
  try { accounts.value = (await get<any[]>('/journal/accounts')).slice(0, 50); } catch { /* journal remains usable */ }
});
onBeforeUnmount(() => { controller?.abort(); chartsController?.abort(); });
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">PAPER LEDGER</span><h1>Trading Journal</h1></div></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-12 col-sm-4"><q-select v-model="account" dense outlined emit-value map-options
        :options="[{ label: 'All paper accounts', value: '' }, ...accounts.map(a => ({ label: `${a.name} (${a.strategy_id})`, value: a.id }))]" label="Portfolio" /></div>
      <div class="col-6 col-sm-4"><q-select v-model="days" dense outlined emit-value map-options
        :options="[{label:'24 hours',value:1},{label:'7 days',value:7},{label:'14 days',value:14},{label:'30 days',value:30}]" label="Period" /></div>
      <div class="col-6 col-sm-4"><q-input v-model="eventType" dense outlined label="Event type" clearable /></div>
    </div>
    <q-table flat bordered :rows="journal.data || []" row-key="id" :loading="loading"
      :pagination="{ rowsPerPage: 0 }" hide-pagination />
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">10 events per page · Page {{ page }}</span>
      <q-pagination v-model="page" :max="pages" :max-pages="5" direction-links :disable="loading" />
    </div>
    <div class="q-mt-lg">
      <q-btn outline icon="monitoring" label="Load optional performance charts" :loading="chartsLoading" @click="loadCharts" />
      <div v-if="showCharts" class="grid q-mt-md">
        <div class="panel"><h2>Recent Equity Curve</h2><EquityChart :points="statistics.equity || []" /></div>
        <div class="panel"><h2>Recent PnL and R</h2><PerformanceCharts :breakdowns="statistics.breakdowns" /></div>
      </div>
    </div>
  </q-page>
</template>
