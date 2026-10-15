<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { get } from '../services/api';
import { usePolling } from '../composables/usePolling';
import { useSettings } from '../stores/settings';
import MetricCard from './MetricCard.vue';
import DailyPerformanceChart from './charts/DailyPerformanceChart.vue';
const data = shallowRef<any>({ summary: null, days: { data: [], meta: {} }, data_quality: {} });
const page = ref(1); const symbol = ref('ALL'); const error = ref('');
const { autoRefresh, timezone, account } = storeToRefs(useSettings());
const apiTimezone = computed(() => timezone.value === 'local' ? Intl.DateTimeFormat().resolvedOptions().timeZone : timezone.value);
let controller: AbortController | undefined;
const load = async () => {
  controller?.abort(); controller = new AbortController();
  try {
    data.value = await get('/paper/daily-performance', { page: page.value, per_page: 10, symbol: symbol.value, timezone: apiTimezone.value, ...(account.value ? { account: account.value } : {}) }, controller.signal);
    error.value = '';
  } catch (exception: any) { if (exception.name !== 'CanceledError') error.value = exception.message; throw exception; }
};
const { loading, refresh } = usePolling(load, 60000, autoRefresh);
watch([symbol, timezone, account], () => { page.value = 1; refresh(); });
watch(page, refresh);
onBeforeUnmount(() => controller?.abort());
const summaries = computed(() => [['Today', data.value.summary?.today], ['Yesterday', data.value.summary?.yesterday], ['Last 7 Days', data.value.summary?.last_7_days], ['Last 30 Days', data.value.summary?.last_30_days], ['All Time', data.value.summary?.all_time]]);
</script>
<template>
  <section>
    <div class="row items-center justify-between q-gutter-sm"><div><h2 class="q-mb-none">Daily Performance</h2><div class="muted">Forward paper data · {{ data.timezone || apiTimezone }}</div></div><q-select v-model="symbol" dense outlined :options="['ALL','BTCUSDT','ETHUSDT']" label="Instrument" style="min-width:160px" /></div>
    <q-banner v-if="error" class="bg-negative text-white q-my-sm">{{ error }} <q-btn flat label="Retry" @click="refresh" /></q-banner>
    <q-inner-loading :showing="loading && !data.summary" />
    <div v-if="data.summary" class="metrics q-mt-md"><MetricCard v-for="([label,value]) in summaries" :key="label as string" :label="`${label} Net PnL (USDT / %)`" :value="value?.net_pnl === null ? 'Incomplete' : `${value?.net_pnl ?? '—'} / ${value?.return_percent ?? '—'}%`" /></div>
    <q-banner v-if="data.data_quality?.limitations?.length" class="bg-warning text-dark q-my-sm">Incomplete data: {{ data.data_quality.limitations.join(', ') }}. Missing values are never estimated.</q-banner>
    <div class="grid q-mt-md">
      <div class="panel"><h3>Daily PnL & Cumulative Equity</h3><DailyPerformanceChart :days="data.days?.data || []" /></div>
      <div class="panel"><h3>Performance Calendar</h3><div class="performance-calendar"><div v-for="day in data.days?.data || []" :key="day.date" class="calendar-day" :class="day.net_pnl === null ? 'unknown' : Number(day.net_pnl) >= 0 ? 'profit' : 'loss'"><b>{{ day.date }}</b><span>{{ day.net_pnl ?? 'Incomplete' }} USDT</span><small>{{ day.closed_positions }} closed · {{ day.win_rate ?? '—' }}% win · {{ day.average_r ?? '—' }}R</small></div></div><q-pagination v-model="page" :max="data.days?.meta?.last_page || 1" :max-pages="5" boundary-links direction-links class="q-mt-md" /></div>
    </div>
    <q-table flat bordered class="q-mt-md" :rows="data.days?.data || []" row-key="date" :pagination="{ rowsPerPage: 0 }" hide-pagination :columns="[
      {name:'date',label:'Date',field:'date',align:'left'},{name:'net',label:'Net PnL',field:'net_pnl'},
      {name:'return',label:'Return %',field:'return_percent'},{name:'realized',label:'Realized',field:'realized_pnl'},
      {name:'unrealized',label:'Unrealized change',field:'unrealized_pnl_change'},{name:'fees',label:'Commissions',field:'commissions'},
      {name:'funding',label:'Funding',field:'funding'},{name:'opened',label:'Opened',field:'opened_positions'},
      {name:'closed',label:'Closed',field:'closed_positions'},{name:'win',label:'Win rate %',field:'win_rate'},{name:'r',label:'Average R',field:'average_r'}]" />
  </section>
</template>
