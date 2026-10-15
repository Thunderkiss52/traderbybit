<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../services/api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const router = useRouter();
const rows = ref<Record<string, unknown>[]>([]);
const page = ref(1);
const pages = ref(1);
const loading = ref(false);
const search = ref('');
const error = ref('');
const endpoint = computed(() => route.meta.endpoint as string);
const pageSize = 10;
const tablePagination = { rowsPerPage: 0 };
let controller: AbortController | undefined;
let requestNumber = 0;
let debounce: ReturnType<typeof setTimeout> | undefined;

async function load() {
  controller?.abort();
  const current = ++requestNumber;
  controller = new AbortController();
  loading.value = true;
  try {
    const response: any = await get(endpoint.value, { page: page.value, search: search.value, per_page: pageSize }, controller.signal);
    if (current !== requestNumber) return;
    rows.value = response.data || [];
    pages.value = response.last_page || 1;
    error.value = '';
  } catch (exception: any) {
    if (current === requestNumber && !controller.signal.aborted) {
      error.value = exception.message;
      rows.value = [];
    }
  } finally {
    if (current === requestNumber) loading.value = false;
  }
}

const exportCsv = () => {
  if (!rows.value.length) return;
  const keys = Object.keys(rows.value[0]);
  const escape = (value: unknown) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  const body = [keys.join(','), ...rows.value.map(row => keys.map(key => escape(row[key])).join(','))].join('\n');
  const url = URL.createObjectURL(new Blob([body], { type: 'text/csv;charset=utf-8' }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `${String(route.meta.title).toLowerCase().replaceAll(' ', '-')}-page-${page.value}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
};
watch([endpoint, page], load, { immediate: true });
watch(search, () => {
  clearTimeout(debounce);
  page.value = 1;
  debounce = setTimeout(load, 300);
});
onBeforeUnmount(() => { controller?.abort(); clearTimeout(debounce); });
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">RESEARCH LEDGER</span><h1>{{ route.meta.title }}</h1></div>
      <div class="actions">
        <q-input v-model="search" dense outlined placeholder="Search" clearable />
        <q-btn outline icon="download" label="CSV (this page)" :disable="!rows.length" @click="exportCsv" />
      </div>
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-table
      flat bordered :rows="rows" :loading="loading" row-key="id"
      :pagination="tablePagination" hide-pagination
      @row-click="(_, row) => route.meta.detail && router.push(`${route.path}/${row.id}`)"
    />
    <EmptyState v-if="!loading && !rows.length && !error" />
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 records per page · Page {{ page }} / {{ pages }}</span>
      <q-pagination v-model="page" :max="pages" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
