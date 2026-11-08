<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import { useSettings } from '../stores/settings';
import EquityChart from '../components/charts/EquityChart.vue';
import PerformanceCharts from '../components/charts/PerformanceCharts.vue';

import type { QTableColumn } from 'quasar';

const settings = useSettings();
const journal = shallowRef<any>({ data: [], next_page_url: null });
const statistics = shallowRef<any>({ metrics: null, equity: [], breakdowns: {} });
const accounts = ref<any[]>([]);
const account = ref(settings.account || '');
const days = ref(7);
const eventType = ref('');
const page = ref(1);
const pages = computed(() => journal.value?.last_page || (journal.value?.next_page_url ? page.value + 1 : Math.max(1, page.value)));
const loading = ref(false);
const chartsLoading = ref(false);
const showCharts = ref(false);
const error = ref('');

const journalColumns: QTableColumn[] = [
  { name: 'sequence', label: '#', field: 'sequence', align: 'right', sortable: true },
  { name: 'event_type', label: 'Event Type', field: 'event_type', align: 'left', sortable: true },
  { name: 'position_id', label: 'Position', field: (r: any) => r.position_id ? r.position_id.slice(0, 8) + '...' : '—', align: 'left' },
  { name: 'signal_id', label: 'Signal', field: (r: any) => r.signal_id ? r.signal_id.slice(0, 8) + '...' : '—', align: 'left' },
  { name: 'occurred_at', label: 'Occurred (UTC)', field: 'occurred_at', align: 'left', sortable: true },
];
let version = 0;
let controller: AbortController | undefined;
let chartsController: AbortController | undefined;
async function loadJournal() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const result: any = await get('/journal', {
      page: page.value, per_page: 10, period_days: days.value,
      ...(account.value ? { account: account.value } : {}),
      ...(eventType.value ? { event_type: eventType.value } : {}),
    }, controller.signal);
    if (current === version) { journal.value = result; error.value = ''; }
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally { if (current === version) loading.value = false; }
}
function resetPage() { if (page.value === 1) loadJournal(); else page.value = 1; showCharts.value = false; chartsController?.abort(); }
async function loadCharts() {
  if (chartsLoading.value) return;
  chartsController?.abort();
  chartsController = new AbortController();
  chartsLoading.value = true;
  try {
    statistics.value = await get('/statistics', {
      ...(account.value ? { account: account.value } : {}), equity_page: 1, r_page: 1, period_page: 1,
    }, chartsController.signal);
    showCharts.value = true;
  } catch (exception: any) {
    if (!chartsController.signal.aborted) error.value = exception.message;
  } finally { chartsLoading.value = false; }
}
async function reloadAccounts() {
  try {
    accounts.value = (await get<any[]>('/journal/accounts')).slice(0, 50);
  } catch {
    /* journal remains usable */
  }
}
watch(page, loadJournal);
watch(account, (val) => {
  settings.account = val;
});
watch(() => settings.account, (val) => {
  if (val !== account.value) {
    account.value = val;
  }
});
watch(() => settings.exchange, () => {
  account.value = '';
  page.value = 1;
  reloadAccounts();
  loadJournal();
});
watch([days, account, eventType], resetPage);
onMounted(() => {
  loadJournal();
  reloadAccounts();
});
onBeforeUnmount(() => { controller?.abort(); chartsController?.abort(); });
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">PAPER LEDGER</span><h1>Trading Journal</h1></div></div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-12 col-sm-4"><q-select v-model="account" dense outlined emit-value map-options
        :options="[{ label: 'All paper accounts', value: '' }, ...accounts.map(a => ({ label: `${a.name} (${a.strategy_id})`, value: a.id }))]" label="Portfolio" /></div>
      <div class="col-6 col-sm-4"><q-select v-model="days" dense outlined emit-value map-options
        :options="[{label:'24 hours',value:1},{label:'7 days',value:7},{label:'14 days',value:14},{label:'30 days',value:30}]" label="Period" /></div>
      <div class="col-6 col-sm-4"><q-input v-model="eventType" dense outlined label="Event type" clearable /></div>
    </div>
    <q-table
      flat
      bordered
      :rows="journal.data || []"
      :columns="journalColumns"
      row-key="id"
      :loading="loading"
      :pagination="{ rowsPerPage: 0 }"
      hide-pagination
    >
      <template #header="props">
        <q-tr :props="props">
          <q-th auto-width />
          <q-th v-for="col in props.cols" :key="col.name" :props="props">
            {{ col.label }}
          </q-th>
        </q-tr>
      </template>
      <template #body="props">
        <q-tr :props="props">
          <q-td auto-width>
            <q-btn size="sm" color="grey-6" round dense flat @click="props.expand = !props.expand" :icon="props.expand ? 'expand_less' : 'expand_more'" />
          </q-td>
          <q-td v-for="col in props.cols" :key="col.name" :props="props">
            <template v-if="col.name === 'event_type'">
              <q-badge color="blue-grey-8" :label="col.value" />
            </template>
            <template v-else-if="col.name === 'signal_id' && props.row.signal_id">
              <router-link :to="`/signals/${props.row.signal_id}`" class="text-primary text-bold" style="text-decoration:none;">
                {{ col.value }}
              </router-link>
            </template>
            <template v-else>
              {{ col.value }}
            </template>
          </q-td>
        </q-tr>
        <q-tr v-show="props.expand" :props="props">
          <q-td colspan="100%">
            <div class="q-pa-sm">
              <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Event Audit Payload:</div>
              <pre class="bg-grey-10 text-grey-2 q-pa-sm rounded-borders text-caption" style="max-height: 250px; overflow: auto; white-space: pre-wrap; font-family: monospace;">{{ props.row.payload ? JSON.stringify(props.row.payload, null, 2) : 'No payload recorded' }}</pre>
            </div>
          </q-td>
        </q-tr>
      </template>
    </q-table>
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">10 events per page · Page {{ page }}</span>
      <q-pagination v-model="page" :max="pages" :max-pages="5" direction-links :disable="loading" />
    </div>
    <div class="q-mt-lg">
      <q-btn outline icon="monitoring" label="Load optional performance charts" :loading="chartsLoading" @click="loadCharts" />
      <div v-if="showCharts" class="grid q-mt-md">
        <div class="panel"><h2>Recent Equity Curve</h2><EquityChart :points="statistics.equity || []" /></div>
        <div class="panel"><h2>Recent PnL and R</h2><PerformanceCharts :breakdowns="statistics.breakdowns" /></div>
      </div>
    </div>
  </q-page>
</template>
