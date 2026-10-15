<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

const props = defineProps<{ breakdowns?: any }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();
const draw = () => {
  const data = props.breakdowns || {};
  const groups = [...(data.symbols || []), ...(data.sides || []), ...(data.quality_classes || [])];
  chart?.setOption({
    animation: false,
    tooltip: { trigger: 'axis' },
    legend: { data: ['Net PnL', 'R distribution'] },
    grid: [{ left: 55, right: '53%', top: 50, bottom: 50 }, { left: '55%', right: 30, top: 50, bottom: 50 }],
    xAxis: [
      { type: 'category', data: groups.map(item => item.symbol || item.side || item.quality_class) },
      { type: 'category', gridIndex: 1, data: (data.r_distribution || []).map((_: unknown, index: number) => index + 1) },
    ],
    yAxis: [{ type: 'value' }, { type: 'value', gridIndex: 1, scale: true }],
    series: [
      { name: 'Net PnL', type: 'bar', data: groups.map(item => Number(item.net_pnl)), xAxisIndex: 0, yAxisIndex: 0 },
      { name: 'R distribution', type: 'bar', data: (data.r_distribution || []).map(Number), xAxisIndex: 1, yAxisIndex: 1 },
    ],
  }, true);
};
onMounted(() => {
  chart = echarts.init(element.value);
  draw();
  window.addEventListener('resize', resize);
});
watch(() => props.breakdowns, draw);
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
  chart = undefined;
});
</script>

<template><div ref="element" class="chart" aria-label="PnL breakdown and R distribution" /></template>
