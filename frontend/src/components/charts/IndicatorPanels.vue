<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

const props = defineProps<{ indicators?: any }>();
const element = ref<HTMLElement>();
let chart: echarts.ECharts | undefined;
const resize = () => chart?.resize();

const draw = () => {
  const payload = props.indicators;
  const series = payload?.series;
  if (!chart || !series) return chart?.clear();
  const times = (payload.times || []).map((time: string) => new Date(time).toISOString());
  chart.setOption({
    animation: false,
    backgroundColor: 'transparent',
    tooltip: { trigger: 'axis' },
    legend: [{ data: ['RSI', 'ADX', '+DI', '-DI'] }, { top: '34%', data: ['MACD', 'Signal', 'Histogram'] }],
    grid: [{ top: 32, height: '25%' }, { top: '42%', height: '22%' }, { top: '75%', height: '18%' }],
    xAxis: [0, 1, 2].map((gridIndex) => ({ type: 'category', gridIndex, data: times, axisLabel: { show: gridIndex === 2 } })),
    yAxis: [0, 1, 2].map((gridIndex) => ({ type: 'value', gridIndex, scale: true })),
    series: [
      { name: 'RSI', type: 'line', showSymbol: false, data: series.rsi, xAxisIndex: 0, yAxisIndex: 0 },
      { name: 'ADX', type: 'line', showSymbol: false, data: series.adx?.adx, xAxisIndex: 0, yAxisIndex: 0 },
      { name: '+DI', type: 'line', showSymbol: false, data: series.adx?.plusDi, xAxisIndex: 0, yAxisIndex: 0 },
      { name: '-DI', type: 'line', showSymbol: false, data: series.adx?.minusDi, xAxisIndex: 0, yAxisIndex: 0 },
      { name: 'MACD', type: 'line', showSymbol: false, data: series.macd?.macd, xAxisIndex: 1, yAxisIndex: 1 },
      { name: 'Signal', type: 'line', showSymbol: false, data: series.macd?.signal, xAxisIndex: 1, yAxisIndex: 1 },
      { name: 'Histogram', type: 'bar', data: series.macd?.histogram, xAxisIndex: 1, yAxisIndex: 1 },
      { name: 'Relative Volume', type: 'bar', data: series.volume?.relativeVolume, xAxisIndex: 2, yAxisIndex: 2 },
    ],
  });
};

onMounted(() => {
  chart = echarts.init(element.value);
  draw();
  window.addEventListener('resize', resize);
});
watch(() => props.indicators, draw, { deep: true });
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
});
</script>

<template><div ref="element" class="indicator-panels" aria-label="RSI MACD ADX and volume panels" /></template>
