<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
const route = useRoute();
const record = ref<any>(null);
const error = ref('');
const loading = ref(false);
const executionsPage = ref(1);
const fundingPage = ref(1);
const journalPage = ref(1);
const isPosition = computed(() => route.meta.endpoint === '/positions');
const selected = computed(() => isPosition.value ? record.value?.position : record.value);
const primary = computed(() => {
  if (!selected.value) return {};
  const omitted = new Set(['indicator_snapshot', 'contract', 'reasons', 'risks']);
  return Object.fromEntries(Object.entries(selected.value).filter(([key]) => !omitted.has(key)));
});
let version = 0;
async function load() {
  const current = ++version;
  loading.value = true;
  try {
    const result = await get(`${String(route.meta.endpoint)}/${String(route.params.id)}`, {
      per_page: 10, executions_page: executionsPage.value, funding_page: fundingPage.value, journal_page: journalPage.value,
    });
    if (current === version) { record.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
watch(() => route.fullPath, () => {
  executionsPage.value = fundingPage.value = journalPage.value = 1;
  load();
}, { immediate: true });
watch([executionsPage, fundingPage, journalPage], load);
</script>
<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">AUDIT DETAIL</span><h1>{{ route.meta.title }}</h1></div>
      <q-btn flat icon="arrow_back" label="Back" @click="$router.back()" />
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-linear-progress v-if="loading" indeterminate />
    <div v-if="selected" class="panel">
      <h2>Record</h2>
      <pre class="report">{{ JSON.stringify(primary, null, 2) }}</pre>
      <q-expansion-item v-if="!isPosition" label="Reasons and risk details" expand-separator>
        <pre class="report">{{ JSON.stringify({ reasons: selected.reasons, risks: selected.risks }, null, 2) }}</pre>
      </q-expansion-item>
      <q-expansion-item v-if="!isPosition" label="Indicator snapshot / strategy contract" expand-separator>
        <pre class="report">{{ JSON.stringify({ indicator_snapshot: selected.indicator_snapshot, contract: selected.contract }, null, 2) }}</pre>
      </q-expansion-item>
    </div>
    <template v-if="isPosition && record">
      <div v-for="item in [
        { title: 'Executions', key: 'executions', model: executionsPage },
        { title: 'Funding', key: 'funding_events', model: fundingPage },
        { title: 'Journal', key: 'journal', model: journalPage },
      ]" :key="item.key" class="panel">
        <h2>{{ item.title }} · latest 10 per page</h2>
        <q-table flat bordered :rows="record[item.key]?.data || []" row-key="id" :pagination="{ rowsPerPage: 0 }" hide-pagination />
        <q-pagination v-if="item.key === 'executions'" v-model="executionsPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
        <q-pagination v-else-if="item.key === 'funding_events'" v-model="fundingPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
        <q-pagination v-else v-model="journalPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
      </div>
    </template>
  </q-page>
</template>
