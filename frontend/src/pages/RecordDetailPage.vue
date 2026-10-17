<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';
import type { QTableColumn } from 'quasar';

const route = useRoute();
const record = shallowRef<any>(null);
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

const executionColumns: QTableColumn[] = [
  { name: 'market_event_at', label: 'Time (UTC)', field: 'market_event_at', align: 'left', sortable: true },
  { name: 'type', label: 'Type', field: 'type', align: 'center', sortable: true },
  { name: 'quantity', label: 'Qty', field: (r: any) => r.quantity ? Number(r.quantity).toFixed(4) : '—', align: 'right' },
  { name: 'price', label: 'Price', field: (r: any) => r.price ? Number(r.price).toFixed(2) : '—', align: 'right' },
  { name: 'gross_pnl', label: 'Gross PnL', field: (r: any) => r.gross_pnl ? Number(r.gross_pnl).toFixed(2) : '0.00', align: 'right' },
  { name: 'commission', label: 'Fee', field: (r: any) => r.commission ? Number(r.commission).toFixed(4) : '0.00', align: 'right' },
  { name: 'slippage', label: 'Slippage', field: (r: any) => r.slippage ? Number(r.slippage).toFixed(4) : '0.00', align: 'right' },
];

const fundingColumns: QTableColumn[] = [
  { name: 'funding_at', label: 'Time (UTC)', field: 'funding_at', align: 'left', sortable: true },
  { name: 'rate', label: 'Rate', field: (r: any) => r.rate ? `${(Number(r.rate) * 100).toFixed(4)}%` : '—', align: 'right' },
  { name: 'amount', label: 'Amount (USDT)', field: (r: any) => r.amount ? Number(r.amount).toFixed(4) : '0.00', align: 'right' },
];

const journalSubColumns: QTableColumn[] = [
  { name: 'sequence', label: '#', field: 'sequence', align: 'right', sortable: true },
  { name: 'event_type', label: 'Event Type', field: 'event_type', align: 'left', sortable: true },
  { name: 'occurred_at', label: 'Occurred (UTC)', field: 'occurred_at', align: 'left', sortable: true },
];

function getColumns(key: string): QTableColumn[] {
  if (key === 'executions') return executionColumns;
  if (key === 'funding_events') return fundingColumns;
  return journalSubColumns;
}
let version = 0;
let controller: AbortController | undefined;
async function load() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const result = await get(`${String(route.meta.endpoint)}/${String(route.params.id)}`, {
      per_page: 10, executions_page: executionsPage.value, funding_page: fundingPage.value, journal_page: journalPage.value,
    }, controller.signal);
    if (current === version) { record.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
watch(() => route.fullPath, () => {
  executionsPage.value = fundingPage.value = journalPage.value = 1;
  load();
}, { immediate: true });
watch([executionsPage, fundingPage, journalPage], load);
onBeforeUnmount(() => controller?.abort());
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
        <q-table
          flat
          bordered
          :rows="record[item.key]?.data || []"
          :columns="getColumns(item.key)"
          row-key="id"
          :pagination="{ rowsPerPage: 0 }"
          hide-pagination
        >
          <template #body-cell-type="props">
            <q-td :props="props">
              <q-badge
                :color="String(props.value).includes('TAKE_PROFIT') ? 'positive' : (String(props.value).includes('STOP_LOSS') ? 'negative' : 'primary')"
                :label="props.value"
              />
            </q-td>
          </template>
        </q-table>
        <q-pagination v-if="item.key === 'executions'" v-model="executionsPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
        <q-pagination v-else-if="item.key === 'funding_events'" v-model="fundingPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
        <q-pagination v-else v-model="journalPage" :max="record[item.key]?.last_page || 1" :max-pages="5" />
      </div>
    </template>
  </q-page>
</template>
