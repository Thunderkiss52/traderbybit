<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

type Period = 'daily' | 'weekly' | 'monthly';
type PnlPoint = { period: string; net_pnl: string; cumulative_net_pnl: string };
const props = defineProps<{ periods?: Partial<Record<Period, PnlPoint[]>> | null }>();
const period = ref<Period>('daily');
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();

const rawPoints = computed(() => {
  const current = props.periods?.[period.value];
  if (!Array.isArray(current)) return [];
  return current.filter(p => p && typeof p === 'object' && !('__PHP_Incomplete_Class_Name' in p));
});

const isCorrupted = computed(() => {
  const current = props.periods?.[period.value];
  if (current && !Array.isArray(current)) return true;
  if (Array.isArray(current) && current.some(p => p && typeof p === 'object' && '__PHP_Incomplete_Class_Name' in p)) {
    return true;
  }
  return false;
});

const draw = () => {
  if (!chart || !element.value) return;
  const points = rawPoints.value;
  if (!points.length) {
    chart.clear();
    return;
  }
  chart.setOption({
    animation: false,
    tooltip: { trigger: 'axis' },
    legend: { data: ['Net PnL', 'Cumulative Net PnL'] },
    grid: { left: 65, right: 35, top: 50, bottom: 55 },
    xAxis: { type: 'category', data: points.map(p => p?.period ?? '') },
    yAxis: { type: 'value', scale: true },
    series: [
      { name: 'Net PnL', type: 'bar', data: points.map(p => Number(p?.net_pnl ?? 0)) },
      { name: 'Cumulative Net PnL', type: 'line', data: points.map(p => Number(p?.cumulative_net_pnl ?? 0)) },
    ],
  }, true);
};

onMounted(() => {
  if (element.value) chart = echarts.init(element.value);
  draw();
  window.addEventListener('resize', resize);
});
watch(() => props.periods, draw);
watch(period, draw);
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
  chart = undefined;
});
</script>

<template>
  <div>
    <q-btn-toggle v-model="period" no-caps dense unelevated toggle-color="primary" :options="[
      { label: 'Daily', value: 'daily' },
      { label: 'Weekly', value: 'weekly' },
      { label: 'Monthly', value: 'monthly' },
    ]" />
    <div v-if="isCorrupted" class="text-negative q-py-sm" role="alert">
      Period PnL data format is invalid or corrupted.
    </div>
    <div v-else-if="!rawPoints.length" class="muted q-py-md">
      No closed paper trades for this period.
    </div>
    <div ref="element" class="chart" aria-label="Realized paper net PnL by period" />
  </div>
</template>
