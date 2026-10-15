<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';
const props = defineProps<{ days: any[] }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();
const draw = () => {
  const days = [...props.days].reverse();
  chart?.setOption({ animation: false, tooltip: { trigger: 'axis' }, legend: { data: ['Daily Net PnL', 'Closing Equity'] },
    grid: { left: 65, right: 65, top: 50, bottom: 55 }, xAxis: { type: 'category', data: days.map(day => day.date) },
    yAxis: [{ type: 'value', scale: true }, { type: 'value', scale: true }], series: [
      { name: 'Daily Net PnL', type: 'bar', data: days.map(day => day.net_pnl === null ? null : ({ value: Number(day.net_pnl), itemStyle: { color: Number(day.net_pnl) >= 0 ? '#21ba45' : '#c10015' } })) },
      { name: 'Closing Equity', type: 'line', yAxisIndex: 1, showSymbol: false, connectNulls: false, data: days.map(day => day.closing_equity === null ? null : Number(day.closing_equity)) },
    ] }, true);
};
onMounted(() => { if (element.value) chart = echarts.init(element.value); draw(); window.addEventListener('resize', resize); });
watch(() => props.days, draw);
onBeforeUnmount(() => { window.removeEventListener('resize', resize); chart?.dispose(); chart = undefined; });
</script>
<template><div ref="element" class="chart" aria-label="Daily paper PnL and cumulative equity" /></template>
