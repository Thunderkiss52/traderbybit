<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get } from '../services/api';
import { useSettings } from '../stores/settings';
import EmptyState from '../components/EmptyState.vue';

const route = useRoute();
const router = useRouter();
const settings = useSettings();
const rows = ref<Record<string, unknown>[]>([]);
const page = ref(1);
const pages = ref(1);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const error = ref('');
const accounts = ref<any[]>([]);
const selectedAccount = ref<string>(settings.account || '');
const endpoint = computed(() => route.meta.endpoint as string);
const pageSize = ref(10); // pageSize = 10
const pageSizeOptions = [10, 25, 50, 100];
const tablePagination = { rowsPerPage: 0 };
let controller: AbortController | undefined;
let requestNumber = 0;
let debounce: ReturnType<typeof setTimeout> | undefined;

function formatDuration(seconds: unknown): string {
  if (typeof seconds !== 'number' || isNaN(seconds) || seconds < 0) return '—';
  if (seconds < 60) return `${Math.floor(seconds)}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  return `${hours}h ${mins}m`;
}

function formatDecimal(val: unknown, decimals = 2): string {
  if (val === null || val === undefined || val === '') return '—';
  const num = Number(val);
  return isNaN(num) ? String(val) : num.toFixed(decimals);
}

import type { QTableColumn } from 'quasar';

const signalColumns: QTableColumn[] = [
  { name: 'symbol', label: 'Symbol', field: 'symbol', align: 'left', sortable: true },
  { name: 'side', label: 'Side', field: 'side', align: 'center', sortable: true },
  { name: 'quality_class', label: 'Class', field: 'quality_class', align: 'center', sortable: true },
  { name: 'strategy', label: 'Strategy', field: (r: any) => `${r.strategy_id || '—'} v${r.strategy_version || ''}`, align: 'left' },
  { name: 'score', label: 'Score', field: (r: any) => formatDecimal(r.score, 1), align: 'right' },
  { name: 'entry_zone', label: 'Entry Zone', field: (r: any) => r.entry_low ? `${formatDecimal(r.entry_low)} – ${formatDecimal(r.entry_high)}` : '—', align: 'right' },
  { name: 'stop_loss', label: 'Stop Loss', field: (r: any) => formatDecimal(r.stop_loss), align: 'right' },
  { name: 'take_profit', label: 'TP 1 / TP 2', field: (r: any) => r.take_profit_1 ? `${formatDecimal(r.take_profit_1)}${r.take_profit_2 ? ' / ' + formatDecimal(r.take_profit_2) : ''}` : '—', align: 'right' },
  { name: 'risk_approved', label: 'Risk Gate', field: 'risk_approved', align: 'center' },
  { name: 'execution_status', label: 'Paper Status', field: 'paper_execution_status', align: 'center' },
  { name: 'decision_time', label: 'Decision (UTC)', field: 'decision_time', align: 'left', sortable: true },
];

const positionColumns: QTableColumn[] = [
  { name: 'symbol', label: 'Symbol', field: 'symbol', align: 'left', sortable: true },
  { name: 'side', label: 'Side', field: 'side', align: 'center', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'center', sortable: true },
  { name: 'entry_price', label: 'Entry Price', field: (r: any) => formatDecimal(r.entry_price), align: 'right' },
  { name: 'current_price', label: 'Current / Ref', field: (r: any) => formatDecimal(r.current_reference_price), align: 'right' },
  { name: 'net_pnl', label: 'Net PnL', field: (r: any) => r.net_pnl !== undefined ? formatDecimal(r.net_pnl) : '—', align: 'right' },
  { name: 'r_multiple', label: 'R-Multiple', field: (r: any) => { const v = r.r_multiple; if (v === null || v === undefined) return '—'; const n = Number(v); if (!Number.isFinite(n)) return '—'; return `${n > 0 ? '+' : ''}${formatDecimal(n)} R`; }, align: 'right' },
  { name: 'duration', label: 'Duration', field: (r: any) => formatDuration(r.duration_seconds), align: 'right' },
  { name: 'opened_at', label: 'Opened (UTC)', field: 'opened_at', align: 'left', sortable: true },
];

const columns = computed<QTableColumn[]>(() => {
  if (endpoint.value === '/signals') return signalColumns;
  if (endpoint.value === '/positions') return positionColumns;
  if (!rows.value.length) return [];
  const omitted = new Set(['contract', 'indicator_snapshot', 'reasons', 'risks']);
  return Object.keys(rows.value[0])
    .filter(k => !omitted.has(k))
    .slice(0, 10)
    .map(key => ({
      name: key,
      label: key.replace(/_/g, ' ').toUpperCase(),
      field: key,
      align: 'left' as const,
    }));
});

const isSignals = computed(() => endpoint.value === '/signals');
const isPositions = computed(() => endpoint.value === '/positions');

const accountOptions = computed(() => [
  { label: 'All Accounts', value: '' },
  ...accounts.value.map((a: any) => ({
    label: `${a.name} (${a.strategy_id})`,
    value: a.id,
  })),
]);

async function load() {
  controller?.abort();
  const current = ++requestNumber;
  controller = new AbortController();
  loading.value = true;
  try {
    const params: Record<string, unknown> = {
      page: page.value,
      search: search.value,
      per_page: pageSize.value,
    };
    if (selectedAccount.value) {
      params.account = selectedAccount.value;
    }
    const response: any = await get(
      endpoint.value,
      params,
      controller.signal
    );
    if (current !== requestNumber) return;
    rows.value = response.data || [];
    pages.value = response.last_page || 1;
    total.value = response.total ?? (response.data?.length || 0);
    error.value = '';
  } catch (exception: any) {
    if (current === requestNumber && !controller.signal.aborted) {
      error.value = exception.message;
      rows.value = [];
      total.value = 0;
    }
  } finally {
    if (current === requestNumber) loading.value = false;
  }
}

const exportCsv = () => {
  if (!rows.value.length) return;
  const omitted = new Set(['contract', 'indicator_snapshot']);
  const keys = Object.keys(rows.value[0]).filter(k => !omitted.has(k));
  const escape = (value: unknown) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  const body = [keys.join(','), ...rows.value.map(row => keys.map(key => escape(row[key])).join(','))].join('\n');
  const url = URL.createObjectURL(new Blob([body], { type: 'text/csv;charset=utf-8' }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `${String(route.meta.title).toLowerCase().replaceAll(' ', '-')}-page-${page.value}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
};

