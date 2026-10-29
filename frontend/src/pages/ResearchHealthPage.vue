<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../services/api';

const period = ref('24h');
const loading = ref(false);
const reconciling = ref(false);
const error = ref('');
const overview = ref<any>(null);
const freshness = ref<any>(null);

// A missing or failed Redis check is not an empty queue. Preserve the unknown state.
function queueCount(name: 'default' | 'audit' | 'research'): string {
  const queues = freshness.value?.queues;
  if (!queues || queues.status === 'UNAVAILABLE') return 'UNAVAILABLE';
  const value = queues[name]?.pending_jobs;
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
    ? String(value)
    : 'UNAVAILABLE';
}
const reconciliationRuns = ref<any[]>([]);

async function refresh() {
  loading.value = true;
  error.value = '';
  try {
    const [ovRes, frRes, rcRes] = await Promise.all([
      api.get('/research/health/overview', { params: { period: period.value } }),
      api.get('/research/health/data-freshness'),
      api.get('/research/health/reconciliation-runs', { params: { per_page: 10 } }),
    ]);
    overview.value = ovRes.data.data;
    freshness.value = frRes.data.data;
    reconciliationRuns.value = rcRes.data.data.data || [];
  } catch (err: any) {
    error.value = err.message || 'Failed to load research health';
  } finally {
    loading.value = false;
  }
}

async function runReconciliation() {
  reconciling.value = true;
  try {
    await api.post('/research/health/reconcile', {});
    await refresh();
  } catch (err: any) {
    error.value = err.message || 'Reconciliation failed';
  } finally {
    reconciling.value = false;
  }
}

