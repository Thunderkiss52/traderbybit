<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { echarts } from '../../services/charts';

const props = defineProps<{ points?: any[] | null }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof echarts.init> | undefined;
const resize = () => chart?.resize();

const isCorrupted = computed(() => {
  if (props.points && !Array.isArray(props.points)) return true;
  if (Array.isArray(props.points) && props.points.some(p => p && typeof p === 'object' && '__PHP_Incomplete_Class_Name' in p)) {
    return true;
  }
  return false;
});

const safePoints = computed(() => {
  if (!Array.isArray(props.points)) return [];
  return props.points.filter(p => p && typeof p === 'object' && !('__PHP_Incomplete_Class_Name' in p));
});

const draw = () => {
  if (!chart || !element.value) return;
  const list = safePoints.value;
  if (!list.length) {
    chart.clear();
    return;
  }
  chart.setOption({
    backgroundColor: 'transparent',
    tooltip: { trigger: 'axis' },
    xAxis: { type: 'category', data: list.map(point => point.recorded_at) },
    yAxis: { type: 'value', scale: true },
    series: [{
      type: 'line',
      showSymbol: false,
      data: list.map(point => Number(point.equity)),
      areaStyle: { opacity: .08 },
    }],
  }, true);
};

onMounted(() => {
  if (element.value) {
    chart = echarts.init(element.value);
    draw();
    window.addEventListener('resize', resize);
  }
});
watch(safePoints, draw);
onBeforeUnmount(() => {
  window.removeEventListener('resize', resize);
  chart?.dispose();
  chart = undefined;
});
</script>

<template>
  <div>
    <div v-if="isCorrupted" class="text-negative q-pa-sm" role="alert">
      Equity data format is invalid or corrupted.
    </div>
    <div v-else-if="!safePoints.length" class="text-grey-6 q-pa-sm">
      No equity snapshots recorded.
    </div>
    <div ref="element" class="chart" aria-label="Equity curve" />
  </div>
</template>
