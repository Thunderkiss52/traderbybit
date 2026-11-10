<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import { useSettings } from '../stores/settings';
import type { QTableColumn } from 'quasar';
import MetricCard from '../components/MetricCard.vue';

const settings = useSettings();
const d = shallowRef<any>({ decisions: { data: [], last_page: 1 }, accounts: [], gate_policy: {}, directional_performance: {}, rejection_reasons: {} });
const page = ref(1);
const loading = ref(false);
const error = ref('');
const selectedAccount = ref<string>(settings.account || '');
const qualityFilter = ref<string>('all');
const riskFilter = ref<string>('all');
const allowedFilter = ref<string>('all');

const accountOptions = computed(() => [
  { label: 'All Accounts (Aggregated)', value: 'all' },
  ...(d.value?.accounts || []).map((a: any) => ({
    label: `${a.name} (${a.strategy_id}) [${a.status}]`,
    value: a.id,
  })),
]);

const qualityOptions = [
  { label: 'All Signal Qualities', value: 'all' },
  { label: 'HIGH (A)', value: 'HIGH' },
  { label: 'MEDIUM (B)', value: 'MEDIUM' },
  { label: 'LOW (C)', value: 'LOW' },
  { label: 'UNKNOWN', value: 'UNKNOWN' },
];

const riskFilterOptions = [
  { label: 'All Trade Risks', value: 'all' },
  { label: 'LOW Risk', value: 'LOW' },
  { label: 'MEDIUM Risk', value: 'MEDIUM' },
  { label: 'HIGH Risk', value: 'HIGH' },
  { label: 'UNKNOWN Risk', value: 'UNKNOWN' },
];

const allowedOptions = [
  { label: 'All Decisions', value: 'all' },
  { label: 'Allowed Only', value: 'true' },
  { label: 'Rejected Only', value: 'false' },
];

