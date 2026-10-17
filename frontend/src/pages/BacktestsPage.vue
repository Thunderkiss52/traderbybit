<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get, startBacktest } from '../services/api';

import type { QTableColumn } from 'quasar';

const rows = shallowRef<any[]>([]);
const page = ref(1);
const lastPage = ref(1);
const error = ref('');
const listLoading = ref(false);

const backtestColumns: QTableColumn[] = [
  { name: 'type', label: 'Type', field: 'type', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'center', sortable: true },
  { name: 'progress', label: 'Progress', field: (r: any) => `${r.progress ?? 0}%`, align: 'right' },
  { name: 'symbols', label: 'Symbols', field: (r: any) => Array.isArray(r.symbols) ? r.symbols.join(', ') : (r.symbols || '—'), align: 'left' },
  { name: 'from_at', label: 'From', field: (r: any) => r.from_at ? r.from_at.slice(0, 10) : '—', align: 'left' },
  { name: 'to_at', label: 'To', field: (r: any) => r.to_at ? r.to_at.slice(0, 10) : '—', align: 'left' },
  { name: 'strategy_version', label: 'Version', field: 'strategy_version', align: 'left' },
  { name: 'created_at', label: 'Created (UTC)', field: 'created_at', align: 'left', sortable: true },
];
const form = ref({
  symbols: ['BTCUSDT'], from: '', to: '', mode: 'split',
  starting_balance: '', strategy_version: '', execution_model_version: '',
});
const message = ref('');
const loading = ref(false);
let version = 0;
let controller: AbortController | undefined;
async function load() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  listLoading.value = true;
  try {
    const response: any = await get('/backtests', { page: page.value, per_page: 10 }, controller.signal);
    if (current !== version) return;
    rows.value = response.data || [];
    lastPage.value = response.last_page || 1;
    error.value = '';
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) { rows.value = []; error.value = exception.message; }
  } finally {
    if (current === version) listLoading.value = false;
  }
}
const submit = async () => {
  loading.value = true;
  try {
    await startBacktest(form.value);
    message.value = 'Research job queued';
    page.value = 1;
    await load();
  } catch (exception: any) {
    message.value = exception.message;
  } finally { loading.value = false; }
};
watch(page, load);
onMounted(async () => {
  try {
    const dashboard: any = await get('/dashboard');
    Object.assign(form.value, dashboard.research_settings || {});
  } catch (exception: any) { error.value = exception.message; }
  await load();
});
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">HISTORICAL LAB</span><h1>Backtests</h1></div></div>
    <q-card flat bordered class="panel">
      <h2>New research run</h2>
      <div class="form">
        <q-select v-model="form.symbols" multiple :options="['BTCUSDT','ETHUSDT']" label="Symbols" />
        <q-input v-model="form.from" type="date" label="From" />
        <q-input v-model="form.to" type="date" label="To" />
        <q-select v-model="form.mode" :options="['split','walk_forward']" label="Mode" />
        <q-input v-model="form.starting_balance" readonly label="Starting balance (USDT)" />
        <q-input v-model="form.strategy_version" readonly label="Strategy version" />
        <q-input v-model="form.execution_model_version" readonly label="Execution model" />
        <q-btn color="primary" label="Queue research backtest" :loading="loading" @click="submit" />
      </div>
      <p>{{ message }}</p>
    </q-card>
    <q-table
      flat
      bordered
      :rows="rows"
      :columns="backtestColumns"
      row-key="id"
      :loading="listLoading"
      :pagination="{ rowsPerPage: 0 }"
      hide-pagination
      @row-click="(_, row) => $router.push(`/backtests/${row.id}`)"
    >
      <template #body-cell-status="props">
        <q-td :props="props">
          <q-badge
            :color="props.value === 'COMPLETED' ? 'positive' : (['RUNNING', 'QUEUED'].includes(props.value) ? 'primary' : 'negative')"
            :label="props.value"
          />
        </q-td>
      </template>
    </q-table>
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 backtests · Page {{ page }} / {{ lastPage }}</span>
      <q-pagination v-model="page" :max="lastPage" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
