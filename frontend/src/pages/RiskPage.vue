<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import { useSettings } from '../stores/settings';
import type { QTableColumn } from 'quasar';
import MetricCard from '../components/MetricCard.vue';

const settings = useSettings();
const d = shallowRef<any>({ decisions: { data: [], last_page: 1 }, accounts: [] });
const page = ref(1);
const loading = ref(false);
const error = ref('');
const selectedAccount = ref<string>(settings.account || '');

const accountOptions = computed(() => (d.value?.accounts || []).map((a: any) => ({
  label: `${a.name} (${a.strategy_id}) [${a.status}]`,
  value: a.id,
})));

const riskColumns: QTableColumn[] = [
  { name: 'decided_at', label: 'Time (UTC)', field: 'decided_at', align: 'left', sortable: true },
  { name: 'signal_id', label: 'Signal ID', field: (r: any) => r.signal_id ? r.signal_id.slice(0, 8) + '...' : '—', align: 'left' },
  { name: 'allowed', label: 'Decision', field: 'allowed', align: 'center', sortable: true },
  { name: 'reasons', label: 'Reasons & Checks', field: (r: any) => Array.isArray(r.reasons) ? r.reasons.join(', ') : (r.reasons || '—'), align: 'left' },
];
let version = 0;
let controller: AbortController | undefined;
async function load() {
  controller?.abort();
  controller = new AbortController();
  const current = ++version;
  loading.value = true;
  try {
    const params: Record<string, unknown> = { page: page.value, per_page: 10 };
    if (selectedAccount.value) {
      params.account = selectedAccount.value;
    }
    const result: any = await get('/risk', params, controller.signal);
    if (current === version) {
      d.value = result;
      if (result.account?.id && !selectedAccount.value) {
        selectedAccount.value = result.account.id;
      }
      error.value = '';
    }
  } catch (exception: any) {
    if (current === version && !controller.signal.aborted) error.value = exception.message;
  } finally {
    if (current === version) loading.value = false;
  }
}

watch(page, load);
watch(selectedAccount, (val) => {
  settings.account = val;
  page.value = 1;
  load();
});
watch(() => settings.account, (val) => {
  if (val !== selectedAccount.value) {
    selectedAccount.value = val;
  }
});
watch(() => settings.exchange, () => {
  selectedAccount.value = '';
  page.value = 1;
  load();
});

onMounted(load);
onBeforeUnmount(() => controller?.abort());
</script>
<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">RISK CONTROL</span><h1>Portfolio Guardrails</h1></div>
      <q-badge v-if="d.account?.status" :color="d.account.status === 'ACTIVE' ? 'positive' : (d.account.status === 'BLOCKED' ? 'warning' : 'negative')">
        {{ d.account.status }}
      </q-badge>
    </div>
    <div class="row items-center justify-between q-mb-md q-col-gutter-sm">
      <div class="col-12 col-sm-auto">
        <q-select
          v-model="selectedAccount"
          :options="accountOptions"
          dense
          outlined
          emit-value
          map-options
          label="Paper Account"
          style="min-width: 320px"
        />
      </div>
      <div class="col-12 col-sm-auto text-caption text-grey">
        Account: {{ d.account?.name || 'Selected' }} · {{ (d.account?.exchange_id || settings.exchange).toUpperCase() }}
      </div>
    </div>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="metrics">
      <MetricCard label="Status" :value="d.account?.status" />
      <MetricCard label="Equity" :value="d.account?.current_equity" />
      <MetricCard label="Reserved Risk" :value="d.account?.reserved_risk" />
      <MetricCard label="Drawdown" :value="d.account?.current_drawdown_percent" />
      <MetricCard label="Max positions" :value="d.limits?.maximum_open_positions" />
    </div>
    <q-table
      flat
      bordered
      :rows="d.decisions?.data || []"
      :columns="riskColumns"
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
            <template v-if="col.name === 'allowed'">
              <q-badge
                :color="props.row.allowed ? 'positive' : 'negative'"
                :label="props.row.allowed ? 'ALLOWED' : 'REJECTED'"
              />
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
              <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Risk Decision Snapshot & Guardrail Evaluation:</div>
              <pre class="bg-grey-10 text-grey-2 q-pa-sm rounded-borders text-caption" style="max-height: 250px; overflow: auto; white-space: pre-wrap; font-family: monospace;">{{ props.row.snapshot ? JSON.stringify(props.row.snapshot, null, 2) : 'No snapshot recorded' }}</pre>
            </div>
          </q-td>
        </q-tr>
      </template>
    </q-table>
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 risk decisions · Page {{ page }} / {{ d.decisions?.last_page || 1 }}</span>
      <q-pagination v-model="page" :max="d.decisions?.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
