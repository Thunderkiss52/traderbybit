<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

type Period = 'daily' | 'weekly' | 'monthly';
type PnlPoint = { period: string; net_pnl: string; cumulative_net_pnl: string };
const props = defineProps<{ periods?: Partial<Record<Period, PnlPoint[]>> }>();
const period = ref<Period>('daily');
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();
const draw = () => {
  const points = props.periods?.[period.value] || [];
  chart?.setOption({
    animation: false,
    tooltip: { trigger: 'axis' },
    legend: { data: ['Net PnL', 'Cumulative Net PnL'] },
    grid: { left: 65, right: 35, top: 50, bottom: 55 },
    xAxis: { type: 'category', data: points.map(p => p.period) },
    yAxis: { type: 'value', scale: true },
    series: [
      { name: 'Net PnL', type: 'bar', data: points.map(p => Number(p.net_pnl)) },
      { name: 'Cumulative Net PnL', type: 'line', data: points.map(p => Number(p.cumulative_net_pnl)) },
    ],
  }, true);
};
onMounted(() => {
  if (element.value) chart = echarts.init(element.value);
  draw();
  window.addEventListener('resize', resize);
});
watch(() => props.periods, draw, { deep: true });
watch(period, draw);
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
});
</script>

<template>
  <div>
    <q-btn-toggle v-model="period" no-caps dense unelevated toggle-color="primary" :options="[
      { label: 'Daily', value: 'daily' },
      { label: 'Weekly', value: 'weekly' },
      { label: 'Monthly', value: 'monthly' },
    ]" />
    <div v-if="!(periods?.[period]?.length)" class="muted q-py-md">No closed paper trades for this period.</div>
    <div ref="element" class="chart" aria-label="Realized paper net PnL by period" />
  </div>
</template>
