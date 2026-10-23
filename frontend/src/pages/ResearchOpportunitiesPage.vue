<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../services/api';

const router = useRouter();
const loading = ref(false);
const error = ref('');
const summary = ref<any>(null)
const formatR = (value: unknown): string =>
  (value !== null && value !== undefined && isFinite(Number(value)))
    ? `${Number(value).toFixed(2)} R`
    : '—'
const evaluations = ref<any[]>([]);
const strategyId = ref('');
const evalPage = ref(1);
const evalPages = ref(1);
const evalTotal = ref(0);

async function refresh() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/opportunities', {
      params: {
        strategy_id: strategyId.value || undefined,
        page: evalPage.value,
        per_page: 15,
      },
    });
    summary.value = res.data.data.summary;
    evaluations.value = res.data.data.evaluations.data || [];
    evalPages.value = res.data.data.evaluations.last_page || 1;
    evalTotal.value = res.data.data.evaluations.total || 0;
  } catch (err: any) {
    error.value = err.message || 'Failed to load counterfactual opportunities';
  } finally {
    loading.value = false;
  }
}

function openReplay(evalId: string) {
  router.push({ path: '/research/replay', query: { id: evalId } });
}

onMounted(() => {
  refresh();
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">STAGE II · COUNTERFACTUAL INTELLIGENCE</span>
        <h1>SKIP Opportunity Analyzer</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn flat icon="refresh" :loading="loading" label="Refresh" @click="refresh" />
      </div>
    </div>

    <q-banner class="bg-warning text-black q-mb-md" rounded dense>
      Legacy read-only. SKIP counterfactual replay is quarantined: rejected-signal replay (P-3) is
      prohibited, so no new hypothetical entries, targets or outcomes are generated or resolved.
      Rows below are historical and must not be treated as executed trades.
    </q-banner>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>
      {{ error }}
    </q-banner>

    <!-- Summary KPI cards -->
    <div class="row q-col-gutter-md q-mb-md" v-if="summary">
      <div class="col-12 col-md-3">
        <q-card flat bordered class="kpi-card">
          <q-card-section>
            <div class="text-caption text-grey">Evaluated SKIPs</div>
            <div class="text-h5 text-bold">{{ summary.total_evaluated }}</div>
            <div class="text-caption text-grey-6">Matured counterfactuals</div>
          </q-card-section>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="kpi-card">
          <q-card-section>
            <div class="text-caption text-grey">Restrictive Skips (Missed TP)</div>
            <div class="text-h5 text-bold text-warning">{{ summary.total_restrictive }}</div>
            <div class="text-caption text-grey-6">
              {{ summary.total_evaluated ? ((summary.total_restrictive / summary.total_evaluated) * 100).toFixed(1) : 0 }}% of filtered signals
            </div>
          </q-card-section>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="kpi-card">
          <q-card-section>
            <div class="text-caption text-grey">Protective Skips (Saved SL)</div>
            <div class="text-h5 text-bold text-positive">{{ summary.total_protective }}</div>
            <div class="text-caption text-grey-6">
              {{ summary.total_evaluated ? ((summary.total_protective / summary.total_evaluated) * 100).toFixed(1) : 0 }}% losses averted
            </div>
          </q-card-section>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="kpi-card">
          <q-card-section>
            <div class="text-caption text-grey">Net Filter Benefit R</div>
            <div class="text-h5 text-bold" :class="Number(summary.net_filter_benefit_r) > 0 ? 'text-positive' : (Number(summary.net_filter_benefit_r) < 0 ? 'text-negative' : 'text-grey')">
              {{ (summary.net_filter_benefit_r !== null && summary.net_filter_benefit_r !== undefined && isFinite(Number(summary.net_filter_benefit_r))) ? Number(summary.net_filter_benefit_r).toFixed(2) + ' R' : '—' }}
            </div>
            <div class="text-caption text-grey-6">Includes recorded timeouts; descriptive, dependent legacy observations</div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- Filter Effectiveness Breakdown -->
    <q-card flat bordered class="q-mb-md" v-if="summary && summary.filters && summary.filters.length">
      <q-card-section>
        <div class="text-subtitle1 text-bold">Filter Family Effectiveness</div>
        <div class="text-caption text-grey">Assessing whether each filter correctly prevents losses or excessively blocks winners.</div>
      </q-card-section>
      <q-separator />
      <q-table
        flat
        :rows="summary.filters"
        :columns="[
          { name: 'filter', label: 'Filter Family', field: 'filter', align: 'left' },
          { name: 'restrictive_skips', label: 'Restrictive (Missed TP)', field: 'restrictive_skips', align: 'right' },
          { name: 'protective_skips', label: 'Protective (Saved SL)', field: 'protective_skips', align: 'right' },
          { name: 'total_skips', label: 'Total Skips', field: 'total_skips', align: 'right' },
          { name: 'missed_tp_r', label: 'Missed TP R', field: 'missed_tp_r', align: 'right' },
          { name: 'avoided_sl_r', label: 'Avoided SL R', field: 'avoided_sl_r', align: 'right' },
          { name: 'timeout_r', label: 'Timeout R', field: 'timeout_r', align: 'right' },
          { name: 'net_filter_benefit_r', label: 'Net Filter Benefit R', field: 'net_filter_benefit_r', align: 'right' },
          { name: 'net_r', label: 'Legacy TP/SL Net R', field: 'net_r', align: 'right' },
        ]"
        row-key="filter"
        hide-pagination
        :pagination="{ rowsPerPage: 0 }"
      >
        <template #body-cell-filter="props">
          <q-td :props="props">
            <q-badge color="grey-8" :label="props.value" />
          </q-td>
        </template>
        <template #body-cell-restrictive_skips="props">
          <q-td :props="props" class="text-warning text-bold">
            {{ props.value }}
          </q-td>
        </template>
        <template #body-cell-protective_skips="props">
          <q-td :props="props" class="text-positive text-bold">
            {{ props.value }}
          </q-td>
        </template>
        <template #body-cell-missed_tp_r="props">
          <q-td :props="props" class="text-warning">
            {{ formatR(props.value) }}
          </q-td>
        </template>
        <template #body-cell-avoided_sl_r="props">
          <q-td :props="props" class="text-positive">
            {{ formatR(props.value) }}
          </q-td>
        </template>
        <template #body-cell-timeout_r="props">
          <q-td :props="props" :class="Number(props.value) < 0 ? 'text-negative' : 'text-grey'">
            {{ formatR(props.value) }}
          </q-td>
        </template>
        <template #body-cell-net_filter_benefit_r="props">
          <q-td :props="props" :class="Number(props.value) > 0 ? 'text-positive' : (Number(props.value) < 0 ? 'text-negative' : 'text-grey')">
            {{ formatR(props.value) }}
          </q-td>
        </template>
        <template #body-cell-net_r="props">
          <q-td :props="props" :class="Number(props.value) > 0 ? 'text-positive' : (Number(props.value) < 0 ? 'text-negative' : 'text-grey')">
            {{ formatR(props.value) }}
          </q-td>
        </template>
      </q-table>
    </q-card>

    <!-- Detailed Evaluations Table -->
    <q-card flat bordered>
      <q-card-section class="row items-center justify-between">
        <div>
          <div class="text-subtitle1 text-bold">Counterfactual Decision Log</div>
          <div class="text-caption text-grey">Historical SKIP candidates and what would have happened if entered.</div>
        </div>
      </q-card-section>
      <q-separator />
      <q-table
        flat
        :rows="evaluations"
        :columns="[
          { name: 'time', label: 'Decision Time', field: 'decision_time', align: 'left' },
          { name: 'strategy', label: 'Strategy / Symbol', field: (r: any) => `${r.strategy_id} (${r.symbol})`, align: 'left' },
          { name: 'direction', label: 'Direction', field: 'candidate_direction', align: 'center' },
          { name: 'skip_reason', label: 'Filter / Skip Reason', field: 'skip_reason', align: 'left' },
          { name: 'status', label: 'Outcome', field: 'status', align: 'center' },
          { name: 'r_multiple', label: 'R-Multiple', field: 'r_multiple', align: 'right' },
          { name: 'actions', label: 'Action', field: 'id', align: 'center' },
        ]"
        row-key="id"
        :pagination="{ rowsPerPage: 0 }"
        hide-pagination
      >
        <template #body-cell-direction="props">
          <q-td :props="props">
            <q-badge :color="props.value === 'BUY' ? 'positive' : 'negative'" :label="props.value" />
          </q-td>
        </template>
        <template #body-cell-status="props">
          <q-td :props="props">
            <q-badge
              :color="props.value === 'RESOLVED_TP' ? 'positive' : (props.value === 'RESOLVED_SL' ? 'negative' : 'grey-7')"
              :label="props.value"
            />
          </q-td>
        </template>
        <template #body-cell-r_multiple="props">
          <q-td :props="props" :class="Number(props.value) > 0 ? 'text-positive' : (Number(props.value) < 0 ? 'text-negative' : 'text-grey')">
            {{ (props.value !== null && props.value !== undefined && isFinite(Number(props.value))) ? Number(props.value).toFixed(2) + ' R' : '—' }}
          </q-td>
        </template>
        <template #body-cell-actions="props">
          <q-td :props="props">
            <q-btn flat dense size="sm" icon="find_in_page" label="Replay" color="primary" @click="openReplay(props.value)" />
          </q-td>
        </template>
        <template #no-data>
          <div class="full-width row flex-center q-pa-lg text-grey">
            No counterfactual evaluations recorded yet. Run analyzer jobs to populate.
          </div>
        </template>
      </q-table>
      <div class="row items-center justify-between q-pa-md">
        <div class="text-caption text-grey">
          Showing {{ evaluations.length ? (evalPage - 1) * 15 + 1 : 0 }}–{{ Math.min(evalPage * 15, evalTotal || evaluations.length) }}
          of {{ evalTotal || evaluations.length }} candidates · Page {{ evalPage }} / {{ evalPages }}
        </div>
        <q-pagination v-model="evalPage" :max="evalPages" :max-pages="7" boundary-links direction-links :disable="loading" @update:model-value="refresh" />
      </div>
    </q-card>
  </q-page>
</template>

<style scoped>
.eyebrow {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--q-primary);
  text-transform: uppercase;
}
.kpi-card {
  border-radius: 8px;
}
</style>
