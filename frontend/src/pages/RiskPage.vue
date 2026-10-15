<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import MetricCard from '../components/MetricCard.vue';
const d = shallowRef<any>({ decisions: { data: [], last_page: 1 } });
const page = ref(1);
const loading = ref(false);
const error = ref('');
let version = 0;
let controller: AbortController | undefined;
async function load() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const result = await get('/risk', { page: page.value, per_page: 10 }, controller.signal);
    if (current === version) { d.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally {
    if (current === version) loading.value = false;
  }
}
watch(page, load);
onMounted(load);
onBeforeUnmount(() => controller?.abort());
</script>
<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">RISK CONTROL</span><h1>Portfolio Guardrails</h1></div></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="metrics">
      <MetricCard label="Status" :value="d.account?.status" />
      <MetricCard label="Equity" :value="d.account?.current_equity" />
      <MetricCard label="Reserved Risk" :value="d.account?.reserved_risk" />
      <MetricCard label="Drawdown" :value="d.account?.current_drawdown_percent" />
      <MetricCard label="Max positions" :value="d.limits?.maximum_open_positions" />
    </div>
    <h2>Latest decisions</h2>
    <q-table flat bordered :rows="d.decisions?.data || []" row-key="id" :loading="loading" :pagination="{ rowsPerPage: 0 }" hide-pagination />
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 risk decisions · Page {{ page }} / {{ d.decisions?.last_page || 1 }}</span>
      <q-pagination v-model="page" :max="d.decisions?.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
