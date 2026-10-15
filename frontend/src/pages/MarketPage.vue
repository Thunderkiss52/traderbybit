<script setup lang="ts">
import { ref, shallowRef, watch, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import CandleChart from '../components/charts/CandleChart.vue';
import IndicatorPanels from '../components/charts/IndicatorPanels.vue';

const route = useRoute();
const interval = ref('15');
const candles = shallowRef<any[]>([]);
const indicators = shallowRef<any>();
const context = shallowRef<any>();
const signals = shallowRef<any[]>([]);
const error = ref('');
const loading = ref(false);
const history = ref<string[]>([]);
const to = ref<string | undefined>();
let controller: AbortController | undefined;
let version = 0;
async function load() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const params = { interval: interval.value, limit: 300, to: to.value };
    const loaded: any = await get(`/market/${route.params.symbol}/analysis`, params, controller.signal);
    if (current !== version) return;
    candles.value = loaded.candles || [];
    indicators.value = loaded.indicators;
    context.value = loaded.context;
    signals.value = loaded.signals || [];
    error.value = '';
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
function older() {
  if (!candles.value.length) return;
  history.value.push(to.value || '');
  to.value = candles.value[0].open_time;
}
function newer() {
  const previous = history.value.pop();
  to.value = previous || undefined;
}
watch([() => route.params.symbol, interval], () => { history.value = []; to.value = undefined; load(); }, { immediate: true });
watch(to, load);
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">INSTRUMENT</span><h1>{{ route.params.symbol }}</h1></div>
      <q-btn-toggle v-model="interval" :options="[{label:'5m',value:'5'},{label:'15m',value:'15'},{label:'1h',value:'60'}]" />
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-linear-progress v-if="loading" indeterminate color="primary" />
    <div class="row items-center q-gutter-md q-mb-md">
      <q-btn label="Older candles" outline :disable="loading || !candles.length" @click="older" />
      <q-btn label="Newer candles" outline :disable="loading || !history.length" @click="newer" />
      <q-btn label="Latest" flat :disable="loading || !history.length" @click="history = []; to = undefined" />
      <span class="text-caption">{{ candles.length }} closed candles per window · {{ signals.length }} recent signals</span>
    </div>
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