onMounted(() => {
  refresh();
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">STAGE I · OPERATIONAL RELIABILITY</span>
        <h1>Strategy & System Health</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn-toggle v-model="period" no-caps unelevated
          toggle-color="primary" :options="[{label:'24h', value:'24h'}, {label:'7d', value:'7d'}, {label:'30d', value:'30d'}]"
          @update:model-value="refresh" />
        <q-btn flat icon="refresh" :loading="loading" label="Refresh" @click="refresh" />
      </div>
    </div>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>

    <!-- Top Status Cards -->
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-12 col-md-4">
        <div class="panel q-pa-md">
          <div class="text-caption text-grey">System Reliability</div>
          <div class="text-h5 q-mt-xs text-weight-bold" :class="{
            'text-positive': overview?.system_status === 'HEALTHY',
            'text-warning': overview?.system_status === 'WARNING',
            'text-negative': overview?.system_status === 'CRITICAL'
          }">
            {{ overview?.system_status || 'UNKNOWN' }}
          </div>
          <div class="text-caption q-mt-xs">DB Latency: {{ freshness?.database?.latency_ms }} ms · Execution Failures (24h): {{ freshness?.execution_failures_24h || 0 }}</div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="panel q-pa-md">
          <div class="text-caption text-grey">Queue Topology & Lag</div>
          <div class="text-body2 q-mt-xs">
            Default: <strong>{{ queueCount('default') }}</strong> ·
            Audit: <strong>{{ queueCount('audit') }}</strong> ·
            Research: <strong>{{ queueCount('research') }}</strong>
          </div>
          <div class="text-caption q-mt-xs">Single-threaded CPU bound research isolation active.</div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="panel q-pa-md row items-center justify-between">
          <div>
            <div class="text-caption text-grey">Ledger Integrity</div>
            <div class="text-body2 text-weight-bold q-mt-xs">Authoritative Audit Pass</div>
          </div>
          <q-btn color="primary" no-caps icon="verified" label="Reconcile" :loading="reconciling" @click="runReconciliation" />
        </div>
      </div>
    </div>

    <!-- Active Alerts if any -->
    <div v-if="freshness?.conflicts?.length" class="q-mb-md">
      <q-banner class="bg-negative text-white q-mb-xs">
        <template #avatar><q-icon name="report_problem" color="white" /></template>
        <div class="text-subtitle2">Unresolved Market Candle Integrity Conflicts ({{ freshness.unresolved_conflicts_count || freshness.conflicts.length }})</div>
        <div v-for="c in freshness.conflicts" :key="c.id" class="text-caption q-mt-xs">
          <strong>{{ c.exchange?.toUpperCase() }} {{ c.symbol }} {{ c.interval }}m @ {{ c.open_time }}:</strong>
          {{ c.error_message }} (Seen: {{ c.occurrence_count }}x, First: {{ c.first_seen_at }}, Last: {{ c.last_seen_at }})
        </div>
      </q-banner>
    </div>

    <div v-if="freshness?.alerts?.length" class="q-mb-md">
      <q-banner v-for="(alert, idx) in freshness.alerts" :key="idx" :class="alert.severity === 'ERROR' ? 'bg-negative text-white q-mb-xs' : 'bg-warning text-dark q-mb-xs'">
        <template #avatar><q-icon :name="alert.severity === 'ERROR' ? 'error' : 'warning'" :color="alert.severity === 'ERROR' ? 'white' : 'dark'" /></template>
        <strong>{{ alert.code }}:</strong> {{ alert.message }}
      </q-banner>
    </div>

    <!-- Strategy Funnel & Health Table -->
    <div class="panel q-pa-md q-mb-md">
      <div class="row items-center justify-between q-mb-sm">
        <h2>Continuous Strategy Health Funnel</h2>
        <span class="text-caption text-grey">Evaluated on closed candles only</span>
      </div>
      <q-table flat bordered :rows="overview?.strategies || []" row-key="strategy_id" :columns="[
        { name: 'strategy', label: 'Strategy', field: 'strategy_id', align: 'left' },
        { name: 'version', label: 'Ver', field: 'strategy_version', align: 'left' },
        { name: 'status', label: 'Health Status', field: 'status', align: 'center' },
        { name: 'evaluations', label: 'Slots', field: (r: any) => r.funnel?.evaluations },
        { name: 'candidates', label: 'BUY/SELL', field: (r: any) => `${r.funnel?.buy || 0} / ${r.funnel?.sell || 0}` },
        { name: 'skip', label: 'SKIP', field: (r: any) => r.funnel?.skip },
        { name: 'risk_approved', label: 'Risk OK', field: (r: any) => `${r.funnel?.risk_approved || 0} / ${r.funnel?.risk_assessed || 0}` },
        { name: 'entries', label: 'Fills / Expired', field: (r: any) => `${r.funnel?.filled_positions || 0} / ${r.funnel?.expired_entries || 0}` },
        { name: 'closed', label: 'Closed (Full/Part)', field: (r: any) => `${r.funnel?.closed_positions || 0} / ${r.funnel?.partially_closed || 0}` },
        { name: 'failures', label: 'Failures', field: (r: any) => r.funnel?.execution_failures },
      ]" :pagination="{ rowsPerPage: 10 }">
        <template #body-cell-status="props">
          <q-td :props="props">
            <q-badge :color="props.row.status === 'HEALTHY' ? 'positive' : (props.row.status === 'CRITICAL' ? 'negative' : 'warning')">
              {{ props.row.status }}
            </q-badge>
          </q-td>
        </template>
      </q-table>
    </div>

    <!-- Reconciliation Runs Table -->
    <div class="panel q-pa-md q-mb-md">
      <div class="row items-center justify-between q-mb-sm">
        <h2>Portfolio / Ledger Reconciliation Runs</h2>
        <span class="text-caption text-grey">Independent decimal verification pass</span>
      </div>
      <q-table flat bordered :rows="reconciliationRuns" row-key="id" :columns="[
        { name: 'status', label: 'Status', field: 'status', align: 'center' },
        { name: 'account_id', label: 'Account ID', field: 'paper_account_id', align: 'left' },
        { name: 'starting', label: 'Starting ($)', field: 'starting_balance' },
        { name: 'recalculated_equity', label: 'Recalculated Equity ($)', field: 'recalculated_equity' },
        { name: 'stored_equity', label: 'Stored Equity ($)', field: 'stored_equity' },
        { name: 'drawdown', label: 'Max Drawdown (%)', field: 'recalculated_drawdown_percent' },
        { name: 'discrepancies', label: 'Discrepancies', field: 'discrepancy_count' },
        { name: 'completed_at', label: 'Completed At (UTC)', field: 'completed_at' },
      ]" :pagination="{ rowsPerPage: 10 }">
        <template #body-cell-status="props">
          <q-td :props="props">
            <q-badge :color="props.row.status === 'PASSED' ? 'positive' : 'negative'">
              {{ props.row.status }}
            </q-badge>
          </q-td>
        </template>
      </q-table>
    </div>

    <!-- Candle Freshness Table -->
    <div class="panel q-pa-md">
      <div class="row items-center justify-between q-mb-sm">
        <h2>Market Ingestion & Candle Freshness</h2>
        <span class="text-caption text-grey">Alerts on >= 2 consecutive missing intervals</span>
      </div>
      <q-table flat bordered :rows="freshness?.candle_freshness || []" :row-key="(r: any) => `${r.exchange || 'all'}:${r.symbol}:${r.interval}`" :columns="[
        { name: 'exchange', label: 'Exchange', field: 'exchange', align: 'left' },
        { name: 'symbol', label: 'Symbol', field: 'symbol', align: 'left' },
        { name: 'interval', label: 'Interval', field: (r: any) => `${r.interval}m`, align: 'left' },
        { name: 'last_closed', label: 'Last Closed Open Time (UTC)', field: 'last_closed_open_time' },
        { name: 'expected_close', label: 'Expected Close (UTC)', field: 'expected_close_time' },
        { name: 'lag', label: 'Lag (s)', field: 'lag_seconds' },
        { name: 'status', label: 'State', field: (r: any) => r.status || (r.is_stale ? 'STALE' : 'FRESH'), align: 'center' },
      ]" :pagination="{ rowsPerPage: 10 }">
        <template #body-cell-status="props">
          <q-td :props="props">
            <q-badge :color="props.row.status === 'CONFLICTED' ? 'negative' : (props.row.status === 'STALE' ? 'warning' : (props.row.status === 'MISSING' ? 'deep-orange' : 'positive'))">
              {{ props.row.status || (props.row.is_stale ? 'STALE' : 'FRESH') }}
            </q-badge>
          </q-td>
        </template>
      </q-table>
    </div>
  </q-page>
</template>
