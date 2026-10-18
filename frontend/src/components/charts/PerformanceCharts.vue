<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

const props = defineProps<{ breakdowns?: any }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();

const safeArray = (val: any): any[] => {
  if (!Array.isArray(val)) return [];
  return val.filter(i => i && typeof i === 'object' && !('__PHP_Incomplete_Class_Name' in i));
};

const isCorrupted = computed(() => {
  const b = props.breakdowns;
  if (!b || typeof b !== 'object') return false;
  for (const key of ['symbols', 'sides', 'quality_classes']) {
    if (key in b) {
      if (!Array.isArray(b[key])) return true;
      if (b[key].some((i: any) => i && typeof i === 'object' && '__PHP_Incomplete_Class_Name' in i)) return true;
    }
  }
  if ('r_distribution' in b && !Array.isArray(b.r_distribution)) return true;
  return false;
});

const draw = () => {
  if (!chart || !element.value) return;
  const data = props.breakdowns || {};
  const symbols = safeArray(data.symbols);
  const sides = safeArray(data.sides);
  const qualityClasses = safeArray(data.quality_classes);
  const rDistribution = Array.isArray(data.r_distribution)
    ? data.r_distribution.filter((r: any) => typeof r !== 'object')
    : [];

  const groups = [...symbols, ...sides, ...qualityClasses];
  if (!groups.length && !rDistribution.length) {
    chart.clear();
    return;
  }

  chart.setOption({
    animation: false,
    tooltip: { trigger: 'axis' },
    legend: { data: ['Net PnL', 'R distribution'] },
    grid: [{ left: 55, right: '53%', top: 50, bottom: 50 }, { left: '55%', right: 30, top: 50, bottom: 50 }],
    xAxis: [
      { type: 'category', data: groups.map((item: any) => item?.symbol || item?.side || item?.quality_class || '') },
      { type: 'category', gridIndex: 1, data: rDistribution.map((_: unknown, index: number) => index + 1) },
    ],
    yAxis: [{ type: 'value' }, { type: 'value', gridIndex: 1, scale: true }],
    series: [
      { name: 'Net PnL', type: 'bar', data: groups.map((item: any) => Number(item?.net_pnl ?? 0)), xAxisIndex: 0, yAxisIndex: 0 },
      { name: 'R distribution', type: 'bar', data: rDistribution.map(Number), xAxisIndex: 1, yAxisIndex: 1 },
    ],
  }, true);
};

onMounted(() => {
  if (element.value) {
    chart = echarts.init(element.value);
    draw();
    window.addEventListener('resize', resize);
  }
});
watch(() => props.breakdowns, draw);
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
  chart = undefined;
});
</script>

<template>
  <div>
    <div v-if="isCorrupted" class="text-negative q-py-sm" role="alert">
      Breakdown data format is invalid or corrupted.
    </div>
    <div ref="element" class="chart" aria-label="PnL breakdown and R distribution" />
  </div>
</template>