async function reloadAccounts() {
  try {
    accounts.value = await get<any[]>('/journal/accounts');
  } catch {
    // fallback gracefully
  }
}

watch([endpoint, page, pageSize, selectedAccount], () => {
  load();
}, { immediate: true });

watch(selectedAccount, (val) => {
  settings.account = val;
});

watch(() => settings.account, (val) => {
  if (val !== selectedAccount.value) {
    selectedAccount.value = val;
  }
});

watch(() => settings.exchange, () => {
  selectedAccount.value = '';
  page.value = 1;
  reloadAccounts();
  load();
});

watch(search, () => {
  clearTimeout(debounce);
  page.value = 1;
  debounce = setTimeout(load, 300);
});

onMounted(reloadAccounts);

onBeforeUnmount(() => { controller?.abort(); clearTimeout(debounce); });
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">RESEARCH LEDGER</span>
        <h1>{{ route.meta.title }}</h1>
      </div>
      <div class="actions row items-center q-gutter-sm">
        <q-select
          v-if="isSignals || isPositions"
          v-model="selectedAccount"
          :options="accountOptions"
          dense
          outlined
          emit-value
          map-options
          label="Account filter"
          style="min-width: 220px"
        />
        <q-input v-model="search" dense outlined placeholder="Search symbol..." clearable style="min-width: 200px" />
        <q-select v-model="pageSize" :options="pageSizeOptions" dense outlined label="Per page" style="min-width: 100px" />
        <q-btn outline icon="download" label="CSV (page)" :disable="!rows.length" @click="exportCsv" />
      </div>
    </div>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>{{ error }}</q-banner>

    <q-table
      flat
      bordered
      :rows="rows"
      :columns="columns"
      :loading="loading"
      row-key="id"
      :pagination="tablePagination"
      hide-pagination
      class="ledger-table"
      @row-click="(_, row) => route.meta.detail && router.push(`${route.path}/${row.id}`)"
    >
      <template #body-cell-symbol="props">
        <q-td :props="props" class="text-bold">
          {{ props.value }}
        </q-td>
      </template>

      <template #body-cell-side="props">
        <q-td :props="props">
          <q-badge
            :color="props.value === 'BUY' ? 'positive' : (props.value === 'SELL' ? 'negative' : 'grey-7')"
            :label="props.value"
          />
        </q-td>
      </template>

      <template #body-cell-quality_class="props">
        <q-td :props="props">
          <q-badge
            :color="props.value === 'A' ? 'amber-9' : (props.value === 'B' ? 'blue-8' : 'blue-grey-6')"
            :label="props.value || '—'"
          />
        </q-td>
      </template>

      <template #body-cell-risk_approved="props">
        <q-td :props="props">
          <q-badge v-if="props.value === true" color="positive" label="APPROVED" />
          <q-badge v-else-if="props.value === false" color="negative" label="REJECTED" />
          <span v-else class="text-grey">—</span>
        </q-td>
      </template>

      <template #body-cell-execution_status="props">
        <q-td :props="props">
          <q-badge
            v-if="props.value === 'FILLED' || props.value === 'COMPLETED'"
            color="positive"
            :label="props.value"
          />
          <q-badge
            v-else-if="props.value === 'REJECTED' || props.value === 'EXPIRED'"
            color="warning"
            :label="props.value"
          />
          <q-badge v-else-if="props.value" color="blue-grey" :label="props.value" />
          <span v-else class="text-grey">—</span>
        </q-td>
      </template>

      <template #body-cell-status="props">
        <q-td :props="props">
          <q-badge
            :color="
              props.value === 'OPEN'
                ? 'primary'
                : (String(props.value).includes('TP')
                  ? 'positive'
                  : (String(props.value).includes('SL') ? 'negative' : 'grey-7'))
            "
            :label="props.value"
          />
        </q-td>
      </template>

      <template #body-cell-net_pnl="props">
        <q-td
          :props="props"
          :class="Number(props.row.net_pnl) > 0 ? 'text-positive text-bold' : (Number(props.row.net_pnl) < 0 ? 'text-negative text-bold' : '')"
        >
          {{ props.value }}
        </q-td>
      </template>

      <template #body-cell-r_multiple="props">
        <q-td
          :props="props"
          :class="Number(props.row.r_multiple) > 0 ? 'text-positive text-bold' : (Number(props.row.r_multiple) < 0 ? 'text-negative text-bold' : '')"
        >
          {{ props.value }}
        </q-td>
      </template>
    </q-table>

    <EmptyState v-if="!loading && !rows.length && !error" />

    <div class="row items-center justify-between q-mt-md">
      <div class="text-caption text-grey">
        Showing {{ rows.length ? (page - 1) * pageSize + 1 : 0 }}–{{ Math.min(page * pageSize, total || rows.length) }}
        of {{ total || rows.length }} records · Page {{ page }} / {{ pages }}
      </div>
      <q-pagination
        v-model="page"
        :max="pages"
        :max-pages="7"
        boundary-links
        direction-links
        :disable="loading"
      />
    </div>
  </q-page>
</template>

<style scoped>
.ledger-table :deep(tbody tr) {
  cursor: pointer;
}
.ledger-table :deep(tbody tr:hover) {
  background-color: rgba(255, 255, 255, 0.04);
}
</style>
