<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const rows = ref<any[]>([]);
const page = ref(1);
const pages = ref(1);
const loading = ref(false);
const search = ref('');
const error = ref('');
const endpoint = computed(() => route.meta.endpoint as string);

const load = async () => {
  loading.value = true;
  try {
    const response: any = await get(endpoint.value, { page: page.value, search: search.value, per_page: 25 });
    rows.value = response.data || response;
    pages.value = response.last_page || 1;
    error.value = '';
  } catch (exception: any) {
    error.value = exception.message;
  } finally {
    loading.value = false;
  }
};

const exportCsv = () => {
  if (!rows.value.length) return;
  const keys = Object.keys(rows.value[0]);
  const escape = (value: unknown) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  const body = [keys.join(','), ...rows.value.map(row => keys.map(key => escape(row[key])).join(','))].join('\n');
  const url = URL.createObjectURL(new Blob([body], { type: 'text/csv;charset=utf-8' }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `${String(route.meta.title).toLowerCase().replaceAll(' ', '-')}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
};

watch([endpoint, page], load, { immediate: true });
let debounce: number | undefined;
watch(search, () => {
  window.clearTimeout(debounce);
  debounce = window.setTimeout(load, 300);
});
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">RESEARCH LEDGER</span><h1>{{ route.meta.title }}</h1></div>
      <div class="actions">
        <q-input v-model="search" dense outlined placeholder="Search" clearable />
        <q-btn outline icon="download" label="CSV" :disable="!rows.length" @click="exportCsv" />
      </div>
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-table
      flat bordered :rows="rows" :loading="loading" row-key="id"
      :pagination="{ rowsPerPage: 25 }" hide-pagination
      @row-click="(_, row) => route.meta.detail && $router.push(`${route.path}/${row.id}`)"
    />
    <EmptyState v-if="!loading && !rows.length" />
    <q-pagination v-model="page" :max="pages" />
  </q-page>
</template>