const riskColumns: QTableColumn[] = [
  { name: 'decided_at', label: 'Time (UTC)', field: 'decided_at', align: 'left', sortable: true },
  { name: 'signal_id', label: 'Signal ID', field: (r: any) => r.signal_id ? r.signal_id.slice(0, 8) + '...' : '—', align: 'left' },
  { name: 'signal_quality', label: 'Signal Quality', field: (r: any) => r.signal_quality || r.snapshot?.signal_quality || 'UNKNOWN', align: 'center', sortable: true },
  { name: 'trade_risk', label: 'Trade Risk', field: (r: any) => r.trade_risk || r.snapshot?.trade_risk || 'UNKNOWN', align: 'center', sortable: true },
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
    const params: Record<string, unknown> = {
      page: page.value,
      per_page: 10,
      exchange: settings.exchange,
    };
    if (settings.instrument && settings.instrument !== 'all') {
      params.instrument = settings.instrument;
      if (settings.resolvedSymbol) params.symbol = settings.resolvedSymbol;
    }
    if (selectedAccount.value && selectedAccount.value !== 'all') {
      params.account = selectedAccount.value;
    } else if (selectedAccount.value === 'all') {
      params.account = 'all';
    }
    if (qualityFilter.value !== 'all') {
      params.quality = qualityFilter.value;
    }
    if (riskFilter.value !== 'all') {
      params.risk = riskFilter.value;
    }
    if (allowedFilter.value !== 'all') {
      params.allowed = allowedFilter.value;
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
watch([selectedAccount, qualityFilter, riskFilter, allowedFilter], () => {
  if (selectedAccount.value && selectedAccount.value !== 'all') {
    settings.account = selectedAccount.value;
  }
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

watch(() => settings.instrument, () => {
  page.value = 1;
  load();
});

onMounted(load);
onBeforeUnmount(() => controller?.abort());

function qualityColor(quality: string) {
  switch (quality) {
    case 'HIGH': return 'cyan-8';
    case 'MEDIUM': return 'primary';
    case 'LOW': return 'orange-8';
    default: return 'blue-grey-6';
  }
}

function riskColor(risk: string) {
  switch (risk) {
    case 'LOW': return 'positive';
    case 'MEDIUM': return 'warning';
    case 'HIGH': return 'negative';
    default: return 'blue-grey-6';
  }
}

function gateDecisionClass(quality: string, risk: string): { label: string; color: string } {
  if (quality === 'HIGH') {
    if (risk === 'LOW') return { label: 'ELIGIBLE', color: 'positive' };
    if (risk === 'MEDIUM') return { label: 'EDGE-CHECK', color: 'info' };
    if (risk === 'HIGH') return { label: 'REJECT', color: 'negative' };
    return { label: 'UNKNOWN', color: 'grey-7' };
  }
  if (quality === 'MEDIUM') {
    if (risk === 'LOW') return { label: 'CONDITIONAL', color: 'info' };
    if (risk === 'MEDIUM') return { label: 'EVIDENCE-REQ', color: 'warning' };
    if (risk === 'HIGH') return { label: 'REJECT', color: 'negative' };
    return { label: 'UNKNOWN', color: 'grey-7' };
  }
  if (quality === 'LOW') {
    return { label: 'OBSERVATION ONLY', color: 'orange-9' };
  }
  return { label: 'REJECT (NO UNKNOWN ENTRY)', color: 'negative' };
}
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div>
        <span class="eyebrow">RISK ARCHITECTURE & SYSTEMATIC QUALITY GATE</span>
        <h1>Portfolio Guardrails & Execution Risk</h1>
      </div>
      <q-badge v-if="d.account?.status" :color="d.account.status === 'ACTIVE' ? 'positive' : (d.account.status === 'BLOCKED' ? 'warning' : 'negative')">
        {{ d.account.status }}
      </q-badge>
    </div>

    <!-- Account Selection & Context Banner -->
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
          style="min-width: 340px"
        />
      </div>
      <div class="col-12 col-sm-auto text-caption text-grey">
        Exchange: <strong class="text-white">{{ (d.account?.exchange_id || settings.exchange).toUpperCase() }}</strong> · Account: <strong class="text-white">{{ d.account?.name || 'All Accounts' }}</strong>
      </div>
    </div>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>

    <!-- Top Account Guardrail Metrics -->
    <div class="metrics q-mb-md">
      <MetricCard label="Account Status" :value="d.account?.status || 'AGGREGATE'" />
      <MetricCard label="Virtual Equity" :value="d.account?.current_equity" />
      <MetricCard label="Reserved Risk" :value="d.account?.reserved_risk" />
      <MetricCard label="Drawdown" :value="d.account?.current_drawdown_percent ? d.account.current_drawdown_percent + '%' : '0%'" />
      <MetricCard label="Max Open Positions" :value="d.limits?.maximum_open_positions" />
      <MetricCard label="Max Risk / Trade" :value="d.limits?.maximum_risk_per_trade_percent ? (d.limits.maximum_risk_per_trade_percent * 100) + '%' : '1.5%'" />
    </div>

    <!-- Directional Performance & Gate Policy Visualizer -->
    <div class="row q-col-gutter-md q-mb-md">
      <!-- Left: Directional Attribution (LONG vs SHORT) -->
      <div class="col-12 col-lg-6">
        <q-card flat bordered class="q-pa-md bg-dark" style="height: 100%">
          <div class="row items-center justify-between q-mb-sm">
            <div class="text-subtitle1 text-weight-bold">
              <q-icon name="swap_vert" color="cyan" class="q-mr-xs" />
              Directional Execution Attribution
            </div>
            <q-badge color="grey-9" label="BUY (Long) vs SELL (Short)" />
          </div>

          <div class="text-caption text-grey-4 q-mb-sm">
            Autonomous performance breakdown preventing indiscriminate execution and revealing directional loss concentration.
          </div>

          <div class="row q-col-gutter-sm">
            <!-- LONG (BUY) Column -->
            <div class="col-6">
              <div class="bg-grey-10 q-pa-sm rounded-borders">
                <div class="text-caption text-weight-bold text-positive row items-center">
                  <q-icon name="arrow_upward" class="q-mr-xs" />
                  LONG (BUY) Cohort
                </div>
                <div class="q-mt-xs text-caption">
                  <div>Positions: <strong class="text-white">{{ d.directional_performance?.directional?.LONG?.closed_positions || 0 }}</strong> closed ({{ d.directional_performance?.directional?.LONG?.opened_positions || 0 }} total)</div>
                  <div>Win Rate: <strong class="text-white">{{ d.directional_performance?.directional?.LONG?.win_rate_percent }}%</strong></div>
                  <div>Profit Factor: <strong class="text-white">{{ d.directional_performance?.directional?.LONG?.profit_factor || '—' }}</strong></div>
                  <div>Net PnL: <strong :class="Number(d.directional_performance?.directional?.LONG?.total_net_pnl || 0) >= 0 ? 'text-positive' : 'text-negative'">{{ d.directional_performance?.directional?.LONG?.total_net_pnl || '0' }} USDT</strong></div>
                  <div>Fees Paid: <span class="text-grey-4">{{ d.directional_performance?.directional?.LONG?.total_fees || '0' }} USDT</span></div>
                  <div>Avg R: <span class="text-cyan">{{ d.directional_performance?.directional?.LONG?.avg_r || '—' }}</span></div>
                </div>
              </div>
            </div>

            <!-- SHORT (SELL) Column -->
            <div class="col-6">
              <div class="bg-grey-10 q-pa-sm rounded-borders">
                <div class="text-caption text-weight-bold text-negative row items-center">
                  <q-icon name="arrow_downward" class="q-mr-xs" />
                  SHORT (SELL) Cohort
                </div>
                <div class="q-mt-xs text-caption">
                  <div>Positions: <strong class="text-white">{{ d.directional_performance?.directional?.SHORT?.closed_positions || 0 }}</strong> closed ({{ d.directional_performance?.directional?.SHORT?.opened_positions || 0 }} total)</div>
                  <div>Win Rate: <strong class="text-white">{{ d.directional_performance?.directional?.SHORT?.win_rate_percent }}%</strong></div>
                  <div>Profit Factor: <strong class="text-white">{{ d.directional_performance?.directional?.SHORT?.profit_factor || '—' }}</strong></div>
                  <div>Net PnL: <strong :class="Number(d.directional_performance?.directional?.SHORT?.total_net_pnl || 0) >= 0 ? 'text-positive' : 'text-negative'">{{ d.directional_performance?.directional?.SHORT?.total_net_pnl || '0' }} USDT</strong></div>
                  <div>Fees Paid: <span class="text-grey-4">{{ d.directional_performance?.directional?.SHORT?.total_fees || '0' }} USDT</span></div>
                  <div>Avg R: <span class="text-cyan">{{ d.directional_performance?.directional?.SHORT?.avg_r || '—' }}</span></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Loss Concentration Insight -->
          <div v-if="d.directional_performance?.loss_concentration" class="q-mt-sm bg-grey-9 q-pa-xs rounded-borders text-caption">
            <div class="text-grey-4 text-xs">
              <q-icon name="warning" color="warning" class="q-mr-xs" />
              Loss Concentration: Directional Loss Share = <strong class="text-white">{{ d.directional_performance.loss_concentration.directional_loss_share?.LONG }}% Long / {{ d.directional_performance.loss_concentration.directional_loss_share?.SHORT }}% Short</strong>
            </div>
          </div>
        </q-card>
      </div>

      <!-- Right: Pre-Execution Quality × Risk Gate Matrix -->
      <div class="col-12 col-lg-6">
        <q-card flat bordered class="q-pa-md bg-dark" style="height: 100%">
          <div class="row items-center justify-between q-mb-sm">
            <div class="text-subtitle1 text-weight-bold">
              <q-icon name="grid_view" color="purple-4" class="q-mr-xs" />
              Signal Quality × Trade Risk Gate Matrix
            </div>
            <q-badge color="positive" label="SYSTEMATIC FILTER ACTIVE" />
          </div>

          <div class="text-caption text-grey-4 q-mb-sm">
            Execution eligibility policy requiring independent opportunity edge (Quality) and prospective hazard (Risk) assessment.
          </div>

          <div class="q-table__container q-table--horizontal-separator rounded-borders bg-grey-10">
            <table class="q-table" style="font-size: 0.75rem;">
              <thead>
                <tr>
                  <th class="text-left text-grey-4">Signal Quality \ Trade Risk</th>
                  <th class="text-center text-positive">LOW RISK</th>
                  <th class="text-center text-warning">MEDIUM RISK</th>
                  <th class="text-center text-negative">HIGH RISK</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="text-weight-bold text-cyan-4">HIGH QUALITY (A)</td>
                  <td class="text-center"><q-badge dense color="positive" label="ELIGIBLE" /></td>
                  <td class="text-center"><q-badge dense color="info" label="EDGE CHECK" /></td>
                  <td class="text-center"><q-badge dense color="negative" label="REJECTED" /></td>
                </tr>
                <tr>
                  <td class="text-weight-bold text-primary">MEDIUM QUALITY (B)</td>
                  <td class="text-center"><q-badge dense color="info" label="CONDITIONAL" /></td>
                  <td class="text-center"><q-badge dense color="warning" label="EVIDENCE REQ" /></td>
                  <td class="text-center"><q-badge dense color="negative" label="REJECTED" /></td>
                </tr>
                <tr>
                  <td class="text-weight-bold text-orange-4">LOW QUALITY (C)</td>
                  <td class="text-center" colspan="3"><q-badge dense color="orange-9" label="OBSERVATION ONLY (ZERO POSITION)" /></td>
                </tr>
                <tr>
                  <td class="text-weight-bold text-grey-5">UNKNOWN / UNVERIFIED</td>
                  <td class="text-center" colspan="3"><q-badge dense color="grey-8" label="NO AUTOMATIC EXECUTION" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </q-card>
      </div>
    </div>

    <!-- Decisions Filters Row -->
    <div class="row q-col-gutter-sm q-mb-sm items-center">
      <div class="col-12 col-sm-3">
        <q-select
          v-model="qualityFilter"
          :options="qualityOptions"
          dense
          outlined
          emit-value
          map-options
          label="Filter by Signal Quality"
        />
      </div>
      <div class="col-12 col-sm-3">
        <q-select
          v-model="riskFilter"
          :options="riskFilterOptions"
          dense
          outlined
          emit-value
          map-options
          label="Filter by Trade Risk"
        />
      </div>
      <div class="col-12 col-sm-3">
        <q-select
          v-model="allowedFilter"
          :options="allowedOptions"
          dense
          outlined
          emit-value
          map-options
          label="Decision Outcome"
        />
      </div>
      <div class="col-12 col-sm-3 text-right">
        <span class="text-caption text-grey">
          Total Filtered: {{ d.decisions?.total || d.decisions?.data?.length || 0 }} decisions
        </span>
      </div>
    </div>

    <!-- Decisions Table -->
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
            <template v-else-if="col.name === 'signal_quality'">
              <q-badge
                :color="qualityColor(col.value)"
                :label="col.value"
              />
            </template>
            <template v-else-if="col.name === 'trade_risk'">
              <q-badge
                :color="riskColor(col.value)"
                :label="col.value"
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
              <div class="row q-col-gutter-sm q-mb-xs">
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-4">Gate Decision:</div>
                  <q-badge
                    :color="props.row.snapshot?.gate_decision === 'ELIGIBLE' ? 'positive' : (props.row.snapshot?.gate_decision === 'CONDITIONALLY_ELIGIBLE' ? 'info' : (props.row.snapshot?.gate_decision === 'INSUFFICIENT_EVIDENCE' ? 'warning' : 'negative'))"
                    :label="props.row.snapshot?.gate_decision || (props.row.allowed ? 'ELIGIBLE' : 'REJECTED')"
                  />
                </div>
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-4">Trade Risk Score:</div>
                  <span class="text-white">{{ props.row.snapshot?.trade_risk_score ?? '—' }} / 100</span>
                </div>
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-4">Expected Net Edge:</div>
                  <span class="text-cyan">{{ props.row.snapshot?.expected_net_edge ?? 'No prior closed sample' }}</span>
                </div>
              </div>
              <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Risk Factors & Decision Snapshot:</div>
              <pre class="bg-grey-10 text-grey-2 q-pa-sm rounded-borders text-caption" style="max-height: 250px; overflow: auto; white-space: pre-wrap; font-family: monospace;">{{ props.row.snapshot ? JSON.stringify(props.row.snapshot, null, 2) : 'No snapshot recorded' }}</pre>
            </div>
          </q-td>
        </q-tr>
      </template>
    </q-table>

    <!-- Pagination & Footer -->
    <div class="row items-center justify-between q-mt-md">
      <span class="text-caption">Latest risk decisions · Page {{ page }} / {{ d.decisions?.last_page || 1 }}</span>
      <q-pagination v-model="page" :max="d.decisions?.last_page || 1" :max-pages="7" boundary-links direction-links />
    </div>
  </q-page>
</template>

<style scoped>
.text-xs {
  font-size: 0.72rem;
}
</style>
