<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import CandleChart from '../components/charts/CandleChart.vue';
import IndicatorPanels from '../components/charts/IndicatorPanels.vue';

const route = useRoute();
const interval = ref('15');
const candles = ref<any[]>([]);
const indicators = ref<any>();
const context = ref<any>();
const signals = ref<any[]>([]);
const error = ref('');
const loading = ref(false);

const load = async () => {
  loading.value = true;
  try {
    const [loadedCandles, loadedIndicators, loadedContext, loadedSignals]: any[] = await Promise.all([
      get(`/market/${route.params.symbol}/candles`, { interval: interval.value, limit: 500 }),
      get(`/market/${route.params.symbol}/indicators`, { interval: interval.value, limit: 500 }),
      get(`/market/${route.params.symbol}/context`),
      get('/signals', { symbol: route.params.symbol, per_page: 100 }),
    ]);
    candles.value = loadedCandles;
    indicators.value = loadedIndicators;
    context.value = loadedContext;
    signals.value = loadedSignals.data || [];
    error.value = '';
  } catch (exception: any) {
    error.value = exception.message;
  } finally {
    loading.value = false;
  }
};

watch([() => route.params.symbol, interval], load, { immediate: true });
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">INSTRUMENT</span><h1>{{ route.params.symbol }}</h1></div>
      <q-btn-toggle v-model="interval" :options="[{label:'5m',value:'5'},{label:'15m',value:'15'},{label:'1h',value:'60'}]" />
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-linear-progress v-if="loading" indeterminate color="primary" />
    <CandleChart :candles="candles" :indicators="indicators" :signals="signals" />
    <IndicatorPanels :indicators="indicators" />
    <div class="panel">
      <h2>Market context</h2>
      <div class="muted">Spread {{ context?.spread ?? '—' }} · Funding {{ context?.funding_rate ?? '—' }} · Open interest {{ context?.open_interest ?? '—' }}</div>
      <p>EMA 20/50/200 · Bollinger Bands · VWAP · Volume · RSI · MACD · ADX · confirmed support/resistance.</p>
      <div class="muted">Backend-calculated values from closed candles only. No frontend recalculation or look-ahead.</div>
    </div>
  </q-page>
</template>
