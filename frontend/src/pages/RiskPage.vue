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
      <template #body-cell-allowed="props">
        <q-td :props="props">
          <q-badge
            :color="props.value ? 'positive' : 'negative'"
            :label="props.value ? 'ALLOWED' : 'REJECTED'"
          />
        </q-td>
      </template>
    </q-table>
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest 10 risk decisions · Page {{ page }} / {{ d.decisions?.last_page || 1 }}</span>
      <q-pagination v-model="page" :max="d.decisions?.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>
