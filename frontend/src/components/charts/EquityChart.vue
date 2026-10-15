<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

const props = defineProps<{ points: any[] }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();
const draw = () => chart?.setOption({
  backgroundColor: 'transparent', tooltip: { trigger: 'axis' },
  xAxis: { type: 'category', data: props.points.map(point => point.recorded_at) },
  yAxis: { type: 'value', scale: true },
  series: [{ type: 'line', showSymbol: false, data: props.points.map(point => Number(point.equity)), areaStyle: { opacity: .08 } }],
});
onMounted(() => {
  chart = echarts.init(element.value);
  draw();
  window.addEventListener('resize', resize);
});
watch(() => props.points, draw, { deep: true });
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
});
</script>

<template><div ref="element" class="chart" aria-label="Equity curve" /></template>
