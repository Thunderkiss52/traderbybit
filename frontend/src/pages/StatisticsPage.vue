<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { get } from '../services/api';
import { usePolling } from '../composables/usePolling';
import { useSettings } from '../stores/settings';
import MetricCard from '../components/MetricCard.vue';
import EquityChart from '../components/charts/EquityChart.vue';
import PerformanceCharts from '../components/charts/PerformanceCharts.vue';
import PeriodPnlChart from '../components/charts/PeriodPnlChart.vue';

const data = shallowRef<any>({ metrics: null, equity: [], breakdowns: {}, period_pnl: {}, data_quality: {} });
const equityPage = ref(1);
const rPage = ref(1);
const periodPage = ref(1);
const periodSeries = computed(() => ({
  daily: data.value.period_pnl?.daily?.data || [],
  weekly: data.value.period_pnl?.weekly?.data || [],
  monthly: data.value.period_pnl?.monthly?.data || [],
}));
const periodPages = computed(() => Math.max(1, ...['daily', 'weekly', 'monthly'].map(name => data.value.period_pnl?.[name]?.last_page || 1)));
const error = ref('');
const controller = new AbortController();
const { autoRefresh } = storeToRefs(useSettings());
const { loading, refresh } = usePolling(async () => {
  try {
    data.value = await get('/statistics', { equity_page: equityPage.value, r_page: rPage.value, period_page: periodPage.value }, controller.signal);
    error.value = '';
  } catch (exception: any) {
    error.value = exception.message;
    throw exception;
  }
}, 60000, autoRefresh);
watch([equityPage, rPage, periodPage], refresh);
onBeforeUnmount(() => controller.abort());
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">FORWARD PAPER PERFORMANCE</span><h1>Virtual Trading Statistics</h1></div></div>
    <q-banner class="bg-grey-9 text-white q-mb-md">Simulation only; historical backtest performance is shown separately.</q-banner>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-inner-loading :showing="loading && !data.metrics" />
    <div v-if="data.metrics" class="metrics">
      <MetricCard v-for="key in [
        'total_signals','buy_signals','sell_signals','skip_signals','opened_positions',
        'winning_trades','losing_trades','win_rate','profit_factor','average_r','expectancy',
        'gross_pnl','net_pnl','total_commissions','total_slippage_cost','total_funding_payments',
        'maximum_drawdown_percent','average_holding_seconds','closed_positions',
      ]" :key="key" :label="key.replaceAll('_',' ')" :value="data.metrics[key]" />
    </div>
    <q-banner v-else>No completed paper trading observations yet.</q-banner>
    <q-banner v-if="data.data_quality?.ambiguous_execution_positions || data.data_quality?.incomplete_funding_positions || data.data_quality?.awaiting_market_data_positions" class="bg-warning text-dark q-mt-sm">
      Model limitations: ambiguous OHLC executions ({{ data.data_quality?.ambiguous_execution_positions || 0 }}),
      incomplete funding ({{ data.data_quality?.incomplete_funding_positions || 0 }}),
      positions waiting for market data ({{ data.data_quality?.awaiting_market_data_positions || 0 }}).
    </q-banner>
    <div class="grid q-mt-md">
      <div class="panel">
        <h2>Equity Curve · 100 snapshots per page</h2>
        <EquityChart :points="data.equity || []" />
        <q-pagination v-model="equityPage" :max="data.equity_pagination?.last_page || 1" :max-pages="5" boundary-links direction-links />
      </div>
      <div class="panel">
        <h2>Net PnL by period · 10 periods per page</h2>
        <PeriodPnlChart :periods="periodSeries" />
        <q-pagination v-model="periodPage" :max="periodPages" :max-pages="5" boundary-links direction-links />
      </div>
      <div class="panel">
        <h2>PnL by symbol, side, class / R</h2>
        <PerformanceCharts :breakdowns="data.breakdowns" />
        <div class="text-caption">R distribution: 10 latest trades per page</div>
        <q-pagination v-model="rPage" :max="data.r_pagination?.last_page || 1" :max-pages="5" boundary-links direction-links />
      </div>
    </div>
  </q-page>
</template>
